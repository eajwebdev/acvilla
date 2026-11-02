<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Room;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Production safety: no address reaches another business's data, browser hardening headers, and unsafe settings refused. */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_an_id_in_the_address_never_reaches_another_business(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $other = User::where('email', 'admin2@example.com')->firstOrFail();
        $booking = Booking::where('tenant_id', $other->tenant_id)->firstOrFail();
        $customer = Customer::where('tenant_id', $other->tenant_id)->firstOrFail();
        $property = Property::where('tenant_id', $other->tenant_id)->firstOrFail();
        $room = Room::where('tenant_id', $other->tenant_id)->firstOrFail();
        $state = fn (Booking $b) => [$b->status, $b->guest_name, $b->check_out->toDateString(), $b->deposit_amount, $b->trashed()];
        $before = $state($booking);
        $payments = $booking->payments()->count();

        $this->actingAs($admin);
        foreach ([
            ['get', "/admin/bookings/{$booking->id}"],
            ['put', "/admin/bookings/{$booking->id}", ['guest_name' => 'Hacked', 'source' => 'direct']],
            ['put', "/admin/bookings/{$booking->id}/status", ['status' => 'CANCELLED']],
            ['put', "/admin/bookings/{$booking->id}/extend", ['check_out' => today()->addDays(40)->toDateString()]],
            ['put', "/admin/bookings/{$booking->id}/deposit", ['amount' => 1]],
            ['post', "/admin/bookings/{$booking->id}/payments", ['amount' => 1, 'method' => 'cash']],
            ['delete', "/admin/bookings/{$booking->id}"],
            ['get', "/admin/customers/{$customer->id}"],
            ['put', "/admin/customers/{$customer->id}", ['status' => 'BLOCKED']],
            ['put', "/admin/properties/{$property->id}", ['name' => 'Hacked', 'city' => 'X', 'province' => 'X', 'type' => 'Resort', 'status' => 'DRAFT']],
            ['put', "/admin/rooms/{$room->id}", ['name' => 'Hacked']],
            ['put', "/admin/settings/team/{$other->id}", ['role' => 'STAFF']],
            ['put', "/admin/settings/team/{$other->id}/status", ['active' => false]],
        ] as $attempt) {
            [$method, $uri, $data] = [...$attempt, []];
            $status = $this->{$method}($uri, $data)->status();
            $this->assertContains($status, [403, 404], "{$method} {$uri} answered {$status}");
        }
        $this->assertSame($before, $state(Booking::withTrashed()->find($booking->id)));
        $this->assertSame($payments, $booking->payments()->count());
        $this->assertSame('ADMIN', $other->fresh()->role);
        $this->assertNull($other->fresh()->deactivated_at);
        $this->assertNotSame('Hacked', $property->fresh()->name);
    }

    public function test_guests_cannot_open_or_change_someone_elses_booking(): void
    {
        $this->seed(DemoSeeder::class);
        $booking = Booking::firstOrFail();
        foreach (["/booking/success/{$booking->reference}", "/booking/{$booking->reference}/live", "/booking/{$booking->reference}/calendar.ics"] as $uri) {
            $this->get($uri)->assertForbidden();
        }
        $this->post("/booking/{$booking->reference}/proof", ['method' => 'gcash'])->assertForbidden();
        // Signed in, but as a different guest.
        $guest = User::create(['name' => 'Someone', 'email' => 'someone@example.net', 'password' => 'x-password-123', 'role' => 'GUEST']);
        $this->actingAs($guest)->get("/booking/success/{$booking->reference}")->assertForbidden();
        $this->get('/admin')->assertForbidden();
    }

    public function test_every_page_carries_the_browser_hardening_headers(): void
    {
        $response = $this->get('/');
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('geolocation=()', $response->headers->get('Permissions-Policy'));
        $this->assertFalse($response->headers->has('X-Powered-By'));
        // Scripts only from this site; no plugins; nobody else may frame the pages.
        foreach (["script-src 'self'", "object-src 'none'", "frame-ancestors 'self'", "base-uri 'self'", "form-action 'self'"] as $rule) {
            $this->assertStringContainsString($rule, SecurityHeaders::CSP);
        }
        $this->assertStringNotContainsString('unsafe-eval', SecurityHeaders::CSP);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", SecurityHeaders::CSP);
    }

    public function test_signed_in_pages_are_never_cached(): void
    {
        $this->seed(DemoSeeder::class);
        $this->actingAs(User::where('email', 'admin@example.com')->firstOrFail())->get('/admin')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_demo_logins_and_insecure_cookies_cannot_be_on_in_production(): void
    {
        $load = function (string $file, array $env): array {
            $old = array_map(fn ($key) => getenv($key), array_combine(array_keys($env), array_keys($env)));
            foreach ($env as $key => $value) {
                putenv("{$key}={$value}"); $_ENV[$key] = $value; $_SERVER[$key] = $value;
            }
            try {
                return require config_path($file);
            } finally {
                foreach ($old as $key => $value) {
                    $value === false ? putenv($key) : putenv("{$key}={$value}");
                    unset($_ENV[$key], $_SERVER[$key]);
                }
            }
        };
        $this->assertFalse($load('platform.php', ['APP_ENV' => 'production', 'DEMO' => 'true'])['demo_mode']);
        $this->assertTrue($load('platform.php', ['APP_ENV' => 'local', 'DEMO' => 'true'])['demo_mode']);
        $this->assertTrue($load('session.php', ['APP_ENV' => 'production'])['secure']);
    }

    public function test_the_production_check_refuses_an_unsafe_setup(): void
    {
        // The test environment is (rightly) not production: the check must fail and say why.
        $this->artisan('app:production-check')->expectsOutputToContain('APP_ENV=production')->assertExitCode(1);
    }

    public function test_the_web_server_never_serves_hidden_files_or_runs_uploads(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));
        $this->assertStringContainsString('RewriteRule (^|/)\.(?!well-known/) - [F,L]', $htaccess);
        $this->assertMatchesRegularExpression('/RewriteRule \^uploads\/\.\*\\\\\.\(php/', $htaccess);
    }
}
