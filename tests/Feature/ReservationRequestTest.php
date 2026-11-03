<?php

namespace Tests\Feature;

use App\Mail\GuestMessage;
use App\Models\AddOn;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AvailabilityService;
use Database\Seeders\AcVillaSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReservationRequestTest extends TestCase
{
    use RefreshDatabase;

    private function room(): Room
    {
        $tenant = Tenant::create(['name' => 'AC Villa', 'slug' => 'ac-villa', 'business_name' => 'AC Villa & Private Resort', 'business_phone' => '0917 000 0000',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $property = Property::create(['tenant_id' => $tenant->id, 'name' => 'AC Villa', 'slug' => 'ac-villa', 'city' => 'Mabinay', 'province' => 'Negros Oriental', 'status' => 'PUBLISHED']);

        return Room::create(['tenant_id' => $tenant->id, 'property_id' => $property->id, 'name' => 'A-Frame Villa', 'slug' => 'a-frame',
            'quantity' => 1, 'maximum_occupancy' => 4, 'base_rate' => 3500, 'cleaning_fee' => 0, 'active' => true]);
    }

    private function request(Room $room, array $extra = []): array
    {
        return $extra + ['room_id' => $room->id, 'guests' => 2, 'guest_name' => 'Maria Santos', 'guest_phone' => '0917 123 4567', 'guest_email' => 'maria@example.com',
            'check_in' => today()->addDays(5)->toDateString(), 'check_out' => today()->addDays(7)->toDateString()];
    }

    public function test_a_website_request_waits_for_the_owners_call_without_payment(): void
    {
        Mail::fake();
        $room = $this->room();
        $this->freezeSecond();

        $this->post('/bookings', $this->request($room, ['guest_email' => 'maria@example.com', 'request_type' => 'wedding', 'call_time' => 'evening', 'message' => 'Garden ceremony for 50']))
            ->assertRedirect('/booking/success/'.Booking::sole()->reference);

        $booking = Booking::sole();
        $this->assertSame(['PENDING', 'UNPAID', 'website', '+639171234567'], [$booking->status, $booking->payment_status, $booking->source, $booking->guest_phone]);
        $this->assertTrue($booking->expires_at->equalTo(now()->addHours(48)));
        $this->assertStringContainsString('Wedding', $booking->notes);
        $this->assertStringContainsString('Evening', $booking->notes);
        $this->assertStringContainsString('Garden ceremony for 50', $booking->notes);
        $this->assertSame(0, $booking->payments()->count());
        Mail::assertSent(GuestMessage::class, fn (GuestMessage $mail) => str_contains($mail->subjectLine, 'reservation request'));

        $this->get('/booking/success/'.$booking->reference)->assertInertia(fn (Assert $page) => $page->component('Public/Success')
            ->where('booking.status', 'PENDING')->where('pendingPayment', null));
        // The dates stay held for this guest while the owner calls.
        $this->assertFalse(app(AvailabilityService::class)->available($room, today()->addDays(5)->toDateString(), today()->addDays(6)->toDateString()));
    }

    public function test_a_mobile_number_and_an_email_are_required(): void
    {
        Mail::fake();
        $room = $this->room();
        $this->post('/bookings', $this->request($room, ['guest_phone' => '']))->assertSessionHasErrors('guest_phone');
        $this->post('/bookings', $this->request($room, ['guest_phone' => 'call me']))->assertSessionHasErrors('guest_phone');
        $this->post('/bookings', $this->request($room, ['request_type' => 'party-bus']))->assertSessionHasErrors('request_type');
        // Booking updates go by email, so a website request needs a real address.
        $this->post('/bookings', $this->request($room, ['guest_email' => '']))->assertSessionHasErrors('guest_email');
        $this->post('/bookings', $this->request($room, ['guest_email' => 'not-an-email']))->assertSessionHasErrors('guest_email');
        $this->assertSame(0, Booking::count());

        $this->post('/bookings', $this->request($room))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('maria@example.com', Booking::sole()->guest_email);
        Mail::assertSent(\App\Mail\GuestMessage::class, fn ($mail) => $mail->hasTo('maria@example.com'));
    }

    public function test_the_owner_sees_requests_to_call_and_confirms_after_the_call(): void
    {
        Mail::fake();
        $room = $this->room();
        $this->post('/bookings', $this->request($room));
        $booking = Booking::sole();
        $admin = User::create(['name' => 'Owner', 'email' => 'owner@acvilla.test', 'password' => 'Password123!', 'role' => 'ADMIN', 'tenant_id' => $room->tenant_id]);

        $this->actingAs($admin)->get('/admin')->assertInertia(fn (Assert $page) => $page->where('attention', fn ($items) => collect($items)
            ->contains(fn ($item) => $item['title'] === 'Call guest, then approve the dates' && $item['booking_id'] === $booking->id)));
        $this->actingAs($admin)->get('/admin/bookings?tab=requests')->assertInertia(fn (Assert $page) => $page->has('bookings.data', 1)->where('bookings.data.0.id', $booking->id));

        $this->actingAs($admin)->put("/admin/bookings/{$booking->id}/status", ['status' => 'CONFIRMED'])->assertRedirect();
        $this->assertSame('CONFIRMED', $booking->fresh()->status);
        $this->assertNull($booking->fresh()->expires_at);
        $this->actingAs($admin)->get('/admin/bookings?tab=requests')->assertInertia(fn (Assert $page) => $page->has('bookings.data', 0));
    }

    public function test_the_landing_page_offers_the_request_types(): void
    {
        $this->room();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Public/Home')->where('requestTypes.wedding', 'Wedding')->where('requestTypes.whole_resort', 'Exclusive whole-resort rental'));
        $this->get('/stays/ac-villa?type=wedding')->assertInertia(fn (Assert $page) => $page->component('Public/Stay')->where('selection.type', 'wedding')->has('callTimes.evening'));
        $this->get('/stays/ac-villa?room=7')->assertInertia(fn (Assert $page) => $page->where('selection.room', 7));
    }

    public function test_the_ac_villa_seeder_sets_up_the_resort_and_can_be_rerun(): void
    {
        $this->seed(AcVillaSeeder::class);
        $this->seed(AcVillaSeeder::class);

        $tenant = Tenant::where('slug', 'ac-villa')->sole();
        $this->assertTrue($tenant->acceptsBookings());
        $this->assertSame(['#0A2266', '#0039D5', 'ACV'], [$tenant->primary_color, $tenant->secondary_color, $tenant->setting('reference_prefix')]);
        $rooms = Room::where('tenant_id', $tenant->id)->where('active', true)->orderBy('id')->get();
        // The published rate card: name => [rate, pax, extra person fee, kind].
        $this->assertSame([
            'Barkada Room' => ['3500.00', 8, '250.00', 'room'], 'AH Room 1' => ['3500.00', 4, '250.00', 'room'],
            'AH Room 2' => ['4999.00', 10, '250.00', 'room'], 'AH Room 3' => ['4999.00', 10, '250.00', 'room'],
            'Function Hall' => ['7000.00', 50, '0.00', 'venue'], 'Grand Hall' => ['15000.00', 100, '0.00', 'venue'],
            'Exclusive Whole Resort Package' => ['38000.00', 60, '0.00', 'room'],
        ], $rooms->mapWithKeys(fn (Room $room) => [$room->name => [$room->base_rate, $room->base_guests, $room->extra_guest_fee, $room->kind]])->all());
        $this->assertTrue($rooms->take(4)->every(fn (Room $room) => $room->quantity === 1 && $room->maximum_occupancy === 15 && ! $room->whole_property && count($room->photos) >= 4));
        $this->assertSame('2000.00', AddOn::where('tenant_id', $tenant->id)->sole()->price);
        $package = $rooms->firstWhere('slug', 'whole-resort');
        $this->assertTrue($package->whole_property);
        $this->assertSame('38000.00', $package->base_rate);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('tenant.slug', 'ac-villa')->has('featured', 1));
    }

    public function test_the_site_shows_ac_villa_even_when_other_tenants_exist(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(AcVillaSeeder::class);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('tenant.slug', 'ac-villa')->where('featured.0.rooms.4.kind', 'venue'));
        $this->get('/?tenant=island-collection')->assertInertia(fn (Assert $page) => $page->where('tenant.slug', 'island-collection'));
    }

    public function test_each_a_house_is_tracked_on_its_own_and_the_whole_resort_package_blocks_them_all(): void
    {
        Mail::fake();
        $this->seed(AcVillaSeeder::class);
        $rooms = Room::where('active', true)->get()->keyBy('slug');
        $availability = app(AvailabilityService::class);
        [$in, $out] = [today()->addDays(10)->toDateString(), today()->addDays(11)->toDateString()];

        // A-House #1 booked: #2 is still free, the whole-resort package is not.
        $this->post('/bookings', $this->request($rooms['barkada-room'], ['check_in' => $in, 'check_out' => $out]))->assertSessionHasNoErrors();
        $this->assertFalse($availability->available($rooms['barkada-room'], $in, $out));
        $this->assertTrue($availability->available($rooms['ah-room-1'], $in, $out));
        $this->assertTrue($availability->available($rooms['function-hall'], $in, $out));
        $this->assertFalse($availability->available($rooms['whole-resort'], $in, $out));
        $this->post('/bookings', $this->request($rooms['whole-resort'], ['check_in' => $in, 'check_out' => $out]))->assertSessionHasErrors('check_in');

        // The whole-resort package on other dates blocks every A-House and the hall.
        [$in, $out] = [today()->addDays(20)->toDateString(), today()->addDays(21)->toDateString()];
        $this->post('/bookings', $this->request($rooms['whole-resort'], ['check_in' => $in, 'check_out' => $out, 'guests' => 40, 'request_type' => 'wedding']))->assertSessionHasNoErrors();
        foreach (['barkada-room', 'ah-room-1', 'ah-room-2', 'ah-room-3', 'function-hall'] as $slug) {
            $this->assertFalse($availability->available($rooms[$slug], $in, $out), $slug);
        }
        $this->assertSame('38000.00', Booking::where('room_id', $rooms['whole-resort']->id)->sole()->total);
        $this->post('/bookings', $this->request($rooms['ah-room-2'], ['check_in' => $in, 'check_out' => $out]))->assertSessionHasErrors('check_in');
    }
}
