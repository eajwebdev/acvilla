<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\PromoCode;
use App\Models\Property;
use App\Models\Room;
use App\Models\SeasonalRate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SeasonsAndDiscountsTest extends TestCase
{
    use RefreshDatabase;

    private function room(array $settings = ['service_fee_percent' => 0], string $slug = 'demo'): Room
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'business_name' => 'Demo Hotel',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear(), 'settings' => $settings]);
        $property = Property::create(['tenant_id' => $tenant->id, 'name' => 'Stay', 'slug' => "stay-{$slug}", 'city' => 'Cebu', 'province' => 'Cebu', 'status' => 'PUBLISHED']);

        return Room::create(['tenant_id' => $tenant->id, 'property_id' => $property->id, 'name' => 'Suite', 'slug' => 'suite',
            'quantity' => 5, 'maximum_occupancy' => 4, 'base_rate' => 1000, 'weekend_rate' => 1500, 'cleaning_fee' => 200, 'active' => true]);
    }

    private function season(Room $room, array $attributes): SeasonalRate
    {
        return SeasonalRate::create($attributes + ['tenant_id' => $room->tenant_id, 'name' => 'Peak', 'adjustment_type' => 'percent', 'active' => true]);
    }

    private function rates(array $quote): array
    {
        return array_column($quote['nightly_rates'], 'rate', 'date');
    }

    private function guest(Room $room, int $in, int $out, array $extra = []): array
    {
        return $extra + ['room_id' => $room->id, 'guests' => 2, 'guest_name' => 'Jane', 'guest_email' => 'jane@example.com', 'guest_phone' => '0917 123 4567',
            'check_in' => today()->addDays($in)->toDateString(), 'check_out' => today()->addDays($out)->toDateString()];
    }

    public function test_seasons_adjust_only_their_nights_and_the_most_specific_wins(): void
    {
        $room = $this->room();
        // Mon 2 Nov – Sat 7 Nov 2026. The 7th is a Saturday (weekend rate 1500).
        $this->season($room, ['starts_on' => '2026-11-03', 'ends_on' => '2026-11-07', 'adjustment_value' => 20]);
        $this->season($room, ['name' => 'Festival', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-05', 'adjustment_type' => 'fixed', 'adjustment_value' => 5000]);
        $this->season($room, ['starts_on' => '2026-11-02', 'ends_on' => '2026-11-02', 'adjustment_value' => 300, 'active' => false]);
        $other = Room::create($room->only('tenant_id', 'property_id', 'quantity', 'maximum_occupancy', 'base_rate', 'cleaning_fee', 'active') + ['name' => 'Other', 'slug' => 'other']);
        $this->season($room, ['starts_on' => '2026-11-02', 'ends_on' => '2026-11-02', 'adjustment_value' => -50, 'room_ids' => [$other->id]]);

        $quote = app(PricingService::class)->quote($room, '2026-11-02', '2026-11-08');
        $this->assertEquals(['2026-11-02' => 1000, '2026-11-03' => 1200, '2026-11-04' => 1200, '2026-11-05' => 5000, '2026-11-06' => 1200, '2026-11-07' => 1800], $this->rates($quote));
        $this->assertSame('Festival', $quote['nightly_rates'][3]['season']);
        $this->assertEquals(500, $this->rates(app(PricingService::class)->quote($other, '2026-11-02', '2026-11-03'))['2026-11-02']);
    }

    public function test_season_minimum_stay_is_enforced(): void
    {
        $room = $this->room();
        $this->season($room, ['name' => 'Holy Week', 'starts_on' => '2026-11-10', 'ends_on' => '2026-11-12', 'adjustment_value' => 10, 'min_nights' => 3]);
        $availability = app(AvailabilityService::class);
        $this->assertSame('Stays during Holy Week need at least 3 nights.', $availability->stayLengthError($room, '2026-11-09', '2026-11-11'));
        $this->assertFalse($availability->available($room, '2026-11-09', '2026-11-11'));
        $this->assertTrue($availability->available($room, '2026-11-09', '2026-11-12'));
        $this->assertTrue($availability->available($room, '2026-11-13', '2026-11-14'));
    }

    public function test_length_of_stay_and_promo_discounts_reduce_room_charges_before_fees_and_tax(): void
    {
        $room = $this->room(['service_fee_percent' => 10, 'tax_percent' => 10, 'weekly_discount_percent' => 10, 'monthly_discount_percent' => 25, 'weekend_days' => []]);
        $promo = PromoCode::create(['tenant_id' => $room->tenant_id, 'code' => 'SAVE10', 'discount_type' => 'percent', 'discount_value' => 10]);
        $pricing = app(PricingService::class);

        $week = $pricing->quote($room, '2026-11-02', '2026-11-09', 2, $promo);
        $this->assertEquals(7000, $week['subtotal']);
        $this->assertEquals([['label' => 'Weekly stay (10%)', 'amount' => 700], ['label' => 'Promo SAVE10 (10%)', 'amount' => 630]], $week['discounts']);
        $this->assertEquals(1330, $week['discount']);
        $this->assertEquals(567, $week['service_fee']);
        $this->assertEquals(623.7, $week['tax']);
        $this->assertEquals(5670 + 200 + 567 + 623.7, $week['total']);

        $month = $pricing->quote($room, '2026-11-01', '2026-11-29');
        $this->assertSame('Monthly stay (25%)', $month['discounts'][0]['label']);
        $this->assertCount(1, $month['discounts']);

        $big = PromoCode::create(['tenant_id' => $room->tenant_id, 'code' => 'BIG', 'discount_type' => 'amount', 'discount_value' => 99999]);
        $short = $pricing->quote($room, '2026-11-02', '2026-11-03', 2, $big);
        $this->assertEquals(1000, $short['discount']);
        $this->assertEquals(200, $short['total']);
    }

    public function test_promo_rules_and_uses_are_enforced_and_given_back_when_cancelled(): void
    {
        $room = $this->room();
        $promo = PromoCode::create(['tenant_id' => $room->tenant_id, 'code' => 'ONCE', 'discount_type' => 'amount', 'discount_value' => 300, 'max_uses' => 1, 'min_nights' => 2]);

        $this->post('/bookings', $this->guest($room, 3, 4, ['promo_code' => 'once']))
            ->assertSessionHasErrors(['promo_code' => 'This promo code needs a stay of at least 2 nights.']);
        $this->post('/bookings', $this->guest($room, 3, 5, ['promo_code' => 'nope']))->assertSessionHasErrors(['promo_code' => "We couldn't find that promo code."]);
        $this->post('/bookings', $this->guest($room, 3, 5, ['promo_code' => ' once ']))->assertRedirect();
        $booking = Booking::firstOrFail();
        $this->assertSame($promo->id, $booking->promo_code_id);
        $this->assertEquals(300, $booking->price->discount);
        $this->assertSame('Promo ONCE', $booking->price->discounts[0]['label']);

        $this->post('/bookings', $this->guest($room, 6, 8, ['promo_code' => 'ONCE']))->assertSessionHasErrors(['promo_code' => 'This promo code has been fully redeemed.']);
        $booking->update(['status' => 'CANCELLED']);
        $this->post('/bookings', $this->guest($room, 6, 8, ['promo_code' => 'ONCE']))->assertSessionHasNoErrors();

        $promo->update(['ends_on' => today()->subDay()]);
        $this->expectException(ValidationException::class);
        PromoCode::findForStay($room->tenant_id, 'ONCE', 3);
    }

    public function test_promo_codes_belong_to_one_tenant(): void
    {
        $room = $this->room();
        $other = $this->room([], 'other');
        PromoCode::create(['tenant_id' => $other->tenant_id, 'code' => 'THEIRS', 'discount_type' => 'percent', 'discount_value' => 50]);
        $this->post('/bookings', $this->guest($room, 3, 5, ['promo_code' => 'THEIRS']))->assertSessionHasErrors('promo_code');
    }

    public function test_quote_endpoint_returns_breakdown_and_explains_problems(): void
    {
        $room = $this->room(['service_fee_percent' => 0, 'weekly_discount_percent' => 0, 'tax_label' => 'VAT', 'tax_percent' => 12]);
        PromoCode::create(['tenant_id' => $room->tenant_id, 'code' => 'TEN', 'discount_type' => 'percent', 'discount_value' => 10]);
        $query = fn (array $extra = []) => '/quote?'.http_build_query(array_diff_key($this->guest($room, 3, 5, $extra), array_flip(['guest_name', 'guest_email'])));

        $this->getJson($query(['promo_code' => 'ten']))->assertOk()->assertJsonPath('nights', 2)->assertJsonPath('tax_label', 'VAT')
            ->assertJsonPath('discounts.0.label', 'Promo TEN (10%)');
        $this->getJson($query(['promo_code' => 'NOPE']))->assertUnprocessable()->assertJsonValidationErrors('promo_code');
        $this->getJson($query(['guests' => 9]))->assertUnprocessable()->assertJsonValidationErrors('guests');
        $room->property->update(['status' => 'DRAFT']);
        $this->getJson($query())->assertNotFound();
    }

    public function test_admin_manages_seasons_and_promos_with_validation_and_tenant_scope(): void
    {
        $room = $this->room();
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@demo.test', 'password' => 'Password123!', 'role' => 'ADMIN', 'tenant_id' => $room->tenant_id]);
        $season = ['name' => 'Christmas', 'starts_on' => '2026-12-20', 'ends_on' => '2027-01-03', 'adjustment_type' => 'percent',
            'adjustment_value' => 30, 'min_nights' => 2, 'room_ids' => [$room->id], 'active' => true];

        $this->actingAs($admin)->post('/admin/settings/seasons', $season)->assertSessionHasNoErrors();
        $this->assertSame([$room->id], SeasonalRate::firstOrFail()->room_ids);
        $this->actingAs($admin)->post('/admin/settings/seasons', ['ends_on' => '2026-12-01'] + $season)->assertSessionHasErrors('ends_on');
        $this->actingAs($admin)->post('/admin/settings/seasons', ['adjustment_value' => -95] + $season)->assertSessionHasErrors('adjustment_value');
        $this->actingAs($admin)->post('/admin/settings/seasons', ['ends_on' => '2028-06-01'] + $season)->assertSessionHasErrors('ends_on');
        $this->actingAs($admin)->get('/admin/settings/seasons')->assertInertia(fn (Assert $page) => $page->component('Admin/SeasonalRates')->has('seasons', 1)->has('rooms', 1));

        $promo = ['code' => 'summer-10', 'discount_type' => 'percent', 'discount_value' => 10, 'active' => true];
        $this->actingAs($admin)->post('/admin/settings/promos', $promo)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('promo_codes', ['code' => 'SUMMER-10', 'tenant_id' => $room->tenant_id]);
        $this->actingAs($admin)->post('/admin/settings/promos', $promo)->assertSessionHasErrors(['code' => 'You already have a promo code with this name.']);
        $this->actingAs($admin)->post('/admin/settings/promos', ['code' => 'BAD CODE!'] + $promo)->assertSessionHasErrors('code');
        $this->actingAs($admin)->post('/admin/settings/promos', ['code' => 'HALF', 'discount_value' => 150] + $promo)->assertSessionHasErrors('discount_value');
        $this->actingAs($admin)->get('/admin/settings/promos')->assertInertia(fn (Assert $page) => $page->component('Admin/PromoCodes')->where('promos.0.uses', 0));

        $other = $this->room([], 'other');
        $foreign = SeasonalRate::create(['tenant_id' => $other->tenant_id, 'name' => 'X', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-02', 'adjustment_type' => 'percent', 'adjustment_value' => 5]);
        $this->actingAs($admin)->delete("/admin/settings/seasons/{$foreign->id}")->assertNotFound();
        $this->actingAs($admin)->post('/admin/settings/seasons', ['room_ids' => [$other->id]] + $season)->assertSessionHasErrors('room_ids.0');

        $staff = User::create(['name' => 'Staff', 'email' => 'staff@demo.test', 'password' => 'Password123!', 'role' => 'STAFF', 'tenant_id' => $room->tenant_id]);
        $this->actingAs($staff)->get('/admin/settings/promos')->assertForbidden();
        $this->actingAs($staff)->post('/admin/settings/seasons', $season)->assertForbidden();
    }
}
