<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The AC Villa way of working: requests by phone, downpayments, extensions only when free. */
class VillaWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Room $room;

    private Room $hall;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $tenant = Tenant::create(['name' => 'AC Villa', 'slug' => 'ac-villa', 'business_name' => 'AC Villa & Private Resort', 'business_phone' => '0917 000 0000',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $tenant->update(['settings' => [...$tenant->settings, 'deposit_percent' => 50, 'balance_due_days' => 0, 'service_fee_percent' => 0]]);
        $property = Property::create(['tenant_id' => $tenant->id, 'name' => 'AC Villa', 'slug' => 'ac-villa', 'city' => 'Mabinay', 'province' => 'Negros Oriental', 'status' => 'PUBLISHED']);
        $this->room = Room::create(['tenant_id' => $tenant->id, 'property_id' => $property->id, 'name' => 'AH Room 1', 'slug' => 'ah-room-1',
            'quantity' => 1, 'maximum_occupancy' => 15, 'base_guests' => 4, 'base_rate' => 3500, 'cleaning_fee' => 0, 'active' => true]);
        $this->hall = Room::create(['tenant_id' => $tenant->id, 'property_id' => $property->id, 'name' => 'Function Hall', 'slug' => 'function-hall', 'kind' => 'venue',
            'quantity' => 1, 'maximum_occupancy' => 50, 'base_guests' => 50, 'base_rate' => 7000, 'cleaning_fee' => 0, 'active' => true]);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret-password', 'role' => 'ADMIN', 'tenant_id' => $tenant->id]);
    }

    private function day(int $days): string
    {
        return today()->addDays($days)->toDateString();
    }

    private function request(Room $room, int $from, int $to, array $extra = []): Booking
    {
        $this->post('/bookings', $extra + ['room_id' => $room->id, 'guests' => 2, 'guest_name' => 'Maria Santos', 'guest_phone' => '0917 123 4567', 'guest_email' => 'maria@example.com',
            'check_in' => $this->day($from), 'check_out' => $this->day($to)])->assertSessionHasNoErrors();

        return Booking::latest('id')->firstOrFail();
    }

    public function test_the_booking_page_opens_with_the_chosen_unit_and_dates(): void
    {
        $this->get('/book?room='.$this->hall->id.'&check_in='.$this->day(10).'&check_out='.$this->day(11).'&guests=30')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Public/Book')
                ->where('selection.room', $this->hall->id)->where('selection.guests', 30)->where('bookable', true)
                ->has('property.rooms', 2)->has('requestTypes.wedding')->has('callTimes.anytime'));
        $this->get('/book?check_out=2020-01-01&check_in=2020-02-01')->assertSessionHasErrors('check_out');
    }

    public function test_an_event_can_be_requested_for_up_to_200_guests(): void
    {
        $this->hall->update(['maximum_occupancy' => 200]);
        $event = $this->request($this->hall, 10, 11, ['guests' => 200]);
        $this->assertSame(200, $event->guests);

        $this->post('/bookings', ['room_id' => $this->hall->id, 'guests' => 201, 'guest_name' => 'Too Many', 'guest_phone' => '0917 999 0000', 'guest_email' => 'many@example.com',
            'check_in' => $this->day(20), 'check_out' => $this->day(21)])->assertSessionHasErrors('guests');
    }

    public function test_sending_the_same_request_twice_keeps_one_booking(): void
    {
        $first = $this->request($this->room, 5, 7);
        $this->post('/bookings', ['room_id' => $this->room->id, 'guests' => 2, 'guest_name' => 'Maria Santos', 'guest_phone' => '+63 917 123 4567', 'guest_email' => 'maria@example.com',
            'check_in' => $this->day(5), 'check_out' => $this->day(7)])->assertRedirect('/booking/success/'.$first->reference);

        $this->assertSame(1, Booking::count());
    }

    public function test_an_event_is_held_and_priced_as_one_day(): void
    {
        $event = $this->request($this->hall, 10, 11, ['guests' => 40]);

        $this->assertSame('7000.00', $event->total);
        $this->actingAs($this->owner)->getJson("/admin/bookings/{$event->id}")->assertJsonPath('booking.unit_kind', 'venue');
    }

    public function test_the_owner_sees_the_request_count_and_can_hold_or_reopen_a_request(): void
    {
        $booking = $this->request($this->room, 5, 7);
        $this->actingAs($this->owner)->get('/admin/bookings')->assertInertia(fn (Assert $page) => $page->where('requestCount', 1));

        $held = $booking->expires_at;
        $this->put("/admin/bookings/{$booking->id}/hold")->assertSessionHasNoErrors();
        $this->assertTrue($booking->fresh()->expires_at->equalTo($held->copy()->addDay()));

        // Lapsed and swept to EXPIRED: it can be reopened while the dates are still free…
        $booking->update(['status' => 'EXPIRED', 'expires_at' => now()->subHour()]);
        $this->put("/admin/bookings/{$booking->id}/hold")->assertSessionHasNoErrors();
        $this->assertSame('PENDING', $booking->fresh()->status);
        $this->assertTrue($booking->fresh()->expires_at->isFuture());

        // …but not once someone else has taken them.
        $booking->refresh()->update(['status' => 'EXPIRED', 'expires_at' => now()->subHour()]);
        Booking::forceCreate([...$booking->only('tenant_id', 'property_id', 'room_id', 'check_in', 'check_out', 'guests', 'guest_name'),
            'reference' => 'ACV-OTHER', 'status' => 'CONFIRMED', 'total' => 7000]);
        $this->put("/admin/bookings/{$booking->id}/hold")->assertSessionHasErrors('status');
        $this->assertSame('EXPIRED', $booking->fresh()->status);
    }

    public function test_a_downpayment_confirms_and_the_balance_can_be_paid_later(): void
    {
        $booking = $this->request($this->room, 5, 7);
        $this->assertSame('3500.00', $booking->deposit_amount);

        $this->actingAs($this->owner)->post("/admin/bookings/{$booking->id}/payments", ['amount' => 3500, 'method' => 'gcash', 'payer_reference' => 'GC123'])->assertSessionHasNoErrors();
        $this->assertSame(['CONFIRMED', 'PARTIALLY_PAID'], [$booking->fresh()->status, $booking->fresh()->payment_status]);

        $this->post("/admin/bookings/{$booking->id}/payments", ['amount' => 3500, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame('PAID', $booking->fresh()->payment_status);
    }

    public function test_a_stay_is_extended_only_when_the_extra_nights_are_free(): void
    {
        $booking = $this->request($this->room, 5, 7);
        $this->actingAs($this->owner)->put("/admin/bookings/{$booking->id}/status", ['status' => 'CONFIRMED']);

        $this->put("/admin/bookings/{$booking->id}/extend", ['check_out' => $this->day(8)])->assertSessionHasNoErrors();
        $booking->refresh();
        $this->assertSame([$this->day(8), '10500.00'], [$booking->check_out->toDateString(), $booking->total]);
        $this->assertCount(3, $booking->price->nightly_rates);

        // The next night is taken by another guest, so the stay can't run into it.
        Booking::forceCreate([...$booking->only('tenant_id', 'property_id', 'room_id', 'guests'), 'guest_name' => 'Next Guest',
            'check_in' => $this->day(9), 'check_out' => $this->day(10), 'reference' => 'ACV-NEXT', 'status' => 'CONFIRMED', 'total' => 3500]);
        $this->put("/admin/bookings/{$booking->id}/extend", ['check_out' => $this->day(10)])->assertSessionHasErrors('check_out');
        $this->put("/admin/bookings/{$booking->id}/extend", ['check_out' => $this->day(9)])->assertSessionHasNoErrors();
        $this->assertSame('14000.00', $booking->fresh()->total);
    }

    public function test_late_check_out_is_refused_when_the_next_guest_arrives_that_day(): void
    {
        $booking = $this->request($this->room, 5, 7);
        $details = ['guest_name' => 'Maria Santos', 'source' => 'website', 'check_in_time' => '14:00'];
        $this->actingAs($this->owner)->put("/admin/bookings/{$booking->id}", [...$details, 'check_out_time' => '16:00'])->assertSessionHasNoErrors();

        $booking->refresh()->update(['check_out_time' => null]);
        Booking::forceCreate([...$booking->only('tenant_id', 'property_id', 'room_id', 'guests'), 'guest_name' => 'Next Guest',
            'check_in' => $this->day(7), 'check_out' => $this->day(8), 'reference' => 'ACV-NEXT', 'status' => 'CONFIRMED', 'total' => 3500]);
        $this->put("/admin/bookings/{$booking->id}", [...$details, 'check_out_time' => '16:00'])->assertSessionHasErrors('check_out_time');
        $this->put("/admin/bookings/{$booking->id}", [...$details, 'check_out_time' => '13:00'])->assertSessionHasNoErrors();
    }
}
