<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    /** A room guests stay in overnight, or a venue such as a function hall that is rented per event. */
    public const KINDS = ['room', 'venue'];

    public const BED_TYPES = ['king', 'queen', 'double', 'single', 'bunk', 'sofa bed', 'floor mattress'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'whole_property' => 'boolean', 'base_rate' => 'decimal:2', 'weekend_rate' => 'decimal:2', 'cleaning_fee' => 'decimal:2',
            'bathrooms' => 'decimal:1', 'extra_guest_fee' => 'decimal:2', 'beds' => 'array', 'amenities' => 'array', 'photos' => 'array'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** Room overrides win; otherwise the tenant's booking rules apply. */
    public function stayLimits(?array $tenantSettings = null): array
    {
        $settings = $tenantSettings ?? $this->property->tenant->settings;

        return [$this->min_nights ?? $settings['min_nights'], $this->max_nights ?? $settings['max_nights']];
    }

    /** Guests covered by the nightly rate before the extra-guest fee applies. */
    public function includedGuests(): int
    {
        return min($this->base_guests ?? $this->maximum_occupancy, $this->maximum_occupancy);
    }
}
