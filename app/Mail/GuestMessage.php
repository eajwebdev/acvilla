<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class GuestMessage extends Mailable
{
    private const URL = '~https?://[^\s<>"]+[^\s<>".,;:!?)]~';

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
        return new Content(
            view: 'emails.guest-message',
            text: 'emails.guest-message-text',
            with: ['blocks' => $this->blocks(), 'button' => $this->buttonUrl()],
        );
    }

    /** The plain-text body split into a greeting, details cards and paragraphs. Works on templates the owner has edited. */
    public function blocks(): array
    {
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/u', trim($this->body)))));

        return collect($paragraphs)
            ->reject(fn (string $text, int $i) => $i > 0 && $i === count($paragraphs) - 1 && $text === $this->tenant->business_name)
            ->map(function (string $text, int $i) {
                $lines = array_map('trim', preg_split('/\R/u', $text));
                if ($i === 0 && count($lines) === 1 && preg_match('/^(Hi|Hello|Dear|Good)\b/i', $text)) {
                    return ['type' => 'greeting', 'text' => $text];
                }
                if (count($lines) > 1) {
                    return ['type' => 'details', 'rows' => array_map(fn (string $line) => $this->row($line), $lines)];
                }

                return ['type' => 'text', 'html' => $this->linked($text)];
            })->values()->all();
    }

    /** The first link in the message becomes the email's button. */
    public function buttonUrl(): ?string
    {
        return preg_match(self::URL, $this->body, $match) ? $match[0] : null;
    }

    private function row(string $line): array
    {
        if (preg_match('/^([^:]{1,28}):\s+(.+)$/u', $line, $match) && ! str_contains($match[1], 'http')) {
            return ['label' => $match[1], 'value' => $this->linked($match[2])];
        }

        return ['label' => null, 'value' => $this->linked($line)];
    }

    /** Escaped text with its links made clickable; the only HTML that can reach the email from a template. */
    private function linked(string $text): string
    {
        $parts = preg_split('~('.trim(self::URL, '~').')~', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        return collect($parts)->map(fn (string $part, int $i) => $i % 2
            ? '<a href="'.e($part).'" style="color:#0039D5;">'.e($part).'</a>'
            : e($part))->implode('');
    }
}
