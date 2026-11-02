<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SiteContent;
use Database\Seeders\AcVillaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebsiteContentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AcVillaSeeder::class);
        $this->tenant = Tenant::where('slug', 'ac-villa')->sole();
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => strtolower($role).'@acvilla.test', 'password' => 'Password123!', 'role' => $role, 'tenant_id' => $this->tenant->id]);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(SiteContent::defaults(), ['hero_headline' => 'Your Private Escape in Mabinay', 'hero_description' => 'Relax.', 'hero_image' => '/images/acvilla/hero-resort.jpg'], $changes);
    }

    public function test_the_guest_site_starts_with_the_default_content(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Public/Home')
            ->where('tenant.site_content.rooms_title', 'Stay in Our *Cozy A-Houses*')->where('tenant.site_content.sections.gallery', true)
            ->has('tenant.site_content.gallery', 9)->where('requestTypes.wedding', 'Wedding'));
    }

    public function test_the_owner_edits_texts_photos_sections_and_form_options(): void
    {
        Mail::fake();
        $admin = $this->user('ADMIN');
        $this->actingAs($admin)->get('/admin/website')->assertInertia(fn (Assert $page) => $page->component('Admin/Website')->has('icons')->where('content.package_title', 'Wedding'));

        $this->actingAs($admin)->put('/admin/website', $this->payload([
            'hero_headline' => 'Escape to AC Villa', 'rooms_title' => 'Our *A-Houses*', 'package_includes' => 'Everything, all day',
            'sections' => [...SiteContent::defaults()['sections'], 'gallery' => false],
            'highlights' => [['icon' => 'heart', 'label' => 'Romantic']],
            'gallery' => [['image' => '/uploads/site/a.jpg', 'caption' => 'Pool']],
            'request_types' => [['key' => 'stay', 'label' => 'Overnight stay'], ['key' => '', 'label' => 'Debut / 18th birthday']],
        ]))->assertSessionHasNoErrors();

        $content = $this->tenant->fresh()->site_content;
        $this->assertSame(['Our *A-Houses*', false, 'Romantic'], [$content['rooms_title'], $content['sections']['gallery'], $content['highlights'][0]['label']]);
        $this->assertSame(['stay', 'debut_18th_birthday'], array_column($content['request_types'], 'key'));
        $this->assertSame('Escape to AC Villa', $this->tenant->fresh()->hero_headline);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('tenant.site_content.package_includes', 'Everything, all day')
            ->where('requestTypes.debut_18th_birthday', 'Debut / 18th birthday')->missing('requestTypes.wedding'));

        // Guests pick from the owner's own options; anything else is refused.
        $room = Room::where('slug', 'barkada-room')->sole();
        $request = ['room_id' => $room->id, 'guests' => 2, 'guest_name' => 'Ana', 'guest_phone' => '0917 123 4567', 'guest_email' => 'ana@example.com',
            'check_in' => today()->addDays(5)->toDateString(), 'check_out' => today()->addDays(6)->toDateString()];
        $this->post('/bookings', [...$request, 'request_type' => 'wedding'])->assertSessionHasErrors('request_type');
        $this->post('/bookings', [...$request, 'request_type' => 'debut_18th_birthday'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('Debut / 18th birthday', Booking::sole()->notes);
    }

    public function test_bad_input_is_rejected_and_only_admins_can_edit(): void
    {
        $this->actingAs($this->user('STAFF'))->get('/admin/website')->assertForbidden();
        $admin = $this->user('ADMIN');
        $this->actingAs($admin)->put('/admin/website', $this->payload([
            'rooms_image' => 'javascript:alert(1)', 'highlights' => [['icon' => 'not-an-icon', 'label' => 'X']], 'request_types' => [],
            'gallery' => [['image' => '//evil.example/x.jpg', 'caption' => '']],
        ]))->assertSessionHasErrors(['rooms_image', 'highlights.0.icon', 'request_types', 'gallery.0.image']);
    }

    public function test_photos_upload_to_the_site_and_rooms_accept_them(): void
    {
        $admin = $this->user('ADMIN');
        $url = $this->actingAs($admin)->postJson('/admin/website/images', ['image' => UploadedFile::fake()->image('pool.jpg', 1200, 800)])
            ->assertOk()->json('url');
        $this->assertMatchesRegularExpression('#^/uploads/site/[a-z0-9]+\.jpg$#', $url);
        $this->assertFileExists(public_path(ltrim($url, '/')));
        $this->actingAs($admin)->postJson('/admin/website/images', ['image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertUnprocessable();

        // Seeded rooms use site paths for photos; editing one must still save.
        $room = Room::where('slug', 'ah-room-1')->sole();
        $this->actingAs($admin)->put("/admin/rooms/{$room->id}", [...$room->only('name', 'description', 'maximum_occupancy', 'quantity', 'base_rate', 'base_guests'),
            'active' => true, 'photos' => [...$room->photos, $url]])->assertSessionHasNoErrors();
        $this->assertContains($url, $room->fresh()->photos);
        File::delete(public_path(ltrim($url, '/')));
    }
}
