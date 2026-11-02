<?php

namespace App\Services;

use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PosSale;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Stay revenue is spread evenly over a booking's nights, so a period only counts the nights that fall inside it. */
class InsightsService
{
    public function analytics(int $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $rooms = Room::where('tenant_id', $tenantId)->where('active', true)->orderBy('name')->get(['id', 'name', 'quantity', 'property_id']);
        $inventory = (int) $rooms->sum('quantity');
        $stays = $this->stays($tenantId, $from, $to);
        $nights = $this->nightly($stays, $from, $to);
        $revenue = round($nights->sum('value'), 2);
        $occupied = $nights->count();
        [$prevFrom, $prevTo] = [$from->subDays($days), $from->subDay()];
        $prevRevenue = round($this->nightly($this->stays($tenantId, $prevFrom, $prevTo), $prevFrom, $prevTo)->sum('value'), 2);
        $departures = $stays->filter(fn (Booking $b) => $b->check_out->betweenIncluded($from, $to));
        $direct = round($nights->whereIn('source', Booking::DIRECT_SOURCES)->sum('value'), 2);
        $blocks = BlockedDate::where('tenant_id', $tenantId)->whereDate('starts_on', '<=', $to->toDateString())->whereDate('ends_on', '>', $from->toDateString())->get();

        $series = collect(range(0, $days - 1))->map(function (int $i) use ($from, $nights, $stays, $inventory, $blocks) {
            $date = $from->addDays($i)->toDateString();
            $tonight = $nights->where('date', $date);

            return ['date' => $date, 'revenue' => round($tonight->sum('value'), 2),
                'occupancy' => $inventory > 0 ? round(100 * $tonight->count() / $inventory, 1) : 0,
                'arrivals' => $stays->filter(fn (Booking $b) => $b->check_in->toDateString() === $date)->count(),
                'departures' => $stays->filter(fn (Booking $b) => $b->check_out->toDateString() === $date)->count(),
                'blocked' => $blocks->filter(fn (BlockedDate $b) => $b->starts_on->toDateString() <= $date && $b->ends_on->toDateString() > $date)->count()];
        })->all();

        $portfolio = $inventory > 0 ? round(100 * $occupied / ($inventory * $days), 1) : 0;
        $units = $rooms->map(function (Room $room) use ($nights, $stays, $days, $portfolio) {
            $mine = $nights->where('room_id', $room->id);
            $bookings = $stays->where('room_id', $room->id);
            $occupancy = $room->quantity > 0 ? round(100 * $mine->count() / ($room->quantity * $days), 1) : 0;

            return ['id' => $room->id, 'name' => $room->name, 'revenue' => round($mine->sum('value'), 2), 'occupancy' => $occupancy,
                'nights' => $mine->count(), 'bookings' => $bookings->count(),
                'avg_stay' => $bookings->count() ? round($bookings->avg(fn (Booking $b) => $b->check_in->diffInDays($b->check_out)), 1) : null,
                'adr' => $mine->count() ? round($mine->sum('value') / $mine->count(), 2) : 0,
                'signal' => match (true) {
                    $occupancy >= 75 => 'top', $occupancy < 40 => 'low', $occupancy + 15 < $portfolio => 'below', default => 'healthy',
                }];
        })->values();
        $weakest = $units->sortBy([['occupancy', 'asc'], ['revenue', 'asc']])->first();

        return [
            'metrics' => ['revenue' => $revenue, 'previousRevenue' => $prevRevenue,
                'change' => $prevRevenue > 0 ? round(100 * ($revenue - $prevRevenue) / $prevRevenue, 1) : null,
                'occupancy' => $portfolio, 'occupiedNights' => $occupied, 'availableNights' => $inventory * $days,
                'adr' => $occupied ? round($revenue / $occupied, 2) : 0, 'turnovers' => $departures->count(),
                'turnoversPerDay' => round($departures->count() / $days, 1), 'direct' => $direct,
                'directShare' => $revenue > 0 ? round(100 * $direct / $revenue, 1) : 0, 'days' => $days],
            'series' => $series,
            'sources' => $this->sources($tenantId, $from, $to),
            'units' => $units,
            'weakest' => $weakest ? [...$weakest, 'gap' => round($portfolio - $weakest['occupancy'], 1)] : null,
        ];
    }

