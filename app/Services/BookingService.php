<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\PromoCode;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(private AvailabilityService $availability, private PricingService $pricing) {}

    public function create(Room $room, array $data, ?int $userId = null): Booking
    {
        return DB::transaction(function () use ($room, $data, $userId) {
            // Lock every unit of the property, since a whole-property booking competes with all of them.
            Room::where('property_id', $room->property_id)->orderBy('id')->lockForUpdate()->get();
            $room = Room::with('property.tenant')->whereKey($room->id)->firstOrFail();
            $settings = $room->property->tenant->settings;
            if ($error = $this->availability->stayLengthError($room, $data['check_in'], $data['check_out'])) {
                throw ValidationException::withMessages(['check_out' => $error]);
            }
            if (! $this->availability->available($room, $data['check_in'], $data['check_out'])) {
                throw ValidationException::withMessages(['check_in' => 'This room is unavailable for those dates.']);
            }
            // Times the guest chose (or the standard ones, moved later/earlier to fit around the guests next to them).
            // A website request can't start at a time that has already passed today; the front desk can record a walk-in.
            $fromNow = ($data['source'] ?? null) === 'website';
            $window = $this->availability->timeWindow($room, $data['check_in'], $data['check_out'], fromNow: $fromNow);
            if (! $window['fits']) {
                throw ValidationException::withMessages(['check_in' => "{$room->name} has no free time left on that day. Choose another date."]);
            }
            $start = substr($data['check_in_time'] ?? $window['default_start'], 0, 5);
            $end = substr($data['check_out_time'] ?? $window['default_end'], 0, 5);
            if ($error = $this->availability->timesError($room, $data['check_in'], $data['check_out'], $start, $end, fromNow: $fromNow)) {
                throw ValidationException::withMessages(['check_in_time' => $error]);
            }
            $standard = $room->kind === 'venue' ? [$settings['event_start_time'], $settings['event_end_time']] : [$settings['check_in_time'], $settings['check_out_time']];
            $nights = (int) CarbonImmutable::parse($data['check_in'])->diffInDays(CarbonImmutable::parse($data['check_out']));
            $promo = filled($data['promo_code'] ?? null) ? PromoCode::findForStay($room->tenant_id, $data['promo_code'], $nights, lock: true) : null;
            try {
                $quote = $this->pricing->quote($room, $data['check_in'], $data['check_out'], (int) $data['guests'], $promo, $data['add_ons'] ?? []);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['check_out' => $e->getMessage()]);
            }
            $customer = Customer::fromGuest($room->tenant_id, $data, $userId);
            if ($customer->status === 'BLOCKED') {
                throw ValidationException::withMessages(['guest_email' => 'This guest cannot create a reservation. Please contact the property.']);
            }
            $booking = Booking::create([
                'reference' => $settings['reference_prefix'].'-'.now()->format('Y').'-'.strtoupper(substr((string) Str::ulid(), -8)),
                'tenant_id' => $room->tenant_id, 'property_id' => $room->property_id, 'room_id' => $room->id,
                'user_id' => $userId, 'customer_id' => $customer->id, 'guest_name' => $data['guest_name'], 'guest_email' => $customer->email,
                'guest_phone' => $customer->phone, 'check_in' => $data['check_in'],
                'source' => $data['source'] ?? 'direct', 'group_code' => $data['group_code'] ?? null, 'notes' => $data['notes'] ?? null,
                // Only times that differ from the standard ones are kept, so a later change to the standard hours still applies.
                'check_in_time' => $start === substr($standard[0], 0, 5) ? null : $start, 'check_out_time' => $end === substr($standard[1], 0, 5) ? null : $end,
                'check_out' => $data['check_out'], 'guests' => $data['guests'], 'total' => $quote['total'], 'promo_code_id' => $promo?->id,
                ...$this->depositTerms($quote['total'], $data['check_in'], $settings),
                'expires_at' => now()->addMinutes($settings['hold_minutes']),
            ]);
            $booking->price()->create($quote);
            AuditLog::create(['actor_user_id' => $userId, 'tenant_id' => $booking->tenant_id,
                'action' => 'booking.created', 'entity_type' => 'Booking', 'entity_id' => $booking->id,
                'metadata' => ['reference' => $booking->reference, 'total' => $booking->total]]);

            return $booking;
        });
    }

    private function depositTerms(float $total, string $checkIn, array $settings): array
    {
        $percent = $settings['deposit_percent'];
        if ($percent >= 100) {
            return ['deposit_amount' => $total, 'balance_due_on' => null];
        }
        $due = CarbonImmutable::parse($checkIn)->subDays($settings['balance_due_days']);

        return ['deposit_amount' => round($total * $percent / 100, 2), 'balance_due_on' => ($due->lt(today()) ? today() : $due)->toDateString()];
    }
}
