<?php

namespace Tests\Feature;

use App\Mail\GuestMessage;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class SystemReliabilityTest extends TestCase
{
    use RefreshDatabase;

    private function room(string $slug = 'bay'): Room
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'business_name' => ucfirst($slug).' Resort', 'status' => 'ACTIVE',
            'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $property = Property::create(['tenant_id' => $tenant->id, 'name' => 'Villas', 'slug' => $slug, 'city' => 'Cebu', 'province' => 'Cebu', 'status' => 'PUBLISHED']);

        return Room::create(['tenant_id' => $tenant->id, 'property_id' => $property->id, 'name' => 'Suite', 'slug' => 'suite',
            'quantity' => 5, 'maximum_occupancy' => 4, 'base_rate' => 1000, 'cleaning_fee' => 0, 'active' => true]);
    }

    private function book(Room $room): Booking
    {
        $this->post('/bookings', ['room_id' => $room->id, 'guests' => 2, 'guest_name' => 'Ana Reyes', 'guest_email' => 'ana@example.com', 'guest_phone' => '0917 123 4567',
            'check_in' => today()->addDays(20)->toDateString(), 'check_out' => today()->addDays(22)->toDateString()])->assertRedirect();

        return Booking::latest('id')->firstOrFail();
    }

    private function user(string $role, ?int $tenantId): User
    {
        return User::create(['name' => $role, 'email' => strtolower($role).$tenantId.'@test.ph', 'password' => 'Password123!', 'role' => $role, 'tenant_id' => $tenantId]);
    }

    private function mailServerDown(): void
    {
        Mail::extend('down', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection to smtp.example.com timed out');
            }

            public function __toString(): string
            {
                return 'down';
            }
        });
        config(['mail.mailers.down' => ['transport' => 'down'], 'mail.default' => 'down']);
    }

    public function test_a_failed_email_is_retried_on_the_same_log_row_after_a_backoff(): void
    {
        $booking = $this->book($this->room());
        $this->mailServerDown();
        $booking->update(['status' => 'CONFIRMED']);

        $message = BookingMessage::where('event', '!=', 'booking_requested')->sole();
        $this->assertSame(['FAILED', 1], [$message->status, $message->attempts]);
        $this->assertStringContainsString('timed out', $message->error);

        Mail::fake();
        $this->artisan('messages:retry-failed')->expectsOutputToContain('Retried 0')->assertSuccessful();
        $this->travel(11)->minutes();
        $this->artisan('messages:retry-failed')->expectsOutputToContain('1 sent')->assertSuccessful();

        $message->refresh();
        $this->assertSame(['SENT', 2, null], [$message->status, $message->attempts, $message->error]);
        $this->assertSame(1, BookingMessage::where('event', '!=', 'booking_requested')->count());
        Mail::assertSent(GuestMessage::class, 1);

        // A later status change does not send the one-time confirmation again.
        $booking->refresh()->update(['status' => 'CHECKED_IN']);
        $booking->update(['status' => 'CONFIRMED']);
        Mail::assertSent(GuestMessage::class, 1);
    }

    public function test_retries_skip_emails_the_booking_has_outgrown_and_stop_after_the_window(): void
    {
        $booking = $this->book($this->room());
        $booking->updateQuietly(['status' => 'CONFIRMED']);
        $stale = BookingMessage::create(['booking_id' => $booking->id, 'event' => 'booking_received', 'recipient' => 'ana@example.com',
            'subject' => 'Payment needed', 'status' => 'FAILED', 'error' => 'timeout']);
        $old = BookingMessage::create(['booking_id' => $booking->id, 'event' => 'payment_received', 'recipient' => 'ana@example.com',
            'subject' => 'Payment received', 'status' => 'FAILED', 'error' => 'timeout', 'created_at' => now()->subHours(49)]);
        Mail::fake();
        $this->travel(11)->minutes();

        $this->artisan('messages:retry-failed')->expectsOutputToContain('1 no longer needed')->assertSuccessful();

        $this->assertSame('SKIPPED', $stale->refresh()->status);
        $this->assertSame(['FAILED', 1], [$old->refresh()->status, $old->attempts]);
        Mail::assertNothingSent();
    }

    public function test_staff_resend_a_failed_email_from_the_booking_and_only_in_their_own_business(): void
    {
        $room = $this->room();
        $booking = $this->book($room);
        $this->mailServerDown();
        $booking->update(['status' => 'CONFIRMED']);
        $message = BookingMessage::where('event', '!=', 'booking_requested')->sole();
        $staff = $this->user('STAFF', $room->tenant_id);
        $outsider = $this->user('ADMIN', $this->room('other')->tenant_id);

        $this->actingAs($staff)->getJson("/admin/bookings/{$booking->id}")
            ->assertJsonPath('messages.0.id', $message->id)->assertJsonPath('messages.0.status', 'FAILED');
        $this->actingAs($outsider)->post("/admin/messages/{$message->id}/retry")->assertNotFound();

        Mail::fake();
        $this->actingAs($staff)->post("/admin/messages/{$message->id}/retry")->assertRedirect()->assertSessionHas('success');
        $this->assertSame('SENT', $message->refresh()->status);
        $this->actingAs($staff)->post("/admin/messages/{$message->id}/retry")->assertStatus(422);
    }

    public function test_a_broken_mail_server_turns_a_test_send_into_a_message_not_a_crash(): void
    {
        $admin = $this->user('ADMIN', $this->room()->tenant_id);
        $this->mailServerDown();

        $this->actingAs($admin)->post('/admin/settings/messages/booking_confirmed/test', ['subject' => 'Hi', 'body' => 'Hello'])
            ->assertRedirect()->assertSessionHasErrors('action');
    }

    public function test_health_checks_flag_problems_and_only_the_platform_owner_sees_them(): void
    {
        $this->room();
        $owner = $this->user('SUPERADMIN', null);
        $admin = $this->user('ADMIN', Tenant::first()->id);
        config(['platform.backups.path' => storage_path('framework/testing/no-backups')]);

        $this->actingAs($admin)->get('/superadmin/health')->assertForbidden();
        $this->actingAs($owner)->get('/superadmin/health')->assertInertia(fn (Assert $page) => $page->component('Superadmin/Health')
            ->where('overall', 'warn')
            ->where('checks', fn ($checks) => collect($checks)->firstWhere('key', 'scheduler')['status'] === 'warn'
                && collect($checks)->firstWhere('key', 'testPayments')['status'] === 'warn'
                && collect($checks)->firstWhere('key', 'database')['status'] === 'ok'));

        Cache::forever(SystemHealth::HEARTBEAT_KEY, now()->toIso8601String());
        $this->assertSame('ok', collect(app(SystemHealth::class)->checks())->firstWhere('key', 'scheduler')['status']);
        $this->artisan('platform:health')->assertExitCode(0);

        // On a live server the same gaps are failures, and the command exits non-zero for monitors and deploy scripts.
        $this->app['env'] = 'production';
        config(['app.debug' => true]);
        $checks = collect(app(SystemHealth::class)->checks())->keyBy('key');
        $this->assertSame('fail', $checks['configuration']['status']);
        $this->assertStringContainsString('APP_DEBUG', $checks['configuration']['detail']);
        $this->assertSame('fail', $checks['testPayments']['status']);
        $this->artisan('platform:health')->assertExitCode(1);
    }

    public function test_log_rows_are_stamped_with_app_time_not_the_database_clock(): void
    {
        $booking = $this->book($this->room());
        $this->travelTo(now()->setTime(9, 30));
        $log = AuditLog::create(['tenant_id' => $booking->tenant_id, 'action' => 'test', 'entity_type' => 'Booking', 'entity_id' => $booking->id]);
        $message = BookingMessage::create(['booking_id' => $booking->id, 'event' => 'thank_you', 'recipient' => 'a@b.ph', 'subject' => 'Hi', 'status' => 'SENT']);

        $this->assertSame('09:30', $log->refresh()->created_at->format('H:i'));
        $this->assertSame('09:30', $message->refresh()->created_at->format('H:i'));
    }

    public function test_uptime_endpoint_and_expired_sessions(): void
    {
        $this->get('/up')->assertOk();

        Route::middleware('web')->post('/_expired', fn () => abort(419));
        $this->from('/admin/bookings')->post('/_expired')->assertRedirect('/admin/bookings')->assertSessionHasErrors('action');
        $this->postJson('/_expired')->assertStatus(419);
    }
}
