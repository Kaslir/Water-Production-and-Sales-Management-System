<?php

namespace App\Http\Controllers;

use App\Mail\AccessVerificationMail;
use App\Models\AccessRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AccessRequestController extends Controller
{
    public function create(): View
    {
        return view('auth.request-access');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone_number' => ['required', 'regex:/^0[17]\d{8}$/'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $email = Str::lower(trim($data['email']));
        $message = 'If this email is eligible, a verification link has been sent. Verify your email before an administrator can review your request.';
        $key = 'access-request:'.hash('sha256', $email.'|'.$request->ip());
        $ipKey = 'access-request-ip:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, 3) || RateLimiter::tooManyAttempts($ipKey, 10)) {
            return redirect()->route('login')->with('status', $message);
        }
        RateLimiter::hit($key, 3600);
        RateLimiter::hit($ipKey, 3600);
        if (User::where('email', $email)->exists()) {
            return redirect()->route('login')->with('status', $message);
        }

        $accessRequest = AccessRequest::where('email', $email)->first();
        if ($accessRequest && ($accessRequest->status === 'approved' || ($accessRequest->status === 'pending' && $accessRequest->email_verified_at))) {
            return redirect()->route('login')->with('status', $message);
        }
        $values = ['name' => $data['name'], 'email' => $email, 'phone_number' => $data['phone_number'], 'password' => $data['password'], 'verification_nonce' => Str::random(40), 'status' => 'pending', 'email_verified_at' => null, 'reviewed_by' => null, 'reviewed_at' => null];
        if ($accessRequest) {
            $accessRequest->update($values);
        } else {
            $accessRequest = AccessRequest::create($values);
        }
        $url = URL::temporarySignedRoute('access.verify', now()->addDay(), ['accessRequest' => $accessRequest->id, 'nonce' => $accessRequest->verification_nonce]);
        try {
            Mail::to($email)->send(new AccessVerificationMail($url));
        } catch (\Throwable $exception) {
            Log::error('Access verification email delivery failed.', ['access_request_id' => $accessRequest->id, 'exception_type' => $exception::class]);
        }

        return redirect()->route('login')->with('status', $message);
    }

    public function verify(Request $request, AccessRequest $accessRequest): RedirectResponse
    {
        abort_unless(hash_equals($accessRequest->verification_nonce, (string) $request->query('nonce')), 403);
        if ($accessRequest->status !== 'pending') {
            return redirect()->route('login')->with('status', 'This access request is no longer pending.');
        }
        $accessRequest->update(['email_verified_at' => $accessRequest->email_verified_at ?? now()]);

        return redirect()->route('login')->with('status', 'Email verified. An administrator can now review your access request.');
    }
}
