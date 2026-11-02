<?php

namespace App\Services;

use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Room;
use App\Models\SeasonalRate;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AvailabilityService
{
    /** Guests can arrive, leave, or hold an event between these times. */
    public const DAY_START = '06:00';

    public const DAY_END = '22:00';

    /** An event lasts at least this long. */
    public const MIN_EVENT_MINUTES = 60;

    /**
     * Whether the unit is free for these dates and has usable time next to the guests before and after.
     * With times, also whether those exact times fit (arrival/start and departure/end, H:i).
     */
    public function available(Room $room, string $checkIn, string $checkOut, ?string $startTime = null, ?string $endTime = null): bool
    {
        $tenant = $room->property->tenant;
        if ($checkIn >= $checkOut || $this->stayLengthError($room, $checkIn, $checkOut) || ! $room->active || ! $tenant->acceptsBookings()) {
            return false;
        }
        if (! $this->rangeFree($room, $checkIn, $checkOut) || ! $this->timeWindow($room, $checkIn, $checkOut)['fits']) {
            return false;
        }

        return $startTime === null || $endTime === null || $this->timesError($room, $checkIn, $checkOut, $startTime, $endTime) === null;
    }

    /**
     * The hours a booking of $room on these dates can use without touching the bookings next to it: the earliest
     * arrival (or event start) on the first day and the latest departure (or event end) on the last day, keeping
     * the cleaning gap. Nights are checked by rangeFree(); this covers the same-day changeovers, such as an event
     * in the hall on the morning the whole-resort guests leave at noon.
     *
     * @return array{event: bool, earliest: string, latest: string, default_start: string, default_end: string, fits: bool,
     *     after: ?array{guest: string, room: ?string, time: string}, before: ?array{guest: string, room: ?string, time: string}}
     */
    public function timeWindow(Room $room, string $checkIn, string $checkOut, ?int $ignoreBookingId = null, ?int $gap = null, bool $fromNow = false): array
    {
        $settings = $room->property->tenant->settings;
        $event = $room->kind === 'venue';
        $first = $checkIn;
        $last = $event ? CarbonImmutable::parse($checkOut)->subDay()->toDateString() : $checkOut;
        $gap ??= (int) ($settings['turnover_minutes'] ?? 0);
        $earliest = self::DAY_START;
        $latest = self::DAY_END;
        $after = $before = null;
        $passed = false;
        // A guest booking for today can only start from the next half hour; times earlier today have gone.
        if ($fromNow && $checkIn === today()->toDateString()) {
            $now = now()->addMinutes(1);
            $earliest = max($earliest, $now->toDateString() === $checkIn ? self::roundUp($now->format('H:i')) : '24:00');
            $passed = $earliest > self::DAY_START;
        }
        $others = $this->holding(Booking::with('room:id,name,kind')->whereIn('room_id', $this->sharing($room))
            ->when($ignoreBookingId, fn ($q) => $q->whereKeyNot($ignoreBookingId)),
            CarbonImmutable::parse($first)->subDay()->toDateString(), CarbonImmutable::parse($last)->addDays(2)->toDateString())->get();
        foreach ($others as $other) {
            [$start, $end] = $other->period($settings);
            if ($end->toDateString() === $first) {
                $free = $end->addMinutes($gap);
                $time = $free->toDateString() === $first ? self::roundUp($free->format('H:i')) : '24:00';
                if ($time > $earliest) {
                    [$earliest, $after, $passed] = [$time, ['guest' => $other->guest_name, 'room' => $other->room?->name, 'time' => $end->format('H:i')], false];
                }
            }
            if ($start->toDateString() === $last) {
                $free = $start->subMinutes($gap);
                $time = $free->toDateString() === $last ? self::roundDown($free->format('H:i')) : '00:00';
                if ($time < $latest) {
                    [$latest, $before] = [$time, ['guest' => $other->guest_name, 'room' => $other->room?->name, 'time' => $start->format('H:i')]];
                }
            }
        }
        $standardStart = substr($settings[$event ? 'event_start_time' : 'check_in_time'], 0, 5);
        $standardEnd = substr($settings[$event ? 'event_end_time' : 'check_out_time'], 0, 5);
        $sameDay = $first === $last;
        $fits = $earliest <= self::DAY_END && $latest >= self::DAY_START
            && (! $sameDay || self::minutes($latest) - self::minutes($earliest) >= self::MIN_EVENT_MINUTES);
        $start = max($standardStart, $earliest);
        $end = min($standardEnd, $latest);
        if ($sameDay && self::minutes($end) - self::minutes($start) < self::MIN_EVENT_MINUTES) {
            $end = $latest;
        }

        return ['event' => $event, 'earliest' => $earliest, 'latest' => $latest, 'default_start' => min($start, self::DAY_END),
            'default_end' => max($end, self::DAY_START), 'fits' => $fits, 'after' => $after, 'before' => $before, 'passed' => $passed];
    }

    /** Why these exact times can't be used, or null when they fit. */
    public function timesError(Room $room, string $checkIn, string $checkOut, string $startTime, string $endTime, ?int $ignoreBookingId = null, ?int $gap = null, bool $fromNow = false): ?string
    {
        [$startTime, $endTime] = [substr($startTime, 0, 5), substr($endTime, 0, 5)];
        $window = $this->timeWindow($room, $checkIn, $checkOut, $ignoreBookingId, $gap, $fromNow);
        $label = fn (string $time) => CarbonImmutable::parse($time === '24:00' ? '23:59' : $time)->format('g:i A');
        $event = $window['event'];
        if ($startTime < $window['earliest'] && $window['passed']) {
            return "That time has already passed today. {$room->name} can ".($event ? 'start' : 'take arrivals')." from {$label($window['earliest'])}.";
        }
        if ($startTime < $window['earliest']) {
            $why = $window['after'] ? " ({$window['after']['room']} guests leave at {$label($window['after']['time'])} that day)" : '';

            return "{$room->name} is free from {$label($window['earliest'])}{$why}, so ".($event ? 'the event can start' : 'arrival can be')." from then.";
        }
        if ($endTime > $window['latest']) {
            $why = $window['before'] ? " ({$window['before']['room']} guests arrive at {$label($window['before']['time'])} that day)" : '';

            return "{$room->name} must be free by {$label($window['latest'])}{$why}, so ".($event ? 'the event must end' : 'check-out must be')." by then.";
        }
        if ($event && $checkIn === CarbonImmutable::parse($checkOut)->subDay()->toDateString() && self::minutes($endTime) - self::minutes($startTime) < self::MIN_EVENT_MINUTES) {
            return 'The event must end at least an hour after it starts.';
        }

        return null;
    }

    /** Units whose bookings can clash with $room on the same day: itself (when there is only one) and the whole-resort package, or every unit for the package. */
    private function sharing(Room $room): Collection
    {
        return Room::where('property_id', $room->property_id)
            ->when(! $room->whole_property, fn ($rooms) => $rooms->where(fn ($q) => $q->where('whole_property', true)
                ->when($room->quantity <= 1, fn ($own) => $own->orWhere('id', $room->id))))
            ->pluck('id');
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    private static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Times are offered in half hours. */
    private static function roundUp(string $time): string
    {
        return self::clock((int) ceil(self::minutes($time) / 30) * 30);
    }

    private static function roundDown(string $time): string
    {
        return self::clock(intdiv(self::minutes($time), 30) * 30);
    }

    /**
     * Whether every night from $checkIn to $checkOut is free of blocks and other bookings, without the
     * guest-facing stay-length rules. Used to extend a stay that is already on the books.
     */
    public function rangeFree(Room $room, string $checkIn, string $checkOut): bool
    {
        $start = CarbonImmutable::parse($checkIn);
        $end = CarbonImmutable::parse($checkOut);
        if ($checkIn >= $checkOut) {
            return false;
        }
        $blocked = BlockedDate::where('room_id', $room->id)
            ->whereDate('starts_on', '<', $checkOut)->whereDate('ends_on', '>', $checkIn)->exists();
        if ($blocked || $this->wholePropertyConflict($room, $checkIn, $checkOut)) {
            return false;
        }
        $bookings = $this->holding(Booking::where('room_id', $room->id), $checkIn, $checkOut)->get(['check_in', 'check_out']);
        for ($night = $start; $night->lt($end); $night = $night->addDay()) {
            $date = $night->toDateString();
            $occupied = $bookings->filter(fn (Booking $booking) => $booking->check_in->toDateString() <= $date && $booking->check_out->toDateString() > $date)->count();
            if ($occupied >= $room->quantity) {
                return false;
            }
        }

        return true;
    }

    /**
     * A whole-property unit needs every other unit of the property free, and any other unit needs
     * the whole-property units free: the whole-resort package and a single A-House can't overlap.
     */
    private function wholePropertyConflict(Room $room, string $checkIn, string $checkOut): bool
    {
        $others = Room::where('property_id', $room->property_id)->whereKeyNot($room->id)
            ->when(! $room->whole_property, fn ($rooms) => $rooms->where('whole_property', true))->pluck('id');
        if ($others->isEmpty()) {
            return false;
        }

        return BlockedDate::whereIn('room_id', $others)->whereDate('starts_on', '<', $checkOut)->whereDate('ends_on', '>', $checkIn)->exists()
            || $this->holding(Booking::whereIn('room_id', $others), $checkIn, $checkOut)->exists();
    }

    /**
     * For the booking calendar: each unit's nights from $from to $to that can't be booked (booked, held or blocked,
     * or taken by the whole-resort package / taking it). Same rules as available(), read in a few queries.
     *
     * @return array<int, list<string>> room id => unavailable nights (Y-m-d)
     */
    public function unavailableNights(Collection $rooms, string $from, string $to): array
    {
        $ids = $rooms->pluck('id');
        $bookings = $this->holding(Booking::whereIn('room_id', $ids), $from, $to)->get(['room_id', 'check_in', 'check_out']);
        $blocks = BlockedDate::whereIn('room_id', $ids)->whereDate('starts_on', '<', $to)->whereDate('ends_on', '>', $from)->get(['room_id', 'starts_on', 'ends_on']);
        $taken = []; // room id => night => units in use
        $mark = function (int $roomId, $start, $end, int $units) use (&$taken, $from, $to) {
            for ($night = CarbonImmutable::parse(max($start->toDateString(), $from)); $night->toDateString() < min($end->toDateString(), $to); $night = $night->addDay()) {
                $taken[$roomId][$night->toDateString()] = ($taken[$roomId][$night->toDateString()] ?? 0) + $units;
            }
        };
        foreach ($bookings as $booking) {
            $mark($booking->room_id, $booking->check_in, $booking->check_out, 1);
        }
        foreach ($blocks as $block) {
            $mark($block->room_id, $block->starts_on, $block->ends_on, PHP_INT_MAX >> 1);
        }
        $full = fn (Room $room, string $night) => ($taken[$room->id][$night] ?? 0) >= max(1, $room->quantity);
        $nights = [];
        for ($night = CarbonImmutable::parse($from); $night->toDateString() < $to; $night = $night->addDay()) {
            $nights[] = $night->toDateString();
        }
        $result = [];
        foreach ($rooms as $room) {
            $result[$room->id] = array_values(array_filter($nights, function (string $night) use ($room, $rooms, $taken, $full) {
                if ($full($room, $night)) {
                    return true;
                }
                // The whole-resort package needs every other unit free that night, and takes all of them.
                $others = $rooms->where('property_id', $room->property_id)->where('id', '!=', $room->id)->when(! $room->whole_property, fn ($all) => $all->where('whole_property', true));

                return $others->contains(fn (Room $other) => ($taken[$other->id][$night] ?? 0) > 0);
            }));
        }

        return $result;
    }

    /**
     * A same-day turnover clash: a later check-out than the next guest's check-in on that day, or an
     * earlier check-in than the previous guest's check-out, in this unit or a whole-resort booking it shares.
     */
    public function turnoverConflict(Booking $booking, string $checkInTime, string $checkOutTime): ?string
    {
        // The owner can set tight changeovers: only a real overlap counts here, without the cleaning gap.
        $window = $this->timeWindow($booking->room, $booking->check_in->toDateString(), $booking->check_out->toDateString(), $booking->id, 0);
        $label = fn (string $time) => CarbonImmutable::parse($time)->format('g:i A');
        if (substr($checkOutTime, 0, 5) > $window['latest'] && $window['before']) {
            return "{$window['before']['guest']} arrives in {$window['before']['room']} at {$label($window['before']['time'])} on that day, so check-out must be by then.";
        }
        if (substr($checkInTime, 0, 5) < $window['earliest'] && $window['after']) {
            return "{$window['after']['guest']} leaves {$window['after']['room']} at {$label($window['after']['time'])} on that day, so check-in can't be earlier.";
        }

        return null;
    }

    /** Bookings overlapping the stay that hold their room: occupying ones and unexpired holds. */
    private function holding($query, string $checkIn, string $checkOut)
    {
        return $query->whereDate('check_in', '<', $checkOut)->whereDate('check_out', '>', $checkIn)
            ->where(fn ($q) => $q->whereIn('status', Booking::OCCUPYING)
                ->orWhere(fn ($hold) => $hold->whereIn('status', ['PENDING', 'AWAITING_PAYMENT'])->where('expires_at', '>', now()))
                // A downpayment receipt waiting for the owner's check keeps the dates, even past the hold time.
                ->orWhere(fn ($proof) => $proof->whereIn('status', ['PENDING', 'AWAITING_PAYMENT'])->whereHas('payments', fn ($p) => $p->where('status', 'PENDING'))));
    }

    public function stayLengthError(Room $room, string $checkIn, string $checkOut): ?string
    {
        $nights = $this->nights($checkIn, $checkOut);
        if ($error = $this->lengthMessage($nights, ...$room->stayLimits())) {
            return $error;
        }
        $season = SeasonalRate::forStay($room, $checkIn, $checkOut)
            ->filter(fn (SeasonalRate $season) => $season->min_nights > $nights)->sortByDesc('min_nights')->first();

        return $season ? "Stays during {$season->name} need at least {$season->min_nights} nights." : null;
    }

    /** For catalog search: only an error when no active room of the tenant accepts this many nights. */
    public function searchLengthError(Tenant $tenant, string $checkIn, string $checkOut): ?string
    {
        $nights = $this->nights($checkIn, $checkOut);
        $limits = Room::where('tenant_id', $tenant->id)->where('active', true)->get()
            ->map(fn (Room $room) => $room->stayLimits($tenant->settings));
        if ($limits->contains(fn (array $limit) => ! $this->lengthMessage($nights, ...$limit))) {
            return null;
        }

        return $this->lengthMessage($nights, $limits->min(0) ?? $tenant->setting('min_nights'), $limits->max(1) ?? $tenant->setting('max_nights'));
    }

    private function nights(string $checkIn, string $checkOut): int
    {
        return (int) CarbonImmutable::parse($checkIn)->diffInDays(CarbonImmutable::parse($checkOut));
    }

    private function lengthMessage(int $nights, int $min, int $max): ?string
    {
        return match (true) {
            $nights < $min => "Stays require at least {$min} ".str('night')->plural($min).'.',
            $nights > $max => "Stays are limited to {$max} ".str('night')->plural($max).'.',
            default => null,
        };
    }

    /** Guest-facing limits on how soon and how far ahead a stay can start. Staff bookings skip these. */
    public function bookingWindowError(Tenant $tenant, string $checkIn): ?string
    {
        $start = CarbonImmutable::parse($checkIn)->startOfDay();
        ['advance_notice_days' => $notice, 'booking_window_days' => $window] = $tenant->settings;
        if ($start->lt(today()->addDays($notice))) {
            return $notice === 0 ? 'Choose a check-in date from today onward.'
                : "Bookings need at least {$notice} ".str('day')->plural($notice).' notice.';
        }
        if ($start->gt(today()->addDays($window))) {
            return "Bookings open up to {$window} days ahead.";
        }

        return null;
    }
}
