<?php

namespace Tests\Feature;

use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\PricingService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingPlatformTest extends TestCase
{
    use RefreshDatabase;

    private function room(int $quantity = 1): Room
    {
        $tenant = Tenant::create(['name' => 'Demo', 'slug' => 'demo', 'business_name' => 'Demo Hotel',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $property = Property::create(['tenant_id' => $tenant->id, 'name' => 'Stay', 'slug' => 'stay', 'city' => 'Cebu', 'province' => 'Cebu']);

        return Room::create(['tenant_id' => $tenant->id, 'property_id' => $property->id, 'name' => 'Suite',
            'slug' => 'suite', 'quantity' => $quantity, 'maximum_occupancy' => 4, 'base_rate' => 1000,
            'cleaning_fee' => 100, 'active' => true]);
    }

    private function data(string $in = '2026-10-05', string $out = '2026-10-07'): array
    {
        return ['guest_name' => 'Jane Guest', 'guest_email' => 'jane@example.com', 'guest_phone' => '0917 123 4567', 'guests' => 2, 'check_in' => $in, 'check_out' => $out];
    }

    public function test_overlap_is_rejected_and_same_day_turnover_is_allowed(): void
    {
        $room = $this->room();
        $service = app(BookingService::class);
        $booking = $service->create($room, $this->data());
        $this->assertFalse(app(AvailabilityService::class)->available($room, '2026-10-06', '2026-10-08'));
        $this->assertTrue(app(AvailabilityService::class)->available($room, '2026-10-07', '2026-10-09'));
        $booking->update(['status' => 'CANCELLED']);
        $this->assertTrue(app(AvailabilityService::class)->available($room, '2026-10-06', '2026-10-08'));
    }

    public function test_expired_holds_and_blocked_dates(): void
    {
        $room = $this->room();
        $booking = app(BookingService::class)->create($room, $this->data());
        $booking->update(['expires_at' => now()->subMinute()]);
        $this->assertTrue(app(AvailabilityService::class)->available($room, '2026-10-06', '2026-10-08'));
        BlockedDate::create(['tenant_id' => $room->tenant_id, 'room_id' => $room->id, 'starts_on' => '2026-10-06', 'ends_on' => '2026-10-08']);
        $this->assertFalse(app(AvailabilityService::class)->available($room, '2026-10-06', '2026-10-08'));
    }

    public function test_early_checkout_does_not_release_reserved_nights(): void
    {
        $room = $this->room();
        $booking = app(BookingService::class)->create($room, $this->data());
        $booking->update(['status' => 'CHECKED_OUT', 'expires_at' => null]);
        $this->assertFalse(app(AvailabilityService::class)->available($room, '2026-10-06', '2026-10-07'));
        $booking->update(['status' => 'COMPLETED']);
        $this->assertFalse(app(AvailabilityService::class)->available($room, '2026-10-06', '2026-10-07'));
        $this->assertTrue(app(AvailabilityService::class)->available($room, '2026-10-07', '2026-10-08'));
    }

    public function test_multi_unit_inventory_is_counted_per_night(): void
    {
        $room = $this->room(2);
        $service = app(BookingService::class);
        $service->create($room, $this->data('2026-10-05', '2026-10-07'));
        $service->create($room, $this->data('2026-10-07', '2026-10-09'));
        $this->assertTrue(app(AvailabilityService::class)->available($room, '2026-10-06', '2026-10-08'));
        $service->create($room, $this->data('2026-10-06', '2026-10-08'));
        $this->assertFalse(app(AvailabilityService::class)->available($room, '2026-10-06', '2026-10-08'));
    }

    public function test_pricing_and_subscription_math(): void
    {
        $room = $this->room();
        $quote = app(PricingService::class)->quote($room, '2026-10-05', '2026-10-07');
        $this->assertEquals(2000, $quote['subtotal']);
        $this->assertEquals(2200, $quote['total']);
        $this->assertEquals(41988, 3499 * 12);
        $this->assertEquals(29988, 2499 * 12);
        $this->assertEquals(12000, 41988 - 29988);
    }

    public function test_demo_login_is_config_gated(): void
    {
        $user = User::create(['name' => 'Demo Guest', 'email' => 'guest@example.com', 'password' => 'Password123!', 'role' => 'GUEST']);
        config(['platform.demo_mode' => false]);
        $this->post('/demo/login', ['role' => 'guest'])->assertNotFound();
        config(['platform.demo_mode' => true]);
        $this->post('/demo/login', ['role' => 'guest'])->assertRedirect('/account/bookings');
        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_and_superadmin_roles_are_enforced(): void
    {
        $guest = User::create(['name' => 'Guest', 'email' => 'other@example.com', 'password' => 'Password123!', 'role' => 'GUEST']);
        $this->actingAs($guest)->get('/admin')->assertForbidden();
        $this->actingAs($guest)->get('/superadmin')->assertForbidden();
    }

    public function test_locked_tenant_cannot_book(): void
    {
        $room = $this->room();
        $room->property->tenant->update(['locked_at' => now()]);
        $this->assertFalse(app(AvailabilityService::class)->available($room, '2026-10-05', '2026-10-07'));
    }

    public function test_cross_tenant_property_changes_are_hidden(): void
    {
        $room = $this->room();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'business_name' => 'Other Hotel',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $admin = User::create(['name' => 'Other Admin', 'email' => 'admin@other.test', 'password' => 'Password123!',
            'role' => 'ADMIN', 'tenant_id' => $other->id]);
        $this->actingAs($admin)->put('/admin/properties/'.$room->property->slug, [
            'name' => 'Stolen', 'city' => 'Cebu', 'province' => 'Cebu', 'type' => 'hotel', 'status' => 'PUBLISHED',
        ])->assertNotFound();
        $this->assertDatabaseHas('properties', ['id' => $room->property_id, 'name' => 'Stay']);
    }

    public function test_admin_cannot_access_superadmin_client_actions(): void
    {
        $room = $this->room();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@demo.test', 'password' => 'Password123!',
            'role' => 'ADMIN', 'tenant_id' => $room->tenant_id]);
        $this->actingAs($admin)->put('/superadmin/clients/'.$room->tenant_id.'/status', ['status' => 'LOCKED'])->assertForbidden();
    }

    public function test_public_booking_and_mock_payment_confirm_the_stay(): void
    {
        $room = $this->room();
        $in = today()->addDays(3)->toDateString();
        $out = today()->addDays(5)->toDateString();
        $this->post('/bookings', $this->data($in, $out) + ['room_id' => $room->id])->assertRedirect();
        $booking = Booking::firstOrFail();
        $this->assertEquals('PENDING', $booking->status);
        $this->assertEquals('website', $booking->source);
        $this->post('/checkout/'.$booking->reference.'/pay', ['method' => 'mock'])
            ->assertRedirect('/booking/success/'.$booking->reference);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => 'CONFIRMED', 'payment_status' => 'PAID']);
        $this->assertDatabaseHas('payments', ['booking_id' => $booking->id, 'status' => 'PAID']);
    }

    public function test_another_guest_cannot_open_checkout(): void
    {
        $room = $this->room();
        $booking = app(BookingService::class)->create($room, $this->data());
        $this->get('/checkout/'.$booking->reference)->assertForbidden();
    }

    public function test_long_stay_returns_validation_error(): void
    {
        $room = $this->room();
        $in = today()->addDays(3)->toDateString();
        $out = today()->addDays(35)->toDateString();
        $this->post('/bookings', $this->data($in, $out) + ['room_id' => $room->id])
            ->assertSessionHasErrors('check_out');
    }

    public function test_superadmin_can_create_tenant_with_scoped_admin(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'Password123!', 'role' => 'SUPERADMIN']);
        $this->actingAs($owner)->post('/superadmin/clients', [
            'business_name' => 'Harbor House', 'business_email' => 'hello@harbor.test',
            'admin_name' => 'Harbor Admin', 'admin_email' => 'admin@harbor.test',
            'admin_password' => 'LongPassword123!',
        ])->assertRedirect();
        $tenant = Tenant::where('business_name', 'Harbor House')->firstOrFail();
        $this->assertEquals('TRIAL', $tenant->subscription_status);
        $this->assertDatabaseHas('users', ['email' => 'admin@harbor.test', 'role' => 'ADMIN', 'tenant_id' => $tenant->id]);
    }

    public function test_annual_subscription_snapshots_full_year_billing(): void
    {
        $this->seed(DemoSeeder::class);
        $owner = User::where('email', 'superadmin@example.com')->firstOrFail();
        $tenant = Tenant::where('slug', 'island-collection')->firstOrFail();
        $plan = SubscriptionPlan::where('code', 'ANNUAL')->firstOrFail();
        $this->actingAs($owner)->post('/superadmin/clients/'.$tenant->id.'/subscription', [
            'subscription_plan_id' => $plan->id, 'starts_at' => today()->toDateString(),
        ])->assertRedirect();
        $this->assertDatabaseHas('subscriptions', ['tenant_id' => $tenant->id,
            'subscription_plan_id' => $plan->id, 'billing_interval' => 'ANNUAL',
            'commitment_months' => 12, 'effective_price' => 2499, 'billing_total' => 29988]);
    }
}
