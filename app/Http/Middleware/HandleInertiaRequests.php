<?php

namespace App\Http\Middleware;

use App\Http\Controllers\GuestAuthController;
use App\Support\PhilippinePhone;

use App\Http\Controllers\AdminNotificationController;
use App\Models\Booking;
use App\Models\CleaningTask;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            // Resolved when the page renders, after route middleware: on /admin the platform owner shows as AC Villa's Admin
            // (role ADMIN), with `superadmin` set so the admin can offer a way back to the platform pages.
            'auth' => fn () => ['user' => $request->user() ? [...$request->user()->only('id', 'name', 'email', 'role', 'tenant_id', 'avatar'),
                'phone' => $request->user()->phone ? PhilippinePhone::format($request->user()->phone) : null,
                'superadmin' => $request->user()->getOriginal('role') === 'SUPERADMIN' || (bool) $request->attributes->get('superadmin')] : null],
            // Guests sign in before booking: with Google once it is set up, otherwise with email.
            'googleLogin' => GuestAuthController::enabled(),
            'guestLoginRequired' => GuestAuthController::required(),
            'demo' => config('platform.demo_mode'),
            'currency' => $request->user()?->tenant?->setting('currency') ?? config('platform.tenant_defaults.currency'),
            'flash' => ['success' => $request->session()->get('success')],
            // The resort's date (Manila), so calendars never offer yesterday even when a phone's clock or timezone is off.
            'serverToday' => today()->toDateString(),
            // Badge on "My tasks": open cleaning assignments up to today.
            'myTaskCount' => fn () => $request->user()?->worksShifts()
                ? CleaningTask::where('assigned_to', $request->user()->id)->where('status', '!=', 'DONE')
                    ->whereDate('date', '<=', today()->toDateString())->whereDate('date', '>=', today()->subDays(14)->toDateString())->count()
                : 0,
            // The bell at the top of the admin: booking steps this user hasn't seen yet.
            'notificationCount' => fn () => $request->user()?->tenant_id && in_array($request->user()->role, User::TEAM_ROLES, true)
                ? AdminNotificationController::unreadCount($request->user()) : 0,
            // Badge on "Bookings": website reservation requests still waiting for the owner's call.
            'requestCount' => fn () => $request->user()?->tenant_id && in_array($request->user()->role, User::TEAM_ROLES, true)
                ? Booking::where('tenant_id', $request->user()->tenant_id)->where('source', 'website')->where('status', 'PENDING')->where('expires_at', '>', now())->count()
                : 0,
        ]);
    }
}
