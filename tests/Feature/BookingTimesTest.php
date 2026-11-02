<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Same-day changeovers: a booking holds its unit from its real start to its real end, with a cleaning gap between guests. */
class BookingTimesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Room $house;

    private Room $hall;

    private Room $resort;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->tenant = Tenant::create(['name' => 'AC Villa', 'slug' => 'ac-villa', 'business_name' => 'AC Villa & Private Resort',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $this->tenant->update(['settings' => [...$this->tenant->settings, 'check_in_time' => '14:00', 'check_out_time' => '12:00',
            'event_start_time' => '08:00', 'event_end_time' => '17:00', 'turnover_minutes' => 120, 'service_fee_percent' => 0]]);
        $property = Property::create(['tenant_id' => $this->tenant->id, 'name' => 'AC Villa', 'slug' => 'ac-villa', 'city' => 'Mabinay', 'province' => 'Negros Oriental', 'status' => 'PUBLISHED']);
        $unit = fn (string $slug, string $name, int $rate, int $max, array $extra = []) => Room::create(['tenant_id' => $this->tenant->id, 'property_id' => $property->id,
            'name' => $name, 'slug' => $slug, 'quantity' => 1, 'maximum_occupancy' => $max, 'base_guests' => $max, 'base_rate' => $rate, 'cleaning_fee' => 0, 'active' => true, ...$extra]);
        $this->house = $unit('ah-room-1', 'AH Room 1', 3500, 4);
        $this->hall = $unit('function-hall', 'Function Hall', 7000, 50, ['kind' => 'venue']);
        $this->resort = $unit('whole-resort', 'Whole Resort', 38000, 60, ['whole_property' => true]);
    }

    private function day(int $days): string
    {
        return today()->addDays($days)->toDateString();
    }

    private function approved(Room $room, int $from, int $to, array $extra = []): Booking
    {
        return Booking::create(['tenant_id' => $this->tenant->id, 'property_id' => $room->property_id, 'room_id' => $room->id, 'guest_name' => 'Earlier Guest',
            'check_in' => $this->day($from), 'check_out' => $this->day($to), 'guests' => 2, 'reference' => 'ACV-'.$room->id.'-'.$from, 'status' => 'CONFIRMED', 'total' => 1, ...$extra]);
    }

    private function request(Room $room, int $from, int $to, array $times = [])
    {
        return $this->post('/bookings', ['items' => [['room_id' => $room->id, 'check_in' => $this->day($from), 'check_out' => $this->day($to), 'guests' => 2, ...$times]],
            'guest_name' => 'Jochel Agnes', 'guest_phone' => '0906 308 4301', 'guest_email' => 'jochel@example.com']);
    }

    private function batch(Room $room, int $from, int $to): array
    {
        return $this->getJson('/availability/batch?'.http_build_query(['items' => [['room_id' => $room->id, 'check_in' => $this->day($from), 'check_out' => $this->day($to), 'guests' => 2]]]))
            ->assertOk()->json(0);
    }

    public function test_an_event_on_the_morning_the_whole_resort_guests_leave_starts_after_they_go(): void
    {
        // Whole resort for the night of day 6, out at 12:00 NN on day 7.
        $this->approved($this->resort, 6, 7);

        $check = $this->batch($this->hall, 7, 8);
        $this->assertTrue($check['available']);
        // Free from 2:00 PM: noon check-out plus the two-hour cleaning gap. The standard 8:00 AM start moves there.
        $this->assertSame(['14:00', '22:00', '14:00', '17:00'], [$check['times']['earliest'], $check['times']['latest'], $check['times']['default_start'], $check['times']['default_end']]);
        $this->assertSame(['room' => 'Whole Resort', 'time' => '12:00'], $check['times']['after']);

        $this->request($this->hall, 7, 8, ['check_in_time' => '08:00', 'check_out_time' => '17:00'])
            ->assertSessionHasErrors(['items' => 'Function Hall is free from 2:00 PM (Whole Resort guests leave at 12:00 PM that day), so the event can start from then.']);
        $this->request($this->hall, 7, 8, ['check_in_time' => '13:30', 'check_out_time' => '20:00'])->assertSessionHasErrors('items');
        $this->assertSame(1, Booking::count());

        $this->request($this->hall, 7, 8, ['check_in_time' => '14:00', 'check_out_time' => '21:00'])->assertSessionHasNoErrors();
        $event = Booking::where('room_id', $this->hall->id)->sole();
        $this->assertSame(['14:00', '21:00'], array_map(fn ($t) => substr($t, 0, 5), $event->times()));
    }

    public function test_without_chosen_times_the_booking_takes_the_first_free_hours(): void
    {
        $this->approved($this->resort, 6, 7);
        $this->request($this->hall, 7, 8)->assertSessionHasNoErrors();

        $event = Booking::where('room_id', $this->hall->id)->sole();
        $this->assertSame(['14:00', '17:00'], array_map(fn ($t) => substr($t, 0, 5), $event->times()));
    }

    public function test_the_whole_resort_must_leave_before_a_morning_event_on_its_check_out_day(): void
    {
        // An 8:00 AM event in the hall on day 7.
        $this->approved($this->hall, 7, 8);

        $check = $this->batch($this->resort, 6, 7);
        $this->assertTrue($check['available']);
        $this->assertSame('06:00', $check['times']['latest']);
        $this->assertSame(['room' => 'Function Hall', 'time' => '08:00'], $check['times']['before']);
        $this->request($this->resort, 6, 7, ['check_in_time' => '14:00', 'check_out_time' => '12:00'])
            ->assertSessionHasErrors(['items' => 'Whole Resort must be free by 6:00 AM (Function Hall guests arrive at 8:00 AM that day), so check-out must be by then.']);
        $this->request($this->resort, 6, 7, ['check_in_time' => '14:00', 'check_out_time' => '06:00'])->assertSessionHasNoErrors();
    }

    public function test_a_room_keeps_the_cleaning_gap_between_guests(): void
    {
        $this->approved($this->house, 4, 5, ['check_out_time' => '13:00']);

        $this->assertSame('15:00', $this->batch($this->house, 5, 6)['times']['earliest']);
        $this->request($this->house, 5, 6, ['check_in_time' => '14:00', 'check_out_time' => '12:00'])->assertSessionHasErrors('items');
        $this->request($this->house, 5, 6, ['check_in_time' => '15:00', 'check_out_time' => '12:00'])->assertSessionHasNoErrors();
        // Other A-Houses are not affected by AH Room 1's late check-out.
        $this->assertSame('06:00', app(AvailabilityService::class)->timeWindow($this->hall, $this->day(5), $this->day(6))['earliest']);
    }

    public function test_a_day_with_no_usable_time_left_is_not_offered(): void
    {
        // The whole resort leaves late at 9:30 PM: with cleaning, nothing is left for an event that day.
        $this->approved($this->resort, 6, 7, ['check_out_time' => '21:30']);

        $check = $this->batch($this->hall, 7, 8);
        $this->assertFalse($check['available']);
        $this->assertSame('No free time left that day', $check['message']);
        $this->request($this->hall, 7, 8)->assertSessionHasErrors();
        $this->assertFalse(app(AvailabilityService::class)->available($this->hall, $this->day(7), $this->day(8)));
    }

    public function test_an_event_must_last_at_least_an_hour_and_end_after_it_starts(): void
    {
        $this->request($this->hall, 9, 10, ['check_in_time' => '15:00', 'check_out_time' => '15:30'])
            ->assertSessionHasErrors(['items' => 'The event must end at least an hour after it starts.']);
        $this->request($this->hall, 9, 10, ['check_in_time' => '18:00', 'check_out_time' => '10:00'])->assertSessionHasErrors('items');
        $this->request($this->hall, 9, 10, ['check_in_time' => '05:00', 'check_out_time' => '10:00'])->assertSessionHasErrors('items');
        $this->request($this->hall, 9, 10, ['check_in_time' => '07:00', 'check_out_time' => '23:00'])->assertSessionHasErrors('items');
        $this->assertSame(0, Booking::count());
    }

    public function test_a_booking_for_today_cannot_start_at_a_time_that_has_passed(): void
    {
        $this->travelTo(today()->setTime(15, 10));

        $check = $this->batch($this->house, 0, 1);
        $this->assertTrue($check['available']);
        $this->assertSame(['15:30', '15:30', true], [$check['times']['earliest'], $check['times']['default_start'], $check['times']['passed']]);
        $this->request($this->house, 0, 1, ['check_in_time' => '14:00', 'check_out_time' => '12:00'])
            ->assertSessionHasErrors(['items' => 'That time has already passed today. AH Room 1 can take arrivals from 3:30 PM.']);
        $this->request($this->house, -1, 1)->assertSessionHasErrors('items.0.check_in');

        $this->request($this->house, 0, 1)->assertSessionHasNoErrors();
        $this->assertSame('15:30', substr(Booking::sole()->check_in_time, 0, 5));
        // Tomorrow is not affected by the time now.
        $this->assertSame('06:00', $this->batch($this->hall, 1, 2)['times']['earliest']);
    }

    public function test_late_at_night_today_is_no_longer_offered_but_the_front_desk_can_record_a_walk_in(): void
    {
        $this->travelTo(today()->setTime(22, 10));

        $check = $this->batch($this->hall, 0, 1);
        $this->assertFalse($check['available']);
        $this->assertSame('No time left today: choose a later date', $check['message']);
        $this->request($this->hall, 0, 1)->assertSessionHasErrors();
        $this->assertSame(0, Booking::count());

        $walkIn = app(\App\Services\BookingService::class)->create($this->house, ['guest_name' => 'Walk-in Guest', 'guests' => 2, 'source' => 'walk_in',
            'check_in' => $this->day(0), 'check_out' => $this->day(1)]);
        $this->assertSame($this->day(0), $walkIn->check_in->toDateString());
    }

    public function test_standard_times_are_not_stored_so_changing_the_settings_still_applies(): void
    {
        $this->request($this->house, 3, 4, ['check_in_time' => '14:00', 'check_out_time' => '12:00'])->assertSessionHasNoErrors();
        $booking = Booking::sole();
        $this->assertNull($booking->check_in_time);
        $this->assertNull($booking->check_out_time);
    }
}
