<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BookingService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function room(array $settings = []): Room
    {
        $tenant = Tenant::create(['name' => 'Demo', 'slug' => 'demo', 'business_name' => 'Demo Hotel',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear(),
            'settings' => $settings]);
        $property = Property::create(['tenant_id' => $tenant->id, 'name' => 'Stay', 'slug' => 'stay', 'city' => 'Cebu', 'province' => 'Cebu']);

        return Room::create(['tenant_id' => $tenant->id, 'property_id' => $property->id, 'name' => 'Suite',
            'slug' => 'suite', 'quantity' => 1, 'maximum_occupancy' => 4, 'base_rate' => 1000, 'weekend_rate' => 1500,
            'cleaning_fee' => 100, 'active' => true]);
    }

    private function admin(Room $room, string $role = 'ADMIN'): User
    {
        return User::create(['name' => $role, 'email' => strtolower($role).'@demo.test', 'password' => 'Password123!',
            'role' => $role, 'tenant_id' => $room->tenant_id]);
    }

    private function data(string $in, string $out): array
    {
        return ['guest_name' => 'Jane Guest', 'guest_email' => 'jane@example.com', 'guest_phone' => '0917 123 4567', 'guests' => 2, 'check_in' => $in, 'check_out' => $out];
    }

    public function test_unsaved_settings_fall_back_to_platform_defaults(): void
    {
        $tenant = $this->room()->property->tenant;
        $this->assertEquals(config('platform.tenant_defaults'), $tenant->fresh()->settings);
        $tenant->update(['settings' => ['tax_percent' => 12, 'unknown' => 'dropped']]);
        $this->assertSame(12.0, $tenant->fresh()->setting('tax_percent'));
        $this->assertSame('PHP', $tenant->fresh()->setting('currency'));
        $this->assertArrayNotHasKey('unknown', $tenant->fresh()->settings);
    }

    public function test_quote_uses_tenant_fees_tax_and_weekend_days(): void
    {
        // 2026-10-09 is a Friday; Friday counts as a weekend night only when the tenant says so.
        $room = $this->room(['service_fee_percent' => 10, 'tax_percent' => 12, 'weekend_days' => [5, 6]]);
        $quote = app(PricingService::class)->quote($room, '2026-10-08', '2026-10-10');
        $this->assertEquals([1000, 1500], array_column($quote['nightly_rates'], 'rate'));
        $this->assertEquals(2500, $quote['subtotal']);
        $this->assertEquals(250, $quote['service_fee']);
        $this->assertEquals(330, $quote['tax']);
        $this->assertEquals(2500 + 100 + 250 + 330, $quote['total']);
    }

    public function test_stay_length_limits_and_reference_prefix(): void
    {
        $room = $this->room(['min_nights' => 2, 'max_nights' => 5, 'reference_prefix' => 'BAY']);
        $availability = app(AvailabilityService::class);
        $this->assertFalse($availability->available($room, '2026-11-01', '2026-11-02'));
        $this->assertFalse($availability->available($room, '2026-11-01', '2026-11-07'));
        $this->assertTrue($availability->available($room, '2026-11-01', '2026-11-03'));

        $booking = app(BookingService::class)->create($room, $this->data('2026-11-01', '2026-11-03'));
        $this->assertStringStartsWith('BAY-', $booking->reference);
        $this->post('/bookings', $this->data(today()->addDays(3)->toDateString(), today()->addDays(4)->toDateString()) + ['room_id' => $room->id])
            ->assertSessionHasErrors(['check_out' => 'Stays require at least 2 nights.']);
    }

    public function test_guest_booking_respects_advance_notice_and_window_but_staff_bookings_skip_notice(): void
    {
        $room = $this->room(['advance_notice_days' => 3, 'booking_window_days' => 60]);
        $soon = [today()->addDay()->toDateString(), today()->addDays(3)->toDateString()];
        $this->post('/bookings', $this->data(...$soon) + ['room_id' => $room->id])->assertSessionHasErrors('check_in');
        $this->post('/bookings', $this->data(today()->addDays(61)->toDateString(), today()->addDays(63)->toDateString()) + ['room_id' => $room->id])
            ->assertSessionHasErrors('check_in');
        $this->getJson('/availability?'.http_build_query(['room_id' => $room->id, 'check_in' => $soon[0], 'check_out' => $soon[1]]))
            ->assertJson(['available' => false, 'message' => 'Bookings need at least 3 days notice.']);

        $this->actingAs($this->admin($room))->post('/admin/bookings', $this->data(...$soon) + ['room_id' => $room->id])->assertRedirect();
        $this->assertSame(1, Booking::count());
    }

    public function test_hold_and_manual_payment_windows_follow_settings(): void
    {
        $room = $this->room(['hold_minutes' => 45, 'manual_payment_hours' => 6, 'request_hold_hours' => 12]);
        $this->freezeSecond();
        $this->assertTrue(app(BookingService::class)->create($room, $this->data(today()->addDays(8)->toDateString(), today()->addDays(9)->toDateString()))
            ->expires_at->equalTo(now()->addMinutes(45)));
        // A website request holds its dates while the owner calls the guest.
        $this->post('/bookings', $this->data(today()->addDays(3)->toDateString(), today()->addDays(5)->toDateString()) + ['room_id' => $room->id]);
        $booking = Booking::where('source', 'website')->firstOrFail();
        $this->assertTrue($booking->expires_at->equalTo(now()->addHours(12)));
        $this->post('/checkout/'.$booking->reference.'/pay', ['method' => 'manual']);
        $this->assertTrue($booking->fresh()->expires_at->equalTo(now()->addHours(6)));
    }

    public function test_admin_updates_a_section_with_validation_and_audit_but_staff_cannot(): void
    {
        $room = $this->room();
        $admin = $this->admin($room);
        $this->actingAs($admin)->get('/admin/settings/pricing')
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Settings')->where('section', 'pricing')->where('settings.tax_percent', 0));
        $this->actingAs($admin)->get('/admin/settings/nope')->assertNotFound();

        $this->actingAs($admin)->put('/admin/settings/booking', ['min_nights' => 5, 'max_nights' => 2, 'advance_notice_days' => 0,
            'booking_window_days' => 365, 'hold_minutes' => 15, 'manual_payment_hours' => 24])->assertSessionHasErrors('max_nights');
        $this->actingAs($admin)->put('/admin/settings/general', ['currency' => 'XYZ', 'reference_prefix' => 'ok',
            'check_in_time' => '14:00', 'check_out_time' => '12:00'])->assertSessionHasErrors('currency');

        $this->actingAs($admin)->put('/admin/settings/pricing', ['service_fee_percent' => 0, 'tax_label' => 'VAT',
            'tax_percent' => 12, 'weekly_discount_percent' => 0, 'monthly_discount_percent' => 0, 'weekend_days' => [6, 5]])->assertRedirect()->assertSessionHasNoErrors();
        $tenant = $room->property->tenant->fresh();
        $this->assertSame([5, 6], $tenant->setting('weekend_days'));
        $this->assertSame('VAT', $tenant->setting('tax_label'));
        $this->assertSame(15, $tenant->setting('hold_minutes'));
        $log = AuditLog::where('action', 'settings.updated')->firstOrFail();
        $this->assertEquals(['service_fee_percent' => 0, 'tax_label' => 'VAT', 'tax_percent' => 12, 'weekend_days' => [5, 6]], $log->metadata['changed']);

        $this->actingAs($admin)->put('/admin/settings/general', ['currency' => 'USD', 'reference_prefix' => 'bay1',
            'check_in_time' => '15:00', 'check_out_time' => '11:00'])->assertSessionHasNoErrors();
        $this->assertSame('BAY1', $tenant->fresh()->setting('reference_prefix'));
        $this->actingAs($admin)->get('/admin')->assertInertia(fn (Assert $page) => $page->where('currency', 'USD'));

        $staff = $this->admin($room, 'STAFF');
        $this->actingAs($staff)->get('/admin/settings/general')->assertForbidden();
        $this->actingAs($staff)->put('/admin/settings/pricing', ['service_fee_percent' => 30, 'tax_label' => 'X', 'tax_percent' => 0, 'weekend_days' => []])->assertForbidden();
    }
}
