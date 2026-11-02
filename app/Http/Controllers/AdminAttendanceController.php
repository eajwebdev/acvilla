<?php

namespace App\Http\Controllers;

use App\Models\AttendanceEntry;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminAttendanceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $date = $user->role === 'ADMIN'
            ? $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? today()->toDateString()
            : today()->toDateString();
        $entries = AttendanceEntry::where('tenant_id', $user->tenant_id)->whereDate('work_date', $date)
            ->get()->keyBy('user_id');

        $own = $entries->get($user->id);
        if ($user->role === 'STAFF' && ! $own) {
            $own = AttendanceEntry::where('tenant_id', $user->tenant_id)->where('user_id', $user->id)
                ->whereNull('clocked_out_at')->latest('work_date')->first();
        }

        return Inertia::render('Admin/Attendance', [
            'date' => $date,
            'today' => today()->toDateString(),
            'entry' => $this->entry($own),
            'staff' => $user->role === 'ADMIN'
                ? User::where('tenant_id', $user->tenant_id)->where('role', 'STAFF')
                    ->where(fn ($query) => $query->whereNull('deactivated_at')->orWhereIn('id', $entries->keys()))
                    ->orderBy('name')->get(['id', 'name'])->map(fn (User $member) => [
                        'id' => $member->id,
                        'name' => $member->name,
                        'entry' => $this->entry($entries->get($member->id)),
                    ])
                : null,
        ]);
    }

    public function clockIn(Request $request)
    {
        $user = $request->user();
        DB::transaction(function () use ($request, $user) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (AttendanceEntry::where('user_id', $user->id)->whereDate('work_date', today()->toDateString())->exists()) {
                throw ValidationException::withMessages(['attendance' => 'You already clocked in today.']);
            }
            if (AttendanceEntry::where('user_id', $user->id)->whereNull('clocked_out_at')->exists()) {
                throw ValidationException::withMessages(['attendance' => 'Clock out of your previous shift before starting another.']);
            }
            $entry = AttendanceEntry::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'work_date' => today()->toDateString(),
                'clocked_in_at' => now(),
                'method' => 'manual',
            ]);
            $this->audit($request, $entry, 'attendance.clock_in');
        });

        return back()->with('success', 'Clocked in for today.');
    }

    public function clockOut(Request $request)
    {
        $user = $request->user();
        DB::transaction(function () use ($request, $user) {
            $entry = AttendanceEntry::where('tenant_id', $user->tenant_id)->where('user_id', $user->id)
                ->whereNull('clocked_out_at')->latest('work_date')->lockForUpdate()->first();
            if (! $entry || $entry->clocked_out_at) {
                throw ValidationException::withMessages(['attendance' => 'Clock in before clocking out.']);
            }
            if (now()->gt($entry->clocked_in_at->copy()->addDay())) {
                throw ValidationException::withMessages(['attendance' => 'This shift is over 24 hours. Ask your manager to correct the missed clock-out.']);
            }
            $entry->update(['clocked_out_at' => now()]);
            $this->audit($request, $entry, 'attendance.clock_out');
        });

        return back()->with('success', 'Clocked out. Your time is saved.');
    }

    public function correct(Request $request, AttendanceEntry $entry)
    {
        abort_unless($entry->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate([
            'clocked_in_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'clocked_out_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ]);
        $in = CarbonImmutable::parse($data['clocked_in_at'], config('app.timezone'));
        $out = filled($data['clocked_out_at'] ?? null) ? CarbonImmutable::parse($data['clocked_out_at'], config('app.timezone')) : null;
        if ($in->toDateString() !== $entry->work_date->toDateString() || $in->isFuture() || ($out && ($out->lte($in) || $out->isFuture() || $out->gt($in->addDay())))) {
            throw ValidationException::withMessages(['clocked_out_at' => 'Use valid shift times. A shift can last up to 24 hours.']);
        }
        DB::transaction(function () use ($request, $entry, $data, $in, $out) {
            $entry = AttendanceEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if (! $out && AttendanceEntry::where('user_id', $entry->user_id)->where('id', '!=', $entry->id)->whereNull('clocked_out_at')->exists()) {
                throw ValidationException::withMessages(['clocked_out_at' => 'This staff member already has another open shift.']);
            }
            $before = $this->entry($entry);
            $entry->update(['clocked_in_at' => $in, 'clocked_out_at' => $out]);
            $this->audit($request, $entry, 'attendance.corrected', [
                'reason' => trim($data['reason']),
                'before' => $before,
                'after' => $this->entry($entry),
            ]);
        });

        return back()->with('success', 'Attendance corrected.');
    }

    private function entry(?AttendanceEntry $entry): ?array
    {
        return $entry ? [
            'id' => $entry->id,
            'work_date' => $entry->work_date->toDateString(),
            'clocked_in_at' => $entry->clocked_in_at->toIso8601String(),
            'clocked_out_at' => $entry->clocked_out_at?->toIso8601String(),
            'method' => $entry->method,
        ] : null;
    }

    private function audit(Request $request, AttendanceEntry $entry, string $action, array $details = []): void
    {
        AuditLog::create([
            'actor_user_id' => $request->user()->id,
            'tenant_id' => $entry->tenant_id,
            'action' => $action,
            'entity_type' => 'AttendanceEntry',
            'entity_id' => $entry->id,
            'metadata' => ['user_id' => $entry->user_id, 'work_date' => $entry->work_date->toDateString(), ...$details],
            'ip_address' => $request->ip(),
        ]);
    }
}
