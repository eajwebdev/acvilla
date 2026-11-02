<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingNotification;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Writes the admin notification bell: every step of a booking, from the guest's request to check-out.
 * Facilities requested together are one notification ("Function Hall + AH Room 1").
 */
class BookingNotifier
{
    /** Events the guest causes; the bell highlights them because someone has to act. */
    public const GUEST_EVENTS = ['requested', 'receipt_uploaded', 'payment_reported'];

    private const TITLES = [
        'requested' => 'New booking request',
        'receipt_uploaded' => 'Downpayment receipt uploaded',
        'payment_reported' => 'Guest reported a payment',
        'dates_approved' => 'Dates approved',
        'payment_approved' => 'Downpayment received',
        'receipt_rejected' => 'Receipt rejected',
        'payment_recorded' => 'Payment recorded',
        'confirmed' => 'Booking confirmed',
        'cancelled' => 'Booking cancelled',
        'declined' => 'Request declined',
        'checked_in' => 'Guest checked in',
        'checked_out' => 'Guest checked out',
        'completed' => 'Stay completed',
        'no_show' => 'Marked as no-show',
        'extended' => 'Stay extended',
        'hold_extended' => 'Hold extended',
        'request_reopened' => 'Request reopened',
        'downpayment_changed' => 'Downpayment changed',
        'manual_booking' => 'Booking added by staff',
        'expired' => 'Request expired',
    ];

    /**
     * @param  Booking|iterable<Booking>  $bookings
     * @param  array{amount?: float, method?: string, to?: string}  $extra
     */
    public function record(Booking|iterable $bookings, string $event, ?User $actor = null, array $extra = []): void
    {
        $bookings = collect($bookings instanceof Booking ? [$bookings] : $bookings)->values();
        if ($bookings->isEmpty() || ! isset(self::TITLES[$event])) {
            return;
        }
        $bookings->each->loadMissing('room:id,name,kind', 'tenant');
        $first = $bookings->first();
        $money = fn (float $amount) => GuestMessenger::money($amount, $first->tenant->setting('currency'));
        $method = isset($extra['method']) ? (TenantSettings::PAYMENT_METHODS[$extra['method']] ?? ucfirst(str_replace('_', ' ', $extra['method']))) : null;
        $who = $first->guest_name;
        $what = $bookings->map(fn (Booking $b) => $b->room?->name)->filter()->implode(' + ');
        $when = $this->dates($bookings);
        $amount = isset($extra['amount']) ? $money((float) $extra['amount']) : null;

        $detail = match ($event) {
            'requested' => "{$who} · {$what} · {$when} · ".$money((float) $bookings->sum('total')),
            'receipt_uploaded' => "{$who} sent {$amount}".($method ? " by {$method}" : '')." for {$what}. Check the receipt.",
            'payment_reported' => "{$who} says they paid {$amount}".($method ? " by {$method}" : '').'.',
            'dates_approved' => "{$who} · {$what} · {$when}. Waiting for the {$amount} downpayment.",
            'payment_approved' => "{$who} paid {$amount} for {$what}. Booking confirmed.",
            'receipt_rejected' => "{$who}'s receipt for {$what} was not accepted. They can upload a clearer one.",
            'payment_recorded' => "{$amount}".($method ? " {$method}" : '')." from {$who} · {$what}",
            'extended' => "{$who} · {$what} now until ".($extra['to'] ?? '').($amount ? " · +{$amount}" : ''),
            'downpayment_changed' => "{$who} · {$what} · downpayment now {$amount}",
            'expired' => "{$who} · {$what} · {$when}. Not confirmed in time, so the dates were released.",
            default => "{$who} · {$what} · {$when}",
        };

        BookingNotification::create(['tenant_id' => $first->tenant_id, 'booking_id' => $first->id, 'event' => $event,
            'title' => self::TITLES[$event], 'detail' => mb_strimwidth($detail, 0, 500, '…'), 'actor' => $actor?->name]);
    }

    /** "Nov 16" for an event day, "Nov 16 to Nov 18" for a stay. */
    private function dates(Collection $bookings): string
    {
        $in = $bookings->min(fn (Booking $b) => $b->check_in->toDateString());
        $out = $bookings->max(fn (Booking $b) => $b->check_out->toDateString());
        $single = $bookings->every(fn (Booking $b) => $b->room?->kind === 'venue');

        return date('M j', strtotime($in)).($single ? '' : ' to '.date('M j', strtotime($out)));
    }
}
