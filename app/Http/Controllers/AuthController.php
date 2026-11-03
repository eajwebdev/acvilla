<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function loginPage(Request $request)
    {
        $this->rememberReturn($request);

        return Inertia::render('Auth/Login', ['google' => GuestAuthController::enabled(), 'return' => $request->session()->get('guest_return')]);
    }

    public function registerPage(Request $request)
    {
        $this->rememberReturn($request);

        return Inertia::render('Auth/Register', ['google' => GuestAuthController::enabled(), 'return' => $request->session()->get('guest_return')]);
    }

    /** A guest sent here from the booking page goes back to it after signing in. */
    private function rememberReturn(Request $request): void
    {
        if ($request->query('return')) {
            $request->session()->put('guest_return', GuestAuthController::safeReturn($request->query('return')));
        }
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|string', 'password' => 'required|string']);
        // The one sign-in box takes an email address or a username.
        $login = str_contains($credentials['email'], '@') ? ['email' => $credentials['email']] : ['username' => $credentials['email']];
        if (! Auth::attempt([...$login, 'password' => $credentials['password'], fn ($query) => $query->whereNull('deactivated_at')], $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'The credentials are invalid.']);
        }
        $request->session()->regenerate();
        if ($request->user()->role === 'GUEST') {
            return redirect(GuestAuthController::landing($request, $request->user()));
        }

        return redirect()->intended($this->dashboard($request->user()->role));
    }

    public function register(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|unique:users,email', 'password' => 'required|string|min:8|confirmed']);
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password']), 'role' => 'GUEST']);
        Auth::login($user);
        $request->session()->regenerate();

        // A new guest adds a mobile number next, then goes back to the booking they started.
        return redirect(GuestAuthController::landing($request, $user));
    }

    public function demo(Request $request)
    {
        abort_unless(config('platform.demo_mode'), 404);
        $data = $request->validate(['role' => 'required|in:admin,staff,guest']);
        $role = strtoupper($data['role']);
        $site = Tenant::where('slug', config('platform.site_tenant'))->where('status', 'ACTIVE')->first();
        $user = ($site && $role !== 'GUEST' ? User::where('tenant_id', $site->id)->where('role', $role)->whereNull('deactivated_at')->orderBy('id')->first() : null)
            ?? User::where('email', $data['role'].'@example.com')->where('role', $role)->firstOrFail();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect($this->dashboard($user->role));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function dashboard(string $role): string
    {
        return match ($role) {
            'SUPERADMIN' => route('superadmin.dashboard'), 'ADMIN' => route('admin.dashboard'), 'STAFF' => route('admin.tasks.mine'), default => route('account.bookings')
        };
    }
}
