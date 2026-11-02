<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** The signed-in admin or staff member's own profile. */
class AccountController extends Controller
{
    public function show(Request $request)
    {
        return Inertia::render('Admin/Profile', [
            'profile' => $request->user()->only('name', 'email', 'role'),
            'canDelete' => $this->canDelete($request->user()),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);
        $user->update($data);
        $this->audit($request, 'profile.updated', ['fields' => array_keys($user->getChanges())]);

        return back()->with('success', 'Profile saved.');
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|current_password',
            'password' => 'required|string|min:8|confirmed|different:current_password',
        ], ['current_password.current_password' => 'Your current password is incorrect.']);
        $request->user()->update(['password' => $data['password']]);
        Auth::logoutOtherDevices($data['password']);
        $this->audit($request, 'profile.password_changed');

        return back()->with('success', 'Password changed. Other devices were signed out.');
    }

    public function destroy(Request $request)
    {
        $request->validate(['password' => 'required|current_password'], ['password.current_password' => 'That password is incorrect.']);
        $user = $request->user();
        if (! $this->canDelete($user)) {
            throw ValidationException::withMessages(['password' => 'You are the only active admin. Make someone else an admin first.']);
        }
        $this->audit($request, 'profile.deleted', ['email' => $user->email]);
        Auth::logout();
        $user->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function canDelete(User $user): bool
    {
        return $user->role !== 'ADMIN' || User::where('tenant_id', $user->tenant_id)->where('role', 'ADMIN')
            ->whereNull('deactivated_at')->whereKeyNot($user->id)->exists();
    }

    private function audit(Request $request, string $action, array $metadata = []): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $request->user()->tenant_id,
            'action' => $action, 'entity_type' => 'User', 'entity_id' => $request->user()->id,
            'metadata' => $metadata, 'ip_address' => $request->ip()]);
    }
}
