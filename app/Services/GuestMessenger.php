<?php

namespace App\Services;

use App\Mail\GuestMessage;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class GuestMessenger
{
    public const EVENTS = [
        'booking_requested' => 'Reservation request received (owner will call)',
        'booking_received' => 'Booking received (payment needed)',
        'booking_confirmed' => 'Booking confirmed',
        'receipt_received' => 'Downpayment receipt received (being checked)',
        'receipt_rejected' => 'Downpayment receipt not accepted (upload again)',
        'payment_received' => 'Payment received',
        'booking_cancelled' => 'Booking cancelled',
        'pre_arrival' => 'Pre-arrival reminder',
        'thank_you' => 'Thank you after check-out',
    ];

    /** Events that go out at most once per booking. Payment receipts can repeat. */
    private const ONCE = ['booking_requested', 'booking_received', 'booking_confirmed', 'booking_cancelled', 'pre_arrival', 'thank_you'];

    /** Automatic retries of a failed email stop after this many tries or this many hours. */
    public const MAX_ATTEMPTS = 5;

    public const RETRY_HOURS = 48;

    public function template(Tenant $tenant, string $event): array
    {
        $default = config("platform.message_defaults.{$event}");
        $saved = MessageTemplate::where('tenant_id', $tenant->id)->where('event', $event)->first();

        return $saved ? [...$default, ...$saved->only('enabled', 'subject', 'body', 'send_days_before'), 'customized' => true]
            : [...$default, 'customized' => false];
    }

    public function send(Booking $booking, string $event): ?BookingMessage
    {
        // Walk-ins booked with only a phone number or Facebook name have nowhere to send email.
        if (blank($booking->guest_email)) {
            return null;
        }
        $booking->loadMissing('property', 'room', 'tenant', 'payments', 'price');
        $template = $this->template($booking->tenant, $event);
        $failed = null;
        if (in_array($event, self::ONCE, true)) {
            // Facilities booked together get one email between them, not one each.
            if (BookingMessage::whereIn('booking_id', $booking->siblings()->pluck('id'))->where('event', $event)->where('status', 'SENT')->exists()) {
                return null;
            }
            // A one-time message that failed earlier is retried on its existing log row rather than logged twice.
            $failed = $booking->messages()->where('event', $event)->where('status', 'FAILED')->latest('id')->first();
        }

        return $template['enabled'] ? $this->deliver($booking, $event, $template, $failed) : null;
    }

    /** Failed emails still inside the retry window whose backoff (10, 20, 40, 80 minutes) has passed. */
    public function dueForRetry(): Collection
    {
        return BookingMessage::where('status', 'FAILED')->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where('created_at', '>=', now()->subHours(self::RETRY_HOURS))->orderBy('id')->get()
            ->filter(fn (BookingMessage $message) => ($message->last_attempt_at ?? $message->created_at)
                ->lte(now()->subMinutes(10 * 2 ** ($message->attempts - 1))));
    }

    /** Send a failed email again on the same log row. Marked SKIPPED once it no longer fits the booking, e.g. a payment request for a booking since confirmed. */
    public function retry(BookingMessage $message): BookingMessage
    {
        $booking = Booking::withTrashed()->with('property', 'room', 'tenant', 'payments', 'price')->find($message->booking_id);
        $template = $booking ? $this->template($booking->tenant, $message->event) : null;
        $alreadySent = $booking && in_array($message->event, self::ONCE, true)
            && $booking->messages()->where('event', $message->event)->where('status', 'SENT')->exists();
        if (! $booking || $booking->trashed() || blank($booking->guest_email) || ! $template['enabled'] || $alreadySent
            || ! $this->stillRelevant($booking, $message->event)) {
            $message->update(['status' => 'SKIPPED']);

            return $message;
        }

        return $this->deliver($booking, $message->event, $template, $message);
    }

    private function deliver(Booking $booking, string $event, array $template, ?BookingMessage $failed): BookingMessage
    {
        $values = $this->values($booking);
        $subject = $this->render($template['subject'], $values);
        $body = $this->render($template['body'], $values);
        $error = null;
        try {
            Mail::to($booking->guest_email, $booking->guest_name)->send(new GuestMessage($booking->tenant, $subject, $body));
        } catch (\Throwable $e) {
            report($e);
            $error = mb_substr($e->getMessage(), 0, 250);
        }
        $result = ['recipient' => $booking->guest_email, 'subject' => $subject, 'status' => $error ? 'FAILED' : 'SENT', 'error' => $error];
        if ($failed) {
            $failed->update([...$result, 'attempts' => $failed->attempts + 1, 'last_attempt_at' => now()]);

            return $failed;
        }

        return BookingMessage::create(['booking_id' => $booking->id, 'event' => $event, ...$result]);
    }

    private function stillRelevant(Booking $booking, string $event): bool
    {
        return match ($event) {
            'booking_requested' => $booking->status === 'PENDING',
            'booking_received' => $booking->status === 'AWAITING_PAYMENT',
            'booking_confirmed' => in_array($booking->status, ['PAID', 'CONFIRMED', 'CHECKED_IN'], true),
            'receipt_received' => $booking->payments()->where('status', 'PENDING')->exists(),
            'receipt_rejected' => $booking->status === 'AWAITING_PAYMENT',
            'payment_received' => in_array($booking->status, Booking::OCCUPYING, true),
            'booking_cancelled' => $booking->status === 'CANCELLED',
            'pre_arrival' => $booking->status === 'CONFIRMED' && $booking->check_in->gte(today()),
            'thank_you' => in_array($booking->status, ['CHECKED_OUT', 'COMPLETED'], true),
            default => false,
        };
    }

    public function render(string $text, array $values): string
    {
        return strtr($text, collect($values)->mapWithKeys(fn ($value, $key) => ['{'.$key.'}' => (string) $value])->all());
    }

    public function values(Booking $booking): array
    {
        $tenant = $booking->tenant;
        $settings = $tenant->settings;
        $money = fn (float $amount) => self::money($amount, $settings['currency']);
        $date = fn ($value) => $value?->format('D, M j, Y') ?? '';
        $pending = $booking->payments->firstWhere('status', 'PENDING');
        // Before the guest has paid, the instructions are for the owner's first transfer channel (GCash when it's on).
        $channel = $pending?->method ?? collect(['gcash', 'maya', 'bank_transfer'])->first(fn ($m) => $settings['payment_methods'][$m]['enabled'] ?? false);
        $method = $channel ? ($settings['payment_methods'][$channel] ?? null) : null;
        // Facilities booked together share one downpayment.
        $group = $booking->siblings()->load('room');
        $dueNow = $booking->status === 'AWAITING_PAYMENT' ? $group->where('status', 'AWAITING_PAYMENT')->sum(fn (Booking $b) => $b->amountDueNow()) : $booking->amountDueNow();
        // One receipt can cover every facility of the booking.
        $sent = $pending ? ($pending->batch ? (float) Payment::where('tenant_id', $booking->tenant_id)->where('batch', $pending->batch)->sum('amount') : (float) $pending->amount) : null;
        $balance = $group->sum(fn (Booking $b) => $b->balanceDue());

        return [
            'guest_name' => $booking->guest_name, 'guest_phone' => $booking->guest_phone ?? '', 'booking_reference' => $booking->reference,
            'business_name' => $tenant->business_name, 'business_phone' => $tenant->business_phone ?? '', 'business_email' => $tenant->business_email ?? '',
            'property_name' => $booking->property->name,
            'property_address' => collect([$booking->property->address, $booking->property->city, $booking->property->province])->filter()->implode(', '),
            'room_name' => $group->map(fn (Booking $b) => $b->room?->name)->filter()->implode(' + '), 'check_in' => $date($booking->check_in), 'check_out' => $date($booking->check_out),
            'check_in_time' => self::time($settings['check_in_time']), 'check_out_time' => self::time($settings['check_out_time']),
            'nights' => (int) $booking->check_in->diffInDays($booking->check_out), 'guests' => $booking->guests,
            'total' => $money((float) $group->sum('total')), 'amount_paid' => $money($group->sum(fn (Booking $b) => $b->paidAmount())),
            'amount_due' => $money($sent ?? $dueNow),
            'balance_due' => $money($balance),
            'balance_due_note' => $balance > 0 && $booking->balance_due_on ? '(due by '.$date($booking->balance_due_on).')' : '',
            'payment_deadline' => $booking->expires_at?->timezone(config('platform.timezone'))->format('M j, Y g:i A') ?? '',
            'payment_instructions' => $method ? self::instructions(TenantSettings::PAYMENT_METHODS[$channel], $method) : '',
            'booking_link' => url('/booking'),
            'add_ons' => collect($booking->price?->add_ons ?? [])->map(fn ($line) => $line['quantity'] > 1 ? "{$line['name']} ×{$line['quantity']}" : $line['name'])->implode(', '),
            'house_rules' => $settings['house_rules'], 'cancellation_policy' => $settings['cancellation_policy'],
        ];
    }

    /** Sample values for previews and test sends. */
    public function sampleValues(Tenant $tenant): array
    {
        $settings = $tenant->settings;
        $money = fn (float $amount) => self::money($amount, $settings['currency']);
        $gcash = $settings['payment_methods']['gcash'];
        $day = fn (int $days) => now()->addDays($days)->format('D, M j, Y');

        return ['guest_name' => 'Maria Santos', 'guest_phone' => '+63 917 123 4567', 'booking_reference' => $settings['reference_prefix'].'-'.date('Y').'-7K2Q9XMA',
            'business_name' => $tenant->business_name, 'business_phone' => $tenant->business_phone ?? '+63 917 000 0000', 'business_email' => $tenant->business_email ?? '',
            'property_name' => 'Azure Coast Villas', 'property_address' => 'Panglao, Bohol', 'room_name' => 'Signature Suite',
            'check_in' => $day(10), 'check_out' => $day(12),
            'check_in_time' => self::time($settings['check_in_time']), 'check_out_time' => self::time($settings['check_out_time']),
            'nights' => 2, 'guests' => 2, 'total' => $money(15050), 'amount_paid' => $money(7525), 'amount_due' => $money(7525),
            'balance_due' => $money(7525), 'balance_due_note' => '(due by '.$day(3).')',
            'payment_deadline' => now()->addDay()->format('M j, Y g:i A'),
            'payment_instructions' => self::instructions('GCash', ['account_name' => $gcash['account_name'] ?: 'Juan Dela Cruz',
                'account_number' => $gcash['account_number'] ?: '0917 123 4567', 'instructions' => $gcash['instructions']]),
            'booking_link' => url('/booking'),
            'add_ons' => 'Breakfast ×2, Airport transfer',
            'house_rules' => $settings['house_rules'] ?: 'Quiet hours from 10 PM. No smoking indoors.',
            'cancellation_policy' => $settings['cancellation_policy'] ?: 'Free cancellation up to 7 days before check-in.',
        ];
    }

    public static function placeholders(): array
    {
        return ['guest_name', 'guest_phone', 'booking_reference', 'business_name', 'business_phone', 'business_email', 'property_name', 'property_address',
            'room_name', 'check_in', 'check_out', 'check_in_time', 'check_out_time', 'nights', 'guests', 'total', 'amount_paid',
            'amount_due', 'balance_due', 'balance_due_note', 'payment_deadline', 'payment_instructions', 'booking_link', 'add_ons', 'house_rules', 'cancellation_policy'];
    }

    public static function money(float $amount, string $currency): string
    {
        $symbol = ['PHP' => '₱', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'KRW' => '₩'][$currency] ?? $currency.' ';

        return $symbol.number_format($amount, in_array($currency, ['JPY', 'KRW'], true) ? 0 : 2);
    }

    private static function time(string $hhmm): string
    {
        return date('g:i A', strtotime($hhmm));
    }

    private static function instructions(string $label, array $method): string
    {
        return collect(["How to pay ({$label}):",
            $method['account_name'] ? "Account name: {$method['account_name']}" : null,
            $method['account_number'] ? "Account number: {$method['account_number']}" : null,
            $method['instructions'] ?: null,
        ])->filter()->implode("\n");
    }
}
