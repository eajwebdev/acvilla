<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'track_stock' => 'boolean', 'active' => 'boolean', 'stock' => 'integer'];
    }
}
