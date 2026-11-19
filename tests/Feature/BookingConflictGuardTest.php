<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Owner actions that change units, hours or a stay can never leave two bookings on top of each other. */
class BookingConflictGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Room $house;

    private Room $villas;

    private Room $resort;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->tenant = Tenant::create(['name' => 'AC Villa', 'slug' => 'ac-villa', 'business_name' => 'AC Villa & Private Resort',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $this->tenant->update(['settings' => [...$this->tenant->settings, 'check_in_time' => '14:00', 'check_out_time' => '12:00',
            'turnover_minutes' => 0, 'service_fee_percent' => 0]]);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret-password', 'role' => 'ADMIN', 'tenant_id' => $this->tenant->id]);
        $property = Property::create(['tenant_id' => $this->tenant->id, 'name' => 'AC Villa', 'slug' => 'ac-villa', 'city' => 'Mabinay', 'province' => 'Negros Oriental', 'status' => 'PUBLISHED']);
        $unit = fn (string $slug, string $name, int $quantity, array $extra = []) => Room::create(['tenant_id' => $this->tenant->id, 'property_id' => $property->id,
            'name' => $name, 'slug' => $slug, 'quantity' => $quantity, 'maximum_occupancy' => 4, 'base_guests' => 4, 'base_rate' => 3500, 'cleaning_fee' => 0, 'active' => true, ...$extra]);
        $this->house = $unit('a-house-1', 'A-House 1', 1);
        $this->villas = $unit('villa', 'Villa', 2);
        $this->resort = $unit('whole-resort', 'Whole Resort', 1, ['whole_property' => true]);
        $this->actingAs($this->owner);
    }

    private function day(int $days): string
    {
        return today()->addDays($days)->toDateString();
    }

    private function booked(Room $room, int $from, int $to, array $extra = []): Booking
    {
        static $n = 0;
        $n++;

        return Booking::create(['tenant_id' => $this->tenant->id, 'property_id' => $room->property_id, 'room_id' => $room->id, 'guest_name' => "Guest {$n}",
            'check_in' => $this->day($from), 'check_out' => $this->day($to), 'guests' => 2, 'reference' => "ACV-T-{$n}", 'status' => 'CONFIRMED', 'total' => 3500, ...$extra]);
    }

    private function roomForm(Room $room, array $changes): array
    {
        return [...$room->only('name', 'maximum_occupancy', 'quantity', 'base_rate'), 'whole_property' => (bool) $room->whole_property, 'active' => true, 'cleaning_fee' => 0, ...$changes];
    }

    private function generalSettings(array $changes): array
    {
        return [...collect($this->tenant->fresh()->settings)->only('currency', 'reference_prefix', 'check_in_time', 'check_out_time', 'event_start_time', 'event_end_time', 'turnover_minutes')->all(), ...$changes];
    }

    public function test_the_unit_count_cannot_drop_below_the_bookings_already_taken(): void
    {
        $this->booked($this->villas, 3, 5);
        $this->booked($this->villas, 4, 6);

        $this->put("/admin/rooms/{$this->villas->id}", $this->roomForm($this->villas, ['quantity' => 1]))->assertSessionHasErrors('conflict');
        $this->assertSame(2, $this->villas->fresh()->quantity);

        // Unrelated edits still save.
        $this->put("/admin/rooms/{$this->villas->id}", $this->roomForm($this->villas, ['base_rate' => 4000]))->assertSessionHasNoErrors();
        $this->assertEquals(4000, $this->villas->fresh()->base_rate);
    }

    public function test_a_unit_cannot_become_the_whole_resort_package_while_others_are_booked(): void
    {
        $this->booked($this->house, 3, 5);
        $this->booked($this->villas, 4, 6);

        $this->put("/admin/rooms/{$this->villas->id}", $this->roomForm($this->villas, ['whole_property' => true]))->assertSessionHasErrors('conflict');
        $this->assertFalse($this->villas->fresh()->whole_property);
    }

    public function test_new_standard_hours_cannot_run_one_guest_into_the_next(): void
    {
        // Back to back on the standard hours: out at 12:00, next guest in at 14:00 on day 5.
        $this->booked($this->house, 3, 5);
        $this->booked($this->house, 5, 7);

        $this->put('/admin/settings/general', $this->generalSettings(['check_out_time' => '15:00']))->assertSessionHasErrors('conflict');
        $this->assertSame('12:00', $this->tenant->fresh()->settings['check_out_time']);

        $this->put('/admin/settings/general', $this->generalSettings(['check_out_time' => '13:00']))->assertSessionHasNoErrors();
        $this->assertSame('13:00', $this->tenant->fresh()->settings['check_out_time']);
    }

    public function test_an_extension_cannot_overlap_the_next_guests_arrival_hour(): void
    {
        // Late check-out at 6 PM; extending to day 6 lands on the day the next guest arrives at 2 PM.
        $stay = $this->booked($this->house, 3, 5, ['check_out_time' => '18:00']);
        $this->booked($this->house, 6, 8);

        $this->put("/admin/bookings/{$stay->id}/extend", ['check_out' => $this->day(6)])->assertSessionHasErrors('conflict');
        $this->assertSame($this->day(5), $stay->fresh()->check_out->toDateString());
    }

    public function test_reopening_a_lapsed_request_checks_its_own_early_arrival(): void
    {
        $request = $this->booked($this->house, 5, 7, ['status' => 'EXPIRED', 'check_in_time' => '09:00', 'source' => 'website']);
        // Booked while the request had lapsed: leaves at noon on day 5.
        $this->booked($this->house, 3, 5);

        $this->put("/admin/bookings/{$request->id}/hold")->assertSessionHasErrors('conflict');
        $this->assertSame('EXPIRED', $request->fresh()->status);
    }

    public function test_the_guard_reports_nothing_for_a_clean_calendar(): void
    {
        $this->booked($this->house, 3, 5);
        $this->booked($this->house, 5, 7);
        $this->booked($this->villas, 3, 5);
        $this->booked($this->villas, 3, 5);
        $this->booked($this->resort, 8, 9);

        $this->assertSame([], app(AvailabilityService::class)->clashes($this->tenant->id));
    }

    public function test_a_block_cannot_cover_a_hold_waiting_on_a_receipt(): void
    {
        $hold = $this->booked($this->house, 3, 5, ['status' => 'AWAITING_PAYMENT', 'expires_at' => now()->subHour()]);
        Payment::create(['tenant_id' => $this->tenant->id, 'booking_id' => $hold->id, 'method' => 'gcash', 'provider' => 'manual',
            'status' => 'PENDING', 'amount' => 1000, 'reference' => 'PAY-T-1']);

        $this->post('/admin/calendar/blocks', ['room_id' => $this->house->id, 'starts_on' => $this->day(4), 'ends_on' => $this->day(6), 'reason' => 'Repaint'])
            ->assertSessionHasErrors('starts_on');
        $this->post('/admin/calendar/blocks', ['room_id' => $this->house->id, 'starts_on' => $this->day(5), 'ends_on' => $this->day(6), 'reason' => 'Repaint'])
            ->assertSessionHasNoErrors();
    }

    public function test_the_expiry_job_leaves_a_hold_that_was_reopened_alone(): void
    {
        $lapsed = $this->booked($this->house, 3, 5, ['status' => 'PENDING', 'expires_at' => now()->subMinute()]);
        $reopened = $this->booked($this->villas, 3, 5, ['status' => 'PENDING', 'expires_at' => now()->addDay()]);

        $this->artisan('bookings:expire-holds')->expectsOutput('Expired 1 booking holds.')->assertSuccessful();
        $this->assertSame('EXPIRED', $lapsed->fresh()->status);
        $this->assertSame('PENDING', $reopened->fresh()->status);
    }
}
