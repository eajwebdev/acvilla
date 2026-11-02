<?php

namespace App\Services;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Add to calendar" for a booking (with every facility booked together): a standard .ics file for Apple Calendar,
 * Android and desktop apps, plus Google Calendar and Outlook links that work in any browser.
 */
class BookingCalendar
{
    /** @return array{title: string, start: CarbonImmutable, end: CarbonImmutable, location: string, details: string, status: string, reference: string} */
    public function event(Booking $booking): array
    {
        /** @var Collection<int, Booking> $group */
        $group = $booking->siblings()->load('room', 'property', 'tenant')
            ->reject(fn (Booking $b) => in_array($b->status, ['CANCELLED', 'EXPIRED', 'NO_SHOW'], true))->values();
        $group = $group->isEmpty() ? collect([$booking->loadMissing('room', 'property', 'tenant')]) : $group;
        $settings = $booking->tenant->settings;
        $periods = $group->map(fn (Booking $b) => $b->period($settings));
        $tenant = $booking->tenant;
        $property = $booking->property;
        $facilities = $group->map(fn (Booking $b) => $b->room?->name)->filter()->implode(' + ');
        $event = $group->every(fn (Booking $b) => $b->isEvent());
        $time = fn (CarbonImmutable $at) => $at->format('D, M j, g:i A');
        $lines = $group->map(function (Booking $b) use ($settings, $time, $group) {
            [$start, $end] = $b->period($settings);

            return ($group->count() > 1 ? "{$b->room?->name}: " : '').($b->isEvent() ? "{$time($start)} to {$end->format('g:i A')}" : "Check-in {$time($start)}, check-out {$time($end)}");
        });

        return [
            'title' => ($event ? 'Event' : 'Stay').' at '.$tenant->business_name.($facilities ? " ({$facilities})" : ''),
            'start' => $periods->min(fn (array $p) => $p[0]),
            'end' => $periods->max(fn (array $p) => $p[1]),
            // The resort's address is already complete (town and province included); otherwise it is put together from the property.
            'location' => $tenant->business_address ?: collect([$property->address, $property->city, $property->province])->filter()->implode(', '),
            'details' => collect([
                "Booking reference: {$booking->reference}",
                ...$lines->all(),
                $booking->status === 'CONFIRMED' || $booking->status === 'CHECKED_IN' ? 'Status: confirmed' : 'Status: waiting for confirmation',
                'Your booking: '.url('/booking/success/'.$booking->reference),
                $tenant->business_phone ? "Questions? Call {$tenant->business_phone}" : null,
            ])->filter()->implode("\n"),
            'status' => in_array($booking->status, ['CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT', 'COMPLETED', 'PAID'], true) ? 'CONFIRMED' : 'TENTATIVE',
            'reference' => $booking->reference,
        ];
    }

    /** The .ics file (RFC 5545): UTC times, escaped text, lines folded at 75 octets, CRLF line ends, two reminders. */
    public function ics(Booking $booking): string
    {
        $e = $this->event($booking);
        $utc = fn (CarbonImmutable $at) => $at->utc()->format('Ymd\THis\Z');
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//AC Villa//Booking//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.strtolower($e['reference']).'@'.(parse_url(url('/'), PHP_URL_HOST) ?: 'acvilla'),
            'DTSTAMP:'.$utc(CarbonImmutable::now()),
            'DTSTART:'.$utc($e['start']),
            'DTEND:'.$utc($e['end']),
            'SUMMARY:'.$this->text($e['title']),
            'LOCATION:'.$this->text($e['location']),
            'DESCRIPTION:'.$this->text($e['details']),
            'URL:'.url('/booking/success/'.$e['reference']),
            'STATUS:'.$e['status'],
            'TRANSP:OPAQUE',
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.$this->text($e['title'].' tomorrow'), 'TRIGGER:-P1D', 'END:VALARM',
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.$this->text($e['title'].' in 2 hours'), 'TRIGGER:-PT2H', 'END:VALARM',
            'END:VEVENT', 'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(fn (string $line) => $this->fold($line), $lines))."\r\n";
    }

    /** @return array{ics: string, google: string, outlook: string} */
    public function links(Booking $booking): array
    {
        $e = $this->event($booking);
        $utc = fn (CarbonImmutable $at) => $at->utc()->format('Ymd\THis\Z');

        return [
            'ics' => url("/booking/{$booking->reference}/calendar.ics"),
            'google' => 'https://calendar.google.com/calendar/render?'.http_build_query(['action' => 'TEMPLATE', 'text' => $e['title'],
                'dates' => $utc($e['start']).'/'.$utc($e['end']), 'details' => $e['details'], 'location' => $e['location'], 'ctz' => config('app.timezone')], '', '&', PHP_QUERY_RFC3986),
            'outlook' => 'https://outlook.live.com/calendar/0/action/compose?'.http_build_query(['path' => '/calendar/action/compose', 'rru' => 'addevent',
                'subject' => $e['title'], 'startdt' => $e['start']->utc()->toIso8601ZuluString(), 'enddt' => $e['end']->utc()->toIso8601ZuluString(),
                'body' => $e['details'], 'location' => $e['location']], '', '&', PHP_QUERY_RFC3986),
        ];
    }

    private function text(string $value): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $value);
    }

    /** Long lines are split into 75-octet pieces, never inside a multi-byte character (₱, ñ). */
    private function fold(string $line): string
    {
        $parts = [];
        $limit = 75;
        while (strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $parts[] = substr($line, 0, $cut);
            $line = substr($line, $cut);
            $limit = 74; // continuation lines start with a space
        }
        $parts[] = $line;

        return implode("\r\n ", $parts);
    }
}
