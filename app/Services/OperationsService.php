<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\PosSale;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Front-desk view of a single day: who arrives, who leaves, who stays, and what is owed. */
class OperationsService
{
    /** Statuses that still expect the guest to arrive or that are already in the unit. */
    public const ACTIVE = ['PENDING', 'AWAITING_PAYMENT', 'PAID', 'CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT', 'COMPLETED'];

    public function inventory(int $tenantId): int
    {
        return (int) Room::where('tenant_id', $tenantId)->where('active', true)->sum('quantity');
    }

    /** Bookings touching the day that are not cancelled, expired or lapsed holds. */
    public function bookingsAround(int $tenantId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Booking::with('room:id,name,property_id', 'property:id,name')
            ->withSum(['payments as paid_amount' => fn ($q) => $q->where('status', 'PAID')], 'amount')
            ->withSum(['posSales as open_charges' => fn ($q) => $q->where('status', 'UNPAID')], 'total')
            ->where('tenant_id', $tenantId)->whereIn('status', self::ACTIVE)
            ->whereDate('check_in', '<=', $to->toDateString())->whereDate('check_out', '>=', $from->toDateString())
            ->where(fn ($q) => $q->whereNotIn('status', ['PENDING', 'AWAITING_PAYMENT'])->orWhereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('check_in')->get();
    }

    public function day(int $tenantId, CarbonImmutable $date, array $settings): array
    {
        return $this->period($tenantId, $date, $date, $settings);
    }

    /**
     * The front desk over a date range (one day when from = to): arrivals and departures inside it, average
     * occupancy per night, money received, and what is still owed. "revenueToday" is the range total and
     * "revenueYesterday" the same-length period just before it, so a single day keeps its old meaning.
     */
    public function period(int $tenantId, CarbonImmutable $from, CarbonImmutable $to, array $settings): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $first = $from->toDateString();
        $last = $to->toDateString();
        $days = (int) $from->diffInDays($to) + 1;
        $bookings = $this->bookingsAround($tenantId, $from, $to);
        $within = fn ($date) => $date->toDateString() >= $first && $date->toDateString() <= $last;
        $arrivals = $bookings->filter(fn (Booking $b) => $within($b->check_in) && $b->status !== 'NO_SHOW');
        $departures = $bookings->filter(fn (Booking $b) => $within($b->check_out));
        $staying = $bookings->filter(fn (Booking $b) => $b->check_in->toDateString() <= $last && $b->check_out->toDateString() > $first);
        $inventory = $this->inventory($tenantId);
        // Units occupied each night of the range, capped at what exists.
        $nightly = collect(range(0, $days - 1))->map(function (int $i) use ($from, $staying, $inventory) {
            $night = $from->addDays($i)->toDateString();

            return min($inventory, $staying->filter(fn (Booking $b) => $b->check_in->toDateString() <= $night && $b->check_out->toDateString() > $night)->count());
        });
        $occupiedNights = $nightly->sum();

        // Money received per day, from the start of the comparison period to the end of the range.
        $since = $from->subDays(max($days, 7));
        $received = collect([
            ...Payment::where('tenant_id', $tenantId)->where('status', 'PAID')->whereBetween('paid_at', [$since, $to->endOfDay()])->get(['paid_at', 'amount'])
                ->map(fn (Payment $p) => [$p->paid_at->toDateString(), (float) $p->amount]),
            ...PosSale::where('tenant_id', $tenantId)->where('status', 'PAID')->whereBetween('paid_at', [$since, $to->endOfDay()])->get(['paid_at', 'total'])
                ->map(fn (PosSale $s) => [$s->paid_at->toDateString(), (float) $s->total]),
        ]);
        $revenue = fn (CarbonImmutable $start, CarbonImmutable $end) => round($received
            ->filter(fn ($r) => $r[0] >= $start->toDateString() && $r[0] <= $end->toDateString())->sum(fn ($r) => $r[1]), 2);
        // The sparkline: each day of the range, or the week up to a single day.
        $sparkFrom = $days > 1 ? $from : $from->subDays(6);
        $balance = fn (Collection $list) => round($list->sum(fn (Booking $b) => $this->balance($b)), 2);
        $tonight = $nightly->first() ?? 0;

        return [
            'date' => $first,
            'to' => $last,
            'days' => $days,
            'inventory' => $inventory,
            'occupied' => $days === 1 ? $tonight : round($occupiedNights / $days, 1),
            'occupiedNights' => $occupiedNights,
            'occupancy' => $inventory > 0 ? (int) round(100 * $occupiedNights / ($inventory * $days)) : 0,
            'arrivals' => $arrivals->values()->map(fn (Booking $b) => $this->row($b, $settings, 'arrival'))->all(),
            'departures' => $departures->values()->map(fn (Booking $b) => $this->row($b, $settings, 'departure'))->all(),
            'inHouse' => $tonight,
            'revenueToday' => $revenue($from, $to),
            'revenueYesterday' => $revenue($from->subDays($days), $from->subDay()),
            'revenueWeek' => collect(range(0, (int) $sparkFrom->diffInDays($to)))->map(fn (int $i) => $revenue($sparkFrom->addDays($i), $sparkFrom->addDays($i)))->all(),
            'balanceDue' => $balance($arrivals->merge($staying)->unique('id')),
            'balanceAtCheckIn' => $balance($arrivals),
        ];
    }

    /** Stay balance plus any POS purchases charged to the room. */
    public function balance(Booking $booking): float
    {
        return max(0, round((float) $booking->total - (float) ($booking->paid_amount ?? 0), 2)) + round((float) ($booking->open_charges ?? 0), 2);
    }

    public function row(Booking $b, array $settings, string $kind): array
    {
        [$in, $out] = $b->times($settings);

        return ['id' => $b->id, 'reference' => $b->reference, 'guest_name' => $b->guest_name, 'guest_phone' => $b->guest_phone,
            'unit' => $b->room?->name, 'property' => $b->property?->name, 'room_id' => $b->room_id, 'guests' => $b->guests,
            'check_in' => $b->check_in->toDateString(), 'check_out' => $b->check_out->toDateString(),
            'time' => $kind === 'arrival' ? $in : $out, 'check_in_time' => $in, 'check_out_time' => $out,
            'nights' => (int) $b->check_in->diffInDays($b->check_out), 'status' => $b->status, 'payment_status' => $b->payment_status,
            'source' => $b->source ?? 'direct', 'total' => (float) $b->total, 'paid' => round((float) ($b->paid_amount ?? 0), 2),
            'open_charges' => round((float) ($b->open_charges ?? 0), 2), 'balance' => $this->balance($b)];
    }
}
