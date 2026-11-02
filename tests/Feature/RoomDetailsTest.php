<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoomDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function room(array $room = [], array $settings = []): Room
    {
        $tenant = Tenant::create(['name' => 'Demo', 'slug' => 'demo', 'business_name' => 'Demo Hotel',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear(),
            'settings' => $settings]);
        $property = Property::create(['tenant_id' => $tenant->id, 'name' => 'Stay', 'slug' => 'stay', 'city' => 'Cebu', 'province' => 'Cebu']);

        return Room::create($room + ['tenant_id' => $tenant->id, 'property_id' => $property->id, 'name' => 'Suite',
            'slug' => 'suite', 'quantity' => 1, 'maximum_occupancy' => 4, 'base_rate' => 1000, 'cleaning_fee' => 0, 'active' => true]);
    }

    private function admin(Room $room): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@demo.test', 'password' => 'Password123!',
            'role' => 'ADMIN', 'tenant_id' => $room->tenant_id]);
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['name' => 'Family Suite', 'description' => 'Roomy.', 'maximum_occupancy' => 4, 'quantity' => 2,
            'base_rate' => 4000, 'weekend_rate' => null, 'cleaning_fee' => 300, 'active' => true,
            'bedrooms' => 2, 'bathrooms' => 1.5, 'size_sqm' => 48, 'beds' => [['type' => 'king', 'count' => 1], ['type' => 'single', 'count' => 2]],
            'base_guests' => 2, 'extra_guest_fee' => 600, 'min_nights' => 2, 'max_nights' => null,
            'amenities' => ['Wi-Fi', 'Kitchen'], 'photos' => ['https://example.com/a.jpg']];
    }

    public function test_extra_guests_are_charged_per_night_and_feed_fees_and_tax(): void
    {
        $room = $this->room(['base_guests' => 2, 'extra_guest_fee' => 500], ['service_fee_percent' => 10, 'tax_percent' => 12]);
        // Monday to Wednesday: two weekday nights.
        $quote = app(PricingService::class)->quote($room, '2026-11-02', '2026-11-04', 4);
        $this->assertEquals(2000, $quote['subtotal']);
        $this->assertEquals(2 * 500 * 2, $quote['extra_guest_fee']);
        $this->assertEquals(400, $quote['service_fee']);
        $this->assertEquals(528, $quote['tax']);
        $this->assertEquals(2000 + 2000 + 400 + 528, $quote['total']);
        $this->assertEquals(0, app(PricingService::class)->quote($room, '2026-11-02', '2026-11-04', 2)['extra_guest_fee']);
    }

    public function test_public_booking_stores_extra_guest_charge(): void
    {
        $room = $this->room(['base_guests' => 2, 'extra_guest_fee' => 500]);
        $this->post('/bookings', ['room_id' => $room->id, 'guests' => 3, 'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'guest_phone' => '0917 123 4567',
            'check_in' => today()->addDays(3)->toDateString(), 'check_out' => today()->addDays(5)->toDateString()])->assertRedirect();
        $this->assertEquals(1000, Booking::firstOrFail()->price->extra_guest_fee);
    }

    public function test_room_stay_limits_override_tenant_rules(): void
    {
        $room = $this->room(['min_nights' => 3, 'max_nights' => 40], ['max_nights' => 30]);
        $availability = app(AvailabilityService::class);
        $this->assertFalse($availability->available($room, '2026-11-01', '2026-11-03'));
        $this->assertTrue($availability->available($room, '2026-11-01', '2026-12-06'));
        $this->assertSame('Stays require at least 3 nights.', $availability->stayLengthError($room, '2026-11-01', '2026-11-03'));

        $tenant = $room->property->tenant;
        $this->assertNull($availability->searchLengthError($tenant, '2026-11-01', '2026-12-06'));
        $room->update(['max_nights' => null]);
        $this->assertSame('Stays are limited to 30 nights.', $availability->searchLengthError($tenant, '2026-11-01', '2026-12-06'));
    }

    public function test_admin_saves_full_room_details_and_invalid_values_are_rejected(): void
    {
        $room = $this->room();
        $admin = $this->admin($room);
        $this->actingAs($admin)->get('/admin/properties')->assertInertia(fn (Assert $page) => $page->component('Admin/Properties')
            ->has('amenities')->has('bedTypes', count(Room::BED_TYPES))->where('stayDefaults.max_nights', 30));

        $this->actingAs($admin)->put("/admin/rooms/{$room->id}", $this->payload())->assertSessionHasNoErrors();
        $room->refresh();
        $this->assertSame(2, $room->bedrooms);
        $this->assertEquals(1.5, $room->bathrooms);
        $this->assertSame([['type' => 'king', 'count' => 1], ['type' => 'single', 'count' => 2]], $room->beds);
        $this->assertSame(['Wi-Fi', 'Kitchen'], $room->amenities);
        $this->assertSame(2, $room->includedGuests());

        $this->actingAs($admin)->put("/admin/rooms/{$room->id}", $this->payload(['amenities' => ['Helipad']]))->assertSessionHasErrors('amenities.0');
        $this->actingAs($admin)->put("/admin/rooms/{$room->id}", $this->payload(['beds' => [['type' => 'hammock', 'count' => 1]]]))->assertSessionHasErrors('beds.0.type');
        $this->actingAs($admin)->put("/admin/rooms/{$room->id}", $this->payload(['base_guests' => 5]))->assertSessionHasErrors('base_guests');
        $this->actingAs($admin)->put("/admin/rooms/{$room->id}", $this->payload(['photos' => ['javascript:alert(1)']]))->assertSessionHasErrors('photos.0');
        // A 40-night minimum is longer than the tenant's 30-night default maximum.
        $this->actingAs($admin)->put("/admin/rooms/{$room->id}", $this->payload(['min_nights' => 40]))->assertSessionHasErrors('max_nights');
    }

    public function test_removing_an_amenity_from_the_library_unticks_it_on_rooms(): void
    {
        $room = $this->room(['amenities' => ['Wi-Fi', 'Kitchen']]);
        $this->actingAs($this->admin($room))->put('/admin/settings/amenities', ['amenities' => ['Wi-Fi', '  Karaoke  ']])->assertSessionHasNoErrors();
        $this->assertSame(['Wi-Fi'], $room->fresh()->amenities);
        $this->assertSame(['Wi-Fi', 'Karaoke'], $room->property->tenant->fresh()->setting('amenities'));
    }
}
