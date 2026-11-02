<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    public const CATEGORIES = ['Cleaning supplies', 'Laundry', 'Utilities', 'Repairs & maintenance', 'Guest supplies', 'Staff', 'Platform fees', 'Taxes & permits', 'Other'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['spent_on' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
