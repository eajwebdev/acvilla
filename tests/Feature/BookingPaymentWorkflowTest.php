<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingPaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_manual_booking_partial_payment_and_state_transitions(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $room = Room::where('slug', 'garden-room')->firstOrFail();
        $this->actingAs($admin)->post('/admin/bookings', [
            'room_id' => $room->id, 'check_in' => today()->addDays(3)->toDateString(),
            'check_out' => today()->addDays(5)->toDateString(), 'guests' => 2,
            'guest_name' => 'Walk-in Guest', 'guest_email' => 'walkin@example.com',
            'tenant_id' => Tenant::where('slug', 'cebu-heritage-stays')->value('id'),
        ])->assertRedirect();
        $booking = Booking::where('guest_email', 'walkin@example.com')->firstOrFail();
        $this->assertEquals($admin->tenant_id, $booking->tenant_id);
        $this->assertEquals($booking->total, $booking->price->total);

        $this->post('/admin/bookings/'.$booking->id.'/payments', ['amount' => 100, 'method' => 'cash'])->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'PENDING', 'payment_status' => 'PARTIALLY_PAID']);
        $this->post('/admin/bookings/'.$booking->id.'/payments', [
            'amount' => (float) $booking->total - 100, 'method' => 'bank_transfer',
        ])->assertRedirect();
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'CONFIRMED', 'payment_status' => 'PAID']);
        $this->post('/admin/bookings/'.$booking->id.'/payments', ['amount' => 1, 'method' => 'cash'])->assertStatus(422);
        $this->assertEquals(2, Payment::where('booking_id', $booking->id)->count());

        $this->put('/admin/bookings/'.$booking->id.'/status', ['status' => 'CHECKED_IN'])->assertRedirect();
        $this->put('/admin/bookings/'.$booking->id.'/status', ['status' => 'CHECKED_OUT'])->assertRedirect();
        $this->put('/admin/bookings/'.$booking->id.'/status', ['status' => 'COMPLETED'])->assertRedirect();
        $this->put('/admin/bookings/'.$booking->id.'/status', ['status' => 'CONFIRMED'])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $booking->id, 'action' => 'payment.recorded']);
    }

    public function test_other_tenant_cannot_change_booking_or_record_payment(): void
    {
        $otherAdmin = User::where('email', 'admin2@example.com')->firstOrFail();
        $booking = Booking::where('reference', 'EAJ-DEMO-CONFIRMED')->firstOrFail();
        $this->actingAs($otherAdmin)->put('/admin/bookings/'.$booking->id.'/status', ['status' => 'CANCELLED'])->assertNotFound();
        $this->post('/admin/bookings/'.$booking->id.'/payments', ['amount' => 100, 'method' => 'cash'])->assertNotFound();
        $this->post('/admin/bookings', [
            'room_id' => $booking->room_id, 'check_in' => today()->addDays(25)->toDateString(),
            'check_out' => today()->addDays(27)->toDateString(), 'guests' => 2,
            'guest_name' => 'Cross Tenant', 'guest_email' => 'cross@example.com',
        ])->assertNotFound();
        $this->assertDatabaseMissing('bookings', ['guest_email' => 'cross@example.com']);
    }

    public function test_guest_manual_payment_request_is_single_and_hold_is_extended(): void
    {
        $guest = User::where('email', 'guest@example.com')->firstOrFail();
        $booking = Booking::where('reference', 'EAJ-DEMO-PENDING')->firstOrFail();
        $this->actingAs($guest)->post('/checkout/'.$booking->reference.'/pay', ['method' => 'manual'])
            ->assertRedirect('/booking/success/'.$booking->reference);
        $booking->refresh();
        $this->assertEquals('AWAITING_PAYMENT', $booking->status);
        $this->assertEquals('PENDING', $booking->payment_status);
        $this->assertTrue($booking->expires_at->greaterThan(now()->addHours(23)));
        $this->post('/checkout/'.$booking->reference.'/pay', ['method' => 'manual'])->assertStatus(422);
        $this->assertEquals(1, Payment::where('booking_id', $booking->id)->count());
    }

    public function test_availability_endpoint_honors_maintenance_and_date_validation(): void
    {
        $room = Room::where('slug', 'heritage-suite')->firstOrFail();
        $this->get('/availability?'.http_build_query([
            'room_id' => $room->id, 'check_in' => today()->addDays(18)->toDateString(),
            'check_out' => today()->addDays(19)->toDateString(),
        ]))->assertOk()->assertJsonPath('available', false);
        $this->get('/availability?'.http_build_query([
            'room_id' => $room->id, 'check_in' => today()->addDays(25)->toDateString(),
            'check_out' => today()->addDays(27)->toDateString(),
        ]))->assertOk()->assertJsonPath('available', true);
        $this->get('/availability?'.http_build_query([
            'room_id' => $room->id, 'check_in' => today()->subDay()->toDateString(),
            'check_out' => today()->addDay()->toDateString(),
        ]))->assertSessionHasErrors('check_in');
    }

    public function test_expiry_commands_update_only_elapsed_records(): void
    {
        $expiredHold = Booking::where('reference', 'EAJ-DEMO-PENDING')->firstOrFail();
        $futureHold = Booking::where('reference', 'EAJ-DEMO-MANUAL')->firstOrFail();
        $expiredHold->update(['expires_at' => now()->subMinute()]);

        $island = Tenant::where('slug', 'island-collection')->firstOrFail();
        $cebu = Tenant::where('slug', 'cebu-heritage-stays')->firstOrFail();
        $island->update(['subscription_ends_at' => now()->subDay()]);
        $this->artisan('bookings:expire-holds')->assertExitCode(0);
        $this->artisan('subscriptions:expire')->assertExitCode(0);

        $this->assertEquals('EXPIRED', $expiredHold->fresh()->status);
        $this->assertEquals('AWAITING_PAYMENT', $futureHold->fresh()->status);
        $this->assertEquals('EXPIRED', $island->fresh()->subscription_status);
        $this->assertEquals('ACTIVE', $cebu->fresh()->subscription_status);
    }
}
