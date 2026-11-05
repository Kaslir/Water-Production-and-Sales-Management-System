<?php

namespace App\Http\Controllers;

use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\EmployeeInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmployeeInvitationController extends Controller
{
    public function show(Request $request, EmployeeInvitation $invitation): View
    {
        $state = $this->state($invitation, (string) $request->query('token'));

        return view('auth.activate', ['invitation' => $state === 'valid' ? $invitation->load('user') : null, 'state' => $state, 'token' => $state === 'valid' ? $request->query('token') : null]);
    }

    public function accept(Request $request, EmployeeInvitation $invitation, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $key = 'employee-activation:'.hash('sha256', $invitation->id.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => 'Too many attempts. Please try again later.']);
        }
        RateLimiter::hit($key, 600);

        DB::transaction(function () use ($invitation, $data, $auditTrail): void {
            $invitation = EmployeeInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->state($invitation, $data['token']) === 'valid', 422, 'This invitation cannot be used.');
            $employee = User::whereKey($invitation->user_id)->lockForUpdate()->firstOrFail();
            abort_if($employee->is_active, 422, 'This account is already active.');
            $employee->forceFill(['password' => $data['password'], 'is_active' => true, 'email_verified_at' => now(), 'activated_at' => now()])->save();
            $invitation->update(['status' => 'accepted', 'accepted_at' => now()]);
            $auditTrail->record('employee.activated', $employee, $employee->user_id, [], ['invitation_id' => $invitation->id]);
        });
        RateLimiter::clear($key);

        return redirect()->route('login')->with('status', 'Your account is ready. Sign in with your new password.');
    }

    public function resend(Request $request, User $user, EmployeeInvitations $invitations, AuditTrail $auditTrail): RedirectResponse
    {
        abort_if($user->is_active || $user->activated_at || ! $user->employeeInvitations()->exists(), 422, 'This employee has no pending activation.');
        $key = 'employee-invitation-resend:'.$user->user_id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['email' => 'Too many invitations sent. Try again later.']);
        }
        RateLimiter::hit($key, 3600);
        $sent = $invitations->send($user, $request->user(), $auditTrail);

        return redirect()->route('users.index')->with($sent ? 'status' : 'mail_error', $sent ? 'A new invitation was sent. The old link is no longer valid.' : 'Invitation saved, but email delivery failed. Check mail settings and resend.');
    }

    public function revoke(Request $request, User $user, AuditTrail $auditTrail): RedirectResponse
    {
        DB::transaction(function () use ($user, $request, $auditTrail): void {
            $user = User::whereKey($user->user_id)->lockForUpdate()->firstOrFail();
            abort_if($user->is_active || $user->activated_at, 422, 'Active or previously activated accounts cannot be revoked as invitations.');
            $updated = $user->employeeInvitations()->where('status', 'pending')->update(['status' => 'revoked', 'revoked_at' => now()]);
            abort_unless($updated, 422, 'There is no pending invitation to revoke.');
            $auditTrail->record('employee.invitation_revoked', $user, $request->user()->user_id);
        });

        return redirect()->route('users.index')->with('status', 'Invitation revoked. The employee cannot activate this account.');
    }

    private function state(EmployeeInvitation $invitation, string $token): string
    {
        if (strlen($token) !== 64 || ! hash_equals($invitation->token_hash, hash('sha256', $token))) {
            return 'invalid';
        }
        if ($invitation->status === 'accepted') {
            return 'used';
        }
        if ($invitation->status !== 'pending') {
            return 'invalid';
        }

        return $invitation->expires_at->isPast() ? 'expired' : 'valid';
    }
}
