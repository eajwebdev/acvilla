<?php

namespace Tests\Feature;

use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * What a customer can and cannot book: one facility, several at once, and never a date that is already taken,
 * whether by another customer's request, an approved booking, the whole-resort package or a date the owner blocked.
 * The calendar shows those dates as fully booked, and the server refuses them even if the page is bypassed.
 */
class CustomerBookingTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private Room $house1;

    private Room $house2;

    private Room $hall;

    private Room $grandHall;

    private Room $resort;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $tenant = Tenant::create(['name' => 'AC Villa', 'slug' => 'ac-villa', 'business_name' => 'AC Villa & Private Resort', 'business_phone' => '0917 000 0000',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $tenant->update(['settings' => [...$tenant->settings, 'deposit_percent' => 50, 'service_fee_percent' => 0]]);
        $this->property = Property::create(['tenant_id' => $tenant->id, 'name' => 'AC Villa', 'slug' => 'ac-villa', 'city' => 'Mabinay', 'province' => 'Negros Oriental', 'status' => 'PUBLISHED']);
        $unit = fn (string $slug, string $name, int $rate, int $max, array $extra = []) => Room::create(['tenant_id' => $tenant->id, 'property_id' => $this->property->id,
            'name' => $name, 'slug' => $slug, 'quantity' => 1, 'maximum_occupancy' => $max, 'base_guests' => $max, 'base_rate' => $rate, 'cleaning_fee' => 0, 'active' => true, ...$extra]);
        $this->house1 = $unit('ah-room-1', 'AH Room 1', 3500, 15);
        $this->house2 = $unit('ah-room-2', 'AH Room 2', 4999, 15);
        $this->hall = $unit('function-hall', 'Function Hall', 7000, 50, ['kind' => 'venue']);
        $this->grandHall = $unit('grand-hall', 'Grand Hall', 15000, 200, ['kind' => 'venue']);
        $this->resort = $unit('whole-resort', 'Whole Resort', 38000, 60, ['whole_property' => true]);
    }

    private function day(int $days): string
    {
        return today()->addDays($days)->toDateString();
    }

    private function item(Room $room, int $from, int $to, int $guests = 4): array
    {
        return ['room_id' => $room->id, 'check_in' => $this->day($from), 'check_out' => $this->day($to), 'guests' => $guests];
    }

    /** A customer sends a request from the booking page (each customer has their own browser and phone number). */
    private function book(array $items, string $phone = '0917 123 4567', string $name = 'Maria Santos')
    {
        $this->flushSession();

        return $this->post('/bookings', ['items' => $items, 'guest_name' => $name, 'guest_phone' => $phone, 'guest_email' => 'guest@example.com']);
    }

    /** What the booking page's calendar strikes out for one facility. */
    private function bookedNights(Room $room, int $from = 0, int $to = 30): array
    {
        return $this->getJson('/availability/calendar?'.http_build_query(['property' => 'ac-villa', 'from' => $this->day($from), 'to' => $this->day($to)]))
            ->assertOk()->json("rooms.{$room->id}");
    }

    /** What the "choose" step shows for a facility on given dates. */
    private function check(Room $room, int $from, int $to, int $guests = 4): array
    {
        return $this->getJson('/availability/batch?'.http_build_query(['items' => [$this->item($room, $from, $to, $guests)]]))->assertOk()->json('0');
    }

    public function test_a_customer_books_one_room(): void
    {
        $this->assertSame([], $this->bookedNights($this->house1));
        $this->assertTrue($this->check($this->house1, 10, 12)['available']);
        $this->assertEquals(7000, $this->check($this->house1, 10, 12)['total']);

        $this->book([$this->item($this->house1, 10, 12)])->assertSessionHasNoErrors()->assertRedirect();

        $booking = Booking::sole();
        $this->assertSame(['PENDING', 'AH Room 1', $this->day(10), $this->day(12), '7000.00'], [$booking->status, $booking->room->name,
            $booking->check_in->toDateString(), $booking->check_out->toDateString(), $booking->total]);
        // Both nights now show as booked for that room, and the whole resort can't be had those nights; other rooms stay free.
        $this->assertSame([$this->day(10), $this->day(11)], $this->bookedNights($this->house1));
        $this->assertSame([$this->day(10), $this->day(11)], $this->bookedNights($this->resort));
        $this->assertSame([], $this->bookedNights($this->house2));
        $this->assertFalse($this->check($this->house1, 10, 12)['available']);
        $this->assertSame('Already booked for these dates', $this->check($this->house1, 11, 13)['message']);
    }

    public function test_a_customer_books_several_facilities_in_one_request(): void
    {
        $this->book([$this->item($this->hall, 10, 11, 40), $this->item($this->house1, 10, 11), $this->item($this->house2, 10, 11)])->assertSessionHasNoErrors();

        $bookings = Booking::with('room')->orderBy('id')->get();
        $this->assertSame(['Function Hall', 'AH Room 1', 'AH Room 2'], $bookings->pluck('room.name')->all());
        $this->assertCount(1, $bookings->pluck('group_code')->unique());
        $this->assertNotNull($bookings->first()->group_code);
        $this->assertSame(15499.0, (float) $bookings->sum('total'));
        foreach ([$this->hall, $this->house1, $this->house2] as $room) {
            $this->assertSame([$this->day(10)], $this->bookedNights($room), "{$room->name} should show as booked");
        }
        // The Grand Hall is a separate venue and stays free that day.
        $this->assertSame([], $this->bookedNights($this->grandHall));
    }

    public function test_a_second_customer_cannot_book_a_taken_room(): void
    {
        $this->book([$this->item($this->house1, 10, 12)])->assertSessionHasNoErrors();

        // Same dates, a later overlap, an earlier overlap: all refused for the other customer.
        $this->book([$this->item($this->house1, 10, 12)], '0918 555 0000', 'Jose Cruz')->assertSessionHasErrors('check_in');
        $this->book([$this->item($this->house1, 11, 13)], '0918 555 0000', 'Jose Cruz')->assertSessionHasErrors('check_in');
        $this->book([$this->item($this->house1, 9, 11)], '0918 555 0000', 'Jose Cruz')->assertSessionHasErrors('check_in');
        $this->assertSame(1, Booking::count());

        // Arriving the morning the first guest leaves is fine, and so is another room on the same dates.
        $this->book([$this->item($this->house1, 12, 14)], '0918 555 0000', 'Jose Cruz')->assertSessionHasNoErrors();
        $this->book([$this->item($this->house2, 10, 12)], '0918 555 0000', 'Jose Cruz')->assertSessionHasNoErrors();
        $this->assertSame(3, Booking::count());
    }

    public function test_an_approved_booking_of_another_customer_blocks_the_dates(): void
    {
        $this->book([$this->item($this->hall, 15, 16, 40)])->assertSessionHasNoErrors();
        Booking::query()->update(['status' => 'CONFIRMED', 'expires_at' => null]);

        $this->assertSame([$this->day(15)], $this->bookedNights($this->hall));
        $this->book([$this->item($this->hall, 15, 16, 30)], '0918 555 0000', 'Jose Cruz')->assertSessionHasErrors('check_in');
        $this->assertSame(1, Booking::count());
        // The next day is still free for the second customer.
        $this->book([$this->item($this->hall, 16, 17, 30)], '0918 555 0000', 'Jose Cruz')->assertSessionHasNoErrors();
    }

    public function test_a_multiple_request_with_one_taken_facility_books_nothing(): void
    {
        $this->book([$this->item($this->house2, 10, 11)])->assertSessionHasNoErrors();

        $this->book([$this->item($this->hall, 10, 11, 40), $this->item($this->house1, 10, 11), $this->item($this->house2, 10, 11)], '0918 555 0000', 'Jose Cruz')
            ->assertSessionHasErrors(['items' => 'AH Room 2 is no longer free for those dates. Choose another facility or other dates.']);

        // Nothing of the second request was held: the hall and AH Room 1 are still free.
        $this->assertSame(1, Booking::count());
        $this->assertSame([], $this->bookedNights($this->hall));
        $this->assertSame([], $this->bookedNights($this->house1));
    }

    public function test_the_whole_resort_and_single_facilities_exclude_each_other(): void
    {
        // A room is booked: the whole resort shows as booked that night and can't be requested.
        $this->book([$this->item($this->house1, 10, 11)])->assertSessionHasNoErrors();
        $this->assertSame([$this->day(10)], $this->bookedNights($this->resort));
        $this->book([$this->item($this->resort, 10, 11, 30)], '0918 555 0000', 'Jose Cruz')->assertSessionHasErrors();

        // The whole resort is booked: every single facility shows as booked those nights.
        $this->book([$this->item($this->resort, 20, 22, 30)], '0918 555 0000', 'Jose Cruz')->assertSessionHasNoErrors();
        foreach ([$this->house1, $this->house2, $this->hall, $this->grandHall] as $room) {
            $this->assertSame([$this->day(20), $this->day(21)], $this->bookedNights($room, 15, 30), "{$room->name} should be booked with the resort");
        }
        $this->book([$this->item($this->hall, 21, 22, 30)], '0919 777 0000', 'Ana Reyes')->assertSessionHasErrors('check_in');
        $this->assertSame(2, Booking::count());
    }

    public function test_dates_the_owner_blocked_cannot_be_booked(): void
    {
        BlockedDate::create(['tenant_id' => $this->house1->tenant_id, 'room_id' => $this->house1->id, 'starts_on' => $this->day(5), 'ends_on' => $this->day(7), 'reason' => 'Repairs']);

        $this->assertSame([$this->day(5), $this->day(6)], $this->bookedNights($this->house1));
        $this->assertFalse($this->check($this->house1, 6, 8)['available']);
        $this->book([$this->item($this->house1, 6, 8)])->assertSessionHasErrors('check_in');
        $this->assertSame(0, Booking::count());
    }

    public function test_cancelled_and_lapsed_requests_free_their_dates(): void
    {
        $this->book([$this->item($this->house1, 10, 11)])->assertSessionHasNoErrors();
        $this->book([$this->item($this->house2, 10, 11)], '0918 555 0000', 'Jose Cruz')->assertSessionHasNoErrors();
        Booking::where('room_id', $this->house1->id)->update(['status' => 'CANCELLED']);
        Booking::where('room_id', $this->house2->id)->update(['expires_at' => now()->subMinute()]);

        $this->assertSame([], $this->bookedNights($this->house1));
        $this->assertSame([], $this->bookedNights($this->house2));
        $this->book([$this->item($this->house1, 10, 11), $this->item($this->house2, 10, 11)], '0919 777 0000', 'Ana Reyes')->assertSessionHasNoErrors();
    }

    public function test_the_calendar_marks_a_date_only_when_every_option_is_taken(): void
    {
        // One hall taken: the other is still free that day.
        $this->book([$this->item($this->hall, 10, 11, 40)])->assertSessionHasNoErrors();
        $this->assertSame([$this->day(10)], $this->bookedNights($this->hall));
        $this->assertSame([], $this->bookedNights($this->grandHall));

        // Both halls taken: both show the day as booked, which is what greys it out for "Event or celebration".
        $this->book([$this->item($this->grandHall, 10, 11, 120)], '0918 555 0000', 'Jose Cruz')->assertSessionHasNoErrors();
        $this->assertSame([$this->day(10)], $this->bookedNights($this->grandHall));
    }

    public function test_the_calendar_never_reports_past_dates_and_needs_a_real_property(): void
    {
        Booking::forceCreate(['tenant_id' => $this->house1->tenant_id, 'property_id' => $this->property->id, 'room_id' => $this->house1->id, 'guest_name' => 'Past Guest',
            'check_in' => $this->day(-5), 'check_out' => $this->day(2), 'guests' => 2, 'reference' => 'ACV-PAST', 'status' => 'CHECKED_IN', 'total' => 1]);

        $this->assertSame([$this->day(0), $this->day(1)], $this->bookedNights($this->house1, -10, 5));
        $this->getJson('/availability/calendar?'.http_build_query(['property' => 'nowhere', 'from' => $this->day(0), 'to' => $this->day(5)]))->assertNotFound();
        $this->getJson('/availability/calendar?'.http_build_query(['property' => 'ac-villa', 'from' => $this->day(5), 'to' => $this->day(1)]))->assertUnprocessable();
    }

    public function test_too_many_guests_or_past_dates_are_refused(): void
    {
        $this->assertSame('Fits up to 50 guests', $this->check($this->hall, 10, 11, 60)['message']);
        $this->book([$this->item($this->hall, 10, 11, 60)])->assertSessionHasErrors('guests');
        $this->book([$this->item($this->house1, -2, -1)])->assertSessionHasErrors();
        $this->assertSame(0, Booking::count());
    }

    public function test_two_identical_units_take_two_bookings_then_show_as_full(): void
    {
        $this->house1->update(['quantity' => 2]);

        $this->book([$this->item($this->house1, 10, 11)])->assertSessionHasNoErrors();
        $this->assertSame([], $this->bookedNights($this->house1));
        $this->book([$this->item($this->house1, 10, 11)], '0918 555 0000', 'Jose Cruz')->assertSessionHasNoErrors();
        $this->assertSame([$this->day(10)], $this->bookedNights($this->house1));
        $this->book([$this->item($this->house1, 10, 11)], '0919 777 0000', 'Ana Reyes')->assertSessionHasErrors('check_in');
        $this->assertSame(2, Booking::count());
    }
}
