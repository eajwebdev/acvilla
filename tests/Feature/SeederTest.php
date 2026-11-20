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
        $this->assertSame(['administrator', 'guest', 'staff', 'superadmin'], User::orderBy('username')->pluck('username')->all());
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
        $this->assertEquals(54000, SubscriptionPlan::where('code', 'ANNUAL')->value('billing_price'));
        $this->assertEquals(6000, SubscriptionPlan::where('code', 'MONTHLY')->value('billing_price'));

        SubscriptionPlan::where('code', 'MONTHLY')->update(['billing_price' => 5500]);
        $this->seed();
        $this->assertEquals(5500,SubscriptionPlan::where('code', 'MONTHLY')->value('billing_price'));
    }

    public function test_local_seed_creates_username_logins_that_can_sign_in(): void
    {
        $this->app['env'] = 'local';
        $this->seed();
        $this->seed();
        $this->app['env'] = 'testing';

        $tenant = Tenant::where('slug', 'ac-villa')->firstOrFail();
        $this->assertDatabaseCount('users', 4);
        $this->assertSame(['ADMIN', $tenant->id], [User::where('username', 'administrator')->value('role'), User::where('username', 'administrator')->value('tenant_id')]);
        $this->assertSame(['STAFF', $tenant->id], [User::where('username', 'staff')->value('role'), User::where('username', 'staff')->value('tenant_id')]);
        $this->assertSame(['SUPERADMIN', null], [User::where('username', 'superadmin')->value('role'), User::where('username', 'superadmin')->value('tenant_id')]);
        $this->assertSame(['GUEST', null], [User::where('username', 'guest')->value('role'), User::where('username', 'guest')->value('tenant_id')]);

        $this->post('/login', ['email' => 'administrator', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        foreach ([['superadmin', 'superadmin@123'], ['administrator', 'Admin@123'], ['staff', 'staff@123'], ['guest', 'guest@123']] as [$username, $password]) {
            $this->post('/login', ['email' => $username, 'password' => $password])->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs(User::where('username', $username)->sole());
            $this->post('/logout');
        }
    }

    public function test_a_deactivated_username_cannot_sign_in(): void
    {
        $this->app['env'] = 'local';
        $this->seed();
        $this->app['env'] = 'testing';
        User::where('username', 'staff')->update(['deactivated_at' => now()]);

        $this->post('/login', ['email' => 'staff', 'password' => 'staff@123'])->assertSessionHasErrors('email');
        $this->assertGuest();
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
        $this->assertEquals(54000, $cebu->subscriptions()->firstOrFail()->billing_total);

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
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.com']);
        $this->assertSame(['ac-villa'], Tenant::pluck('slug')->all());
    }

    public function test_reseeding_keeps_a_password_changed_after_first_login(): void
    {
        $this->seed();
        User::where('username', 'administrator')->sole()->update(['password' => Hash::make('a-new-strong-password')]);
        $this->seed();

        $this->assertTrue(Hash::check('a-new-strong-password', User::where('username', 'administrator')->sole()->password));
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

    public function test_the_seed_adds_no_bookings_or_customers(): void
    {
        $this->seed();
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_the_cleanup_migration_removes_only_the_sample_bookings_and_their_customers(): void
    {
        $this->seed();
        $tenant = Tenant::where('slug', 'ac-villa')->sole();
        $sample = $this->booking($tenant, 'ACV-TEST-RESORT', 'whole-resort', 'Maria Dela Cruz', '0917 555 0102');
        $this->booking($tenant, 'ACV-TEST-AH1', 'ah-room-1', 'Jose Ramos', '0917 555 0103');
        $real = $this->booking($tenant, 'ACV-REAL-1', 'barkada-room', 'Real Guest', '0917 000 1111');
        // A sample guest who also made a real booking is a real customer: they stay.
        $both = $this->booking($tenant, 'ACV-TEST-AH2', 'ah-room-2', 'Ana Villanueva', '0917 555 0104');
        $this->booking($tenant, 'ACV-REAL-2', 'ah-room-3', 'Ana Villanueva', '0917 555 0104');
        \App\Models\CleaningTask::create(['tenant_id' => $tenant->id, 'room_id' => $sample->room_id, 'booking_id' => $sample->id, 'date' => $sample->check_out, 'checklist' => []]);
        $sample->delete(); // soft-deleted sample bookings go too

        (require database_path('migrations/2026_11_20_000001_remove_sample_bookings_and_customers.php'))->up();

        $this->assertSame(['ACV-REAL-1', 'ACV-REAL-2'], Booking::withTrashed()->orderBy('reference')->pluck('reference')->all());
        $this->assertSame(['Ana Villanueva', 'Real Guest'], \App\Models\Customer::orderBy('name')->pluck('name')->all());
        $this->assertDatabaseCount('cleaning_tasks', 0);
        $this->assertDatabaseMissing('payments', ['reference' => 'PAY-ACV-TEST-RESORT']);
        $this->assertDatabaseHas('payments', ['booking_id' => $real->id]);
        $this->assertNull($both->fresh());
    }

    private function booking(Tenant $tenant, string $reference, string $slug, string $name, string $phone): Booking
    {
        $room = Room::where('tenant_id', $tenant->id)->where('slug', $slug)->sole();
        $in = today()->addDays(30)->toDateString();
        $out = today()->addDays(31)->toDateString();
        $quote = app(\App\Services\PricingService::class)->quote($room, $in, $out, 2);
        $customer = \App\Models\Customer::fromGuest($tenant->id, ['guest_name' => $name, 'guest_phone' => $phone]);
        $booking = Booking::create(['reference' => $reference, 'tenant_id' => $tenant->id, 'property_id' => $room->property_id, 'room_id' => $room->id,
            'customer_id' => $customer->id, 'guest_name' => $name, 'guest_phone' => $phone, 'check_in' => $in, 'check_out' => $out, 'guests' => 2,
            'status' => 'CONFIRMED', 'payment_status' => 'PARTIALLY_PAID', 'source' => 'website', 'total' => $quote['total']]);
        $booking->price()->create($quote);
        \App\Models\Payment::create(['reference' => 'PAY-'.$reference, 'tenant_id' => $tenant->id, 'booking_id' => $booking->id, 'method' => 'gcash',
            'provider' => 'manual', 'status' => 'PAID', 'amount' => 100, 'paid_at' => now()]);

        return $booking;
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
