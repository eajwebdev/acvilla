<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Review;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomersAndReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_customer_directory_groups_stays_tracks_paid_spend_and_is_tenant_scoped(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $staff = User::where('email', 'staff@example.com')->firstOrFail();
        $otherAdmin = User::where('email', 'admin2@example.com')->firstOrFail();
        $customer = Customer::where('tenant_id', $admin->tenant_id)->firstOrFail();

        $this->actingAs($admin)->get('/admin/customers')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Customers')->has('customers.data', 1)
            ->where('customers.data.0.name', 'Demo Guest')
            ->where('customers.data.0.bookings_count', 4));
        $this->get('/admin/customers/'.$customer->id)->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Customer')->where('summary.bookings', 4)->where('summary.spent', fn ($value) => $value > 0));
        $this->put('/admin/customers/'.$customer->id, ['status' => 'VIP', 'notes' => 'Prefers a quiet room.'])->assertRedirect();
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'status' => 'VIP', 'notes' => 'Prefers a quiet room.']);

        $this->actingAs($staff)->get('/admin/customers')->assertForbidden();
        $this->actingAs($otherAdmin)->get('/admin/customers/'.$customer->id)->assertNotFound();
    }

    public function test_local_phone_numbers_are_normalized_and_blocked_customers_cannot_book(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $room = Room::where('tenant_id', $admin->tenant_id)->firstOrFail();
        $data = ['room_id' => $room->id, 'check_in' => today()->addDays(40)->toDateString(), 'check_out' => today()->addDays(42)->toDateString(),
            'guests' => 1, 'guest_name' => 'Phone Guest', 'guest_phone' => '0917 123 4567', 'source' => 'phone'];

        $this->actingAs($admin)->post('/admin/bookings', $data)->assertRedirect();
        $customer = Customer::where('tenant_id', $admin->tenant_id)->where('phone', '+639171234567')->firstOrFail();
        $this->assertDatabaseHas('bookings', ['customer_id' => $customer->id, 'guest_phone' => '+639171234567']);
        $customer->update(['status' => 'BLOCKED']);
        $data['check_in'] = today()->addDays(50)->toDateString();
        $data['check_out'] = today()->addDays(52)->toDateString();
        $this->post('/admin/bookings', $data)->assertSessionHasErrors('guest_email');
    }

    public function test_completed_guest_can_review_once_and_admin_moderates_before_publication(): void
    {
        $guest = User::where('email', 'guest@example.com')->firstOrFail();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $otherAdmin = User::where('email', 'admin2@example.com')->firstOrFail();
        $booking = Booking::where('reference', 'EAJ-DEMO-COMPLETED')->firstOrFail();

        $this->actingAs($guest)->post('/account/reviews', ['booking_id' => $booking->id, 'rating' => 5, 'comment' => 'Warm service and a spotless room.'])->assertRedirect();
        $review = Review::firstOrFail();
        $this->assertFalse($review->approved);
        $this->post('/account/reviews', ['booking_id' => $booking->id, 'rating' => 4])->assertStatus(422);
        $this->get('/stays/azure-coast-villas')->assertInertia(fn (Assert $page) => $page->has('reviews', 0));

        $this->actingAs($otherAdmin)->put('/admin/reviews/'.$review->id, ['approved' => true])->assertNotFound();
        $this->actingAs($admin)->get('/admin/reviews')->assertInertia(fn (Assert $page) => $page->component('Admin/Reviews')->has('reviews.data', 1)->where('pending', 1));
        $this->put('/admin/reviews/'.$review->id, ['approved' => true])->assertRedirect();
        $this->get('/stays/azure-coast-villas')->assertInertia(fn (Assert $page) => $page->has('reviews', 1)->where('reviewSummary.average', 5));
    }

    public function test_guest_cannot_review_an_unfinished_or_someone_elses_stay(): void
    {
        $guest = User::where('email', 'guest@example.com')->firstOrFail();
        $pending = Booking::where('reference', 'EAJ-DEMO-PENDING')->firstOrFail();
        $this->actingAs($guest)->post('/account/reviews', ['booking_id' => $pending->id, 'rating' => 5])->assertStatus(422);

        $stranger = User::factory()->create(['role' => 'GUEST']);
        $completed = Booking::where('reference', 'EAJ-DEMO-COMPLETED')->firstOrFail();
        $this->actingAs($stranger)->post('/account/reviews', ['booking_id' => $completed->id, 'rating' => 5])->assertNotFound();
    }
}
