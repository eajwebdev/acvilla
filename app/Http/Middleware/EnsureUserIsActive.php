<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Signs out a user whose access was turned off while they were logged in. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->deactivated_at) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Your access has been turned off. Contact your administrator.']);
        }

        return $next($request);
    }
}
