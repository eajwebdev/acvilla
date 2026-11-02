<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosSaleItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'amount' => 'decimal:2', 'quantity' => 'integer'];
    }
}
