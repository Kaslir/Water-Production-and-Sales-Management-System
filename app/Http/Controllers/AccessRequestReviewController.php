<?php

namespace App\Http\Controllers;

use App\Mail\AccessApprovedMail;
use App\Models\AccessRequest;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccessRequestReviewController extends Controller
{
    public function index(): View
    {
        $requests = AccessRequest::query()->orderByRaw("CASE WHEN status = 'pending' AND email_verified_at IS NOT NULL THEN 0 WHEN status = 'pending' THEN 1 ELSE 2 END")
            ->latest()->paginate(20);

        return view('users.access-requests', compact('requests'));
    }

    public function approve(Request $request, AccessRequest $accessRequest, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', Rule::in(User::ROLES)]]);
        DB::transaction(function () use ($accessRequest, $request, $auditTrail, $data): void {
            $accessRequest = AccessRequest::whereKey($accessRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($accessRequest->status === 'pending' && $accessRequest->email_verified_at, 422, 'This request must be pending with a verified email.');
            abort_if(User::where('email', $accessRequest->email)->exists(), 422, 'A user with this email already exists.');
            $user = User::create([
                'name' => $accessRequest->name,
                'email' => $accessRequest->email,
                'phone_number' => $accessRequest->phone_number,
                'password' => $accessRequest->password,
                'role' => $data['role'],
                'is_active' => true,
            ]);
            $user->forceFill(['email_verified_at' => $accessRequest->email_verified_at])->save();
            $accessRequest->update(['status' => 'approved', 'reviewed_by' => $request->user()->user_id, 'reviewed_at' => now()]);
            $auditTrail->record('access_request.approved', $user, $request->user()->user_id, [], ['role' => $user->role]);
        });

        return $this->sendApprovalEmail($accessRequest);
    }

    public function reject(Request $request, AccessRequest $accessRequest, AuditTrail $auditTrail): RedirectResponse
    {
        DB::transaction(function () use ($accessRequest, $request, $auditTrail): void {
            $accessRequest = AccessRequest::whereKey($accessRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($accessRequest->status === 'pending', 422, 'This request has already been reviewed.');
            $accessRequest->update(['status' => 'rejected', 'reviewed_by' => $request->user()->user_id, 'reviewed_at' => now()]);
            $auditTrail->record('access_request.rejected', $accessRequest, $request->user()->user_id);
        });

        return redirect()->route('access.reviews.index')->with('status', 'Access request rejected.');
    }

    public function resend(AccessRequest $accessRequest): RedirectResponse
    {
        abort_unless($accessRequest->status === 'approved', 422);

        return $this->sendApprovalEmail($accessRequest);
    }

    private function sendApprovalEmail(AccessRequest $accessRequest): RedirectResponse
    {
        try {
            Mail::to($accessRequest->email)->send(new AccessApprovedMail);

            return redirect()->route('access.reviews.index')->with('status', 'Account approved and notification email sent.');
        } catch (\Throwable $exception) {
            Log::error('Access approval email delivery failed.', ['access_request_id' => $accessRequest->id, 'exception_type' => $exception::class]);

            return redirect()->route('access.reviews.index')->with('mail_error', 'Account approved, but the email could not be sent. Check mail settings, then use Resend notice.');
        }
    }
}
