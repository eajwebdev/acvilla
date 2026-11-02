<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireActiveTenant
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->tenant?->acceptsBookings(), 403, 'This account is currently unavailable.');

        return $next($request);
    }
}
