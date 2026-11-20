<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

/**
 * The platform owner (SUPERADMIN) belongs to no business, but this install runs one: AC Villa. On /admin pages they
 * act as that business's Admin, so every admin page and permission works for them unchanged.
 *
 * Only the in-memory user changes: role and tenant are marked as unchanged, so a profile save never writes them to
 * the database, and the account stays a platform owner everywhere else (/superadmin, sign-in).
 */
class SuperadminActsAsSiteAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user?->role === 'SUPERADMIN' && $user->tenant_id === null) {
            $site = Tenant::where('slug', config('platform.site_tenant'))->first();
            abort_unless($site, 404);
            $user->forceFill(['role' => 'ADMIN', 'tenant_id' => $site->id])->syncOriginalAttributes(['role', 'tenant_id']);
            $user->setRelation('tenant', $site);
            $request->attributes->set('superadmin', true);
        }

        return $next($request);
    }
}
