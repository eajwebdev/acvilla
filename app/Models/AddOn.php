<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AddOn extends Model
{
    public const PRICING = [
        'per_stay' => 'per stay',
        'per_night' => 'per night',
        'per_guest' => 'per guest',
        'per_guest_night' => 'per guest, per night',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'room_ids' => 'array', 'active' => 'boolean'];
    }

    public static function offeredFor(Room $room): Collection
    {
        return static::where('tenant_id', $room->tenant_id)->where('active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (self $addOn) => $addOn->appliesToRoom($room->id))->values();
    }

    /**
     * Turn a guest's [add-on id => quantity] choice into priced lines, rejecting anything this room doesn't offer.
     *
     * @return array<int, array{id: int, name: string, pricing: string, quantity: int, unit_price: float, amount: float}>
     */
    public static function priceSelection(Room $room, array $selection, int $nights, int $guests): array
    {
        $selection = array_filter(array_map('intval', $selection));
        if (! $selection) {
            return [];
        }
        $offered = static::offeredFor($room)->keyBy('id');
        $lines = [];
        foreach ($selection as $id => $quantity) {
            $addOn = $offered->get((int) $id);
            if (! $addOn || $quantity < 1 || $quantity > $addOn->max_quantity) {
                throw ValidationException::withMessages(['add_ons' => $addOn
                    ? "Choose up to {$addOn->max_quantity} of {$addOn->name}."
                    : 'One of the extras you picked is no longer available.']);
            }
            $lines[] = ['id' => $addOn->id, 'name' => $addOn->name, 'pricing' => $addOn->pricing, 'quantity' => $quantity,
                'unit_price' => (float) $addOn->price, 'amount' => round($addOn->amountFor($quantity, $nights, $guests), 2)];
        }

        return $lines;
    }

    public function appliesToRoom(int $roomId): bool
    {
        return empty($this->room_ids) || in_array($roomId, $this->room_ids, true);
    }

    public function amountFor(int $quantity, int $nights, int $guests): float
    {
        return (float) $this->price * $quantity
            * (str_contains($this->pricing, 'night') ? $nights : 1)
            * (str_contains($this->pricing, 'guest') ? $guests : 1);
    }
}
