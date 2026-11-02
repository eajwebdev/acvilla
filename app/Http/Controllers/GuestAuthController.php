<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Support\PhilippinePhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;

/**
 * Guests sign in with Google, then add their name and a Philippine mobile number once. Their bookings are then
 * on their account: no reference number needed to find them, on any phone.
 */
class GuestAuthController extends Controller
{
    /** Google sign-in is on once the Google client id and secret are set. */
    public static function enabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    /** Booking needs a guest account with a mobile number (on by default; REQUIRE_GUEST_LOGIN=false turns it off). */
    public static function required(): bool
    {
        return (bool) config('platform.require_guest_login');
    }

    /** Where a guest goes after signing in: the profile step first if the mobile number is missing, then back where they were. */
    public static function landing(Request $request, User $user): string
    {
        if ($user->role !== 'GUEST') {
            $request->session()->forget('guest_return');

            return match ($user->role) { 'STAFF' => '/admin/my-tasks', 'SUPERADMIN' => '/superadmin', default => '/admin' };
        }

        return blank($user->phone) ? '/account/complete' : $request->session()->pull('guest_return', '/booking');
    }

    /**
     * The one address Google sends guests back to. It must match an "Authorised redirect URI" in the Google console
     * exactly, so it comes from GOOGLE_REDIRECT_URI rather than from whichever host the page was opened on.
     */
    public static function callbackUrl(): string
    {
        $configured = (string) config('services.google.redirect');

        return str_starts_with($configured, 'http') ? $configured : url($configured ?: '/auth/google/callback');
    }

    public function redirect(Request $request)
    {
        abort_unless(self::enabled(), 404);
        // Start on the callback's own host (localhost vs 127.0.0.1, http vs https), so the sign-in session is still there when Google sends the guest back.
        $callback = parse_url(self::callbackUrl());
        $origin = ($callback['scheme'] ?? 'http').'://'.($callback['host'] ?? '').(isset($callback['port']) ? ':'.$callback['port'] : '');
        if ($origin !== $request->getSchemeAndHttpHost()) {
            return redirect()->away($origin.$request->getRequestUri());
        }
        $request->session()->put('guest_return', self::safeReturn($request->query('return')));

        return Socialite::driver('google')->redirectUrl(self::callbackUrl())->redirect();
    }

    public function callback(Request $request)
    {
        abort_unless(self::enabled(), 404);
        try {
            $google = Socialite::driver('google')->redirectUrl(self::callbackUrl())->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect($request->session()->pull('guest_return', '/booking'))->with('success', 'Google sign-in was cancelled or failed. Please try again.');
        }
        $email = strtolower((string) $google->getEmail());
        $verified = (bool) ($google->user['email_verified'] ?? $google->user['verified_email'] ?? false);
        if ($email === '' || ! $verified) {
            return redirect($request->session()->pull('guest_return', '/booking'))->with('success', 'Your Google account needs a verified email address.');
        }
        $user = User::where('google_id', $google->getId())->first() ?? User::where('email', $email)->first();
        if ($user?->deactivated_at) {
            return redirect('/booking')->with('success', 'This account has been deactivated. Please contact the resort.');
        }
        $user ??= User::create(['name' => $google->getName() ?: Str::before($email, '@'), 'email' => $email, 'password' => Str::random(40), 'role' => 'GUEST']);
        $user->forceFill(['google_id' => $google->getId(), 'avatar' => $google->getAvatar(), 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->put('google_names', [$google->user['given_name'] ?? null, $google->user['family_name'] ?? null]);
        return redirect(self::landing($request, $user));
    }

    /** "First, tell us your name": name and a mobile number the owner can call, asked once after the first Google sign-in. */
    public function edit(Request $request)
    {
        if ($request->query('return')) {
            $request->session()->put('guest_return', self::safeReturn($request->query('return')));
        }
        $user = $request->user();
        [$given, $family] = $request->session()->get('google_names', [null, null]);
        $split = str_contains(trim($user->name), ' ') ? [Str::beforeLast(trim($user->name), ' '), Str::afterLast(trim($user->name), ' ')] : [trim($user->name), ''];

        return Inertia::render('Guest/CompleteProfile', [
            'tenant' => Tenant::where('status', 'ACTIVE')->orderByRaw('slug = ? desc', [config('platform.site_tenant')])->orderBy('id')->first()?->makeVisible('site_content'),
            'first_name' => $given ?: $split[0], 'last_name' => $family ?: $split[1],
            'phone' => $user->phone ? substr($user->phone, 3) : '', 'email' => $user->email, 'avatar' => $user->avatar,
            'return' => $request->session()->get('guest_return', '/booking'), 'first' => blank($user->phone),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['first_name' => 'required|string|max:60', 'last_name' => 'required|string|max:60', 'phone' => 'required|string|max:30'],
            ['phone.required' => 'Add a mobile number the owner can call to confirm your booking.']);
        $phone = PhilippinePhone::normalize($data['phone']);
        if (! $phone) {
            return back()->withErrors(['phone' => 'Enter a Philippine mobile number, e.g. 917 123 4567.']);
        }
        $request->user()->update(['name' => trim($data['first_name']).' '.trim($data['last_name']), 'phone' => $phone]);
        $request->session()->forget('google_names');

        return redirect($request->session()->pull('guest_return', '/booking'))->with('success', 'You’re all set.');
    }

    /** Only pages on this site, so the return address can't send the guest elsewhere. */
    public static function safeReturn(?string $path): string
    {
        return is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\') ? $path : '/booking';
    }
}
