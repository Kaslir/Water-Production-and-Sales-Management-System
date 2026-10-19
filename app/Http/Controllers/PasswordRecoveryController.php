<?php

namespace App\Http\Controllers;

use App\Models\PasswordResetCode;
use App\Models\TechnicalEvent;
use App\Models\User;
use App\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordRecoveryController extends Controller
{
    public function requestForm(): View
    {
        return view('auth.forgot-password');
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $email = Str::lower($data['email']);
        $key = 'password-reset-request:'.hash('sha256', $email.'|'.$request->ip());
        $message = 'If this account exists, a reset code has been sent to its email address.';
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return redirect()->route('password.reset_form')->with('status', $message);
        }
        RateLimiter::hit($key, 600);
        $user = User::query()->where('email', $email)->where('is_active', true)->first();
        if ($user) {
            $code = (string) random_int(100000, 999999);
            DB::transaction(function () use ($user, $code): void {
                PasswordResetCode::query()->where('user_id', $user->user_id)->whereNull('used_at')->update(['used_at' => now()]);
                PasswordResetCode::create(['user_id' => $user->user_id, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10)]);
            });
            try {
                Mail::raw("Your AquaTrack password reset code is {$code}. It expires in 10 minutes. If you did not request this, ignore this email.", fn ($mail) => $mail->to($user->email)->subject('Reset your AquaTrack password'));
            } catch (\Throwable $exception) {
                Log::error('Password reset email delivery failed.', ['user_id' => $user->user_id, 'exception_type' => $exception::class]);
                try {
                    TechnicalEvent::create(['event_type' => 'PasswordResetMailFailure', 'source' => 'forgot-password', 'occurred_at' => now()]);
                } catch (\Throwable) {
                }
            }
        }

        return redirect()->route('password.reset_form')->with('status', $message);
    }

    public function resetForm(): View
    {
        return view('auth.reset-password');
    }

    public function reset(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $email = Str::lower($data['email']);
        $key = 'password-reset-verify:'.hash('sha256', $email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['code' => 'Too many attempts. Please request a new code later.'])->onlyInput('email');
        }
        $user = User::query()->where('email', $email)->where('is_active', true)->first();
        $valid = false;
        if ($user) {
            DB::transaction(function () use ($user, $data, $auditTrail, &$valid): void {
                $record = PasswordResetCode::query()->where('user_id', $user->user_id)->whereNull('used_at')->latest('id')->lockForUpdate()->first();
                if (! $record || $record->expires_at->isPast() || $record->attempts >= 5) {
                    return;
                }
                $record->increment('attempts');
                if (! Hash::check($data['code'], $record->code_hash)) {
                    return;
                }
                $record->update(['used_at' => now()]);
                $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $user->user_id)->delete();
                }
                $auditTrail->record('user.password_reset', $user, $user->user_id);
                $valid = true;
            });
        }
        if (! $valid) {
            RateLimiter::hit($key, 600);

            return back()->withErrors(['code' => 'Invalid or expired reset code.'])->onlyInput('email');
        }
        RateLimiter::clear($key);
        try {
            Mail::raw('Your AquaTrack password was changed. If this was not you, contact your administrator.', fn ($mail) => $mail->to($user->email)->subject('AquaTrack password changed'));
        } catch (\Throwable $exception) {
            Log::error('Password change notice delivery failed.', ['user_id' => $user->user_id, 'exception_type' => $exception::class]);
            try {
                TechnicalEvent::create(['event_type' => 'PasswordChangedMailFailure', 'source' => 'reset-password', 'occurred_at' => now()]);
            } catch (\Throwable) {
            }
        }

        return redirect()->route('login')->with('status', 'Password reset. Please log in.');
    }
}
