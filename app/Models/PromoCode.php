<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PromoCode extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'discount_value' => 'decimal:2', 'active' => 'boolean'];
    }

    /** Find a usable code for this tenant, or fail validation on the promo_code field. */
    public static function findForStay(int $tenantId, string $code, int $nights, bool $lock = false): self
    {
        $promo = static::where('tenant_id', $tenantId)->where('code', strtoupper(trim($code)))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $problem = $promo ? $promo->problemFor($nights) : "We couldn't find that promo code.";
        if ($problem) {
            throw ValidationException::withMessages(['promo_code' => $problem]);
        }

        return $promo;
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** Bookings that hold a use: live holds and every booking that went ahead. Cancelled and lapsed holds give the use back. */
    public function usesCount(): int
    {
        return $this->bookings()->where(fn ($q) => $q
            ->whereIn('status', ['PAID', 'CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT', 'COMPLETED'])
            ->orWhere(fn ($hold) => $hold->whereIn('status', ['PENDING', 'AWAITING_PAYMENT'])->where('expires_at', '>', now()))
        )->count();
    }

    public function problemFor(int $nights): ?string
    {
        return match (true) {
            ! $this->active => 'This promo code is no longer active.',
            $this->starts_on && today()->lt($this->starts_on) => 'This promo code is not valid yet.',
            $this->ends_on && today()->gt($this->ends_on) => 'This promo code has expired.',
            $this->min_nights && $nights < $this->min_nights => "This promo code needs a stay of at least {$this->min_nights} nights.",
            $this->max_uses !== null && $this->usesCount() >= $this->max_uses => 'This promo code has been fully redeemed.',
            default => null,
        };
    }

    public function label(): string
    {
        return $this->discount_type === 'percent'
            ? "Promo {$this->code} (".rtrim(rtrim((string) $this->discount_value, '0'), '.').'%)'
            : "Promo {$this->code}";
    }
}
