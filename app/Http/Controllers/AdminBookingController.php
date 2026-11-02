<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PosSale;
use App\Models\Room;
use App\Services\AvailabilityService;
use App\Services\BookingNotifier;
use App\Services\BookingService;
use App\Services\GuestMessenger;
use App\Services\PosService;
use App\Services\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminBookingController extends Controller
{
    private const TRANSITIONS = [
        // A website request: the owner approves the dates and asks for the downpayment, or confirms it outright.
        'PENDING' => ['AWAITING_PAYMENT', 'CONFIRMED', 'CANCELLED'], 'AWAITING_PAYMENT' => ['CONFIRMED', 'CANCELLED'],
        'PAID' => ['CONFIRMED', 'CANCELLED'], 'CONFIRMED' => ['CHECKED_IN', 'CANCELLED', 'NO_SHOW'],
        'CHECKED_IN' => ['CHECKED_OUT'], 'CHECKED_OUT' => ['COMPLETED'],
    ];

    private const TABS = ['all', 'requests', 'unpaid', 'paid', 'direct', 'airbnb', 'cancelled'];

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'in:PENDING,AWAITING_PAYMENT,PAID,CONFIRMED,CHECKED_IN,CHECKED_OUT,COMPLETED,CANCELLED,EXPIRED,NO_SHOW'],
            'search' => ['nullable', 'string', 'max:120'],
            'tab' => ['nullable', 'in:'.implode(',', self::TABS)],
            'source' => ['nullable', 'in:'.implode(',', array_keys(Booking::SOURCES))],
            'sort' => ['nullable', 'in:guest,unit,stay,balance,created'],
            'dir' => ['nullable', 'in:asc,desc'],
            'open' => ['nullable', 'integer'],
        ]);
        $query = Booking::query()->select('bookings.*')->with(['property:id,name', 'room:id,name', 'payments' => fn ($payments) => $payments->where('status', 'PENDING')])
            ->withSum(['payments as paid_amount' => fn ($payments) => $payments->where('status', 'PAID')], 'amount')
            ->withSum(['posSales as open_charges' => fn ($sales) => $sales->where('status', 'UNPAID')], 'total')
            ->where('bookings.tenant_id', $tenantId);
        if (! empty($filters['from'])) {
            $query->whereDate('bookings.created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('bookings.created_at', '<=', $filters['to']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        match ($filters['tab'] ?? 'all') {
            'requests' => $query->where('source', 'website')->where('status', 'PENDING')->where('expires_at', '>', now()),
            'unpaid' => $query->whereIn('payment_status', ['UNPAID', 'PARTIALLY_PAID', 'PENDING'])->whereNotIn('status', ['CANCELLED', 'EXPIRED', 'NO_SHOW']),
            'paid' => $query->where('payment_status', 'PAID'),
            'direct' => $query->whereIn('source', Booking::DIRECT_SOURCES),
            'airbnb' => $query->where('source', 'airbnb'),
            'cancelled' => $query->whereIn('status', ['CANCELLED', 'EXPIRED', 'NO_SHOW']),
            default => null,
        };
        if (! empty($filters['source'])) {
            $query->where('source', $filters['source']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('reference', 'like', $term)->orWhere('guest_name', 'like', $term)
                ->orWhere('guest_email', 'like', $term)->orWhere('guest_phone', 'like', $term)
                ->orWhereHas('room', fn ($room) => $room->where('name', 'like', $term)));
        }
        $dir = $filters['dir'] ?? 'desc';
        match ($filters['sort'] ?? 'created') {
            'guest' => $query->orderBy('guest_name', $dir),
            'unit' => $query->orderBy(Room::select('name')->whereColumn('rooms.id', 'bookings.room_id'), $dir),
            'stay' => $query->orderBy('check_in', $dir),
            'balance' => $query->orderByRaw('(bookings.total - coalesce((select sum(amount) from payments where payments.booking_id = bookings.id and payments.status = ?), 0)) '.($dir === 'asc' ? 'asc' : 'desc'), ['PAID']),
            default => $query->orderBy('bookings.created_at', $dir)->orderBy('bookings.id', $dir),
        };

        return Inertia::render('Admin/Bookings', [
            'bookings' => $query->paginate(30)->withQueryString(),
            'filters' => $filters,
            'active' => Booking::where('tenant_id', $tenantId)->whereIn('status', ['PENDING', 'AWAITING_PAYMENT', 'PAID', 'CONFIRMED', 'CHECKED_IN'])
                ->where(fn ($q) => $q->whereNotIn('status', ['PENDING', 'AWAITING_PAYMENT'])->orWhere('expires_at', '>', now()))->count(),
            'sources' => Booking::SOURCES,
        ]);
    }

    /** Everything the booking drawer shows, as JSON so any page can open it. */
    public function show(Request $request, Booking $booking)
    {
        abort_unless($booking->tenant_id === $request->user()->tenant_id, 404);
        $booking->load(['property:id,name', 'room:id,name,maximum_occupancy,kind', 'price', 'payments' => fn ($q) => $q->latest(),
            'posSales' => fn ($q) => $q->with('items')->latest(), 'messages' => fn ($q) => $q->latest('created_at')->limit(10)]);
        $settings = $request->user()->tenant->settings;
        [$in, $out] = $booking->times($settings);
        $paid = $booking->paidAmount();
        $openCharges = $booking->openCharges();
        $expired = $booking->holdLapsed();

        return response()->json([
            'booking' => [...$booking->only('id', 'reference', 'guest_name', 'guest_email', 'guest_phone', 'guests', 'status', 'payment_status',
                'source', 'notes', 'group_code', 'room_id', 'deposit_amount'),
                'check_in' => $booking->check_in->toDateString(), 'check_out' => $booking->check_out->toDateString(),
                'check_in_time' => $in, 'check_out_time' => $out, 'nights' => (int) $booking->check_in->diffInDays($booking->check_out),
                'expires_at' => $booking->expires_at?->toIso8601String(), 'created_at' => $booking->created_at->toIso8601String(),
                'unit' => $booking->room?->name, 'unit_kind' => $booking->room?->kind ?? 'room', 'property' => $booking->property?->name, 'total' => (float) $booking->total],
            'price' => $booking->price,
            'paid' => $paid,
            'balance' => max(0, round((float) $booking->total - $paid, 2)),
            'open_charges' => $openCharges,
            'payments' => $booking->payments->map(fn (Payment $p) => [...$p->only('id', 'method', 'amount', 'status', 'reference', 'payer_reference', 'paid_at', 'created_at', 'batch'),
                'proof_url' => $p->proof_path ? route('admin.payments.proof', $p) : null,
                'covers' => $p->batch ? Payment::where('batch', $p->batch)->count() : 1, 'batch_total' => $p->batch ? (float) Payment::where('batch', $p->batch)->sum('amount') : (float) $p->amount]),
            'downpayment_due' => $booking->amountDueNow(),
            'charges' => $booking->posSales->map(fn (PosSale $s) => [...$s->only('id', 'number', 'total', 'status', 'payment_method', 'created_at'),
                'items' => $s->items->map(fn ($i) => $i->only('name', 'quantity', 'amount'))]),
            'messages' => $booking->messages->map(fn ($m) => $m->only('id', 'event', 'subject', 'status', 'error', 'created_at')),
            'transitions' => $expired ? [] : (self::TRANSITIONS[$booking->status] ?? []),
            'expired' => $expired || $booking->status === 'EXPIRED',
            // Unconfirmed requests can have their hold extended, or be reopened once it lapsed.
            'can_hold' => in_array($booking->status, ['PENDING', 'AWAITING_PAYMENT', 'EXPIRED'], true),
            'group' => $booking->group_code ? Booking::with('room:id,name')->where('tenant_id', $booking->tenant_id)->where('group_code', $booking->group_code)
                ->get()->map(fn (Booking $b) => ['id' => $b->id, 'unit' => $b->room?->name, 'status' => $b->status,
                    'check_in' => $b->check_in->toDateString(), 'check_out' => $b->check_out->toDateString(), 'total' => (float) $b->total]) : [],
        ]);
    }

    /** Which units are free for the dates, with the price each would cost. Staff skip the advance-notice window. */
    public function availability(Request $request, AvailabilityService $availability, PricingService $pricing)
    {
        $data = $request->validate(['check_in' => 'required|date_format:Y-m-d', 'check_out' => 'required|date_format:Y-m-d|after:check_in',
            'guests' => 'nullable|integer|min:1|max:200']);
        $guests = (int) ($data['guests'] ?? 1);
        $rooms = Room::with('property.tenant')->where('tenant_id', $request->user()->tenant_id)->where('active', true)->orderBy('name')->get();
        $nights = (int) CarbonImmutable::parse($data['check_in'])->diffInDays(CarbonImmutable::parse($data['check_out']));

        $settings = $request->user()->tenant->settings;

        return response()->json(['nights' => $nights, 'check_in_time' => $settings['check_in_time'], 'check_out_time' => $settings['check_out_time'], 'units' => $rooms->map(function (Room $room) use ($data, $guests, $availability, $pricing) {
            $error = $availability->stayLengthError($room, $data['check_in'], $data['check_out']);
            $free = ! $error && $availability->available($room, $data['check_in'], $data['check_out']);
            $total = null;
            if (! $error) {
                try {
                    $total = $pricing->quote($room, $data['check_in'], $data['check_out'], min($guests, $room->maximum_occupancy))['total'];
                } catch (\InvalidArgumentException) {
                    $total = null;
                }
            }

            return ['id' => $room->id, 'name' => $room->name, 'property' => $room->property->name, 'max_guests' => $room->maximum_occupancy,
                'available' => $free, 'reason' => $error ?? ($free ? null : 'Booked or blocked for these dates'), 'total' => $total];
        })->values()]);
    }

    public function store(Request $request, BookingService $service)
    {
        $data = $request->validate(['room_id' => 'required_without:room_ids|integer', 'room_ids' => 'nullable|array|min:1|max:20', 'room_ids.*' => 'integer|distinct',
            'check_in' => 'required|date|after_or_equal:today', 'check_out' => 'required|date|after:check_in', 'guests' => 'required|integer|min:1|max:200',
            'guest_name' => 'required|string|max:120', 'guest_email' => 'nullable|email|max:255', 'guest_phone' => 'nullable|string|max:120',
            'source' => 'nullable|in:'.implode(',', array_keys(Booking::SOURCES)), 'check_in_time' => 'nullable|date_format:H:i',
            'check_out_time' => 'nullable|date_format:H:i', 'notes' => 'nullable|string|max:1000', 'confirm' => 'nullable|boolean']);
        $roomIds = $data['room_ids'] ?? [$data['room_id']];
        $rooms = Room::where('tenant_id', $request->user()->tenant_id)->where('active', true)->whereIn('id', $roomIds)->get()->keyBy('id');
        abort_unless($rooms->count() === count(array_unique($roomIds)), 404);
        $group = count($roomIds) > 1 ? 'GRP-'.strtoupper(substr((string) Str::ulid(), -8)) : null;
        foreach ($rooms as $room) {
            if ($data['guests'] > $room->maximum_occupancy) {
                throw ValidationException::withMessages(['guests' => "{$room->name} fits up to {$room->maximum_occupancy} guests."]);
            }
        }
        $bookings = DB::transaction(fn () => collect($roomIds)->map(function (int $id) use ($rooms, $data, $service, $group, $request) {
            $booking = $service->create($rooms[$id], [...$data, 'group_code' => $group]);
            if ($request->boolean('confirm')) {
                $booking->update(['status' => 'CONFIRMED', 'expires_at' => null]);
            }
            $this->audit($request, $booking, 'booking.manual_created');

            return $booking;
        }));
        app(BookingNotifier::class)->record($bookings, 'manual_booking', $request->user());

        return back()->with('success', $bookings->count() > 1
            ? "Group booking created: {$bookings->count()} units ({$group})."
            : 'Booking created: '.$bookings->first()->reference);
    }

    /** Front-desk edits that don't change price or dates. */
    public function update(Request $request, Booking $booking)
    {
        abort_unless($booking->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate(['guest_name' => 'required|string|max:120', 'guest_email' => 'nullable|email|max:255',
            'guest_phone' => 'nullable|string|max:120', 'source' => 'required|in:'.implode(',', array_keys(Booking::SOURCES)),
            'check_in_time' => 'nullable|date_format:H:i', 'check_out_time' => 'nullable|date_format:H:i', 'notes' => 'nullable|string|max:1000']);
        // A late check-out or early check-in is only allowed when it doesn't run into the guest before or after.
        [$in, $out] = $booking->times();
        if (($data['check_in_time'] ?? $in) !== $in || ($data['check_out_time'] ?? $out) !== $out) {
            $booking->loadMissing('room', 'tenant');
            if ($booking->room && ($clash = app(AvailabilityService::class)->turnoverConflict($booking, $data['check_in_time'] ?? $in, $data['check_out_time'] ?? $out))) {
                throw ValidationException::withMessages(['check_out_time' => $clash]);
            }
        }
        $customer = Customer::fromGuest($booking->tenant_id, $data, $booking->user_id);
        $booking->update([...$data, 'guest_email' => $customer->email, 'guest_phone' => $customer->phone, 'customer_id' => $customer->id]);
        $this->audit($request, $booking, 'booking.updated');

        return back()->with('success', 'Booking details saved.');
    }

    public function status(Request $request, Booking $booking)
    {
        abort_unless($booking->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate(['status' => 'required|in:AWAITING_PAYMENT,CONFIRMED,CHECKED_IN,CHECKED_OUT,COMPLETED,CANCELLED,NO_SHOW']);
        if ($data['status'] === 'AWAITING_PAYMENT') {
            return $this->approve($request, $booking);
        }
        $booking = DB::transaction(function () use ($request, $booking, $data) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_if($booking->holdLapsed(), 422);
            abort_unless(in_array($data['status'], self::TRANSITIONS[$booking->status] ?? [], true), 422);
            if ($data['status'] === 'CHECKED_OUT' && ($open = $booking->openCharges()) > 0) {
                throw ValidationException::withMessages(['status' => 'Settle '.number_format($open, 2).' in room charges before checking out.']);
            }
            $declined = $data['status'] === 'CANCELLED' && in_array($booking->status, ['PENDING', 'AWAITING_PAYMENT'], true);
            $booking->update(['status' => $data['status'], 'expires_at' => null]);
            $this->audit($request, $booking, 'booking.status_changed');
            app(BookingNotifier::class)->record($booking, $declined ? 'declined' : strtolower($data['status']), $request->user());

            return $booking;
        });

        return back()->with('success', match ($data['status']) {
            'CHECKED_IN' => $booking->guest_name.' checked in.', 'CHECKED_OUT' => $booking->guest_name.' checked out.',
            default => 'Booking updated.',
        });
    }

    /**
     * Keep a reservation request's dates held for another 24 hours while the owner reaches the guest.
     * A request that already lapsed is reopened, but only if its unit is still free for those dates.
     */
    public function hold(Request $request, Booking $booking, AvailabilityService $availability)
    {
        abort_unless($booking->tenant_id === $request->user()->tenant_id, 404);
        $booking = DB::transaction(function () use ($request, $booking, $availability) {
            Room::where('property_id', $booking->property_id)->orderBy('id')->lockForUpdate()->get();
            $booking = Booking::with('room.property.tenant')->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($booking->status, ['PENDING', 'AWAITING_PAYMENT', 'EXPIRED'], true), 422);
            $lapsed = $booking->status === 'EXPIRED' || ! $booking->expires_at?->isFuture();
            if ($lapsed && (! $booking->room || ! $availability->available($booking->room, $booking->check_in->toDateString(), $booking->check_out->toDateString()))) {
                throw ValidationException::withMessages(['status' => 'Those dates have been taken since this request lapsed. Offer the guest other dates or another unit.']);
            }
            $from = $lapsed ? now() : $booking->expires_at;
            $booking->update(['status' => $booking->status === 'EXPIRED' ? 'PENDING' : $booking->status, 'expires_at' => $from->copy()->addDay()]);
            $this->audit($request, $booking, $lapsed ? 'booking.request_reopened' : 'booking.hold_extended');
            app(BookingNotifier::class)->record($booking, $lapsed ? 'request_reopened' : 'hold_extended', $request->user());

            return $booking;
        });

        return back()->with('success', 'Dates held until '.$booking->expires_at->timezone(config('app.timezone'))->format('M j, g:i A').'.');
    }

    /**
     * Extend a stay (or an event by another day) to a later check-out, only when the extra nights are free.
     * The extra nights are priced at the room's rates for those dates and added to the bill; payments so far stay as they are.
     */
    public function extend(Request $request, Booking $booking, AvailabilityService $availability, PricingService $pricing)
    {
        abort_unless($booking->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate(['check_out' => 'required|date_format:Y-m-d']);
        $booking = DB::transaction(function () use ($request, $booking, $data, $availability, $pricing) {
            Room::where('property_id', $booking->property_id)->orderBy('id')->lockForUpdate()->get();
            $booking = Booking::with('room.property.tenant', 'price')->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_unless($booking->room && in_array($booking->status, ['PENDING', 'AWAITING_PAYMENT', 'PAID', 'CONFIRMED', 'CHECKED_IN'], true), 422);
            abort_if($booking->holdLapsed(), 422);
            $from = $booking->check_out->toDateString();
            if ($data['check_out'] <= $from) {
                throw ValidationException::withMessages(['check_out' => 'Choose a check-out after the current one ('.$booking->check_out->format('M j').').']);
            }
            if (! $availability->rangeFree($booking->room, $from, $data['check_out'])) {
                throw ValidationException::withMessages(['check_out' => "{$booking->room->name} is already booked or blocked for part of those dates, so the stay can't be extended that far."]);
            }
            try {
                $extra = $pricing->quote($booking->room, $from, $data['check_out'], $booking->guests);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['check_out' => 'That extension is longer than the stay limit for this unit.']);
            }
            // Cleaning is charged once per stay, so the extension adds room nights, extra guests, fees and tax only.
            $added = round($extra['total'] - $extra['cleaning_fee'], 2);
            if ($booking->price) {
                $booking->price->update(['subtotal' => $booking->price->subtotal + $extra['subtotal'], 'extra_guest_fee' => $booking->price->extra_guest_fee + $extra['extra_guest_fee'],
                    'service_fee' => $booking->price->service_fee + $extra['service_fee'], 'tax' => $booking->price->tax + $extra['tax'],
                    'discount' => $booking->price->discount + $extra['discount'], 'total' => $booking->price->total + $added,
                    'nightly_rates' => [...($booking->price->nightly_rates ?? []), ...$extra['nightly_rates']]]);
            }
            $booking->update(['check_out' => $data['check_out'], 'total' => round((float) $booking->total + $added, 2)]);
            $booking->syncPaymentStatus();
            app(BookingNotifier::class)->record($booking, 'extended', $request->user(), ['amount' => $added, 'to' => CarbonImmutable::parse($data['check_out'])->format('M j')]);
            AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $booking->tenant_id, 'action' => 'booking.extended',
                'entity_type' => 'Booking', 'entity_id' => $booking->id, 'ip_address' => $request->ip(),
                'metadata' => ['reference' => $booking->reference, 'from' => $from, 'to' => $data['check_out'], 'added' => $added]]);

            return [$booking, $added];
        });
        [$booking, $added] = $booking;

        return back()->with('success', 'Extended to '.$booking->check_out->format('M j').'. '.number_format($added, 2).' added to the bill.');
    }

    public function payment(Request $request, Booking $booking, GuestMessenger $messenger)
    {
        abort_unless($booking->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate(['amount' => 'required|numeric|decimal:0,2|min:0.01', 'method' => 'required|in:cash,bank_transfer,gcash,maya',
            'payer_reference' => 'nullable|string|max:100']);
        abort_unless(! in_array($booking->status, ['CANCELLED', 'EXPIRED', 'COMPLETED'], true), 422);
        abort_if($booking->holdLapsed(), 422);
        [$booking, $statusBefore] = DB::transaction(function () use ($request, $booking, $data) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $statusBefore = $booking->status;
            abort_unless(! in_array($booking->status, ['CANCELLED', 'EXPIRED', 'COMPLETED'], true), 422);
            abort_if($booking->holdLapsed(), 422);
            abort_unless($booking->paidAmount() + (float) $data['amount'] <= (float) $booking->total + 0.001, 422);
            Payment::create(['tenant_id' => $booking->tenant_id, 'booking_id' => $booking->id,
                'method' => $data['method'], 'provider' => 'manual', 'status' => 'PAID', 'amount' => $data['amount'],
                'reference' => 'PAY-'.strtoupper(substr((string) Str::ulid(), -10)), 'payer_reference' => $data['payer_reference'] ?? null, 'paid_at' => now()]);
            $booking->syncPaymentStatus();
            $this->audit($request, $booking, 'payment.recorded');
            app(BookingNotifier::class)->record($booking, 'payment_recorded', $request->user(), ['amount' => (float) $data['amount'], 'method' => $data['method']]);

            return [$booking, $statusBefore];
        });
        $this->receipt($messenger, $booking, $statusBefore);

        return back()->with('success', 'Payment recorded.');
    }

    /** Pay off every POS purchase charged to the room, usually at check-out. */
    public function settleCharges(Request $request, Booking $booking, PosService $pos)
    {
        abort_unless($booking->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate(['method' => 'required|in:cash,gcash,maya,card,bank_transfer', 'reference' => 'nullable|string|max:100']);
        $settled = $pos->settle($request->user(), $booking->posSales()->where('status', 'UNPAID')->get(), $data['method'], $data['reference'] ?? null);
        abort_if($settled <= 0, 422, 'There are no open room charges.');

        return back()->with('success', 'Room charges settled.');
    }

    /**
     * Confirm or reject a payment the guest reported: a downpayment receipt (one photo can cover several facilities
     * booked together, all approved or rejected at once) or a reference from the old checkout.
     */
    public function review(Request $request, Payment $payment, GuestMessenger $messenger)
    {
        abort_unless($payment->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'amount' => 'nullable|numeric|decimal:0,2|min:0.01']);
        abort_unless($payment->status === 'PENDING', 422);
        $results = DB::transaction(function () use ($request, $payment, $data) {
            $batch = $payment->batch ? Payment::where('tenant_id', $payment->tenant_id)->where('batch', $payment->batch)->where('status', 'PENDING')->orderBy('id')->lockForUpdate()->get()
                : Payment::whereKey($payment->id)->lockForUpdate()->get();
            abort_if($batch->isEmpty(), 422);
            $amountFor = fn (Payment $p) => $batch->count() === 1 && isset($data['amount']) ? (float) $data['amount'] : (float) $p->amount;

            return $batch->map(function (Payment $p) use ($request, $data, $amountFor) {
                $booking = Booking::whereKey($p->booking_id)->lockForUpdate()->firstOrFail();
                $statusBefore = $booking->status;
                abort_unless(! in_array($booking->status, ['CANCELLED', 'EXPIRED', 'COMPLETED'], true), 422);
                if ($data['decision'] === 'approve') {
                    abort_unless($booking->paidAmount() + $amountFor($p) <= (float) $booking->total + 0.001, 422);
                    $p->update(['status' => 'PAID', 'amount' => $amountFor($p), 'paid_at' => now()]);
                } else {
                    $p->update(['status' => 'FAILED']);
                    // Give the guest time to send a clearer receipt before the dates are released.
                    if (in_array($booking->status, ['PENDING', 'AWAITING_PAYMENT'], true)) {
                        $booking->update(['expires_at' => now()->addHours($booking->tenant->setting('manual_payment_hours'))]);
                    }
                }
                $booking->syncPaymentStatus();
                $this->audit($request, $booking, $data['decision'] === 'approve' ? 'payment.approved' : 'payment.rejected');

                return [$booking, $statusBefore, (float) $p->amount];
            });
        });
        $reviewed = $results->map(fn (array $pair) => $pair[0]);
        app(BookingNotifier::class)->record($reviewed, $data['decision'] === 'approve' ? 'payment_approved' : 'receipt_rejected', $request->user(),
            ['amount' => $results->sum(fn (array $row) => $row[2])]);
        if ($data['decision'] === 'approve') {
            foreach ($results as [$booking, $statusBefore]) {
                $this->receipt($messenger, $booking, $statusBefore);
            }
        } else {
            $messenger->send($reviewed->first()->fresh(), 'receipt_rejected');
        }

        return back()->with('success', $data['decision'] === 'approve'
            ? ($results->count() > 1 ? "Downpayment approved for {$results->count()} facilities." : 'Payment approved.')
            : 'Receipt rejected. The guest can upload a clearer one.');
    }

    /** The downpayment receipt photo, served only to staff of the same business. */
    public function proofImage(Request $request, Payment $payment)
    {
        abort_unless($payment->tenant_id === $request->user()->tenant_id && $payment->proof_path, 404);
        abort_unless(Storage::disk('local')->exists($payment->proof_path), 404);

        return Storage::disk('local')->response($payment->proof_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /**
     * The owner approved the dates on the call: every pending facility of the request now waits for the downpayment,
     * held for the payment window, and the guest is told how much to send.
     */
    private function approve(Request $request, Booking $booking)
    {
        $approved = DB::transaction(function () use ($request, $booking) {
            $group = Booking::where('tenant_id', $booking->tenant_id)->whereIn('id', $booking->siblings()->pluck('id'))->orderBy('id')->lockForUpdate()->get()
                ->filter(fn (Booking $b) => $b->status === 'PENDING' && ! $b->holdLapsed());
            abort_if($group->isEmpty(), 422);
            $hours = $booking->tenant->setting('manual_payment_hours');
            foreach ($group as $b) {
                $b->update(['status' => 'AWAITING_PAYMENT', 'expires_at' => now()->addHours($hours)]);
                $this->audit($request, $b, 'booking.dates_approved');
            }

            return $group;
        });
        DB::afterCommit(fn () => app(GuestMessenger::class)->send($approved->first()->fresh(), 'booking_received'));
        $due = $approved->sum(fn (Booking $b) => $b->fresh()->amountDueNow());
        app(BookingNotifier::class)->record($approved, 'dates_approved', $request->user(), ['amount' => $due]);

        return back()->with('success', 'Dates approved. The guest now pays the '.number_format($due, 2).' downpayment and uploads the receipt.');
    }

    /** Change how much downpayment this booking needs; the standard percentage lives in Settings → Payments. */
    public function deposit(Request $request, Booking $booking)
    {
        abort_unless($booking->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate(['amount' => 'required|numeric|decimal:0,2|min:0']);
        abort_unless(in_array($booking->status, ['PENDING', 'AWAITING_PAYMENT', 'PAID', 'CONFIRMED', 'CHECKED_IN'], true), 422);
        if ((float) $data['amount'] > (float) $booking->total) {
            throw ValidationException::withMessages(['amount' => 'The downpayment cannot be more than the total ('.number_format((float) $booking->total, 2).').']);
        }
        DB::transaction(function () use ($request, $booking, $data) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $booking->update(['deposit_amount' => round((float) $data['amount'], 2)]);
            if ($booking->paidAmount() > 0) {
                $booking->syncPaymentStatus();
            }
            $this->audit($request, $booking, 'booking.downpayment_changed');
            app(BookingNotifier::class)->record($booking, 'downpayment_changed', $request->user(), ['amount' => (float) $data['amount']]);
        });

        return back()->with('success', 'Downpayment set to '.number_format((float) $data['amount'], 2).'.');
    }

    /** A payment that confirms the booking is covered by the confirmation email; later payments get a receipt. */
    private function receipt(GuestMessenger $messenger, Booking $booking, string $statusBefore): void
    {
        if (! ($booking->status === 'CONFIRMED' && $statusBefore !== 'CONFIRMED')) {
            $messenger->send($booking->fresh(), 'payment_received');
        }
    }

    private function audit(Request $request, Booking $booking, string $action): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $booking->tenant_id,
            'action' => $action, 'entity_type' => 'Booking', 'entity_id' => $booking->id,
            'metadata' => ['reference' => $booking->reference, 'status' => $booking->status], 'ip_address' => $request->ip()]);
    }
}
