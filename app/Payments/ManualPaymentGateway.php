<?php

namespace App\Payments;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Str;

/** GCash, Maya, bank transfer, cash or a generic request: the guest pays outside the app and staff verify it. */
class ManualPaymentGateway implements PaymentGateway
{
    public function __construct(private string $method) {}

    public function start(Booking $booking, array $input): Payment
    {
        abort_if($booking->payments()->where('status', 'PENDING')->exists(), 422);
        $payment = Payment::create(['tenant_id' => $booking->tenant_id, 'booking_id' => $booking->id,
            'method' => $this->method, 'provider' => 'manual', 'status' => 'PENDING', 'amount' => $booking->amountDueNow(),
            'reference' => 'PAY-'.strtoupper(substr((string) Str::ulid(), -10)), 'payer_reference' => $input['payer_reference'] ?? null]);
        $booking->update(['status' => 'AWAITING_PAYMENT', 'payment_status' => 'PENDING',
            'expires_at' => now()->addHours($booking->tenant->setting('manual_payment_hours'))]);

        return $payment;
    }
}
