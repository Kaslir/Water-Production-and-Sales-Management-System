<?php

namespace App\Http\Controllers;

use App\Models\ProfileEmailChange;
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
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $pending = ProfileEmailChange::where('user_id', $request->user()->user_id)->first();

        return view('profile.show', ['user' => $request->user(), 'pending' => $pending]);
    }

    public function update(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $user = $request->user();
        $name = trim($data['name']);
        if ($name === '') {
            return back()->withErrors(['name' => 'Enter your name.']);
        }
        if ($name !== $user->name) {
            $old = $user->name;
            $user->update(['name' => $name]);
            $auditTrail->record('user.profile_updated', $user, $user->user_id, ['name' => $old], ['name' => $name]);
        }

        return back()->with('status', 'Profile updated.');
    }

    public function requestEmailChange(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'new_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'current_password' => ['required', 'string'],
        ]);
        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }
        $email = Str::lower(trim($data['new_email']));
        if ($email === Str::lower($user->email)) {
            return back()->withErrors(['new_email' => 'Enter a different email address.']);
        }
        $key = 'profile-email:'.$user->user_id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->withErrors(['new_email' => 'Please wait before requesting another code.']);
        }
        RateLimiter::hit($key, 600);
        $code = (string) random_int(100000, 999999);
        $pending = ProfileEmailChange::updateOrCreate(['user_id' => $user->user_id], [
            'new_email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
        ]);
        try {
            Mail::raw("Your AquaTrack email change code is {$code}. It expires in 10 minutes. If you did not request this, contact your administrator.", fn ($mail) => $mail->to($email)->subject('Verify your new AquaTrack email'));
        } catch (\Throwable $exception) {
            $pending->delete();
            Log::error('Profile email verification delivery failed.', ['user_id' => $user->user_id, 'exception_type' => $exception::class]);

            return back()->withErrors(['new_email' => 'The verification email could not be sent. Check the mail settings and try again.']);
        }

        return back()->with('status', 'A verification code was sent to the new email address.');
    }

    public function verifyEmailChange(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        $result = DB::transaction(function () use ($user, $data, $auditTrail): string {
            $pending = ProfileEmailChange::where('user_id', $user->user_id)->lockForUpdate()->first();
            if (! $pending || $pending->expires_at->isPast() || $pending->attempts >= 5) {
                return 'expired';
            }
            $pending->increment('attempts');
            if (! Hash::check($data['code'], $pending->code_hash)) {
                return 'invalid';
            }
            if (User::where('email', $pending->new_email)->where('user_id', '!=', $user->user_id)->exists()) {
                return 'taken';
            }
            $oldEmail = $user->email;
            $user->forceFill(['email' => $pending->new_email, 'email_verified_at' => now()])->save();
            $pending->delete();
            $auditTrail->record('user.email_changed', $user, $user->user_id, ['email' => $oldEmail], ['email' => $user->email]);

            return $oldEmail;
        });
        if (in_array($result, ['expired', 'invalid', 'taken'], true)) {
            $message = $result === 'taken' ? 'This email address is already in use.' : 'Invalid or expired verification code.';

            return back()->withErrors(['code' => $message]);
        }
        try {
            Mail::raw('Your AquaTrack account email address was changed. If this was not you, contact your administrator.', fn ($mail) => $mail->to($result)->subject('AquaTrack email changed'));
        } catch (\Throwable $exception) {
            Log::error('Profile email change notice failed.', ['user_id' => $user->user_id, 'exception_type' => $exception::class]);
        }

        return back()->with('status', 'Email address updated.');
    }

    public function cancelEmailChange(Request $request): RedirectResponse
    {
        ProfileEmailChange::where('user_id', $request->user()->user_id)->delete();

        return back()->with('status', 'Pending email change cancelled.');
    }

    public function updatePassword(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }
        if (Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'Choose a different password.']);
        }
        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->user_id)->where('id', '!=', $request->session()->getId())->delete();
        }
        $auditTrail->record('user.password_changed', $user, $user->user_id);
        try {
            Mail::raw('Your AquaTrack password was changed. If this was not you, contact your administrator.', fn ($mail) => $mail->to($user->email)->subject('AquaTrack password changed'));
        } catch (\Throwable $exception) {
            Log::error('Profile password change notice failed.', ['user_id' => $user->user_id, 'exception_type' => $exception::class]);
        }

        return back()->with('status', 'Password updated.');
    }
}
