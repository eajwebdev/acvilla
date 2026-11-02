<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['nightly_rates' => 'array', 'discounts' => 'array', 'add_ons' => 'array'];
    }
}
