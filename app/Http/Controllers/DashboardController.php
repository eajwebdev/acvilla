<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\PosItem;
use App\Models\Property;
use App\Models\Room;
use App\Rules\ImageSource;
use App\Services\CleaningService;
use App\Services\DashboardMetrics;
use App\Services\DashboardPeriod;
use App\Services\OperationsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function admin(Request $request, OperationsService $operations, CleaningService $cleaning)
    {
        if ($request->user()->role === 'STAFF') {
            return redirect()->route('admin.tasks.mine');
        }
        $data = $request->validate(['months' => ['nullable', 'in:3,6,12'], ...self::RANGE_RULES]);
        $tenant = $request->user()->tenant;
        $settings = $tenant->settings;
        [$date, $to] = $this->range($data);
        $day = $operations->period($tenant->id, $date, $to, $settings);
        // Turnovers are listed day by day, so only for ranges up to a month.
        $span = (int) $date->diffInDays($to);
        $tasks = $span < 31 ? collect(range(0, $span))->flatMap(fn (int $i) => $cleaning->forDay($tenant->id, $date->addDays($i), $settings))->values() : collect();
        // Overdue check-outs and no-shows are judged against today when it falls in the range.
        $anchor = today()->between($date, $to) ? CarbonImmutable::today() : $date;

        return Inertia::render('Admin/Dashboard', [
            'tenant' => $tenant->only('business_name', 'subscription_status'),
            'today' => today()->toDateString(),
            'day' => $day,
            'properties' => Property::where('tenant_id', $tenant->id)->count(),
            'units' => Room::where('tenant_id', $tenant->id)->where('active', true)->count(),
            'trend' => $this->revenueTrend($tenant->id, (int) ($data['months'] ?? 12)),
            'months' => (int) ($data['months'] ?? 12),
            'target' => $settings['monthly_revenue_target'],
            'attention' => $this->attention($tenant->id, $anchor, $settings, $tasks->all(), $day),
            'cleaning' => $tasks,
            'cleaningLimited' => $span >= 31,
        ]);
    }

    /** ?date= picks one day; ?from=&to= a range (up to a year). */
    private const RANGE_RULES = ['date' => ['nullable', 'date_format:Y-m-d'], 'from' => ['nullable', 'date_format:Y-m-d'],
        'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']];

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function range(array $data): array
    {
        $from = CarbonImmutable::parse($data['from'] ?? $data['date'] ?? today()->toDateString())->startOfDay();
        $to = isset($data['from'], $data['to']) ? CarbonImmutable::parse($data['to'])->startOfDay() : $from;

        return [$from, $to->min($from->addDays(365))];
    }

    /** Arrivals and departures for the day or range as a spreadsheet for the front desk. */
    public function exportDay(Request $request, OperationsService $operations)
    {
        $data = $request->validate(self::RANGE_RULES);
        $tenant = $request->user()->tenant;
        [$date, $to] = $this->range($data);
        $day = $operations->period($tenant->id, $date, $to, $tenant->settings);
        $name = $date->equalTo($to) ? $date->toDateString() : $date->toDateString().'_to_'.$to->toDateString();

        return response()->streamDownload(function () use ($day) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Type', 'Date', 'Time', 'Guest', 'Contact', 'Unit', 'Guests', 'Nights', 'Source', 'Status', 'Total', 'Paid', 'Balance', 'Reference']);
            foreach (['arrivals' => 'Arrival', 'departures' => 'Departure'] as $key => $label) {
                foreach ($day[$key] as $row) {
                    fputcsv($out, array_map(fn ($v) => preg_match('/^[\s]*[=+\-@]/u', (string) $v) ? "'".$v : $v, [$label, $key === 'arrivals' ? $row['check_in'] : $row['check_out'],
                        $row['time'], $row['guest_name'], $row['guest_phone'], $row['unit'], $row['guests'], $row['nights'], $row['source'], $row['status'],
                        $row['total'], $row['paid'], $row['balance'], $row['reference']]));
                }
            }
            fclose($out);
        }, 'front-desk_'.$name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Booked stay value per check-in month, oldest first, ending with the current month. */
    private function revenueTrend(int $tenantId, int $months): array
    {
        $start = CarbonImmutable::today()->startOfMonth()->subMonths($months - 1);
        $bookings = Booking::where('tenant_id', $tenantId)->whereIn('status', Booking::OCCUPYING)
            ->whereDate('check_in', '>=', $start->toDateString())->whereDate('check_in', '<=', today()->endOfMonth()->toDateString())
            ->get(['check_in', 'total']);

        return collect(range(0, $months - 1))->map(function (int $i) use ($start, $bookings) {
            $month = $start->addMonths($i);

            return ['month' => $month->format('Y-m'), 'label' => $month->format('M'),
                'booked' => round((float) $bookings->filter(fn (Booking $b) => $b->check_in->format('Y-m') === $month->format('Y-m'))->sum('total'), 2)];
        })->all();
    }

    /** Things the front desk should act on for the day, most urgent first. */
    private function attention(int $tenantId, CarbonImmutable $date, array $settings, array $tasks, array $day): array
    {
        $items = [];
        $iso = $date->toDateString();
        $isToday = $date->isToday();
        $now = now()->format('H:i');
        // Website reservation requests wait for the owner to call the guest, so they lead the list.
        // One line per request, even when it covers several facilities.
        $requests = Booking::with('room:id,name')->where('tenant_id', $tenantId)->where('source', 'website')->where('status', 'PENDING')
            ->where('expires_at', '>', now())->whereDoesntHave('payments')->oldest()->limit(40)->get()->groupBy(fn (Booking $b) => $b->group_code ?? 'b'.$b->id)->take(20);
        foreach ($requests as $group) {
            $b = $group->first();
            $items[] = ['tone' => 'warn', 'title' => 'Call guest, then approve the dates', 'booking_id' => $b->id,
                'detail' => "{$b->guest_name} · {$b->guest_phone} · ".$group->map(fn (Booking $g) => $g->room?->name)->implode(' + ').' · '
                    .$b->check_in->format('M j').' · hold ends '.$b->expires_at->timezone(config('platform.timezone'))->format('M j g:i A')];
        }
        // One line per downpayment receipt, however many facilities it pays for.
        $receipts = Payment::with('booking:id,guest_name')->where('tenant_id', $tenantId)->where('status', 'PENDING')->latest()->limit(30)->get()
            ->groupBy(fn (Payment $p) => $p->batch ?? 'p'.$p->id)->take(10);
        foreach ($receipts as $batch) {
            $payment = $batch->first();
            $items[] = ['tone' => 'warn', 'title' => $payment->proof_path ? 'Check downpayment receipt' : 'Verify '.str_replace('_', ' ', $payment->method).' payment', 'booking_id' => $payment->booking_id,
                'detail' => ($payment->booking?->guest_name ?? 'Guest').' sent '.number_format((float) $batch->sum('amount'), 2).($batch->count() > 1 ? " for {$batch->count()} facilities" : '')];
        }
        $inHouse = Booking::with('room:id,name')->where('tenant_id', $tenantId)->where('status', 'CHECKED_IN')->whereDate('check_out', '<=', $iso)->get();
        foreach ($inHouse as $b) {
            $dueOut = $b->check_out->toDateString() < $iso || ($isToday && $now > $b->times($settings)[1]);
            if ($dueOut) {
                $items[] = ['tone' => 'danger', 'title' => 'Check-out overdue', 'booking_id' => $b->id,
                    'detail' => "{$b->guest_name} · {$b->room?->name} was due out ".$b->check_out->format('M j').' '.$b->times($settings)[1]];
            }
        }
        $noShows = Booking::with('room:id,name')->where('tenant_id', $tenantId)->whereIn('status', ['CONFIRMED', 'PAID'])
            ->whereDate('check_in', '<', $iso)->whereDate('check_out', '>', $iso)->get();
        foreach ($noShows as $b) {
            $items[] = ['tone' => 'danger', 'title' => 'Guest has not checked in', 'booking_id' => $b->id,
                'detail' => "{$b->guest_name} · {$b->room?->name} was due ".$b->check_in->format('M j')];
        }
        foreach ($day['arrivals'] as $row) {
            if ($row['balance'] > 0 && ! in_array($row['status'], ['CHECKED_IN', 'CHECKED_OUT', 'COMPLETED'], true)) {
                $items[] = ['tone' => 'info', 'title' => 'Collect balance at check-in', 'booking_id' => $row['id'],
                    'detail' => "{$row['guest_name']} · {$row['unit']} owes ".number_format($row['balance'], 2)];
            }
        }
        foreach ($day['departures'] as $row) {
            if ($row['open_charges'] > 0) {
                $items[] = ['tone' => 'warn', 'title' => 'Open room charges', 'booking_id' => $row['id'],
                    'detail' => "{$row['guest_name']} · {$row['unit']} has ".number_format($row['open_charges'], 2).' in POS charges'];
            }
        }
        if ($isToday) {
            foreach (Booking::where('tenant_id', $tenantId)->whereIn('status', ['PENDING', 'AWAITING_PAYMENT'])->whereBetween('expires_at', [now(), now()->addHours(3)])->get() as $b) {
                $items[] = ['tone' => 'info', 'title' => 'Unpaid hold expiring', 'booking_id' => $b->id,
                    'detail' => "{$b->guest_name} · releases at ".$b->expires_at->format('g:i A')];
            }
        }
        foreach ($tasks as $task) {
            if ($task['risk']) {
                $items[] = ['tone' => 'danger', 'title' => 'Cleaning at risk', 'href' => '/admin/cleaning?date='.$task['date'],
                    'detail' => "{$task['unit']} must be ready by {$task['ready_by']}"];
            } elseif (! $task['assigned_to'] && $task['status'] !== 'DONE') {
                $items[] = ['tone' => 'info', 'title' => 'Assign a cleaner', 'href' => '/admin/cleaning?date='.$task['date'],
                    'detail' => "{$task['unit']} turnover has no cleaner yet"];
            }
        }
        foreach (PosItem::where('tenant_id', $tenantId)->where('active', true)->where('track_stock', true)->where('stock', '<=', 3)->orderBy('stock')->limit(5)->get() as $item) {
            $items[] = ['tone' => 'info', 'title' => 'Low stock', 'href' => '/admin/settings/pos-items', 'detail' => "{$item->name}: {$item->stock} left"];
        }
        $rank = ['danger' => 0, 'warn' => 1, 'info' => 2];
        usort($items, fn ($a, $b) => $rank[$a['tone']] <=> $rank[$b['tone']]);

        return array_map(fn ($item) => ['key' => md5($iso.$item['title'].$item['detail'].($item['booking_id'] ?? $item['href'] ?? '')), ...$item], $items);
    }

    public function superadmin(Request $request, DashboardMetrics $metrics)
    {
        return Inertia::render('Superadmin/Dashboard', [
            ...$metrics->superadmin(new DashboardPeriod($request)),
        ]);
    }

    /** A guest's bookings live on My bookings, which lists every booking on their account. */
    public function account(Request $request)
    {
        return redirect('/booking');
    }

    public function branding(Request $request)
    {
        return Inertia::render('Admin/Branding', ['tenant' => $request->user()->tenant]);
    }

    public function updateBranding(Request $request)
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'business_email' => ['nullable', 'email', 'max:255'],
            'business_phone' => ['nullable', 'string', 'max:30'],
            'logo' => ['nullable', new ImageSource],
            'hero_headline' => ['nullable', 'string', 'max:160'],
            'hero_description' => ['nullable', 'string', 'max:500'],
            'hero_image' => ['nullable', new ImageSource],
            'business_address' => ['nullable', 'string', 'max:255'],
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $request->user()->tenant->update($data);

        return back()->with('success', 'Branding updated.');
    }
}
