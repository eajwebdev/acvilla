<?php

namespace Tests\Feature;

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

    public function test_quote_browser_url_opens_quotation_without_breaking_pricing_api(): void
    {
        $this->withHeader('Accept', 'text/html')->get('/quote')->assertRedirect('/quotation');
        $this->withHeader('Accept', 'application/json')->get('/quote')->assertUnprocessable();
    }
}
