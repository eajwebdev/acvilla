<?php

namespace App\Models;

use App\Services\GuestMessenger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Booking extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    /** Where a booking came from: social and messaging channels, the guest website, walk-ins and OTAs. */
    public const SOURCES = ['facebook' => 'Facebook', 'messenger' => 'Messenger', 'instagram' => 'Instagram', 'whatsapp' => 'WhatsApp',
        'viber' => 'Viber', 'website' => 'Website', 'phone' => 'Phone / SMS', 'walk_in' => 'Walk-in', 'direct' => 'Direct (other)',
        'airbnb' => 'Airbnb', 'booking_com' => 'Booking.com', 'agoda' => 'Agoda', 'other' => 'Other'];

    /** Channels that reach the guest without an OTA commission. */
    public const DIRECT_SOURCES = ['facebook', 'messenger', 'instagram', 'whatsapp', 'viber', 'website', 'phone', 'walk_in', 'direct'];

    /** Stays that hold a unit: confirmed or in-house. */
    public const OCCUPYING = ['PAID', 'CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT', 'COMPLETED'];

    private const STATUS_MESSAGES = ['CONFIRMED' => 'booking_confirmed', 'CANCELLED' => 'booking_cancelled', 'CHECKED_OUT' => 'thank_you'];

    protected static function booted(): void
    {
        static::updated(function (Booking $booking) {
            $event = $booking->wasChanged('status') ? (self::STATUS_MESSAGES[$booking->status] ?? null) : null;
            if ($event) {
                DB::afterCommit(fn () => app(GuestMessenger::class)->send($booking->fresh(), $event));
            }
        });
    }

    protected function casts(): array
    {
        return ['check_in' => 'date:Y-m-d', 'check_out' => 'date:Y-m-d', 'expires_at' => 'datetime', 'total' => 'decimal:2',
            'deposit_amount' => 'decimal:2', 'balance_due_on' => 'date:Y-m-d'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(BookingMessage::class);
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()->where('status', 'PAID')->sum('amount');
    }

    /** What the guest must pay now to secure the booking: the deposit (or full total) minus anything already paid. */
    public function amountDueNow(): float
    {
        return max(0, round((float) ($this->deposit_amount ?? $this->total) - $this->paidAmount(), 2));
    }

    /**
     * An unconfirmed request or downpayment hold whose time ran out. A downpayment receipt waiting for the
     * owner's check keeps the dates held, so a slow review never releases a guest who already paid.
     */
    public function holdLapsed(): bool
    {
        return in_array($this->status, ['PENDING', 'AWAITING_PAYMENT'], true) && ! $this->expires_at?->isFuture()
            && ! $this->payments()->where('status', 'PENDING')->exists();
    }

    /** Every booking made together with this one (several facilities in one request), this one included. */
    public function siblings(): Collection
    {
        return $this->group_code
            ? self::where('tenant_id', $this->tenant_id)->where('group_code', $this->group_code)->orderBy('id')->get()
            : new Collection([$this]);
    }

    public function balanceDue(): float
    {
        return max(0, round((float) $this->total - $this->paidAmount(), 2));
    }

    /** Recompute payment_status from payments; a paid deposit confirms a booking that was still on hold. */
    public function syncPaymentStatus(): void
    {
        $paid = $this->paidAmount();
        $total = (float) $this->total;
        $updates = ['payment_status' => match (true) {
            $paid >= $total - 0.001 => 'PAID',
            $paid > 0 => 'PARTIALLY_PAID',
            $this->payments()->where('status', 'PENDING')->exists() => 'PENDING',
            default => 'UNPAID',
        }];
        if ($paid >= (float) ($this->deposit_amount ?? $total) - 0.001 && in_array($this->status, ['PENDING', 'AWAITING_PAYMENT', 'PAID'], true)) {
            $updates += ['status' => 'CONFIRMED', 'expires_at' => null];
        }
        $this->update($updates);
    }

    public function posSales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }

    public function cleaningTask(): HasOne
    {
        return $this->hasOne(CleaningTask::class);
    }

    /** POS purchases charged to this stay and not yet settled. */
    public function openCharges(): float
    {
        return round((float) $this->posSales()->where('status', 'UNPAID')->sum('total'), 2);
    }

    /** Arrival and departure times, falling back to the tenant's defaults. */
    /** A hall booking is an event: it runs on its day(s) rather than overnight. */
    public function isEvent(): bool
    {
        return $this->room?->kind === 'venue';
    }

    public function times(?array $settings = null): array
    {
        $settings ??= $this->tenant->settings;
        $event = $this->isEvent();

        return [$this->check_in_time ?: $settings[$event ? 'event_start_time' : 'check_in_time'],
            $this->check_out_time ?: $settings[$event ? 'event_end_time' : 'check_out_time']];
    }

    /**
     * When the booking really holds its unit: a stay from arrival on the check-in day to departure on the
     * check-out day; an event from its start to its end on the event day (the last day for a multi-day event).
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function period(?array $settings = null): array
    {
        [$in, $out] = array_map(fn (string $time) => substr($time, 0, 5), $this->times($settings));
        $last = $this->isEvent() ? $this->check_out->copy()->subDay() : $this->check_out;

        return [CarbonImmutable::parse($this->check_in->toDateString().' '.$in), CarbonImmutable::parse($last->toDateString().' '.$out)];
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function price(): HasOne
    {
        return $this->hasOne(BookingPrice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
