<?php

namespace App\Http\Controllers;

use App\Models\AddOn;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\Property;
use App\Models\Review;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Payments\PaymentService;
use App\Services\AvailabilityService;
use App\Services\BookingCalendar;
use App\Services\BookingNotifier;
use App\Services\BookingService;
use App\Services\GuestMessenger;
use App\Services\PricingService;
use App\Services\SiteContent;
use App\Support\PhilippinePhone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PublicController extends Controller
{
    public function home(Request $request)
    {
        $tenant = $this->siteTenant($request);

        return Inertia::render('Public/Home', ['tenant' => $this->site($tenant), 'requestTypes' => SiteContent::options($tenant?->site_content ?? SiteContent::defaults(), 'request_types'),
            'featured' => $tenant ? Property::with(['rooms' => fn ($rooms) => $rooms->where('active', true)->orderBy('whole_property')->orderBy('kind')->orderBy('id')])->where('tenant_id', $tenant->id)->where('status', 'PUBLISHED')->where('featured', true)->get() : [],
            'hero' => $tenant ? Property::where('tenant_id', $tenant->id)->where('status', 'PUBLISHED')->whereNotNull('cover_image')->first() : null]);
    }

    public function platform()
    {
        return Inertia::render('Platform/Home');
    }

    public function stays(Request $request, AvailabilityService $availability)
    {
        $tenant = $this->siteTenant($request);
        $filters = $request->validate([
            'location' => ['nullable', 'string', 'max:120'],
            'check_in' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'check_out' => ['nullable', 'date_format:Y-m-d', 'after:check_in'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        if ($request->filled('check_in') xor $request->filled('check_out')) {
            throw ValidationException::withMessages(['check_out' => 'Choose both check-in and check-out dates.']);
        }
        if ($tenant && ! empty($filters['check_in']) && ! empty($filters['check_out'])) {
            if ($error = $availability->bookingWindowError($tenant, $filters['check_in'])) {
                throw ValidationException::withMessages(['check_in' => $error]);
            }
            if ($error = $availability->searchLengthError($tenant, $filters['check_in'], $filters['check_out'])) {
                throw ValidationException::withMessages(['check_out' => $error]);
            }
        }
        $q = Property::with(['rooms' => fn ($rooms) => $rooms->where('active', true)->orderBy('whole_property')->orderBy('kind')->orderBy('id')])->where('status', 'PUBLISHED');
        $q->where('tenant_id', $tenant?->id ?? 0);
        if (! empty($filters['location'])) {
            $q->where(fn ($query) => $query->where('city', 'like', '%'.$filters['location'].'%')->orWhere('province', 'like', '%'.$filters['location'].'%')->orWhere('name', 'like', '%'.$filters['location'].'%'));
        }
        if (! empty($filters['check_in']) && ! empty($filters['check_out'])) {
            $matching = $q->get()->filter(fn (Property $property) => $property->rooms->contains(fn (Room $room) => $room->maximum_occupancy >= ($filters['guests'] ?? 1) && $availability->available($room, $filters['check_in'], $filters['check_out'])))->pluck('id');
            $q->whereIn('id', $matching);
        } elseif (! empty($filters['guests'])) {
            $q->whereHas('rooms', fn ($rooms) => $rooms->where('maximum_occupancy', '>=', $filters['guests'])->where('active', true));
        }

        return Inertia::render('Public/Stays', ['tenant' => $this->site($tenant), 'properties' => $q->paginate(12)->withQueryString(), 'filters' => $filters]);
    }

    public function show(Request $request, Property $property)
    {
        abort_unless($property->status === 'PUBLISHED', 404);
        $selection = $request->validate([
            'check_in' => ['nullable', 'date_format:Y-m-d'],
            'check_out' => ['nullable', 'date_format:Y-m-d', 'after:check_in'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:200'],
            'type' => ['nullable', 'string', 'max:40'],
            'room' => ['nullable', 'integer'],
        ]);
        if (isset($selection['room'])) {
            $selection['room'] = (int) $selection['room'];
        }
        if (isset($selection['guests'])) {
            $selection['guests'] = (int) $selection['guests'];
        }

        $property->load(['rooms' => fn ($rooms) => $rooms->where('active', true)->orderBy('whole_property')->orderBy('kind')->orderBy('id'), 'tenant']);
        $addOns = $this->addOns($property);

        $reviewQuery = Review::where('property_id', $property->id)->where('approved', true);
        $reviewSummary = ['count' => (clone $reviewQuery)->count(), 'average' => (clone $reviewQuery)->avg('rating')];
        $reviews = $reviewQuery->with('user:id,name')->latest()->limit(12)->get();

        return Inertia::render('Public/Stay', ['property' => $property, 'tenant' => $this->site($property->tenant), 'bookable' => $property->tenant->acceptsBookings(),
            'selection' => $selection, 'addOns' => $addOns, 'reviews' => $reviews,
            'requestTypes' => SiteContent::options($property->tenant->site_content, 'request_types'),
            'callTimes' => SiteContent::options($property->tenant->site_content, 'call_times'),
            'reviewSummary' => ['count' => $reviewSummary['count'], 'average' => $reviewSummary['average'] === null ? null : round((float) $reviewSummary['average'], 1)]]);
    }

    /**
     * The guided booking page every "Book now" button leads to: when, which unit, your details, review.
     * It books the resort's main property; ?property= picks another of the same business.
     */
    public function reserve(Request $request)
    {
        $tenant = $this->siteTenant($request);
        $selection = $request->validate([
            'property' => ['nullable', 'string', 'max:120'],
            'check_in' => ['nullable', 'date_format:Y-m-d'],
            'check_out' => ['nullable', 'date_format:Y-m-d', 'after:check_in'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:200'],
            'type' => ['nullable', 'string', 'max:40'],
            'room' => ['nullable', 'integer'],
        ]);
        $property = $tenant ? Property::where('tenant_id', $tenant->id)->where('status', 'PUBLISHED')
            ->when($selection['property'] ?? null, fn ($q, $slug) => $q->where('slug', $slug), fn ($q) => $q->orderByDesc('featured')->orderBy('id'))->first() : null;
        abort_unless($property, 404);
        $property->load(['rooms' => fn ($rooms) => $rooms->where('active', true)->orderBy('whole_property')->orderBy('kind')->orderBy('id')]);

        return Inertia::render('Public/Book', ['property' => $property, 'tenant' => $this->site($tenant), 'bookable' => $tenant->acceptsBookings(),
            'selection' => array_filter([...$selection, 'room' => isset($selection['room']) ? (int) $selection['room'] : null, 'guests' => isset($selection['guests']) ? (int) $selection['guests'] : null], fn ($v) => $v !== null),
            'addOns' => $this->addOns($property),
            'requestTypes' => SiteContent::options($tenant->site_content, 'request_types'),
            'callTimes' => SiteContent::options($tenant->site_content, 'call_times')]);
    }

    /** Active extras that apply to at least one unit of the property. */
    private function addOns(Property $property)
    {
        return AddOn::where('tenant_id', $property->tenant_id)->where('active', true)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'description', 'price', 'pricing', 'max_quantity', 'room_ids'])
            ->filter(fn (AddOn $addOn) => $property->rooms->contains(fn (Room $room) => $addOn->appliesToRoom($room->id)))->values();
    }

    /**
     * The booking calendar's greyed-out days: for each unit of a property, the nights that can't be booked
     * between two dates (up to about a year), so guests see what's taken before they pick.
     */
    public function availabilityCalendar(Request $request, AvailabilityService $service)
    {
        $data = $request->validate(['property' => 'required|string|max:120', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after:from']);
        $property = Property::where('slug', $data['property'])->where('status', 'PUBLISHED')->firstOrFail();
        $from = max($data['from'], today()->toDateString());
        $to = min($data['to'], today()->addDays(400)->toDateString());
        $rooms = Room::where('property_id', $property->id)->where('active', true)->get();

        return response()->json(['from' => $from, 'to' => $to, 'rooms' => $from < $to ? (object) $service->unavailableNights($rooms, $from, $to) : (object) []]);
    }

    /**
     * Availability and price for several facilities in one call (each with its own dates and guests), so the booking
     * page checks every option at once instead of firing a request per unit.
     */
    public function availabilityBatch(Request $request, AvailabilityService $service, PricingService $pricing)
    {
        $data = $request->validate(['items' => 'required|array|min:1|max:20', 'items.*.room_id' => 'required|integer',
            'items.*.check_in' => 'required|date_format:Y-m-d', 'items.*.check_out' => 'required|date_format:Y-m-d', 'items.*.guests' => 'required|integer|min:1|max:200']);
        $rooms = Room::with('property.tenant')->whereIn('id', array_column($data['items'], 'room_id'))->get()->keyBy('id');

        return response()->json(collect($data['items'])->map(function (array $item) use ($rooms, $service, $pricing) {
            $room = $rooms[$item['room_id']] ?? null;
            $result = fn (bool $free, ?string $message = null, ?float $total = null, ?array $times = null) => ['room_id' => (int) $item['room_id'], 'available' => $free, 'message' => $message, 'total' => $total, 'times' => $times];
            if (! $room || ! $room->active || $room->property->status !== 'PUBLISHED') {
                return $result(false, 'Not available');
            }
            if ($item['check_in'] < today()->toDateString() || $item['check_out'] <= $item['check_in']) {
                return $result(false, 'Choose dates from today onward.');
            }
            if ($item['guests'] > $room->maximum_occupancy) {
                return $result(false, "Fits up to {$room->maximum_occupancy} guests");
            }
            if ($message = $service->bookingWindowError($room->property->tenant, $item['check_in']) ?? $service->stayLengthError($room, $item['check_in'], $item['check_out'])) {
                return $result(false, $message);
            }
            if (! $service->available($room, $item['check_in'], $item['check_out'])) {
                return $result(false, $service->rangeFree($room, $item['check_in'], $item['check_out']) ? 'No free time left that day' : 'Already booked for these dates');
            }
            $window = $service->timeWindow($room, $item['check_in'], $item['check_out'], fromNow: true);
            if (! $window['fits']) {
                return $result(false, 'No time left today: choose a later date');
            }
            $times = [...collect($window)->only('event', 'earliest', 'latest', 'default_start', 'default_end', 'passed')->all(),
                'after' => $window['after'] ? ['room' => $window['after']['room'], 'time' => $window['after']['time']] : null,
                'before' => $window['before'] ? ['room' => $window['before']['room'], 'time' => $window['before']['time']] : null];
            try {
                return $result(true, null, $pricing->quote($room, $item['check_in'], $item['check_out'], (int) $item['guests'])['total'], $times);
            } catch (\InvalidArgumentException) {
                return $result(true, null, null, $times);
            }
        })->values());
    }

    public function availability(Request $request, AvailabilityService $service)
    {
        $data = $request->validate(['room_id' => 'required|exists:rooms,id', 'check_in' => 'required|date|after_or_equal:today', 'check_out' => 'required|date|after:check_in']);

        $room = Room::with('property.tenant')->findOrFail($data['room_id']);
        $message = $service->bookingWindowError($room->property->tenant, $data['check_in'])
            ?? $service->stayLengthError($room, $data['check_in'], $data['check_out']);

        return response()->json(['available' => ! $message && $service->available($room, $data['check_in'], $data['check_out']), 'message' => $message]);
    }

    /** Live price breakdown for the stay page, using the same rules as booking. */
    public function quote(Request $request, AvailabilityService $availability, PricingService $pricing)
    {
        if ($request->query() === [] && $request->acceptsHtml()) {
            return redirect()->route('quotation');
        }

        $data = $request->validate(['room_id' => 'required|exists:rooms,id', 'check_in' => 'required|date_format:Y-m-d|after_or_equal:today',
            'check_out' => 'required|date_format:Y-m-d|after:check_in', 'guests' => 'required|integer|min:1|max:200',
            'promo_code' => 'nullable|string|max:20', 'add_ons' => 'nullable|array|max:30', 'add_ons.*' => 'integer|min:0|max:50']);
        $room = Room::with('property.tenant')->findOrFail($data['room_id']);
        abort_unless($room->active && $room->property->status === 'PUBLISHED', 404);
        if ($data['guests'] > $room->maximum_occupancy) {
            throw ValidationException::withMessages(['guests' => "This room fits up to {$room->maximum_occupancy} guests."]);
        }
        $tenant = $room->property->tenant;
        if ($error = $availability->bookingWindowError($tenant, $data['check_in'])) {
            throw ValidationException::withMessages(['check_in' => $error]);
        }
        if ($error = $availability->stayLengthError($room, $data['check_in'], $data['check_out'])) {
            throw ValidationException::withMessages(['check_out' => $error]);
        }
        $nights = (int) CarbonImmutable::parse($data['check_in'])->diffInDays(CarbonImmutable::parse($data['check_out']));
        $promo = filled($data['promo_code'] ?? null) ? PromoCode::findForStay($tenant->id, $data['promo_code'], $nights) : null;

        return response()->json(['nights' => $nights, 'tax_label' => $tenant->setting('tax_label'),
            ...$pricing->quote($room, $data['check_in'], $data['check_out'], (int) $data['guests'], $promo, $data['add_ons'] ?? [])]);
    }

    /**
     * A website reservation is a request, not a paid booking: the dates are held while the owner calls the guest,
     * approves the dates and asks for the downpayment. A request can take several facilities at once (two A-Houses
     * and the function hall, say): they are checked and created together, so either all of them are held or none.
     * The "event or stay" and "best time to call" choices are the owner's own lists; their labels go into the notes.
     */
    public function book(Request $request, BookingService $service, AvailabilityService $availability, GuestMessenger $messenger)
    {
        // A request comes from a guest account (Google, or email until Google is set up) with a mobile number: its
        // name, email and number go on the booking, and the guest finds it under My bookings without a reference.
        if (GuestAuthController::required()) {
            $account = $request->user();
            if (! $account) {
                throw ValidationException::withMessages(['account' => GuestAuthController::enabled() ? 'Sign in with Google to send your request.' : 'Sign in or create an account to send your request.']);
            }
            if ($account->role === 'GUEST') {
                if (blank($account->phone)) {
                    throw ValidationException::withMessages(['account' => 'Add your mobile number to your account first.']);
                }
                $request->merge(['guest_name' => $account->name, 'guest_email' => $account->email, 'guest_phone' => PhilippinePhone::format($account->phone)]);
            }
        }
        $data = $request->validate([
            'items' => 'nullable|array|min:1|max:10',
            'items.*.room_id' => 'required|integer|distinct', 'items.*.guests' => 'required|integer|min:1|max:200',
            'items.*.check_in' => 'required|date_format:Y-m-d|after_or_equal:today', 'items.*.check_out' => 'required|date_format:Y-m-d|after:items.*.check_in',
            'items.*.check_in_time' => 'nullable|date_format:H:i', 'items.*.check_out_time' => 'nullable|date_format:H:i',
            'room_id' => 'required_without:items|integer', 'check_in' => 'required_without:items|date|after_or_equal:today',
            'check_out' => 'required_without:items|date|after:check_in', 'guests' => 'required_without:items|integer|min:1|max:200',
            'guest_name' => 'required|string|max:120', 'guest_email' => 'required|email|max:255',
            'guest_phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+()\-\s]{7,30}$/'],
            'request_type' => 'nullable|string|max:40', 'call_time' => 'nullable|string|max:40',
            'message' => 'nullable|string|max:1000',
            'promo_code' => 'nullable|string|max:20', 'add_ons' => 'nullable|array|max:30', 'add_ons.*' => 'integer|min:0|max:50',
        ], ['guest_phone.required' => 'Please give a mobile number so we can call you to confirm.',
            'guest_phone.regex' => 'Enter a valid mobile number, e.g. 0917 123 4567.', 'items.*.room_id.distinct' => 'Each facility can only be chosen once.']);
        $items = $data['items'] ?? [['room_id' => (int) $data['room_id'], 'check_in' => $data['check_in'], 'check_out' => $data['check_out'], 'guests' => (int) $data['guests']]];
        $rooms = Room::with('property.tenant')->whereIn('id', array_column($items, 'room_id'))->where('active', true)->get()->keyBy('id');
        abort_unless($rooms->count() === count($items) && $rooms->pluck('tenant_id')->unique()->count() === 1
            && $rooms->every(fn (Room $room) => $room->property->status === 'PUBLISHED'), 404);
        $tenant = $rooms->first()->property->tenant;
        foreach ($items as $item) {
            $room = $rooms[$item['room_id']];
            if ($item['guests'] > $room->maximum_occupancy) {
                throw ValidationException::withMessages(['guests' => "{$room->name} fits up to {$room->maximum_occupancy} guests."]);
            }
            if ($error = $availability->bookingWindowError($tenant, $item['check_in'])) {
                throw ValidationException::withMessages(['check_in' => $error]);
            }
        }
        $types = SiteContent::options($tenant->site_content, 'request_types');
        $times = SiteContent::options($tenant->site_content, 'call_times');
        foreach (['request_type' => $types, 'call_time' => $times] as $field => $options) {
            if (filled($data[$field] ?? null) && ! array_key_exists($data[$field], $options)) {
                throw ValidationException::withMessages([$field => 'Choose one of the options shown.']);
            }
        }
        // A guest who taps Send twice, or comes back and sends the same request again, keeps the request they already have.
        $phone = Customer::normalizePhone($data['guest_phone']);
        $duplicates = collect($items)->map(fn (array $item) => Booking::where('room_id', $item['room_id'])->where('source', 'website')->where('status', 'PENDING')
            ->where('expires_at', '>', now())->whereDate('check_in', $item['check_in'])->whereDate('check_out', $item['check_out'])->latest('id')->get()
            ->first(fn (Booking $booking) => Customer::normalizePhone($booking->guest_phone) === $phone));
        if ($duplicates->every(fn ($booking) => $booking !== null)) {
            $this->remember($request, $duplicates->first()->siblings());

            return redirect()->route('booking.success', $duplicates->first()->reference);
        }
        $notes = collect([
            'Website reservation request · '.($types[$data['request_type'] ?? ''] ?? reset($types)),
            'Best time to call: '.($times[$data['call_time'] ?? ''] ?? reset($times)),
            filled($data['message'] ?? null) ? 'Message: '.trim($data['message']) : null,
        ])->filter()->implode("\n");
        $group = count($items) > 1 ? 'GRP-'.strtoupper(substr((string) Str::ulid(), -8)) : null;
        // Each extra goes on the first chosen facility that offers it; a promo code applies once, to the first.
        $extras = collect($data['add_ons'] ?? [])->filter(fn ($qty) => (int) $qty > 0);
        $bookings = DB::transaction(function () use ($items, $rooms, $data, $notes, $group, $extras, $service, $tenant, $request) {
            // Hold every unit of the business while the request is checked, so two guests can never take the same dates.
            Room::where('tenant_id', $tenant->id)->orderBy('id')->lockForUpdate()->get();
            $placed = [];

            return collect($items)->map(function (array $item, int $i) use ($rooms, $data, $notes, $group, $extras, &$placed, $service, $tenant, $request) {
                $room = $rooms[$item['room_id']];
                $offered = AddOn::offeredFor($room)->pluck('id')->map(fn ($id) => (int) $id)->all();
                $mine = $extras->filter(fn ($qty, $id) => ! in_array((int) $id, $placed, true) && in_array((int) $id, $offered, true))->all();
                $placed = [...$placed, ...array_map('intval', array_keys($mine))];
                try {
                    $booking = $service->create($room, [...$data, ...$item, 'add_ons' => $mine, 'promo_code' => $i === 0 ? ($data['promo_code'] ?? null) : null,
                        'group_code' => $group, 'source' => 'website', 'notes' => $notes], $request->user()?->id);
                } catch (ValidationException $e) {
                    if (array_key_exists('check_in_time', $e->errors())) {
                        throw ValidationException::withMessages(['items' => $e->errors()['check_in_time'][0]]);
                    }
                    if (count($rooms) > 1 && array_key_exists('check_in', $e->errors())) {
                        throw ValidationException::withMessages(['items' => "{$room->name} is no longer free for those dates. Choose another facility or other dates."]);
                    }
                    throw $e;
                }
                $booking->update(['expires_at' => now()->addHours($tenant->setting('request_hold_hours'))]);

                return $booking;
            });
        });
        $messenger->send($bookings->first()->fresh(), 'booking_requested');
        app(BookingNotifier::class)->record($bookings, 'requested');
        $this->remember($request, $bookings);

        return redirect()->route('booking.success', $bookings->first()->reference);
    }

    /**
     * Where a request stands for the guest: waiting for the call, downpayment due, receipt being checked,
     * receipt rejected, confirmed, or closed (cancelled / not confirmed in time).
     */
    private function stage($group): string
    {
        $group->loadMissing('payments');
        $live = $group->reject(fn (Booking $b) => in_array($b->status, ['CANCELLED', 'EXPIRED', 'NO_SHOW'], true));
        $latestProof = $group->flatMap->payments->whereNotNull('proof_path')->sortByDesc('id')->first();
        $awaiting = $live->contains(fn (Booking $b) => $b->status === 'AWAITING_PAYMENT' && ! $b->holdLapsed());

        return match (true) {
            $live->isEmpty() => 'closed',
            $live->every(fn (Booking $b) => in_array($b->status, ['CHECKED_OUT', 'COMPLETED'], true)) => 'completed',
            $live->every(fn (Booking $b) => in_array($b->status, Booking::OCCUPYING, true)) => 'confirmed',
            $latestProof?->status === 'PENDING' => 'reviewing',
            $awaiting => $latestProof?->status === 'FAILED' ? 'rejected' : 'due',
            $live->contains(fn (Booking $b) => $b->status === 'PENDING' && ! $b->holdLapsed()) => 'requested',
            default => 'closed',
        };
    }

    /**
     * "My bookings": the guest's phone keeps the reference and mobile number of each request it made, and sends
     * them here to see every booking's latest status. A pair only counts when the number matches the booking.
     */
    public function bookingSummaries(Request $request)
    {
        $data = $request->validate(['items' => 'present|array|max:30', 'items.*.reference' => 'required|string|max:40', 'items.*.phone' => 'required|string|max:30']);
        $found = [];
        foreach ($data['items'] as $item) {
            $booking = Booking::where('reference', strtoupper(trim($item['reference'])))->first();
            if (! $booking || Customer::normalizePhone($booking->guest_phone) !== Customer::normalizePhone($item['phone'])) {
                continue;
            }
            $group = $booking->siblings();
            if (! isset($found[$group->first()->reference])) {
                $this->remember($request, $group);
                $found[$group->first()->reference] = $this->summary($group);
            }
        }

        return response()->json(['bookings' => collect($found)->sortByDesc('created_at')->values()]);
    }

    /** One line on My bookings: a booking with every facility booked together. */
    private function summary(Collection $group): array
    {
        $group->load('room:id,name,kind', 'property:id,name', 'payments');
        $primary = $group->first();
        $live = $group->reject(fn (Booking $b) => in_array($b->status, ['CANCELLED', 'EXPIRED', 'NO_SHOW'], true));

        return ['reference' => $primary->reference, 'property' => $primary->property?->name,
            'facilities' => $group->map(fn (Booking $b) => $b->room?->name)->filter()->values(),
            'event' => $group->every(fn (Booking $b) => $b->room?->kind === 'venue'),
            'check_in' => $group->min(fn (Booking $b) => $b->check_in->toDateString()), 'check_out' => $group->max(fn (Booking $b) => $b->check_out->toDateString()),
            'guests' => $group->max('guests'), 'total' => round(($live->isEmpty() ? $group : $live)->sum(fn (Booking $b) => (float) $b->total), 2),
            'paid' => round($group->sum(fn (Booking $b) => $b->paidAmount()), 2), 'stage' => $this->stage($group), 'created_at' => $primary->created_at->toIso8601String()];
    }

    /** Bookings that belong to the signed-in guest: made on the account, or earlier with the same (Google-verified) email. */
    private function accountBookings(?User $user): ?array
    {
        if (! $user || $user->role !== 'GUEST') {
            return null;
        }
        $mine = Booking::where('user_id', $user->id)
            ->when($user->email_verified_at, fn ($q) => $q->orWhereRaw('lower(guest_email) = ?', [strtolower($user->email)]))
            ->latest('id')->limit(100)->get();
        $found = [];
        foreach ($mine as $booking) {
            $group = $booking->siblings();
            $found[$group->first()->reference] ??= $this->summary($group);
        }

        return collect($found)->sortByDesc('created_at')->values()->all();
    }

    /** My bookings: a signed-in guest sees all of theirs; anyone can still open one with its reference and mobile number. */
    public function findBooking(Request $request)
    {
        return Inertia::render('Public/FindBooking', ['tenant' => $this->site($this->siteTenant($request)), 'account' => $this->accountBookings($request->user())]);
    }

    public function lookupBooking(Request $request)
    {
        $data = $request->validate(['reference' => 'required|string|max:40', 'phone' => 'required|string|max:30']);
        $booking = Booking::where('reference', strtoupper(trim($data['reference'])))->first();
        if (! $booking || Customer::normalizePhone($booking->guest_phone) !== Customer::normalizePhone($data['phone'])) {
            throw ValidationException::withMessages(['reference' => 'We could not find a booking with that reference and mobile number.']);
        }
        $this->remember($request, $booking->siblings());

        return redirect()->route('booking.success', $booking->reference);
    }

    /**
     * After the owner approves the dates, the guest pays the downpayment (GCash and the like) and uploads a photo of
     * the receipt. One receipt covers every facility of the request; the owner checks it and confirms the booking.
     */
    public function proof(Request $request, string $reference, PaymentService $payments)
    {
        $booking = Booking::with('tenant')->where('reference', $reference)->firstOrFail();
        abort_unless($this->canView($request, $booking), 403);
        $methods = array_column($this->downpaymentOptions($payments, $booking->tenant), 'method');
        $data = $request->validate(['method' => ['required', Rule::in($methods)], 'payer_reference' => 'nullable|string|max:100',
            'proof' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:10240'],
            ['proof.required' => 'Attach a photo or screenshot of your payment receipt.', 'proof.mimes' => 'The receipt must be a photo (JPG, PNG, WEBP or HEIC).',
                'proof.max' => 'The photo must be under 10 MB.']);
        $path = $request->file('proof')->store("payment-proofs/{$booking->tenant_id}", 'local');
        try {
            DB::transaction(function () use ($booking, $data, $path) {
                $siblings = Booking::where('tenant_id', $booking->tenant_id)->whereIn('id', $booking->siblings()->pluck('id'))->lockForUpdate()->get();
                $due = $siblings->filter(fn (Booking $b) => $b->status === 'AWAITING_PAYMENT' && ! $b->holdLapsed() && $b->amountDueNow() > 0);
                if ($due->isEmpty() || $siblings->contains(fn (Booking $b) => $b->payments()->where('status', 'PENDING')->exists())) {
                    throw ValidationException::withMessages(['proof' => 'There is no downpayment waiting for a receipt on this booking.']);
                }
                $batch = 'PB-'.strtoupper(substr((string) Str::ulid(), -10));
                app(BookingNotifier::class)->record($due, 'receipt_uploaded', null, ['amount' => $due->sum(fn (Booking $b) => $b->amountDueNow()), 'method' => $data['method']]);
                foreach ($due as $b) {
                    Payment::create(['tenant_id' => $b->tenant_id, 'booking_id' => $b->id, 'method' => $data['method'], 'provider' => 'manual', 'status' => 'PENDING',
                        'amount' => $b->amountDueNow(), 'reference' => 'PAY-'.strtoupper(substr((string) Str::ulid(), -10)),
                        'payer_reference' => $data['payer_reference'] ?? null, 'proof_path' => $path, 'batch' => $batch]);
                    $b->update(['payment_status' => 'PENDING']);
                }
                DB::afterCommit(fn () => app(GuestMessenger::class)->send($due->first()->fresh(), 'receipt_received'));
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return redirect()->route('booking.success', $booking->reference)->with('success', 'Receipt sent. We will check it and confirm your booking.');
    }

    /** Downpayment channels the guest can use: the owner's enabled methods except cash, which is paid in person. */
    private function downpaymentOptions(PaymentService $payments, Tenant $tenant): array
    {
        $options = array_values(array_filter($payments->options($tenant), fn (array $o) => ! in_array($o['method'], ['cash', 'mock'], true)));

        return $options ?: [['method' => 'manual', 'label' => 'GCash or bank transfer', 'account_name' => '', 'account_number' => '',
            'instructions' => 'Send the downpayment to the account the owner gave you on the call, then upload the receipt.', 'qr_image' => '', 'enabled' => true]];
    }

    private function canView(Request $request, Booking $booking): bool
    {
        $user = $request->user();
        if ($user && ($user->id === $booking->user_id
            || ($user->role === 'GUEST' && $user->email_verified_at && $booking->guest_email && strtolower($booking->guest_email) === strtolower($user->email)))) {
            return true;
        }

        return in_array($booking->reference, [...(array) $request->session()->get('booking_references', []), $request->session()->get('booking_reference')], true);
    }

    /** Let this browser open these bookings (and only these) without signing in. */
    private function remember(Request $request, $bookings): void
    {
        $references = collect($bookings)->pluck('reference');
        $request->session()->put('booking_reference', $references->first());
        $request->session()->put('booking_references', $references->merge((array) $request->session()->get('booking_references', []))->unique()->take(30)->values()->all());
    }

    public function checkout(Request $request, string $reference, PaymentService $payments)
    {
        $booking = Booking::with('property.tenant', 'room', 'price')->where('reference', $reference)->firstOrFail();
        abort_unless($this->canView($request, $booking), 403);

        if (in_array($booking->status, ['CONFIRMED', 'PAID', 'CHECKED_IN', 'CHECKED_OUT', 'COMPLETED'], true)
            || ($booking->status === 'AWAITING_PAYMENT' && $booking->payments()->where('status', 'PENDING')->exists())) {
            return redirect()->route('booking.success', $booking->reference);
        }
        $checkoutUnavailable = ! in_array($booking->status, ['PENDING', 'AWAITING_PAYMENT'], true)
            || ! $booking->expires_at?->isFuture() || ! $booking->tenant->acceptsBookings();

        return Inertia::render('Public/Checkout', ['booking' => $booking, 'tenant' => $this->site($booking->property->tenant),
            'paymentOptions' => $checkoutUnavailable ? [] : $payments->options($booking->property->tenant),
            'amountDueNow' => $booking->amountDueNow(), 'checkoutUnavailable' => $checkoutUnavailable]);
    }

    public function pay(Request $request, string $reference, PaymentService $payments, GuestMessenger $messenger)
    {
        $data = $request->validate(['method' => 'required|string|max:30', 'payer_reference' => 'nullable|string|max:100', 'accept_policies' => 'boolean']);
        $booking = Booking::where('reference', $reference)->firstOrFail();
        abort_unless($this->canView($request, $booking), 403);
        abort_unless($booking->tenant->acceptsBookings() && in_array($booking->status, ['PENDING', 'AWAITING_PAYMENT'], true) && $booking->expires_at?->isFuture(), 422);
        $settings = $booking->tenant->settings;
        if (($settings['house_rules'] !== '' || $settings['cancellation_policy'] !== '') && ! ($data['accept_policies'] ?? false)) {
            throw ValidationException::withMessages(['accept_policies' => 'Please accept the house rules and cancellation policy to continue.']);
        }
        $gateway = $payments->gateway($booking->tenant, $data['method']);
        if (! $gateway) {
            throw ValidationException::withMessages(['method' => 'Choose one of the payment options shown.']);
        }
        $payment = DB::transaction(function () use ($booking, $data, $gateway) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($booking->status, ['PENDING', 'AWAITING_PAYMENT'], true) && $booking->expires_at?->isFuture(), 422);

            $booking->update(['policies_accepted_at' => ($data['accept_policies'] ?? false) ? now() : null]);

            return $gateway->start($booking, $data);
        });
        if ($payment->status === 'PENDING') {
            $messenger->send($booking->fresh(), 'booking_received');
            app(BookingNotifier::class)->record($booking, 'payment_reported', null, ['amount' => (float) $payment->amount, 'method' => $payment->method]);
        }

        return redirect()->route('booking.success', $booking->reference);
    }

    /** The guest's booking page: where the request stands, and the downpayment step once the owner approves the dates. */
    /** A fingerprint of the booking (and the facilities booked with it) that the guest's open page polls to update itself. */
    public function live(Request $request, string $reference)
    {
        $booking = Booking::where('reference', $reference)->firstOrFail();
        abort_unless($this->canView($request, $booking), 403);
        $group = $booking->siblings()->load('payments');

        return response()->json(['stamp' => md5(json_encode($group->map(fn (Booking $b) => [
            $b->only('id', 'status', 'payment_status', 'total', 'deposit_amount', 'check_in', 'check_out', 'check_in_time', 'check_out_time', 'guests'),
            $b->expires_at?->toIso8601String(), $b->updated_at?->toIso8601String(),
            $b->payments->map(fn (Payment $p) => [$p->id, $p->status, $p->amount, $p->updated_at?->toIso8601String()])->all(),
        ])->all()))]);
    }

    /** The booking as a calendar file: iPhone/iPad show "Add to Calendar", Android and desktop open their calendar app. */
    public function calendar(Request $request, string $reference, BookingCalendar $calendar)
    {
        $booking = Booking::where('reference', $reference)->firstOrFail();
        abort_unless($this->canView($request, $booking), 403);

        return response($calendar->ics($booking), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="AC-Villa-'.$booking->reference.'.ics"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function success(Request $request, string $reference, PaymentService $payments)
    {
        $booking = Booking::with('property.tenant', 'room', 'payments')->where('reference', $reference)->firstOrFail();
        abort_unless($this->canView($request, $booking), 403);
        $pending = $booking->payments->firstWhere('status', 'PENDING');
        $group = $booking->siblings()->load('room:id,name,kind,whole_property', 'payments');
        $live = $group->reject(fn (Booking $b) => in_array($b->status, ['CANCELLED', 'EXPIRED', 'NO_SHOW'], true));
        $latestProof = $group->flatMap->payments->whereNotNull('proof_path')->sortByDesc('id')->first();
        $awaiting = $live->contains(fn (Booking $b) => $b->status === 'AWAITING_PAYMENT' && ! $b->holdLapsed());
        $downpayment = $live->sum(fn (Booking $b) => $b->status === 'AWAITING_PAYMENT' ? $b->amountDueNow() : 0);

        return Inertia::render('Public/Success', ['booking' => $booking->makeHidden('payments'), 'tenant' => $this->site($booking->property->tenant),
            'calendar' => app(BookingCalendar::class)->links($booking),
            'pendingPayment' => $pending ? [...$pending->only('method', 'amount', 'payer_reference'),
                'option' => collect($payments->options($booking->tenant))->firstWhere('method', $pending->method)] : null,
            'amountPaid' => $booking->paidAmount(), 'balanceDue' => $booking->balanceDue(),
            'group' => $group->map(fn (Booking $b) => [...$b->only('id', 'reference', 'guests', 'status', 'payment_status'),
                'check_in' => $b->check_in->toDateString(), 'check_out' => $b->check_out->toDateString(),
                'room' => $b->room?->only('name', 'kind', 'whole_property'), 'total' => (float) $b->total])->values(),
            'downpayment' => [
                'stage' => $this->stage($group),
                'amount' => round($downpayment, 2),
                // What the guest sent with their latest receipt, across every facility it covers.
                'sent' => round((float) $group->flatMap->payments->where('status', 'PENDING')->sum('amount'), 2),
                'total' => round($live->sum(fn (Booking $b) => (float) $b->total), 2),
                'paid' => round($live->sum(fn (Booking $b) => $b->paidAmount()), 2),
                'percent' => $booking->tenant->setting('deposit_percent'),
                'deadline' => $awaiting ? $live->where('status', 'AWAITING_PAYMENT')->min('expires_at')?->toIso8601String() : null,
                'options' => $awaiting ? $this->downpaymentOptions($payments, $booking->tenant) : [],
            ]]);
    }

    public function legal(Request $request, string $page)
    {
        $tenant = $this->siteTenant($request);
        $key = ['privacy' => 'privacy_policy', 'terms' => 'terms_of_service'][$page];
        abort_unless($tenant && $tenant->setting($key) !== '', 404);

        return Inertia::render('Public/Legal', ['tenant' => $this->site($tenant), 'page' => $page,
            'title' => $page === 'privacy' ? 'Privacy policy' : 'Terms of service', 'body' => $tenant->setting($key)]);
    }

    /** Guest pages also get the editable website content (hidden from every other tenant payload). */
    private function site(?Tenant $tenant): ?Tenant
    {
        return $tenant?->makeVisible('site_content');
    }

    private function siteTenant(Request $request): ?Tenant
    {
        if ($request->filled('tenant')) {
            return Tenant::where('slug', $request->input('tenant'))->firstOrFail();
        }

        // The site belongs to one business; other tenants are reached with ?tenant=.
        return Tenant::where('status', 'ACTIVE')->orderByRaw('slug = ? desc', [config('platform.site_tenant')])->orderBy('id')->first();
    }
}
