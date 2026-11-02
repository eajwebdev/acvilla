<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminTeamController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render('Admin/Team', [
            'members' => User::where('tenant_id', $request->user()->tenant_id)->whereIn('role', ['ADMIN', 'STAFF'])
                ->orderByRaw('deactivated_at is not null')->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'deactivated_at', 'created_at']),
            'me' => $request->user()->id,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:255|unique:users,email',
            'role' => 'required|in:ADMIN,STAFF',
            'password' => 'required|string|min:8',
        ], ['email.unique' => 'Someone already uses this email address on the platform.']);
        $member = User::create($data + ['tenant_id' => $request->user()->tenant_id]);
        $this->audit($request, 'team.member_added', $member);

        return back()->with('success', "{$member->name} can now sign in with the password you set.");
    }

    public function update(Request $request, User $member)
    {
        $this->own($request, $member);
        $data = $request->validate(['role' => 'required|in:ADMIN,STAFF']);
        $member->update($data);
        $this->audit($request, 'team.role_changed', $member);

        return back()->with('success', "{$member->name} is now ".strtolower($member->role).'.');
    }

    public function status(Request $request, User $member)
    {
        $this->own($request, $member);
        $active = $request->validate(['active' => 'required|boolean'])['active'];
        $member->update(['deactivated_at' => $active ? null : now()]);
        $this->audit($request, $active ? 'team.member_reactivated' : 'team.member_deactivated', $member);

        return back()->with('success', $active ? "{$member->name} can sign in again." : "{$member->name} can no longer sign in.");
    }

    /** Members of another tenant 404; your own account is managed from My profile, so admins can't lock themselves out. */
    private function own(Request $request, User $member): void
    {
        abort_unless($member->tenant_id === $request->user()->tenant_id && in_array($member->role, ['ADMIN', 'STAFF'], true), 404);
        if ($member->is($request->user())) {
            throw ValidationException::withMessages(['member' => 'You can’t change your own role or access here.']);
        }
    }

    private function audit(Request $request, string $action, User $member): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $request->user()->tenant_id,
            'action' => $action, 'entity_type' => 'User', 'entity_id' => $member->id,
            'metadata' => $member->only('name', 'email', 'role'), 'ip_address' => $request->ip()]);
    }
}
