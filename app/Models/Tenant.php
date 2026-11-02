<?php

namespace App\Models;

use App\Services\SiteContent;
use App\Services\TenantSettings;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $guarded = ['id'];

    // Only guest pages need the website content; PublicController makes it visible there.
    protected $hidden = ['site_content'];

    protected function casts(): array
    {
        return ['subscription_started_at' => 'datetime', 'subscription_ends_at' => 'datetime', 'trial_ends_at' => 'datetime', 'locked_at' => 'datetime'];
    }

    /** Always returns every setting, with defaults filled in for keys the tenant never saved. */
    protected function settings(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => TenantSettings::resolve($value ? json_decode($value, true) : null),
            set: fn (array $value) => json_encode(TenantSettings::resolve($value)),
        );
    }

    /** The guest website's editable copy, photos and options, with defaults for anything never saved. */
    protected function siteContent(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => SiteContent::resolve($value ? json_decode($value, true) : null),
            set: fn (array $value) => json_encode(SiteContent::resolve($value)),
        );
    }

    public function setting(string $key): mixed
    {
        return $this->settings[$key];
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function acceptsBookings(): bool
    {
        return $this->status === 'ACTIVE' && $this->locked_at === null &&
            in_array($this->subscription_status, ['ACTIVE', 'TRIAL'], true) &&
            ($this->subscription_status !== 'ACTIVE' || ! $this->subscription_started_at?->isFuture()) &&
            ($this->subscription_status === 'TRIAL' ? $this->trial_ends_at?->isFuture() : $this->subscription_ends_at?->isFuture());
    }
}