    public function finance(int $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$start, $end] = [$from->startOfDay(), $to->endOfDay()];
        $payments = Payment::with('booking:id,guest_name,reference')->where('tenant_id', $tenantId)->where('status', 'PAID')->whereBetween('paid_at', [$start, $end])->get();
        $pos = PosSale::where('tenant_id', $tenantId)->where('status', 'PAID')->whereBetween('paid_at', [$start, $end])->get();
        $expenses = Expense::where('tenant_id', $tenantId)->whereDate('spent_on', '>=', $from->toDateString())->whereDate('spent_on', '<=', $to->toDateString())->get();
        $outstanding = Booking::withSum(['payments as paid_amount' => fn ($q) => $q->where('status', 'PAID')], 'amount')->where('tenant_id', $tenantId)
            ->whereIn('status', ['PENDING', 'AWAITING_PAYMENT', 'PAID', 'CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT'])
            ->where(fn ($q) => $q->whereNotIn('status', ['PENDING', 'AWAITING_PAYMENT'])->orWhere('expires_at', '>', now()))->get()
            ->sum(fn (Booking $b) => max(0, (float) $b->total - (float) $b->paid_amount));
        $labels = ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer', 'card' => 'Card', 'mock' => 'Online (test)', 'manual' => 'Manual'];
        $byMethod = collect($payments->map(fn ($p) => ['method' => $p->method, 'amount' => (float) $p->amount]))
            ->merge($pos->map(fn ($s) => ['method' => $s->payment_method, 'amount' => (float) $s->total]))
            ->groupBy('method')->map(fn (Collection $rows, string $method) => ['method' => $method, 'label' => $labels[$method] ?? ucfirst(str_replace('_', ' ', $method)), 'total' => round($rows->sum('amount'), 2)])
            ->sortByDesc('total')->values();
        $stay = round((float) $payments->sum('amount'), 2);
        $posTotal = round((float) $pos->sum('total'), 2);
        $spent = round((float) $expenses->sum('amount'), 2);
        $days = (int) $from->diffInDays($to) + 1;
        $transactions = collect()
            ->merge($payments->map(fn (Payment $p) => ['type' => 'payment', 'date' => $p->paid_at->toIso8601String(), 'label' => ($p->booking?->guest_name ?? 'Booking').' · '.($labels[$p->method] ?? $p->method), 'amount' => (float) $p->amount, 'booking_id' => $p->booking_id]))
            ->merge($pos->map(fn (PosSale $s) => ['type' => 'pos', 'date' => $s->paid_at->toIso8601String(), 'label' => 'POS '.$s->number.($s->customer_name ? ' · '.$s->customer_name : ''), 'amount' => (float) $s->total, 'booking_id' => $s->booking_id]))
            ->merge($expenses->map(fn (Expense $e) => ['type' => 'expense', 'date' => $e->spent_on->toDateString().'T12:00:00', 'label' => $e->category.($e->description ? ' · '.$e->description : ''), 'amount' => -(float) $e->amount, 'booking_id' => null]))
            ->sortByDesc('date')->take(60)->values();

        return [
            'totals' => ['stay' => $stay, 'pos' => $posTotal, 'collected' => round($stay + $posTotal, 2), 'expenses' => $spent,
                'net' => round($stay + $posTotal - $spent, 2), 'outstanding' => round($outstanding, 2),
                'openRoomCharges' => round((float) PosSale::where('tenant_id', $tenantId)->where('status', 'UNPAID')->sum('total'), 2)],
            'byMethod' => $byMethod,
            'series' => collect(range(0, $days - 1))->map(function (int $i) use ($from, $payments, $pos, $expenses) {
                $date = $from->addDays($i)->toDateString();

                return ['date' => $date,
                    'income' => round($payments->filter(fn ($p) => $p->paid_at->toDateString() === $date)->sum('amount') + $pos->filter(fn ($s) => $s->paid_at->toDateString() === $date)->sum('total'), 2),
                    'expenses' => round($expenses->filter(fn ($e) => $e->spent_on->toDateString() === $date)->sum('amount'), 2)];
            })->all(),
            'transactions' => $transactions,
        ];
    }

    /** Bookings made in the period, grouped by the channel they came from. */
    private function sources(int $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $made = Booking::where('tenant_id', $tenantId)->whereNotIn('status', ['CANCELLED', 'EXPIRED'])
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])->get(['source', 'total', 'check_in', 'check_out']);
        $count = max(1, $made->count());

        return $made->groupBy(fn (Booking $b) => $b->source ?: 'direct')->map(fn (Collection $list, string $source) => [
            'source' => $source, 'label' => Booking::SOURCES[$source] ?? ucfirst($source), 'bookings' => $list->count(),
            'nights' => $list->sum(fn (Booking $b) => $b->check_in->diffInDays($b->check_out)),
            'value' => round((float) $list->sum('total'), 2), 'share' => round(100 * $list->count() / $count, 1),
            'direct' => in_array($source, Booking::DIRECT_SOURCES, true),
        ])->sortByDesc('bookings')->values()->all();
    }

    private function stays(int $tenantId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Booking::where('tenant_id', $tenantId)->whereIn('status', Booking::OCCUPYING)
            ->whereDate('check_in', '<=', $to->toDateString())->whereDate('check_out', '>', $from->toDateString())
            ->get(['id', 'room_id', 'check_in', 'check_out', 'total', 'source', 'status']);
    }

    /** One row per occupied night inside the period, carrying that night's share of the booking total. */
    private function nightly(Collection $stays, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $stays->flatMap(function (Booking $b) use ($from, $to) {
            $nights = max(1, (int) $b->check_in->diffInDays($b->check_out));
            $value = (float) $b->total / $nights;
            $rows = [];
            for ($d = $b->check_in->toImmutable()->max($from); $d->lt($b->check_out) && $d->lte($to); $d = $d->addDay()) {
                $rows[] = ['date' => $d->toDateString(), 'room_id' => $b->room_id, 'source' => $b->source ?? 'direct', 'value' => $value];
            }

            return $rows;
        });
    }
}
