<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in the admin notification bell: something a guest, a staff member or the system did with a booking. */
class BookingNotification extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
