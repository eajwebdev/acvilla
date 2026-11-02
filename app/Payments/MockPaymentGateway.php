<?php

namespace App\Payments;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Str;

/** Test mode: the amount due now is marked paid immediately. */
class MockPaymentGateway implements PaymentGateway
{
    public function start(Booking $booking, array $input): Payment
    {
        $payment = Payment::create(['tenant_id' => $booking->tenant_id, 'booking_id' => $booking->id,
            'method' => 'mock', 'provider' => 'mock', 'status' => 'PAID', 'amount' => $booking->amountDueNow(),
            'reference' => 'PAY-'.strtoupper(substr((string) Str::ulid(), -10)), 'paid_at' => now()]);
        $booking->syncPaymentStatus();

        return $payment;
    }
}
