<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class SeasonalRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'adjustment_value' => 'decimal:2', 'room_ids' => 'array', 'active' => 'boolean'];
    }

    /** Active seasons for this room touching any night from $start up to (not including) $end, most specific first. */
    public static function forStay(Room $room, string $start, string $end): Collection
    {
        return static::where('tenant_id', $room->tenant_id)->where('active', true)
            ->whereDate('starts_on', '<', $end)->whereDate('ends_on', '>=', $start)
            ->get()
            ->filter(fn (self $season) => $season->appliesToRoom($room->id))
            ->sortBy([fn (self $a, self $b) => $a->lengthInDays() <=> $b->lengthInDays(), fn (self $a, self $b) => $b->id <=> $a->id])
            ->values();
    }

    public function appliesToRoom(int $roomId): bool
    {
        return empty($this->room_ids) || in_array($roomId, $this->room_ids, true);
    }

    public function covers(CarbonImmutable $night): bool
    {
        return $night->betweenIncluded($this->starts_on->startOfDay(), $this->ends_on->startOfDay());
    }

    public function lengthInDays(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on);
    }

    public function apply(float $rate): float
    {
        return $this->adjustment_type === 'fixed'
            ? (float) $this->adjustment_value
            : round(max(0, $rate * (1 + (float) $this->adjustment_value / 100)), 2);
    }
}
