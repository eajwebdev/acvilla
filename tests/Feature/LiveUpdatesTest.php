<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Open pages poll a fingerprint and refresh only when it moves: the guest's booking page and every admin page. */
class LiveUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Room $house;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->tenant = Tenant::create(['name' => 'AC Villa', 'slug' => 'ac-villa', 'business_name' => 'AC Villa & Private Resort',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $settings = $this->tenant->settings;
        $settings['payment_methods']['gcash'] = [...$settings['payment_methods']['gcash'], 'enabled' => true, 'account_name' => 'AC Villa', 'account_number' => '0927 493 1583'];
        $this->tenant->update(['settings' => [...$settings, 'deposit_percent' => 50, 'service_fee_percent' => 0]]);
        $property = Property::create(['tenant_id' => $this->tenant->id, 'name' => 'AC Villa', 'slug' => 'ac-villa', 'city' => 'Mabinay', 'province' => 'Negros Oriental', 'status' => 'PUBLISHED']);
        $this->house = Room::create(['tenant_id' => $this->tenant->id, 'property_id' => $property->id, 'name' => 'AH Room 1', 'slug' => 'ah-room-1',
            'quantity' => 1, 'maximum_occupancy' => 4, 'base_guests' => 4, 'base_rate' => 3500, 'cleaning_fee' => 0, 'active' => true]);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret-password', 'role' => 'ADMIN', 'tenant_id' => $this->tenant->id]);
    }

    private function book(): Booking
    {
        $this->post('/bookings', ['items' => [['room_id' => $this->house->id, 'check_in' => today()->addDays(5)->toDateString(), 'check_out' => today()->addDays(6)->toDateString(), 'guests' => 2]],
            'guest_name' => 'Maria Santos', 'guest_phone' => '0917 123 4567', 'guest_email' => 'maria@example.com'])->assertSessionHasNoErrors();

        return Booking::latest('id')->firstOrFail();
    }

    public function test_the_guest_page_fingerprint_moves_with_every_step(): void
    {
        $booking = $this->book();
        $stamp = fn () => $this->getJson("/booking/{$booking->reference}/live")->assertOk()->json('stamp');
        $seen = [$stamp()];
        $this->assertSame($seen[0], $stamp(), 'Nothing changed, so the page does not reload.');

        $this->actingAs($this->owner)->put("/admin/bookings/{$booking->id}/status", ['status' => 'AWAITING_PAYMENT'])->assertSessionHasNoErrors();
        $seen[] = $stamp();
        $this->post("/booking/{$booking->reference}/proof", ['method' => 'gcash', 'proof' => UploadedFile::fake()->image('receipt.jpg')])->assertSessionHasNoErrors();
        $seen[] = $stamp();
        $this->put('/admin/payments/'.$booking->payments()->firstOrFail()->id.'/review', ['decision' => 'approve'])->assertSessionHasNoErrors();
        $seen[] = $stamp();
        $this->assertCount(4, array_unique($seen));
        $this->assertSame('CONFIRMED', $booking->fresh()->status);
    }

    public function test_a_stranger_cannot_follow_someone_elses_booking(): void
    {
        $booking = $this->book();
        $this->flushSession();
        $this->getJson("/booking/{$booking->reference}/live")->assertForbidden();
        $this->getJson('/booking/ACV-NOPE/live')->assertNotFound();
    }

    public function test_the_admin_fingerprint_moves_on_new_requests_and_counts_unread(): void
    {
        $live = fn () => $this->actingAs($this->owner)->getJson('/admin/live')->assertOk()->json();
        $before = $live();
        $this->assertSame($before['stamp'], $live()['stamp']);

        $this->app['auth']->forgetGuards();
        $booking = $this->book();
        $after = $live();
        $this->assertNotSame($before['stamp'], $after['stamp']);
        $this->assertSame($before['unread'] + 1, $after['unread']);

        // Staff pages follow along too; another business's changes do not move this one.
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => 'secret-password', 'role' => 'STAFF', 'tenant_id' => $this->tenant->id]);
        $this->actingAs($staff)->getJson('/admin/live')->assertOk()->assertJsonPath('stamp', $after['stamp']);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'business_name' => 'Other', 'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        Booking::create(['tenant_id' => $other->id, 'property_id' => $booking->property_id, 'room_id' => $this->house->id, 'guest_name' => 'Elsewhere',
            'check_in' => today()->addDays(30), 'check_out' => today()->addDays(31), 'guests' => 1, 'reference' => 'OTH-1', 'status' => 'CANCELLED', 'total' => 1]);
        $this->assertSame($after['stamp'], $live()['stamp']);
        $this->getJson('/admin/live')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/admin/live')->assertUnauthorized();
    }
}
