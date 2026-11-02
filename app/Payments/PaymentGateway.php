<?php

namespace App\Payments;

use App\Models\Booking;
use App\Models\Payment;

/** One way a guest can pay at checkout. A PayMongo gateway would implement this too. */
interface PaymentGateway
{
    /** Called inside a transaction with the booking row locked. */
    public function start(Booking $booking, array $input): Payment;
}
