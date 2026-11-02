<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Cloudflare or a load balancer, trust its X-Forwarded-* headers (TRUSTED_PROXIES=* or a list of IPs) so HTTPS and client IPs are seen correctly.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->web(append: [\App\Http\Middleware\SecurityHeaders::class, \App\Http\Middleware\EnsureUserIsActive::class, \App\Http\Middleware\HandleInertiaRequests::class]);
        $middleware->alias([
            'role' => \App\Http\Middleware\RequireRole::class,
            'tenant.active' => \App\Http\Middleware\RequireActiveTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A refused action (abort(422)) from an Inertia form comes back as a toast instead of an error page.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->header('X-Inertia') && $e->getStatusCode() === 422 && ! $request->isMethod('GET')) {
                return back()->withErrors(['action' => $e->getMessage() ?: 'That change is not allowed right now.']);
            }
            // A form left open past the session lifetime (common on staff phones) gets a retry prompt, not "419 Page Expired".
            if ($e->getStatusCode() === 419 && ! $request->expectsJson()) {
                return back()->withErrors(['action' => 'This page was open too long and your session expired. Please try again.']);
            }
        });
        // Outside local development and tests, page errors get the branded error screen instead of the framework default.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            if (app()->environment(['local', 'testing']) || $request->expectsJson() || ! in_array($status, [403, 404, 429, 500, 503], true)) {
                return $response;
            }

            return Inertia::render('Error', ['status' => $status])->toResponse($request)->setStatusCode($status);
        });
    })->create();
