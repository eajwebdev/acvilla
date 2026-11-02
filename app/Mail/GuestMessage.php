<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class GuestMessage extends Mailable
{
    public function __construct(public Tenant $tenant, public string $subjectLine, public string $body) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->tenant->business_name),
            replyTo: $this->tenant->business_email ? [new Address($this->tenant->business_email, $this->tenant->business_name)] : [],
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.guest-message', text: 'emails.guest-message-text');
    }
}
