<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        // Re-read the account on each protected request so an already-authenticated session
        // cannot continue using an in-memory copy after an administrator disables it.
        if ($request->user() && ! $request->user()->fresh()?->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account is inactive.']);
        }

        return $next($request);
    }
}
