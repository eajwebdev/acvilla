<?php

namespace App\Services;

use App\Models\AddOn;
use App\Models\PromoCode;
use App\Models\Room;
use App\Models\SeasonalRate;
use Carbon\CarbonImmutable;

class PricingService
{
    public function quote(Room $room, string $checkIn, string $checkOut, int $guests = 1, ?PromoCode $promo = null, array $addOnSelection = []): array
    {
        $settings = $room->property->tenant->settings;
        $start = CarbonImmutable::parse($checkIn);
        $end = CarbonImmutable::parse($checkOut);
        if ($start->gte($end) || $start->diffInDays($end) > $room->stayLimits($settings)[1]) {
            throw new \InvalidArgumentException('Invalid stay dates.');
        }
        $seasons = SeasonalRate::forStay($room, $start->toDateString(), $end->toDateString());
        $rates = [];
        $subtotal = 0;
        for ($date = $start; $date->lt($end); $date = $date->addDay()) {
            $weekend = in_array($date->dayOfWeek, $settings['weekend_days'], true);
            $rate = (float) (($weekend && $room->weekend_rate) ? $room->weekend_rate : $room->base_rate);
            $season = $seasons->first(fn (SeasonalRate $season) => $season->covers($date));
            $rate = $season ? $season->apply($rate) : $rate;
            $rates[] = ['date' => $date->toDateString(), 'rate' => $rate, 'season' => $season?->name];
            $subtotal += $rate;
        }
        $nights = count($rates);
        $extraGuests = max(0, $guests - $room->includedGuests());
        $extra = round($extraGuests * (float) $room->extra_guest_fee * $nights, 2);

        // Discounts reduce room charges only; cleaning is never discounted.
        $roomCharges = $subtotal + $extra;
        $discounts = [];
        $stayDiscount = match (true) {
            $nights >= 28 && $settings['monthly_discount_percent'] > 0 => ['Monthly stay', $settings['monthly_discount_percent']],
            $nights >= 7 && $settings['weekly_discount_percent'] > 0 => ['Weekly stay', $settings['weekly_discount_percent']],
            default => null,
        };
        if ($stayDiscount) {
            [$label, $percent] = $stayDiscount;
            $discounts[] = ['label' => $label.' ('.rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.').'%)', 'amount' => round($roomCharges * $percent / 100, 2)];
        }
        if ($promo) {
            $remaining = $roomCharges - array_sum(array_column($discounts, 'amount'));
            $amount = $promo->discount_type === 'percent'
                ? round($remaining * (float) $promo->discount_value / 100, 2)
                : min((float) $promo->discount_value, $remaining);
            $discounts[] = ['label' => $promo->label(), 'amount' => $amount];
        }
        $discount = round(array_sum(array_column($discounts, 'amount')), 2);

        // Extras are never discounted but are part of what fees and tax apply to.
        $addOns = AddOn::priceSelection($room, $addOnSelection, $nights, $guests);
        $addOnsTotal = round(array_sum(array_column($addOns, 'amount')), 2);
        $taxable = $roomCharges - $discount + $addOnsTotal;
        $cleaning = (float) $room->cleaning_fee;
        $service = round($taxable * $settings['service_fee_percent'] / 100, 2);
        $tax = round(($taxable + $service) * $settings['tax_percent'] / 100, 2);

        return ['nightly_rates' => $rates, 'subtotal' => $subtotal, 'extra_guest_fee' => $extra, 'cleaning_fee' => $cleaning,
            'service_fee' => $service, 'tax' => $tax, 'discount' => $discount, 'discounts' => $discounts,
            'add_ons' => $addOns, 'add_ons_total' => $addOnsTotal,
            'total' => round($taxable + $cleaning + $service + $tax, 2)];
    }
}
