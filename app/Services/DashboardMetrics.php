<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

class DashboardMetrics
{
    public function admin(int $tenantId, DashboardPeriod $period): array
    {
        [$previousFrom, $previousTo] = $period->previous();
        $current = $this->adminTotals($tenantId, $period->from, $period->to);
        $previous = $this->adminTotals($tenantId, $previousFrom, $previousTo);
        $bookings = Booking::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$period->from->startOfDay(), $period->to->endOfDay()])->get();
        $payments = Payment::where('tenant_id', $tenantId)->where('status', 'PAID')
            ->whereBetween('paid_at', [$period->from->startOfDay(), $period->to->endOfDay()])->get();
        $stays = Booking::where('tenant_id', $tenantId)
            ->whereIn('status', ['PAID', 'CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT', 'COMPLETED'])
            ->whereDate('check_in', '<=', $period->to->toDateString())
            ->whereDate('check_out', '>', $period->from->toDateString())->get();
        $inventory = (int) Room::where('tenant_id', $tenantId)->where('active', true)->sum('quantity');
        $series = $this->series($period, [
            'revenue' => $payments->map(fn (Payment $payment) => ['date' => $payment->paid_at->toDateString(), 'value' => (float) $payment->amount])->all(),
            'bookings' => $bookings->map(fn (Booking $booking) => ['date' => $booking->created_at->toDateString(), 'value' => 1])->all(),
        ]);
        foreach ($series as &$point) {
            $bucketFrom = CarbonImmutable::parse($point['date']);
            $bucketTo = $bucketFrom->addDays($point['days']);
            $occupied = 0;
            foreach ($stays as $stay) {
                $overlapStart = $stay->check_in->toImmutable()->max($bucketFrom);
                $overlapEnd = $stay->check_out->toImmutable()->min($bucketTo);
                $occupied += max(0, (int) $overlapStart->diffInDays($overlapEnd, false));
            }
            $point['occupancy'] = $inventory > 0 ? round(100 * $occupied / ($inventory * $point['days']), 1) : 0;
        }

        return ['period' => $period->toArray(), 'metrics' => $current, 'previous' => $previous,
            'series' => $series, 'properties' => Room::where('tenant_id', $tenantId)->distinct('property_id')->count('property_id'),
            'currentState' => ['checkedInGuests' => (int) Booking::where('tenant_id', $tenantId)->where('status', 'CHECKED_IN')->whereDate('check_in', '<=', today())->whereDate('check_out', '>', today())->sum('guests'),
                'occupiedRooms' => Booking::where('tenant_id', $tenantId)->where('status', 'CHECKED_IN')->whereDate('check_in', '<=', today())->whereDate('check_out', '>', today())->count(),
                'upcomingCheckins' => Booking::where('tenant_id', $tenantId)->whereIn('status', ['PAID', 'CONFIRMED'])->whereDate('check_in', '>=', today())->whereDate('check_in', '<=', today()->addDays(7))->count()],
            'recent' => Booking::with('property', 'room')->where('tenant_id', $tenantId)->latest()->limit(6)->get()];
    }

    private function adminTotals(int $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $bookings = Booking::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])->get();
        $qualifying = $bookings->whereNotIn('status', ['CANCELLED', 'EXPIRED']);
        $paid = Payment::where('tenant_id', $tenantId)->where('status', 'PAID')
            ->whereBetween('paid_at', [$from->startOfDay(), $to->endOfDay()])->sum('amount');
        $bookedIds = $qualifying->pluck('id');
        $paidForBookings = $bookedIds->isEmpty() ? 0 : Payment::whereIn('booking_id', $bookedIds)->where('status', 'PAID')->sum('amount');
        $inventory = (int) Room::where('tenant_id', $tenantId)->where('active', true)->sum('quantity');
        $stays = Booking::where('tenant_id', $tenantId)->whereIn('status', ['PAID', 'CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT', 'COMPLETED'])
            ->whereDate('check_in', '<=', $to->toDateString())->whereDate('check_out', '>', $from->toDateString())->get();
        $occupied = $stays->sum(function (Booking $booking) use ($from, $to) {
            $start = $booking->check_in->toImmutable()->max($from);
            $end = $booking->check_out->toImmutable()->min($to->addDay());

            return max(0, (int) $start->diffInDays($end, false));
        });
        $nights = (int) $from->diffInDays($to) + 1;

        return ['bookings' => $bookings->count(), 'gross' => round((float) $qualifying->sum('total'), 2),
            'revenue' => round((float) $paid, 2), 'outstanding' => round(max(0, (float) $qualifying->sum('total') - (float) $paidForBookings), 2),
            'cancellations' => $bookings->where('status', 'CANCELLED')->count(),
            'guests' => (int) $qualifying->sum('guests'),
            'confirmed' => $bookings->where('status', 'CONFIRMED')->count(),
            'completed' => $bookings->where('status', 'COMPLETED')->count(),
            'occupancy' => $inventory > 0 ? round(min(100, 100 * $occupied / ($inventory * $nights)), 1) : 0,
            'average' => $qualifying->count() ? round((float) $qualifying->avg('total'), 2) : 0];
    }

    public function superadmin(DashboardPeriod $period): array
    {
        [$previousFrom, $previousTo] = $period->previous();
        $tenants = Tenant::withCount('properties')->latest()->get();
        $contracts = Subscription::whereBetween('created_at', [$period->from->startOfDay(), $period->to->endOfDay()])->get();
        $bookings = Booking::whereBetween('created_at', [$period->from->startOfDay(), $period->to->endOfDay()])->get();
        $latest = Subscription::where('status', 'ACTIVE')->whereDate('starts_at', '<=', today())
            ->whereDate('ends_at', '>=', today())->orderByDesc('id')->get()->unique('tenant_id');
        $activeTenantIds = $tenants->where('status', 'ACTIVE')->pluck('id');
        $mrr = $latest->whereIn('tenant_id', $activeTenantIds)->sum(fn (Subscription $subscription) => (float) $subscription->effective_price);

        return [
            'period' => $period->toArray(),
            'metrics' => ['active' => $tenants->where('status', 'ACTIVE')->count(),
                'trial' => $tenants->where('subscription_status', 'TRIAL')->count(),
                'locked' => $tenants->where('status', 'LOCKED')->count(),
                'suspended' => $tenants->where('status', 'SUSPENDED')->count(),
                'newClients' => $tenants->whereBetween('created_at', [$period->from->startOfDay(), $period->to->endOfDay()])->count(),
                'subscriptions' => round((float) $contracts->sum('billing_total'), 2),
                'bookings' => $bookings->count(),
                'bookingValue' => round((float) $bookings->whereNotIn('status', ['CANCELLED', 'EXPIRED'])->sum('total'), 2),
                'mrr' => round($mrr, 2), 'arr' => round($mrr * 12, 2)],
            'previous' => ['newClients' => Tenant::whereBetween('created_at', [$previousFrom->startOfDay(), $previousTo->endOfDay()])->count(),
                'subscriptions' => round((float) Subscription::whereBetween('created_at', [$previousFrom->startOfDay(), $previousTo->endOfDay()])->sum('billing_total'), 2),
                'bookings' => Booking::whereBetween('created_at', [$previousFrom->startOfDay(), $previousTo->endOfDay()])->count()],
            'series' => $this->series($period, [
                'subscriptions' => $contracts->map(fn (Subscription $contract) => ['date' => $contract->created_at->toDateString(), 'value' => (float) $contract->billing_total])->all(),
                'clients' => $tenants->map(fn (Tenant $tenant) => ['date' => $tenant->created_at->toDateString(), 'value' => 1])->all(),
                'bookings' => $bookings->map(fn (Booking $booking) => ['date' => $booking->created_at->toDateString(), 'value' => 1])->all(),
            ]),
            'tenants' => $tenants->take(6),
        ];
    }

    private function series(DashboardPeriod $period, array $sources): array
    {
        $days = (int) $period->from->diffInDays($period->to) + 1;
        $step = $days <= 45 ? 1 : ($days <= 150 ? 7 : 30);
        $points = [];
        for ($date = $period->from; $date->lte($period->to); $date = $date->addDays($step)) {
            $bucketEnd = $date->addDays($step)->min($period->to->addDay());
            $point = ['date' => $date->toDateString(), 'days' => (int) $date->diffInDays($bucketEnd)];
            foreach ($sources as $name => $items) {
                $point[$name] = round(collect($items)->filter(fn (array $item) => $item['date'] >= $date->toDateString() && $item['date'] < $bucketEnd->toDateString())->sum('value'), 2);
            }
            $points[] = $point;
        }

        return $points;
    }
}
