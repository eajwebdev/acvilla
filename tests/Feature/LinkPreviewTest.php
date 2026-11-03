<?php

namespace Tests\Feature;

use Database\Seeders\AcVillaSeeder;
use App\Models\Property;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A pasted link shows a picture and a title in Messenger, which reads the page's HTML without running its JavaScript. */
class LinkPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AcVillaSeeder::class);
    }

    public function test_the_home_page_shares_the_branded_picture_as_a_full_address(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:image" content="'.url('/branding/acvilla-share.jpg').'">', $html);
        $this->assertStringContainsString('<meta property="og:image:width" content="1200">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="'.e(Tenant::first()->business_name), $html);
        $this->assertFileExists(public_path('branding/acvilla-share.jpg'));
        $this->assertSame([1200, 630], array_slice(getimagesize(public_path('branding/acvilla-share.jpg')), 0, 2));
    }

    public function test_a_stay_page_shares_its_own_name_and_cover_photo(): void
    {
        $property = Property::where('status', 'PUBLISHED')->first();
        $property->update(['cover_image' => '/images/acvilla/aerial.jpg', 'short_description' => 'Villas <b>by</b> the pool.']);

        $html = $this->get("/stays/{$property->slug}")->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="'.e($property->name), $html);
        $this->assertStringContainsString('<meta property="og:description" content="Villas by the pool.">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="'.url('/images/acvilla/aerial.jpg').'">', $html);
    }

    public function test_a_page_without_its_own_details_falls_back_to_the_resort(): void
    {
        $html = $this->get('/booking')->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="'.e(Tenant::first()->business_name).'">', $html);
        $this->assertStringContainsString(url('/branding/acvilla-share.jpg'), $html);
    }
}
