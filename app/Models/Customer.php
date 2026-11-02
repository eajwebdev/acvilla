<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Customer extends Model
{
    protected $guarded = ['id'];

    public static function fromGuest(int $tenantId, array $guest, ?int $userId = null): self
    {
        $email = filled($guest['guest_email'] ?? null) ? strtolower(trim($guest['guest_email'])) : null;
        $phone = self::normalizePhone($guest['guest_phone'] ?? null);
        $name = trim($guest['guest_name']);
        $identity = $email ? 'email:'.$email : ($phone ? 'phone:'.$phone : 'name:'.strtolower($name));

        $customer = self::firstOrNew(['tenant_id' => $tenantId, 'identity_key' => $identity]);
        $customer->fill(['name' => $name, 'email' => $email, 'phone' => $phone]);
        if ($userId) {
            $customer->user_id = $userId;
        }
        $customer->save();

        return $customer;
    }

    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '09') && strlen($digits) === 11) {
            return '+63'.substr($digits, 1);
        }
        if (str_starts_with($digits, '63')) {
            return '+'.$digits;
        }

        return '+'.$digits;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Booking::class);
    }
}
