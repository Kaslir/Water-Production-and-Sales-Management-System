<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    public function loginForm()
    {
        return view('auth.login');
    }

    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required']);
        $key = 'login:'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'Too many attempts. Please wait.']);
        } if (! Auth::validate($data)) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'Invalid credentials.'])->onlyInput('email');
        } $u = User::where('email', $data['email'])->first();
        if (! $u->is_active) {
            return back()->withErrors(['email' => 'This account is inactive.']);
        } RateLimiter::clear($key);
        $code = (string) random_int(100000, 999999);
        $u->securityCodes()->whereNull('used_at')->update(['used_at' => now()]);
        $u->securityCodes()->create(['code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10)]);
        session(['pending_user_id' => $u->user_id]);
        Mail::raw("Your Water Management security code is {$code}. It expires in 10 minutes.", fn ($m) => $m->to($u->email)->subject('Your security code'));

        return redirect()->route('security.form')->with('status', 'A security code has been sent.');
    }

    public function securityForm()
    {
        abort_unless(session('pending_user_id'), 403);

        return view('auth.security');
    }

    public function verify(Request $r, AuditTrail $audit)
    {
        $r->validate(['code' => 'required|digits:6']);
        $u = User::findOrFail(session('pending_user_id'));
        $key = 'login-code:'.$u->user_id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['code' => 'Too many attempts. Please request a new code later.']);
        }$record = $u->securityCodes()->whereNull('used_at')->where('expires_at', '>', now())->latest()->first();
        if (! $record || ! Hash::check($r->code, $record->code_hash)) {
            RateLimiter::hit($key, 600);

            return back()->withErrors(['code' => 'Invalid or expired security code.']);
        }if (! $u->is_active) {
            return back()->withErrors(['code' => 'This account is inactive.']);
        }$record->update(['used_at' => now()]);
        RateLimiter::clear($key);
        Auth::login($u);
        $r->session()->regenerate();
        session()->forget('pending_user_id');
        $audit->record('user.logged_in', $u, $u->user_id);

        return redirect()->route('dashboard');
    }

    public function resend(Request $r)
    {
        $u = User::findOrFail(session('pending_user_id'));
        $key = 'security-resend:'.$u->user_id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            return back()->withErrors(['code' => 'Please wait before requesting another code.']);
        }RateLimiter::hit($key, 60);
        $code = (string) random_int(100000, 999999);
        $u->securityCodes()->whereNull('used_at')->update(['used_at' => now()]);
        $u->securityCodes()->create(['code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10)]);
        Mail::raw("Your new security code is {$code}. It expires in 10 minutes.", fn ($m) => $m->to($u->email)->subject('Your security code'));

        return back()->with('status', 'A new code was sent.');
    }

    public function logout(Request $r, AuditTrail $audit)
    {
        $user = $r->user();
        if ($user) {
            $audit->record('user.logged_out', $user, $user->user_id);
        }Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }
}
