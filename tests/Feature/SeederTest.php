<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_seed_creates_only_ac_villa_without_demo_credentials(): void
    {
        config(['platform.demo_mode' => false]);
        $this->seed();

        $this->assertDatabaseCount('subscription_plans', 2);
        $this->assertSame(['ac-villa'], Tenant::pluck('slug')->all());
        $this->assertDatabaseCount('users', 0);
        $this->assertEquals(29988, SubscriptionPlan::where('code', 'ANNUAL')->value('billing_price'));

        SubscriptionPlan::where('code', 'MONTHLY')->update(['billing_price' => 3999]);
        $this->seed();
        $this->assertEquals(3999, SubscriptionPlan::where('code', 'MONTHLY')->value('billing_price'));
    }

    public function test_demo_seed_populates_two_tenants_and_is_repeatable(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertDatabaseCount('subscription_plans', 2);
        $this->assertDatabaseCount('tenants', 2);
        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('properties', 4);
        $this->assertDatabaseCount('rooms', 7);
        $this->assertDatabaseCount('bookings', 5);
        $this->assertDatabaseCount('booking_prices', 5);
        $this->assertDatabaseCount('payments', 3);
        $this->assertDatabaseCount('blocked_dates', 1);

        $island = Tenant::where('slug', 'island-collection')->firstOrFail();
        $cebu = Tenant::where('slug', 'cebu-heritage-stays')->firstOrFail();
        $this->assertTrue($island->acceptsBookings());
        $this->assertTrue($cebu->acceptsBookings());
        $this->assertEquals(1, $island->subscriptions()->firstOrFail()->commitment_months);
        $this->assertEquals(12, $cebu->subscriptions()->firstOrFail()->commitment_months);
        $this->assertEquals(29988, $cebu->subscriptions()->firstOrFail()->billing_total);

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $admin2 = User::where('email', 'admin2@example.com')->firstOrFail();
        $this->assertEquals($island->id, $admin->tenant_id);
        $this->assertEquals($cebu->id, $admin2->tenant_id);
        $this->assertTrue(Hash::check('Password123!', $admin->password));

        $confirmed = Booking::where('reference', 'EAJ-DEMO-CONFIRMED')->firstOrFail();
        $this->assertEquals('CONFIRMED', $confirmed->status);
        $this->assertEquals('PAID', $confirmed->payment_status);
        $this->assertEquals($confirmed->total, $confirmed->price->total);
        $this->assertDatabaseHas('payments', ['booking_id' => $confirmed->id, 'status' => 'PAID']);
    }

    public function test_database_seed_never_adds_the_sample_tenants_even_in_demo_mode(): void
    {
        config(['platform.demo_mode' => true]);
        $this->seed();
        $this->assertSame(['ac-villa'], Tenant::pluck('slug')->all());
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
        $this->assertDatabaseMissing('properties', ['slug' => 'azure-coast-villas']);
    }

    public function test_production_seed_never_creates_demo_accounts_even_if_demo_flag_is_on(): void
    {
        $this->app['env'] = 'production';
        config(['platform.demo_mode' => true]);
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseCount('subscription_plans', 2);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(['ac-villa'], Tenant::pluck('slug')->all());
    }

    public function test_the_seed_sets_up_the_instapay_qr_once_and_keeps_the_owners_changes(): void
    {
        $this->seed();
        $tenant = Tenant::where('slug', 'ac-villa')->firstOrFail();
        $gcash = $tenant->settings['payment_methods']['gcash'];
        $this->assertSame([true, '/images/acvilla/instapay-qr.jpg', 'JOL***L A.'], [$gcash['enabled'], $gcash['qr_image'], $gcash['account_name']]);
        $this->assertFileExists(public_path('images/acvilla/instapay-qr.jpg'));

        $settings = $tenant->settings;
        $settings['payment_methods']['gcash'] = [...$gcash, 'qr_image' => '/uploads/site/new-qr.png', 'account_name' => 'NEW N.'];
        $tenant->update(['settings' => $settings]);
        $this->seed();
        $kept = $tenant->fresh()->settings['payment_methods']['gcash'];
        $this->assertSame(['/uploads/site/new-qr.png', 'NEW N.'], [$kept['qr_image'], $kept['account_name']]);
    }

    public function test_conflict_test_seed_books_fixed_november_dates_without_overlaps(): void
    {
        $this->seed();
        $this->seed(\Database\Seeders\ConflictTestSeeder::class);
        $this->seed(\Database\Seeders\ConflictTestSeeder::class);

        $year = now(config('platform.timezone'))->year;
        $nov = fn (int $day) => sprintf('%d-11-%02d', $year, $day);
        $bookings = Booking::with('room')->orderBy('check_in')->orderBy('id')->get();
        $this->assertSame([
            ['Barkada Room', $nov(5), $nov(6)], ['Exclusive Whole Resort Package', $nov(6), $nov(7)],
            ['AH Room 1', $nov(8), $nov(9)], ['AH Room 2', $nov(8), $nov(9)], ['AH Room 3', $nov(8), $nov(9)],
        ], $bookings->map(fn (Booking $b) => [$b->room->name, $b->check_in->toDateString(), $b->check_out->toDateString()])->all());
        // Approved: downpayment paid, nothing double-booked.
        $bookings->each(fn (Booking $b) => $this->assertSame(['CONFIRMED', 'PARTIALLY_PAID'], [$b->status, $b->payment_status]));
        $availability = app(\App\Services\AvailabilityService::class);
        $rooms = Room::pluck('id', 'slug');
        $free = fn (string $slug, int $day) => $availability->available(Room::find($rooms[$slug]), $nov($day), $nov($day + 1));
        $this->assertFalse($free('function-hall', 6));
        $this->assertTrue($free('function-hall', 7));
        $this->assertFalse($free('ah-room-2', 8));
        $this->assertTrue($free('barkada-room', 8));
    }

    public function test_reseeding_refreshes_a_house_photos_but_keeps_owner_uploads(): void
    {
        $this->seed();
        $barkada = Room::where('slug', 'barkada-room')->sole();
        $this->assertSame('/images/acvilla/aframe-front.jpg', $barkada->photos[0]);
        $this->assertContains('/images/acvilla/aframe-loft.jpg', Room::where('slug', 'ah-room-3')->sole()->photos);

        $barkada->update(['photos' => ['/storage/uploads/owner-photo.jpg']]);
        $this->seed();
        $this->assertSame(['/storage/uploads/owner-photo.jpg'], $barkada->fresh()->photos);
    }
}
