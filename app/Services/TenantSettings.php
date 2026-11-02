<?php

namespace App\Services;

use Illuminate\Validation\Rule;

class TenantSettings
{
    public const CURRENCIES = ['PHP', 'USD', 'EUR', 'GBP', 'JPY', 'KRW', 'SGD', 'HKD', 'AUD', 'CAD', 'AED'];

    /** Methods a guest pays outside the app and an admin then verifies. */
    public const PAYMENT_METHODS = ['gcash' => 'GCash / InstaPay', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer', 'cash' => 'Cash on arrival'];

    public static function defaults(): array
    {
        return config('platform.tenant_defaults');
    }

    /** Validation rules for each settings section, keyed by section slug. */
    public static function sections(): array
    {
        return [
            'general' => [
                'currency' => ['required', 'in:'.implode(',', self::CURRENCIES)],
                'reference_prefix' => ['required', 'regex:/^[A-Z0-9]{2,8}$/'],
                'check_in_time' => ['required', 'date_format:H:i'],
                'check_out_time' => ['required', 'date_format:H:i'],
                'event_start_time' => ['sometimes', 'required', 'date_format:H:i'],
                'event_end_time' => ['sometimes', 'required', 'date_format:H:i', 'after:event_start_time'],
                'turnover_minutes' => ['sometimes', 'required', 'integer', 'min:0', 'max:720'],
            ],
            'booking' => [
                'min_nights' => ['required', 'integer', 'min:1', 'max:365'],
                'max_nights' => ['required', 'integer', 'min:1', 'max:365', 'gte:min_nights'],
                'advance_notice_days' => ['required', 'integer', 'min:0', 'max:365'],
                'booking_window_days' => ['required', 'integer', 'min:1', 'max:1095', 'gt:advance_notice_days'],
                'hold_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
                'manual_payment_hours' => ['required', 'integer', 'min:1', 'max:168'],
                'request_hold_hours' => ['required', 'integer', 'min:1', 'max:168'],
            ],
            'pricing' => [
                'service_fee_percent' => ['required', 'numeric', 'min:0', 'max:30'],
                'tax_label' => ['required', 'string', 'max:30'],
                'tax_percent' => ['required', 'numeric', 'min:0', 'max:30'],
                'weekly_discount_percent' => ['required', 'numeric', 'min:0', 'max:90'],
                'monthly_discount_percent' => ['required', 'numeric', 'min:0', 'max:90'],
                'weekend_days' => ['present', 'array', 'max:7'],
                'weekend_days.*' => ['integer', 'distinct', 'between:0,6'],
            ],
            'policies' => [
                'house_rules' => ['nullable', 'string', 'max:3000'],
                'cancellation_policy' => ['nullable', 'string', 'max:3000'],
                'privacy_policy' => ['nullable', 'string', 'max:20000'],
                'terms_of_service' => ['nullable', 'string', 'max:20000'],
            ],
            'payments' => [
                'test_payments' => ['required', 'boolean'],
                'deposit_percent' => ['required', 'integer', 'min:1', 'max:100'],
                'balance_due_days' => ['required', 'integer', 'min:0', 'max:90'],
                'payment_methods' => ['required', 'array:'.implode(',', array_keys(self::PAYMENT_METHODS))],
                ...collect(self::PAYMENT_METHODS)->keys()->flatMap(fn ($m) => [
                    "payment_methods.{$m}.enabled" => ['required', 'boolean'],
                    "payment_methods.{$m}.account_name" => ['nullable', 'string', 'max:120'],
                    // A QR code is enough to pay by; without one the guest needs a number to send to.
                    "payment_methods.{$m}.account_number" => $m === 'cash' ? ['nullable', 'string', 'max:60']
                        : ['nullable', Rule::requiredIf(fn () => filter_var(request()->input("payment_methods.{$m}.enabled"), FILTER_VALIDATE_BOOLEAN)
                            && blank(request()->input("payment_methods.{$m}.qr_image"))), 'string', 'max:60'],
                    "payment_methods.{$m}.instructions" => ['nullable', 'string', 'max:1000'],
                    // An uploaded QR (/uploads/site/…), one shipped with the site (/images/…) or a link to one.
                    "payment_methods.{$m}.qr_image" => ['nullable', 'string', 'max:2048', 'regex:#^(https?://|/uploads/|/images/)#'],
                ])->all(),
            ],
            'amenities' => [
                'amenities' => ['present', 'array', 'max:60'],
                'amenities.*' => ['required', 'string', 'max:40', 'distinct:ignore_case'],
            ],
            'targets' => [
                'monthly_revenue_target' => ['required', 'integer', 'min:0', 'max:1000000000'],
            ],
            'cleaning' => [
                'cleaning_checklist' => ['present', 'array', 'max:40'],
                'cleaning_checklist.*' => ['required', 'string', 'max:80', 'distinct:ignore_case'],
                'cleaning_photo_required' => ['sometimes', 'boolean'],
            ],
        ];
    }

    /** Merge stored values over defaults, dropping unknown keys and fixing types. */
    public static function resolve(?array $stored): array
    {
        $defaults = self::defaults();
        $settings = array_intersect_key(array_merge($defaults, $stored ?? []), $defaults);
        foreach ($defaults as $key => $default) {
            $settings[$key] = match (true) {
                str_ends_with($key, '_percent') => (float) $settings[$key],
                is_bool($default) => filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN),
                is_array($default) && ! array_is_list($default) => self::resolveMap($default, (array) $settings[$key]),
                is_int($default) => (int) $settings[$key],
                is_array($default) && is_int($default[0] ?? null) => array_values(array_map('intval', (array) $settings[$key])),
                is_array($default) => array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $settings[$key]), 'strlen')),
                default => (string) $settings[$key],
            };
        }

        return $settings;
    }

    /** Nested config such as payment_methods: keep known keys only, typed like their defaults. */
    private static function resolveMap(array $defaults, array $stored): array
    {
        $out = [];
        foreach ($defaults as $key => $default) {
            $value = $stored[$key] ?? $default;
            $out[$key] = match (true) {
                is_array($default) => self::resolveMap($default, (array) $value),
                is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => trim((string) $value),
            };
        }

        return $out;
    }
}
