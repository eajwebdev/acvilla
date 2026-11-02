<?php

namespace Tests\Feature;

use App\Models\AddOn;
use App\Models\Booking;
use App\Models\PromoCode;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AddOnsAndPoliciesTest extends TestCase
{
    use RefreshDatabase;

    private function room(array $settings = [], string $slug = 'demo'): Room
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'business_name' => 'Bay Resort', 'status' => 'ACTIVE',
            'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear(), 'settings' => $settings + ['service_fee_percent' => 0]]);
        $property = Property::create(['tenant_id' => $tenant->id, 'name' => 'Bay', 'slug' => "bay-{$slug}", 'city' => 'Cebu', 'province' => 'Cebu', 'status' => 'PUBLISHED']);

        return Room::create(['tenant_id' => $tenant->id, 'property_id' => $property->id, 'name' => 'Suite', 'slug' => 'suite',
            'quantity' => 5, 'maximum_occupancy' => 4, 'base_rate' => 1000, 'cleaning_fee' => 100, 'active' => true]);
    }

    private function addOn(Room $room, array $attributes): AddOn
    {
        return AddOn::create($attributes + ['tenant_id' => $room->tenant_id, 'name' => 'Extra', 'price' => 100, 'pricing' => 'per_stay', 'max_quantity' => 1]);
    }

    private function guest(Room $room, array $extra = []): array
    {
        return $extra + ['room_id' => $room->id, 'guests' => 3, 'guest_name' => 'Ana', 'guest_email' => 'ana@example.com', 'guest_phone' => '0917 123 4567',
            'check_in' => today()->addDays(10)->toDateString(), 'check_out' => today()->addDays(12)->toDateString()];
    }

    public function test_each_pricing_mode_and_fees_include_extras_but_promos_do_not_discount_them(): void
    {
        $room = $this->room(['service_fee_percent' => 10, 'tax_percent' => 10, 'weekend_days' => []]);
        $breakfast = $this->addOn($room, ['name' => 'Breakfast', 'price' => 250, 'pricing' => 'per_guest_night', 'max_quantity' => 1]);
        $transfer = $this->addOn($room, ['name' => 'Transfer', 'price' => 900, 'pricing' => 'per_stay', 'max_quantity' => 3]);
        $kayak = $this->addOn($room, ['name' => 'Kayak', 'price' => 300, 'pricing' => 'per_night', 'max_quantity' => 2]);
        $towel = $this->addOn($room, ['name' => 'Towel kit', 'price' => 50, 'pricing' => 'per_guest', 'max_quantity' => 1]);
        $promo = PromoCode::create(['tenant_id' => $room->tenant_id, 'code' => 'HALF', 'discount_type' => 'percent', 'discount_value' => 50]);

        $quote = app(PricingService::class)->quote($room, '2026-11-02', '2026-11-04', 3, $promo,
            [$breakfast->id => 1, $transfer->id => 2, $kayak->id => 2, $towel->id => 1, 999 => 0]);
        $this->assertEquals([1500, 1800, 1200, 150], array_column($quote['add_ons'], 'amount'));
        $this->assertEquals(4650, $quote['add_ons_total']);
        $this->assertEquals(1000, $quote['discount']);
        $this->assertEquals(565, $quote['service_fee']);
        $this->assertEquals(621.5, $quote['tax']);
        $this->assertEquals(1000 + 4650 + 100 + 565 + 621.5, $quote['total']);
    }

    public function test_unavailable_or_over_limit_extras_are_rejected(): void
    {
        $room = $this->room();
        $other = Room::create($room->only('tenant_id', 'property_id', 'quantity', 'maximum_occupancy', 'base_rate', 'cleaning_fee', 'active') + ['name' => 'Other', 'slug' => 'other']);
        $scoped = $this->addOn($room, ['room_ids' => [$other->id]]);
        $paused = $this->addOn($room, ['active' => false]);
        $limited = $this->addOn($room, ['max_quantity' => 2, 'name' => 'Kayak']);
        $foreign = $this->addOn($this->room([], 'other'), []);
        $pricing = app(PricingService::class);

        foreach ([[$scoped->id => 1], [$paused->id => 1], [$foreign->id => 1]] as $selection) {
            try {
                $pricing->quote($room, '2026-11-02', '2026-11-04', 2, null, $selection);
                $this->fail('Expected the extra to be rejected.');
            } catch (ValidationException $e) {
                $this->assertSame('One of the extras you picked is no longer available.', $e->errors()['add_ons'][0]);
            }
        }
        $this->expectExceptionMessage('Choose up to 2 of Kayak.');
        $pricing->quote($room, '2026-11-02', '2026-11-04', 2, null, [$limited->id => 3]);
    }

    public function test_guest_books_with_extras_and_stay_page_lists_only_offered_ones(): void
    {
        $room = $this->room();
        $breakfast = $this->addOn($room, ['name' => 'Breakfast', 'price' => 200, 'pricing' => 'per_guest_night']);
        $this->addOn($room, ['name' => 'Paused', 'active' => false]);
        $this->addOn($this->room([], 'other'), ['name' => 'Elsewhere']);

        $this->get('/stays/bay-demo')->assertInertia(fn (Assert $page) => $page->has('addOns', 1)->where('addOns.0.name', 'Breakfast'));
        $this->getJson('/quote?'.http_build_query(array_diff_key($this->guest($room, ['add_ons' => [$breakfast->id => 1]]), array_flip(['guest_name', 'guest_email']))))
            ->assertOk()->assertJsonPath('add_ons.0.amount', 1200)->assertJsonPath('add_ons_total', 1200);

        $this->post('/bookings', $this->guest($room, ['add_ons' => [$breakfast->id => 1]]))->assertRedirect();
        $price = Booking::firstOrFail()->price;
        $this->assertEquals(1200, $price->add_ons_total);
        $this->assertSame('Breakfast', $price->add_ons[0]['name']);
        $this->assertEquals(2000 + 1200 + 100, Booking::firstOrFail()->total);
    }

    public function test_guests_must_accept_policies_when_the_tenant_has_them(): void
    {
        $room = $this->room(['house_rules' => 'No smoking.', 'cancellation_policy' => 'No refunds within 48 hours.']);
        $this->post('/bookings', $this->guest($room));
        $booking = Booking::firstOrFail();
        $this->get("/stays/bay-demo")->assertInertia(fn (Assert $page) => $page->where('tenant.settings.house_rules', 'No smoking.'));

        $this->post("/checkout/{$booking->reference}/pay", ['method' => 'mock'])->assertSessionHasErrors('accept_policies');
        $this->assertSame('PENDING', $booking->fresh()->status);
        $this->post("/checkout/{$booking->reference}/pay", ['method' => 'mock', 'accept_policies' => true])->assertRedirect();
        $this->assertNotNull($booking->fresh()->policies_accepted_at);
        $this->assertSame('CONFIRMED', $booking->fresh()->status);
    }

    public function test_admin_manages_add_ons_and_policies(): void
    {
        $room = $this->room();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@bay.test', 'password' => 'Password123!', 'role' => 'ADMIN', 'tenant_id' => $room->tenant_id]);
        $payload = ['name' => 'Airport transfer', 'description' => 'Tagbilaran airport', 'price' => 900, 'pricing' => 'per_stay', 'max_quantity' => 2, 'room_ids' => [$room->id], 'active' => true];

        $this->actingAs($admin)->post('/admin/settings/add-ons', $payload)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/settings/add-ons', ['name' => 'Breakfast'] + $payload)->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/settings/add-ons', ['pricing' => 'per_hour'] + $payload)->assertSessionHasErrors('pricing');
        $this->actingAs($admin)->post('/admin/settings/add-ons', ['max_quantity' => 50] + $payload)->assertSessionHasErrors('max_quantity');
        [$transfer, $breakfast] = AddOn::orderBy('sort_order')->get()->all();
        $this->assertSame([$room->id], $transfer->room_ids);

        $this->actingAs($admin)->put("/admin/settings/add-ons/{$breakfast->id}/move", ['direction' => 'up'])->assertRedirect();
        $this->assertSame(['Breakfast', 'Airport transfer'], AddOn::orderBy('sort_order')->pluck('name')->all());
        $this->actingAs($admin)->get('/admin/settings/add-ons')->assertInertia(fn (Assert $page) => $page->component('Admin/AddOns')
            ->where('addOns.0.name', 'Breakfast')->has('pricingOptions', 4));

        $foreign = $this->addOn($this->room([], 'other'), []);
        $this->actingAs($admin)->put("/admin/settings/add-ons/{$foreign->id}", $payload)->assertNotFound();
        $this->actingAs($admin)->delete("/admin/settings/add-ons/{$transfer->id}")->assertRedirect();
        $this->assertSame(2, AddOn::count());

        $this->actingAs($admin)->put('/admin/settings/policies', ['house_rules' => "Quiet hours from 10 PM.\nNo pets.", 'cancellation_policy' => null])->assertSessionHasNoErrors();
        $this->assertSame(["Quiet hours from 10 PM.\nNo pets.", ''], array_values(array_intersect_key($room->property->tenant->fresh()->settings, array_flip(['house_rules', 'cancellation_policy']))));

        $staff = User::create(['name' => 'Staff', 'email' => 'staff@bay.test', 'password' => 'Password123!', 'role' => 'STAFF', 'tenant_id' => $room->tenant_id]);
        $this->actingAs($staff)->get('/admin/settings/add-ons')->assertForbidden();
    }
}
