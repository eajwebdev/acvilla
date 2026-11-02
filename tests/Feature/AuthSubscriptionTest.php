<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_login_logout_and_bad_password(): void
    {
        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'Password123!'])
            ->assertRedirect('/admin');
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_registration_cannot_choose_platform_role_or_tenant(): void
    {
        $other = Tenant::where('slug', 'cebu-heritage-stays')->firstOrFail();
        $this->post('/register', [
            'name' => 'New Traveler', 'email' => 'new@example.com', 'password' => 'LongPassword123!',
            'password_confirmation' => 'LongPassword123!', 'role' => 'SUPERADMIN', 'tenant_id' => $other->id,
        ])->assertRedirect('/account/complete'); // a new guest adds a mobile number first
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertEquals('GUEST', $user->role);
        $this->assertNull($user->tenant_id);
        $this->assertTrue(Hash::check('LongPassword123!', $user->password));
    }

    public function test_demo_flag_reaches_login_page(): void
    {
        config(['platform.demo_mode' => false]);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('demo', false));
        config(['platform.demo_mode' => true]);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('demo', true));
    }

    public function test_assigning_plan_snapshots_price_and_preserves_previous_contract(): void
    {
        $owner = User::where('email', 'superadmin@example.com')->firstOrFail();
        $tenant = Tenant::where('slug', 'island-collection')->firstOrFail();
        $annual = SubscriptionPlan::where('code', 'ANNUAL')->firstOrFail();
        $initial = Subscription::where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)->post('/superadmin/clients/'.$tenant->id.'/subscription', [
            'subscription_plan_id' => $annual->id, 'starts_at' => today()->toDateString(),
        ])->assertRedirect();
        $contract = Subscription::where('tenant_id', $tenant->id)->where('subscription_plan_id', $annual->id)->firstOrFail();
        $this->assertEquals(29988, $contract->billing_total);
        $this->assertEquals(12, $contract->commitment_months);
        $this->assertEquals(3499, $initial->fresh()->billing_total);

        $annual->update(['billing_price' => 25000]);
        $this->assertEquals(29988, $contract->fresh()->billing_total);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'subscription.created']);
    }

    public function test_superadmin_lock_suspend_and_reactivate(): void
    {
        $owner = User::where('email', 'superadmin@example.com')->firstOrFail();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $tenant = $admin->tenant;

        $this->actingAs($owner)->put('/superadmin/clients/'.$tenant->id.'/status', ['status' => 'LOCKED'])->assertRedirect();
        $this->assertNotNull($tenant->fresh()->locked_at);
        $admin->unsetRelation('tenant');
        $this->actingAs($admin)->get('/admin/bookings')->assertForbidden();
        $this->actingAs($owner)->put('/superadmin/clients/'.$tenant->id.'/status', ['status' => 'SUSPENDED'])->assertRedirect();
        $admin->unsetRelation('tenant');
        $this->actingAs($admin)->get('/admin')->assertForbidden();
        $this->actingAs($owner)->put('/superadmin/clients/'.$tenant->id.'/status', ['status' => 'ACTIVE'])->assertRedirect();
        $this->assertNull($tenant->fresh()->locked_at);
        $admin->unsetRelation('tenant');
        $this->actingAs($admin)->get('/admin')->assertOk();
    }
}
