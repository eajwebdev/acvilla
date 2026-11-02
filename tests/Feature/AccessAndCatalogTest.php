<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccessAndCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_public_catalog_search_and_draft_visibility(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Public/Home')->where('tenant.slug', 'island-collection')->has('featured', 3));
        $this->get('/?tenant=cebu-heritage-stays')->assertInertia(fn (Assert $page) => $page->component('Public/Home')->where('tenant.slug', 'cebu-heritage-stays')->has('featured', 1));
        $this->get('/stays?tenant=cebu-heritage-stays')->assertInertia(fn (Assert $page) => $page->component('Public/Stays')->has('properties.data', 1)->where('properties.data.0.slug', 'cebu-heritage-house'));
        $blockedFrom = today()->addDays(18)->toDateString();
        $blockedTo = today()->addDays(19)->toDateString();
        $this->get('/stays?tenant=cebu-heritage-stays&check_in='.$blockedFrom.'&check_out='.$blockedTo)
            ->assertInertia(fn (Assert $page) => $page->component('Public/Stays')->has('properties.data', 0));
        $this->get('/stays?location=Siargao')->assertInertia(fn (Assert $page) => $page->component('Public/Stays')->has('properties.data', 1)->where('properties.data.0.slug', 'island-house-siargao'));
        $this->get('/stays/azure-coast-villas')->assertInertia(fn (Assert $page) => $page->component('Public/Stay')->where('bookable', true)->has('property.rooms', 2));
        $this->get('/stays/azure-coast-villas?check_in='.today()->addDays(2)->toDateString().'&check_out='.today()->addDays(4)->toDateString().'&guests=3')
            ->assertInertia(fn (Assert $page) => $page->component('Public/Stay')->where('selection.guests', 3));

        Property::where('slug', 'azure-coast-villas')->update(['status' => 'DRAFT']);
        $this->get('/stays/azure-coast-villas')->assertNotFound();
    }

    public function test_admin_views_are_tenant_scoped_and_staff_cannot_mutate(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $staff = User::where('email', 'staff@example.com')->firstOrFail();
        $cebu = Property::where('slug', 'cebu-heritage-house')->firstOrFail();

        $this->actingAs($admin)->get('/admin/properties')->assertInertia(fn (Assert $page) => $page->component('Admin/Properties')->has('properties', 3));
        $this->actingAs($staff)->get('/admin/bookings')->assertInertia(fn (Assert $page) => $page->component('Admin/Bookings')->has('bookings.data', 4));
        $this->actingAs($staff)->post('/admin/properties', [
            'name' => 'Forbidden', 'city' => 'Cebu', 'province' => 'Cebu', 'type' => 'hotel',
        ])->assertForbidden();
        $this->actingAs($admin)->put('/admin/properties/'.$cebu->slug, [
            'name' => 'Changed', 'city' => 'Cebu', 'province' => 'Cebu', 'type' => 'hotel', 'status' => 'PUBLISHED',
        ])->assertNotFound();
        $this->assertEquals('Cebu Heritage House', $cebu->fresh()->name);
    }

    public function test_admin_can_update_guest_branding_but_staff_cannot(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $staff = User::where('email', 'staff@example.com')->firstOrFail();
        $data = ['business_name' => 'Island Collection', 'business_email' => 'hello@island.example',
            'business_phone' => '+63 900 000 0000', 'logo' => 'https://example.com/logo.png',
            'primary_color' => '#123456', 'secondary_color' => '#ABCDEF'];
        $this->actingAs($admin)->put('/admin/branding', $data)->assertRedirect();
        $this->assertDatabaseHas('tenants', ['id' => $admin->tenant_id, 'business_name' => 'Island Collection', 'primary_color' => '#123456']);
        $this->get('/?tenant=island-collection')->assertInertia(fn (Assert $page) => $page->component('Public/Home')->where('tenant.primary_color', '#123456'));
        $this->actingAs($staff)->put('/admin/branding', $data)->assertForbidden();
    }

    public function test_property_and_room_creation_ignore_client_tenant_id(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $other = Tenant::where('slug', 'cebu-heritage-stays')->firstOrFail();
        $this->actingAs($admin)->post('/admin/properties', [
            'tenant_id' => $other->id, 'name' => 'New Island Lodge', 'city' => 'Panglao',
            'province' => 'Bohol', 'type' => 'villa',
        ])->assertRedirect();
        $property = Property::where('name', 'New Island Lodge')->firstOrFail();
        $this->assertEquals($admin->tenant_id, $property->tenant_id);
        $this->assertEquals('DRAFT', $property->status);

        $this->post('/admin/properties/'.$property->slug.'/rooms', [
            'tenant_id' => $other->id, 'name' => 'Sea Suite', 'maximum_occupancy' => 3,
            'quantity' => 2, 'base_rate' => 4500,
        ])->assertRedirect();
        $room = Room::where('property_id', $property->id)->firstOrFail();
        $this->assertEquals($admin->tenant_id, $room->tenant_id);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $admin->tenant_id, 'action' => 'room.created']);
    }

    public function test_locked_and_expired_tenant_block_operations_but_superadmin_can_view(): void
    {
        $owner = User::where('email', 'superadmin@example.com')->firstOrFail();
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $tenant = $admin->tenant;

        $this->actingAs($owner)->put('/superadmin/clients/'.$tenant->id.'/status', ['status' => 'LOCKED'])->assertRedirect();
        $admin->unsetRelation('tenant');
        $this->actingAs($admin)->get('/admin')->assertForbidden();
        $this->actingAs($owner)->get('/superadmin/clients')->assertOk();
        $this->put('/superadmin/clients/'.$tenant->id.'/status', ['status' => 'ACTIVE'])->assertRedirect();

        $tenant->update(['subscription_ends_at' => now()->subDay()]);
        $admin->unsetRelation('tenant');
        $this->actingAs($admin)->get('/admin')->assertForbidden();
        $this->get('/stays/azure-coast-villas')->assertInertia(fn (Assert $page) => $page->component('Public/Stay')->where('bookable', false));
    }

    public function test_demo_login_supports_all_roles_and_rejects_arbitrary_identity(): void
    {
        config(['platform.demo_mode' => true]);
        foreach (['admin' => '/admin', 'staff' => '/admin/my-tasks', 'guest' => '/account/bookings'] as $role => $path) {
            $this->post('/demo/login', ['role' => $role])->assertRedirect($path);
            $this->assertEquals(strtoupper($role), auth()->user()->role);
            auth()->logout();
        }
        $this->post('/demo/login', ['role' => 'admin', 'email' => 'attacker@example.com'])->assertRedirect('/admin');
        $this->assertEquals('admin@example.com', auth()->user()->email);
        $this->post('/demo/login', ['role' => 'attacker'])->assertSessionHasErrors('role');
        config(['platform.demo_mode' => false]);
        $this->post('/demo/login', ['role' => 'guest'])->assertNotFound();
    }
}
