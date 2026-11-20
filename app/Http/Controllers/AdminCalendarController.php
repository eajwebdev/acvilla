<?php

namespace App\Http\Controllers;

use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Room;
use App\Services\AvailabilityService;
use App\Services\OperationsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminCalendarController extends Controller
{
    public function index(Request $request, OperationsService $operations)
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'room_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'view' => ['nullable', 'in:schedule,board,month'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $tenant = $request->user()->tenant;
        $settings = $tenant->settings;
        $month = CarbonImmutable::parse(($filters['month'] ?? (isset($filters['date']) ? substr($filters['date'], 0, 7) : now()->format('Y-m'))).'-01');
        // Six full weeks, Sunday first, like a wall calendar.
        $start = $month->startOfMonth()->startOfWeek(CarbonImmutable::SUNDAY);
        $end = $start->addDays(41);
        $rooms = Room::with('property:id,name')->where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'property_id', 'name', 'quantity', 'active']);
        if (! empty($filters['room_id'])) {
            abort_unless($rooms->contains('id', (int) $filters['room_id']), 404);
        }
        $bookings = $operations->bookingsAround($tenant->id, $start, $end)
            ->when(! empty($filters['room_id']), fn ($list) => $list->where('room_id', (int) $filters['room_id']))
            ->values()->map(fn (Booking $b) => $operations->row($b, $settings, 'arrival'));
        $blocks = BlockedDate::with('room:id,name')->where('tenant_id', $tenant->id)->whereDate('starts_on', '<=', $end->toDateString())
            ->whereDate('ends_on', '>', $start->toDateString())
            ->when(! empty($filters['room_id']), fn ($q) => $q->where('room_id', $filters['room_id']))->get()
            ->map(fn (BlockedDate $block) => ['id' => $block->id, 'room_id' => $block->room_id, 'unit' => $block->room?->name, 'reason' => $block->reason,
                'starts_on' => $block->starts_on->toDateString(), 'ends_on' => $block->ends_on->toDateString()]);
        $active = $rooms->where('active', true);

        // The schedule and the unit board: a window of days (today and the next 6 by default, up to 62 days), always
        // loaded together with today so the "today at a glance" strip is right whatever window is picked.
        $from = CarbonImmutable::parse($filters['from'] ?? today()->toDateString());
        $to = CarbonImmutable::parse($filters['to'] ?? $from->addDays(6)->toDateString())->min($from->addDays(61));
        $windowFrom = $from->min(today());
        $windowTo = $to->max(today());
        $windowBookings = $operations->bookingsAround($tenant->id, $windowFrom, $windowTo)
            ->when(! empty($filters['room_id']), fn ($list) => $list->where('room_id', (int) $filters['room_id']))
            ->values()->map(fn (Booking $b) => $operations->row($b, $settings, 'arrival'));
        $windowBlocks = BlockedDate::with('room:id,name')->where('tenant_id', $tenant->id)->whereDate('starts_on', '<=', $windowTo->toDateString())
            ->whereDate('ends_on', '>', $windowFrom->toDateString())
            ->when(! empty($filters['room_id']), fn ($q) => $q->where('room_id', $filters['room_id']))->get()
            ->map(fn (BlockedDate $block) => ['id' => $block->id, 'room_id' => $block->room_id, 'unit' => $block->room?->name, 'reason' => $block->reason,
                'starts_on' => $block->starts_on->toDateString(), 'ends_on' => $block->ends_on->toDateString()]);

        return Inertia::render('Admin/Calendar', [
            'month' => $month->format('Y-m'), 'start' => $start->toDateString(), 'today' => today()->toDateString(),
            'view' => $filters['view'] ?? 'schedule',
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'bookings' => $windowBookings, 'blocked' => $windowBlocks],
            'filters' => $filters, 'bookings' => $bookings, 'blocked' => $blocks,
            'rooms' => $rooms->map(fn (Room $room) => ['id' => $room->id, 'name' => $room->name, 'property' => $room->property?->name, 'quantity' => $room->quantity, 'active' => $room->active])->values(),
            'inventory' => (int) (! empty($filters['room_id']) ? $active->where('id', (int) $filters['room_id'])->sum('quantity') : $active->sum('quantity')),
        ]);
    }

    public function block(Request $request, AvailabilityService $availability)
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after:starts_on'],
            'reason' => ['required', 'string', 'max:120'],
        ]);
        $tenantId = $request->user()->tenant_id;
        $room = Room::where('tenant_id', $tenantId)->findOrFail($data['room_id']);
        DB::transaction(function () use ($tenantId, $room, $data, $availability) {
            // Same lock and same "holds the unit" rule as a booking, so a block and a booking can't land together.
            Room::where('tenant_id', $tenantId)->orderBy('id')->lockForUpdate()->get();
            $occupied = $availability->holding(Booking::where('room_id', $room->id), $data['starts_on'], $data['ends_on'])->count();
            if ($occupied >= $room->quantity) {
                throw ValidationException::withMessages(['starts_on' => "{$room->name} already has a reservation for those dates. Move or cancel it first."]);
            }
            if (BlockedDate::where('room_id', $room->id)->whereDate('starts_on', '<', $data['ends_on'])->whereDate('ends_on', '>', $data['starts_on'])->exists()) {
                throw ValidationException::withMessages(['starts_on' => "{$room->name} is already blocked for those dates."]);
            }
            BlockedDate::create(['tenant_id' => $tenantId, ...$data]);
        });

        return back()->with('success', 'Dates blocked.');
    }

    public function unblock(Request $request, BlockedDate $blockedDate)
    {
        abort_unless($blockedDate->tenant_id === $request->user()->tenant_id, 404);
        $blockedDate->delete();

        return back()->with('success', 'Date block removed.');
    }
}
