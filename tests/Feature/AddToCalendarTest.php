<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** "Add to calendar": a real .ics file from the server (iPhone, Android, desktop) and Google / Outlook links. */
class AddToCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Room $house;

    private Room $hall;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $tenant = Tenant::create(['name' => 'AC Villa', 'slug' => 'ac-villa', 'business_name' => 'AC Villa & Private Resort', 'business_phone' => '0927 493 1583',
            'business_address' => 'North Poblacion, Mabinay, Negros Oriental (beside NORSU)', 'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $tenant->update(['settings' => [...$tenant->settings, 'check_in_time' => '14:00', 'check_out_time' => '12:00', 'event_start_time' => '08:00', 'event_end_time' => '17:00', 'service_fee_percent' => 0]]);
        $property = Property::create(['tenant_id' => $tenant->id, 'name' => 'AC Villa', 'slug' => 'ac-villa', 'city' => 'Mabinay', 'province' => 'Negros Oriental', 'status' => 'PUBLISHED']);
        $unit = fn (string $slug, string $name, array $extra = []) => Room::create(['tenant_id' => $tenant->id, 'property_id' => $property->id, 'name' => $name, 'slug' => $slug,
            'quantity' => 1, 'maximum_occupancy' => 50, 'base_guests' => 4, 'base_rate' => 3500, 'cleaning_fee' => 0, 'active' => true, ...$extra]);
        $this->house = $unit('ah-room-1', 'AH Room 1');
        $this->hall = $unit('function-hall', 'Function Hall', ['kind' => 'venue']);
    }

    private function book(array $items): Booking
    {
        $this->post('/bookings', ['items' => $items, 'guest_name' => 'Maria Santos', 'guest_phone' => '0917 123 4567', 'guest_email' => 'maria@example.com'])->assertSessionHasNoErrors();

        return Booking::orderBy('id')->firstOrFail();
    }

    private function day(int $days): string
    {
        return today()->addDays($days)->toDateString();
    }

    public function test_the_calendar_file_has_the_real_times_and_opens_on_every_device(): void
    {
        $booking = $this->book([['room_id' => $this->house->id, 'check_in' => $this->day(5), 'check_out' => $this->day(7), 'guests' => 2, 'check_in_time' => '15:30']]);

        $response = $this->get("/booking/{$booking->reference}/calendar.ics")->assertOk();
        $this->assertStringStartsWith('text/calendar', $response->headers->get('Content-Type'));
        $this->assertSame('attachment; filename="AC-Villa-'.$booking->reference.'.ics"', $response->headers->get('Content-Disposition'));
        $ics = $response->getContent();

        // 3:30 PM and 12:00 NN in Manila (UTC+8), written in UTC so every calendar shows the right local time.
        $this->assertStringContainsString("DTSTART:".today()->addDays(5)->format('Ymd')."T073000Z\r\n", $ics);
        $this->assertStringContainsString("DTEND:".today()->addDays(7)->format('Ymd')."T040000Z\r\n", $ics);
        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:Stay at AC Villa & Private Resort (AH Room 1)', $ics);
        // The resort's full address, once (long lines are folded, so compare the unfolded text).
        $this->assertStringContainsString("LOCATION:North Poblacion\\, Mabinay\\, Negros Oriental (beside NORSU)\r\n", str_replace("\r\n ", '', $ics));
        $this->assertStringContainsString('STATUS:TENTATIVE', $ics);
        $this->assertSame(2, substr_count($ics, 'BEGIN:VALARM'));
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), "Line too long: {$line}");
        }
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $ics), 'Only CRLF line endings');
    }

    public function test_a_booking_with_several_facilities_is_one_event_covering_all_of_them(): void
    {
        $booking = $this->book([
            ['room_id' => $this->hall->id, 'check_in' => $this->day(10), 'check_out' => $this->day(11), 'guests' => 40],
            ['room_id' => $this->house->id, 'check_in' => $this->day(10), 'check_out' => $this->day(11), 'guests' => 2],
        ]);
        $ics = $this->get("/booking/{$booking->reference}/calendar.ics")->assertOk()->getContent();
        $unfolded = str_replace("\r\n ", '', $ics);
        // From the 8:00 AM event start to the 12:00 NN check-out next day.
        $this->assertStringContainsString('DTSTART:'.today()->addDays(10)->format('Ymd').'T000000Z', $unfolded);
        $this->assertStringContainsString('DTEND:'.today()->addDays(11)->format('Ymd').'T040000Z', $unfolded);
        $this->assertStringContainsString('(Function Hall + AH Room 1)', $unfolded);
        $this->assertStringContainsString('Function Hall: ', $unfolded);
        $this->assertStringContainsString('AH Room 1: Check-in', $unfolded);
    }

    public function test_the_booking_page_offers_google_and_outlook_links_with_the_same_times(): void
    {
        $booking = $this->book([['room_id' => $this->house->id, 'check_in' => $this->day(5), 'check_out' => $this->day(6), 'guests' => 2]]);
        $dates = today()->addDays(5)->format('Ymd').'T060000Z/'.today()->addDays(6)->format('Ymd').'T040000Z';

        $this->get("/booking/success/{$booking->reference}")->assertInertia(fn (Assert $page) => $page
            ->where('calendar.ics', url("/booking/{$booking->reference}/calendar.ics"))
            ->where('calendar.google', fn (string $url) => str_starts_with($url, 'https://calendar.google.com/calendar/render?action=TEMPLATE')
                && str_contains($url, 'dates='.rawurlencode($dates)) && str_contains($url, 'ctz=Asia%2FManila'))
            ->where('calendar.outlook', fn (string $url) => str_starts_with($url, 'https://outlook.live.com/calendar/0/action/compose?')
                && str_contains($url, 'startdt='.rawurlencode(today()->addDays(5)->format('Y-m-d').'T06:00:00Z'))));
    }

    public function test_only_the_guest_can_download_their_calendar_file(): void
    {
        $booking = $this->book([['room_id' => $this->house->id, 'check_in' => $this->day(5), 'check_out' => $this->day(6), 'guests' => 2]]);
        $this->flushSession();
        $this->get("/booking/{$booking->reference}/calendar.ics")->assertForbidden();
        $this->get('/booking/ACV-NOPE/calendar.ics')->assertNotFound();
    }
}
