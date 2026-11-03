<?php

namespace Tests\Feature;

use App\Mail\GuestMessage;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The guest email shows the logo, a details card and a button, and never trusts the text it is given. */
class GuestEmailDesignTest extends TestCase
{
    private function mail(string $body): GuestMessage
    {
        $tenant = new Tenant(['business_name' => 'AC Villa & Private Resort', 'primary_color' => '#005A1C']);

        return new GuestMessage($tenant, 'Your stay is confirmed', $body);
    }

    public function test_the_logo_is_embedded_in_the_email(): void
    {
        config(['mail.default' => 'array']);
        $email = Mail::to('guest@example.net')->send($this->mail('Hi Carla,'))->getOriginalMessage();

        $this->assertStringContainsString('src="cid:', $email->getHtmlBody());
        $this->assertCount(1, $email->getAttachments());
    }

    public function test_the_sender_is_the_configured_address_under_the_resort_name(): void
    {
        config(['mail.from.address' => 'acvilla1126@gmail.com']);
        $from = $this->mail('Hi Carla,')->envelope()->from;

        $this->assertSame('acvilla1126@gmail.com', $from->address);
        $this->assertSame('AC Villa & Private Resort', $from->name);
    }

    public function test_the_body_becomes_a_greeting_a_details_card_and_a_button(): void
    {
        $mail = $this->mail("Hi Carla,\n\nGreat news.\n\nAH Room 2\nCheck-in: Fri, Nov 13\nTotal: ₱4,999.00\n\nOpen https://acvilla.test/booking, please.\n\nAC Villa & Private Resort");
        $blocks = $mail->blocks();

        $this->assertSame(['greeting', 'text', 'details', 'text'], array_column($blocks, 'type'));
        $this->assertSame(['label' => 'Total', 'value' => '₱4,999.00'], $blocks[2]['rows'][2]);
        $this->assertNull($blocks[2]['rows'][0]['label']);
        $this->assertSame('https://acvilla.test/booking', $mail->buttonUrl());
        $this->assertStringContainsString('Open my booking', $mail->render());
    }

    public function test_there_is_no_button_without_a_link(): void
    {
        $this->assertNull($this->mail('Hi Carla,')->buttonUrl());
        $this->assertStringNotContainsString('Open my booking', $this->mail('Hi Carla,')->render());
    }

    public function test_owner_and_guest_text_is_escaped(): void
    {
        $html = $this->mail("Hi <b>Carla</b>,\n\n<script>alert(1)</script>\n\nNote: <img src=x>\nName: <i>x</i>")->render();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x>', $html);
        $this->assertStringNotContainsString('<i>x</i>', $html);
        $this->assertStringNotContainsString('<b>Carla</b>', $html);
    }
}
