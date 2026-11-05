<?php

namespace App\Services;

use App\Mail\EmployeeInvitationMail;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeInvitations
{
    public function send(User $employee, User $administrator, AuditTrail $auditTrail): bool
    {
        $this->assertConfigured();

        $token = Str::random(64);
        $invitation = DB::transaction(function () use ($employee, $administrator, $auditTrail, $token): EmployeeInvitation {
            $employee = User::whereKey($employee->user_id)->lockForUpdate()->firstOrFail();
            abort_if($employee->is_active || $employee->activated_at, 422, 'This employee cannot receive an activation invitation.');
            $employee->employeeInvitations()->where('status', 'pending')->update(['status' => 'revoked', 'revoked_at' => now()]);
            $invitation = $employee->employeeInvitations()->create([
                'invited_by' => $administrator->user_id,
                'token_hash' => hash('sha256', $token),
                'status' => 'pending',
                'expires_at' => now()->addDay(),
            ]);
            $auditTrail->record('employee.invited', $employee, $administrator->user_id, [], ['invitation_id' => $invitation->id]);

            return $invitation;
        });

        $relativeUrl = route('invitations.show', ['invitation' => $invitation->id, 'token' => $token], false);
        $url = rtrim((string) config('app.url'), '/').$relativeUrl;
        try {
            Mail::to($employee->email)->send(new EmployeeInvitationMail($employee->name, ucwords(str_replace('_', ' ', $employee->role)), $url));

            return true;
        } catch (\Throwable $exception) {
            Log::error('Employee invitation delivery failed.', ['invitation_id' => $invitation->id, 'exception_type' => $exception::class]);

            return false;
        }
    }

    public function assertConfigured(): void
    {
        if (app()->isProduction() && ! str_starts_with((string) config('app.url'), 'https://')) {
            throw ValidationException::withMessages(['email' => 'Configure an HTTPS APP_URL before sending invitations.']);
        }
        if (app()->isProduction() && config('session.secure') !== true) {
            throw ValidationException::withMessages(['email' => 'Enable SESSION_SECURE_COOKIE before sending invitations in production.']);
        }
        if (config('mail.default') === 'log' && ! app()->runningUnitTests()) {
            throw ValidationException::withMessages(['email' => 'Configure SMTP mail before inviting employees; the log mailer would expose invitation links.']);
        }
    }
}
