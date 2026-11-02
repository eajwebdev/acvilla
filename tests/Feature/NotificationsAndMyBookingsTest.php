<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingNotification;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The owner's notification bell, and the guest's "My bookings" page. */
class NotificationsAndMyBookingsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Room $house;

    private Room $hall;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        config(['platform.site_tenant' => 'ac-villa']);
        $this->tenant = Tenant::create(['name' => 'AC Villa', 'slug' => 'ac-villa', 'business_name' => 'AC Villa & Private Resort', 'business_phone' => '0917 000 0000',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $settings = $this->tenant->settings;
        $settings['payment_methods']['gcash'] = [...$settings['payment_methods']['gcash'], 'enabled' => true, 'account_name' => 'AC Villa', 'account_number' => '0927 493 1583'];
        $this->tenant->update(['settings' => [...$settings, 'deposit_percent' => 50, 'service_fee_percent' => 0]]);
        $property = Property::create(['tenant_id' => $this->tenant->id, 'name' => 'AC Villa', 'slug' => 'ac-villa', 'city' => 'Mabinay', 'province' => 'Negros Oriental', 'status' => 'PUBLISHED']);
        $this->house = Room::create(['tenant_id' => $this->tenant->id, 'property_id' => $property->id, 'name' => 'Barkada Room', 'slug' => 'barkada-room',
            'quantity' => 1, 'maximum_occupancy' => 15, 'base_guests' => 8, 'base_rate' => 3500, 'cleaning_fee' => 0, 'active' => true]);
        $this->hall = Room::create(['tenant_id' => $this->tenant->id, 'property_id' => $property->id, 'name' => 'Function Hall', 'slug' => 'function-hall', 'kind' => 'venue',
            'quantity' => 1, 'maximum_occupancy' => 50, 'base_guests' => 50, 'base_rate' => 7000, 'cleaning_fee' => 0, 'active' => true]);
        $this->owner = User::create(['name' => 'AC Villa Owner', 'email' => 'owner@acvilla.test', 'password' => 'secret-password', 'role' => 'ADMIN', 'tenant_id' => $this->tenant->id]);
        $this->staff = User::create(['name' => 'Front Desk', 'email' => 'desk@acvilla.test', 'password' => 'secret-password', 'role' => 'STAFF', 'tenant_id' => $this->tenant->id]);
    }

    private function day(int $days): string
    {
        return today()->addDays($days)->toDateString();
    }

    private function request(array $rooms, string $phone = '0906 308 4301'): Booking
    {
        $this->post('/bookings', ['items' => array_map(fn (Room $room) => ['room_id' => $room->id, 'check_in' => $this->day(14), 'check_out' => $this->day(15), 'guests' => 8], $rooms),
            'guest_name' => 'Jophed Abril', 'guest_phone' => $phone, 'guest_email' => 'guest@example.com'])->assertSessionHasNoErrors();

        return Booking::orderBy('id')->firstOrFail();
    }

    /** @return list<string> the bell's titles, newest first */
    private function bell(User $user): array
    {
        return array_column($this->actingAs($user)->getJson('/admin/notifications')->assertOk()->json('items'), 'title');
    }

    public function test_a_website_request_shows_in_the_owners_bell_and_bookings_list(): void
    {
        $booking = $this->request([$this->hall, $this->house]);

        $items = $this->actingAs($this->owner)->getJson('/admin/notifications')->assertOk()->json('items');
        $this->assertCount(1, $items, 'Facilities requested together are one notification');
        $this->assertSame(['requested', 'New booking request', $booking->id, true, true], [$items[0]['event'], $items[0]['title'], $items[0]['booking_id'], $items[0]['unread'], $items[0]['needs_action']]);
        $this->assertStringContainsString('Jophed Abril · Function Hall + Barkada Room', $items[0]['detail']);
        $this->assertStringContainsString('₱10,500.00', $items[0]['detail']);
        $this->get('/admin/bookings')->assertInertia(fn (Assert $page) => $page->where('notificationCount', 1)->where('requestCount', 2)
            ->where('bookings.data.0.reference', Booking::latest('id')->first()->reference));
        $this->getJson('/admin/notifications/count')->assertExactJson(['unread' => 1]);

        // Another business never sees it.
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'business_name' => 'Other', 'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $stranger = User::create(['name' => 'Stranger', 'email' => 'x@example.com', 'password' => 'secret-password', 'role' => 'ADMIN', 'tenant_id' => $other->id]);
        $this->assertSame([], $this->bell($stranger));
    }

    public function test_every_step_of_the_booking_is_in_the_bell(): void
    {
        $booking = $this->request([$this->house]);
        $this->actingAs($this->owner)->put("/admin/bookings/{$booking->id}/status", ['status' => 'AWAITING_PAYMENT'])->assertSessionHasNoErrors();
        $this->post("/booking/{$booking->reference}/proof", ['method' => 'gcash', 'proof' => UploadedFile::fake()->image('receipt.jpg')])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->put('/admin/payments/'.Payment::first()->id.'/review', ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->put("/admin/bookings/{$booking->id}/extend", ['check_out' => $this->day(16)])->assertSessionHasNoErrors();
        $this->post("/admin/bookings/{$booking->id}/payments", ['amount' => 1000, 'method' => 'cash'])->assertSessionHasNoErrors();
        $this->put("/admin/bookings/{$booking->id}/status", ['status' => 'CHECKED_IN'])->assertSessionHasNoErrors();

        $this->assertSame(['Guest checked in', 'Payment recorded', 'Stay extended', 'Downpayment received', 'Downpayment receipt uploaded', 'Dates approved', 'New booking request'], $this->bell($this->staff));
        $events = BookingNotification::orderBy('id')->get();
        $this->assertStringContainsString('sent ₱1,750.00 by GCash', $events->firstWhere('event', 'receipt_uploaded')->detail);
        $this->assertStringContainsString('Waiting for the ₱1,750.00 downpayment', $events->firstWhere('event', 'dates_approved')->detail);
        $this->assertStringContainsString('paid ₱1,750.00', $events->firstWhere('event', 'payment_approved')->detail);
        $this->assertSame('AC Villa Owner', $events->firstWhere('event', 'dates_approved')->actor);
        $this->assertNull($events->firstWhere('event', 'receipt_uploaded')->actor);
    }

    public function test_declines_and_expired_requests_are_reported(): void
    {
        $declined = $this->request([$this->house]);
        $this->actingAs($this->owner)->put("/admin/bookings/{$declined->id}/status", ['status' => 'CANCELLED'])->assertSessionHasNoErrors();
        $this->flushSession();
        $this->post('/bookings', ['items' => [['room_id' => $this->hall->id, 'check_in' => $this->day(20), 'check_out' => $this->day(21), 'guests' => 30]],
            'guest_name' => 'Ana Reyes', 'guest_phone' => '0918 111 2222', 'guest_email' => 'ana@example.com'])->assertSessionHasNoErrors();
        Booking::where('guest_name', 'Ana Reyes')->update(['expires_at' => now()->subMinute()]);

        Artisan::call('bookings:expire-holds');
        // Newest first: Ana's request expired, Ana's request, the decline, then the first request.
        $this->assertSame(['Request expired', 'New booking request', 'Request declined', 'New booking request'], $this->bell($this->owner));
    }

    public function test_opening_the_bell_marks_it_read_for_that_user_only(): void
    {
        $this->request([$this->house]);
        $this->actingAs($this->owner)->post('/admin/notifications/seen')->assertOk();
        $this->getJson('/admin/notifications/count')->assertExactJson(['unread' => 0]);
        $this->assertFalse($this->getJson('/admin/notifications')->json('items.0.unread'));
        $this->actingAs($this->staff)->getJson('/admin/notifications/count')->assertExactJson(['unread' => 1]);

        // Something new after that is unread again.
        $this->flushSession();
        $this->post('/bookings', ['items' => [['room_id' => $this->hall->id, 'check_in' => $this->day(20), 'check_out' => $this->day(21), 'guests' => 30]],
            'guest_name' => 'Ana Reyes', 'guest_phone' => '0918 111 2222', 'guest_email' => 'ana@example.com'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->getJson('/admin/notifications/count')->assertExactJson(['unread' => 1]);
        // Closing the bell only marks what it showed: a list loaded before Ana's request leaves hers unread.
        $this->postJson('/admin/notifications/seen', ['up_to' => BookingNotification::min('id')])->assertExactJson(['unread' => 1]);
        $this->postJson('/admin/notifications/seen', ['up_to' => BookingNotification::max('id')])->assertExactJson(['unread' => 0]);
    }

    public function test_guests_cannot_open_the_bell(): void
    {
        $this->getJson('/admin/notifications')->assertUnauthorized();
        $guest = User::create(['name' => 'Guest', 'email' => 'g@example.com', 'password' => 'secret-password', 'role' => 'GUEST']);
        $this->actingAs($guest)->get('/admin/notifications')->assertForbidden();
    }

    public function test_demo_sign_in_opens_the_site_business(): void
    {
        config(['platform.demo_mode' => true]);
        $this->request([$this->house]);

        $this->flushSession();
        auth()->logout();
        $this->post('/demo/login', ['role' => 'admin'])->assertRedirect();
        $this->assertAuthenticatedAs($this->owner);
        $this->get('/admin/bookings')->assertInertia(fn (Assert $page) => $page->has('bookings.data', 1));
    }

    public function test_my_bookings_lists_the_guests_bookings_by_reference_and_phone(): void
    {
        $booking = $this->request([$this->hall, $this->house]);
        $this->flushSession();

        // A different phone number gets nothing, and the booking page stays private.
        $this->postJson('/booking/summaries', ['items' => [['reference' => $booking->reference, 'phone' => '0999 000 0000']]])->assertOk()->assertExactJson(['bookings' => []]);
        $this->get('/booking/success/'.$booking->reference)->assertForbidden();

        $summary = $this->postJson('/booking/summaries', ['items' => [['reference' => strtolower($booking->reference), 'phone' => '+63 906 308 4301']]])->assertOk()->json('bookings.0');
        $this->assertSame([$booking->reference, ['Function Hall', 'Barkada Room'], 'requested', 10500], [$summary['reference'], $summary['facilities'], $summary['stage'], (int) $summary['total']]);
        // Found means this phone may now open the booking page.
        $this->get('/booking/success/'.$booking->reference)->assertOk();

        $this->actingAs($this->owner)->put("/admin/bookings/{$booking->id}/status", ['status' => 'AWAITING_PAYMENT']);
        $this->assertSame('due', $this->postJson('/booking/summaries', ['items' => [['reference' => $booking->reference, 'phone' => '0906 308 4301']]])->json('bookings.0.stage'));
        $this->get('/booking')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Public/FindBooking'));
    }
}
