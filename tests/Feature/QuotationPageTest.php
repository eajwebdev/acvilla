<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuotationPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_quotation_page_is_part_of_the_app(): void
    {
        $this->get('/quotation')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Public/Quotation'));
    }

    public function test_quotation_page_gets_the_resort_for_its_menu_and_footer(): void
    {
        $this->seed();
        $this->get('/quotation?package=ac-villa')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Public/Quotation')->where('tenant.slug', 'ac-villa'));
    }

    private function owner(): User
    {
        return User::create(['name' => 'EAJ', 'email' => 'owner@eaj.test', 'password' => 'secret-password', 'role' => 'SUPERADMIN']);
    }

    /** The owner applies a discount on the page; returns the signed link the page opens (what the customer gets). */
    private function discountLink(int $percent, string $label = 'Launch discount', int $days = 14): string
    {
        $response = $this->actingAs($this->owner())->post('/quotation/discount', ['percent' => $percent, 'label' => $label, 'days' => $days]);
        $response->assertRedirect();
        auth()->logout();

        return $response->headers->get('Location');
    }

    public function test_the_package_shows_its_full_price_and_no_discount_controls_to_customers(): void
    {
        $this->get('/quotation?package=ac-villa')->assertInertia(fn (Assert $page) => $page
            ->where('package.price', 6000)->where('package.max_percent', 25)
            ->where('package.discount', null)->where('package.can_discount', false));
    }

    public function test_one_package_with_a_setup_fee_and_the_client_named_only_on_their_link(): void
    {
        $this->get('/quotation?package=ac-villa')->assertInertia(fn (Assert $page) => $page
            ->where('package.setup_fee', 12000)->where('package.price', 6000)
            ->where('package.client', ['name' => 'Ech Carido Che', 'business' => 'AC Villa & Private Resort']));
        // The public pricing page shows the same package to any prospect, without the client's name.
        $this->get('/quotation')->assertInertia(fn (Assert $page) => $page
            ->where('package.setup_fee', 12000)->where('package.price', 6000)->where('package.client', null));
    }

    public function test_the_quotation_carries_its_number_dates_and_author(): void
    {
        $this->get('/quotation?package=ac-villa')->assertInertia(fn (Assert $page) => $page
            ->where('package.document.number', 'EAJ-2026-011')
            ->where('package.document.issued', 'November 20, 2026')
            ->where('package.document.valid_until', 'December 20, 2026')
            ->where('package.document.prepared_by', 'Edwin Abril Jr'));
    }

    public function test_the_platform_owner_gets_the_discount_controls(): void
    {
        $this->actingAs($this->owner())->get('/quotation?package=ac-villa')
            ->assertInertia(fn (Assert $page) => $page->where('package.can_discount', true));
    }

    public function test_a_percent_discount_shows_on_the_link_the_customer_gets(): void
    {
        $link = $this->discountLink(10);

        $this->assertStringContainsString('/quotation?', $link);
        $this->get($link)->assertInertia(fn (Assert $page) => $page
            ->where('package.discount.percent', 10)
            ->where('package.discount.amount', 600)
            ->where('package.discount.label', 'Launch discount')
            ->where('package.discount.valid_until', now()->addDays(14)->format('F j, Y'))
            ->where('package.can_discount', false));
    }

    public function test_only_the_platform_owner_can_make_a_discount(): void
    {
        $this->post('/quotation/discount', ['percent' => 25, 'days' => 14])->assertRedirect('/login');
        $resortAdmin = User::create(['name' => 'Resort', 'email' => 'admin@resort.test', 'password' => 'secret-password', 'role' => 'ADMIN']);
        $this->actingAs($resortAdmin)->post('/quotation/discount', ['percent' => 25, 'days' => 14])->assertForbidden();
    }

    public function test_a_customer_cannot_change_the_discount_in_the_link(): void
    {
        $tampered = str_replace('percent=10', 'percent=25', $this->discountLink(10));

        $this->get($tampered)->assertInertia(fn (Assert $page) => $page->where('package.discount', null));
        $this->get('/quotation?package=ac-villa&percent=20')->assertInertia(fn (Assert $page) => $page->where('package.discount', null));
    }

    public function test_an_expired_offer_goes_back_to_the_full_price(): void
    {
        $link = $this->discountLink(10, days: 7);
        $this->travel(8)->days();

        $this->get($link)->assertInertia(fn (Assert $page) => $page->where('package.discount', null));
    }

    public function test_a_discount_cannot_go_below_the_price_floor(): void
    {
        // A ₱4,500 floor on ₱6,000 is 25%.
        $this->actingAs($this->owner())->post('/quotation/discount', ['percent' => 30, 'days' => 14])->assertSessionHasErrors('percent');
    }

    public function test_zero_percent_goes_back_to_the_plain_quotation(): void
    {
        $this->actingAs($this->owner())->post('/quotation/discount', ['percent' => 0, 'days' => 14])->assertRedirect('/quotation?package=ac-villa');
    }

    public function test_quote_browser_url_opens_quotation_without_breaking_pricing_api(): void
    {
        $this->withHeader('Accept', 'text/html')->get('/quote')->assertRedirect('/quotation');
        $this->withHeader('Accept', 'application/json')->get('/quote')->assertUnprocessable();
    }
}
