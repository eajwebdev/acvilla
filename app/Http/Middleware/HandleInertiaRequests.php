<?php

namespace App\Http\Middleware;

use App\Http\Controllers\GuestAuthController;
use App\Support\PhilippinePhone;

use App\Http\Controllers\AdminNotificationController;
use App\Models\Booking;
use App\Models\CleaningTask;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => ['user' => $request->user() ? [...$request->user()->only('id', 'name', 'email', 'role', 'tenant_id', 'avatar'),
                'phone' => $request->user()->phone ? PhilippinePhone::format($request->user()->phone) : null] : null],
            // Guests sign in before booking: with Google once it is set up, otherwise with email.
            'googleLogin' => GuestAuthController::enabled(),
            'guestLoginRequired' => GuestAuthController::required(),
            'demo' => config('platform.demo_mode'),
            'currency' => $request->user()?->tenant?->setting('currency') ?? config('platform.tenant_defaults.currency'),
            'flash' => ['success' => $request->session()->get('success')],
            // The resort's date (Manila), so calendars never offer yesterday even when a phone's clock or timezone is off.
            'serverToday' => today()->toDateString(),
            // Badge on "My tasks": open cleaning assignments up to today.
            'myTaskCount' => fn () => $request->user()?->role === 'STAFF'
                ? CleaningTask::where('assigned_to', $request->user()->id)->where('status', '!=', 'DONE')
                    ->whereDate('date', '<=', today()->toDateString())->whereDate('date', '>=', today()->subDays(14)->toDateString())->count()
                : 0,
            // The bell at the top of the admin: booking steps this user hasn't seen yet.
            'notificationCount' => fn () => $request->user()?->tenant_id && in_array($request->user()->role, ['ADMIN', 'STAFF'], true)
                ? AdminNotificationController::unreadCount($request->user()) : 0,
            // Badge on "Bookings": website reservation requests still waiting for the owner's call.
            'requestCount' => fn () => $request->user()?->tenant_id && in_array($request->user()->role, ['ADMIN', 'STAFF'], true)
                ? Booking::where('tenant_id', $request->user()->tenant_id)->where('source', 'website')->where('status', 'PENDING')->where('expires_at', '>', now())->count()
                : 0,
        ]);
    }
}
