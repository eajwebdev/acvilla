<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Baseline browser hardening for every web response. */
class SecurityHeaders
{
    /**
     * What a page may load: scripts only from this site; styles and fonts from here and Google Fonts; images from
     * anywhere over HTTPS (Google profile photos, payment QR links); the Google map as the only frame. No plugins,
     * no other site may frame these pages, and forms only post back here.
     */
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        ."font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob: https:; connect-src 'self'; "
        ."frame-src https://www.google.com https://maps.google.com; frame-ancestors 'self'; form-action 'self'; "
        ."base-uri 'self'; object-src 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        // PHP adds its version itself (expose_php); drop it at both levels so the PHP version is never advertised.
        $headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }
        // In production only: the Vite dev server (local) serves scripts from another port and hot-reloads inline.
        if (app()->isProduction() && ! is_file(public_path('hot'))) {
            // Over HTTPS, any stray http:// link is upgraded too (on plain HTTP that would break every form).
            $headers->set('Content-Security-Policy', self::CSP.($request->isSecure() ? '; upgrade-insecure-requests' : ''));
        }
        // Signed-in pages (bookings, receipts, admin) are never kept by shared caches or the back/forward cache of another user.
        if ($request->user()) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        // Only over HTTPS in production, so a local or staging HTTP host is never pinned.
        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
