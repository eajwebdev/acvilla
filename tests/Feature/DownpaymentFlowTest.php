<?php

namespace Tests\Feature;

use App\Mail\GuestMessage;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Request several facilities → owner approves the dates → guest pays the downpayment and uploads the receipt → owner verifies. */
class DownpaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Room $house1;

    private Room $house2;

    private Room $hall;

    private Room $resort;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
        $this->tenant = Tenant::create(['name' => 'AC Villa', 'slug' => 'ac-villa', 'business_name' => 'AC Villa & Private Resort', 'business_phone' => '0917 000 0000',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $settings = $this->tenant->settings;
        $settings['payment_methods']['gcash'] = [...$settings['payment_methods']['gcash'], 'enabled' => true, 'account_name' => 'AC Villa', 'account_number' => '0927 493 1583', 'qr_image' => '/uploads/site/gcash-qr.png'];
        $this->tenant->update(['settings' => [...$settings, 'deposit_percent' => 50, 'balance_due_days' => 0, 'service_fee_percent' => 0, 'manual_payment_hours' => 48]]);
        $property = Property::create(['tenant_id' => $this->tenant->id, 'name' => 'AC Villa', 'slug' => 'ac-villa', 'city' => 'Mabinay', 'province' => 'Negros Oriental', 'status' => 'PUBLISHED']);
        $unit = fn (string $slug, string $name, int $rate, int $max, array $extra = []) => Room::create(['tenant_id' => $this->tenant->id, 'property_id' => $property->id,
            'name' => $name, 'slug' => $slug, 'quantity' => 1, 'maximum_occupancy' => $max, 'base_guests' => $max, 'base_rate' => $rate, 'cleaning_fee' => 0, 'active' => true, ...$extra]);
        $this->house1 = $unit('ah-room-1', 'AH Room 1', 3500, 15);
        $this->house2 = $unit('ah-room-2', 'AH Room 2', 4999, 15);
        $this->hall = $unit('function-hall', 'Function Hall', 7000, 50, ['kind' => 'venue']);
        $this->resort = $unit('whole-resort', 'Whole Resort', 38000, 60, ['whole_property' => true]);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'secret-password', 'role' => 'ADMIN', 'tenant_id' => $this->tenant->id]);
    }

    private function day(int $days): string
    {
        return today()->addDays($days)->toDateString();
    }

    private function item(Room $room, int $from, int $to, int $guests = 4): array
    {
        return ['room_id' => $room->id, 'check_in' => $this->day($from), 'check_out' => $this->day($to), 'guests' => $guests];
    }

    private function request(array $items, string $phone = '0917 123 4567')
    {
        return $this->post('/bookings', ['items' => $items, 'guest_name' => 'Maria Santos', 'guest_phone' => $phone, 'guest_email' => 'maria@example.com']);
    }

    private function wedding(): Booking
    {
        $this->request([$this->item($this->hall, 10, 11, 40), $this->item($this->house1, 10, 11), $this->item($this->house2, 10, 11)])->assertSessionHasNoErrors();

        return Booking::orderBy('id')->firstOrFail();
    }

    public function test_the_guest_gets_one_email_for_every_step_of_a_booking_with_several_facilities(): void
    {
        $first = $this->wedding();
        $emails = function (): array {
            $sent = [];
            Mail::assertSent(GuestMessage::class, function (GuestMessage $mail) use (&$sent) {
                $this->assertTrue($mail->hasTo('maria@example.com'));
                $sent[] = $mail;

                return true;
            });

            return $sent;
        };
        $subjects = fn () => array_map(fn (GuestMessage $mail) => $mail->subjectLine, $emails());

        // Request: one email naming every facility and the combined total.
        $this->assertCount(1, $emails());
        $this->assertStringContainsString('Function Hall + AH Room 1 + AH Room 2', $emails()[0]->body);
        $this->assertStringContainsString('15,499', $emails()[0]->body);

        $this->actingAs($this->owner)->put("/admin/bookings/{$first->id}/status", ['status' => 'AWAITING_PAYMENT'])->assertSessionHasNoErrors();
        $this->assertSame("Booking {$first->reference}: downpayment needed", $subjects()[1]);
        $this->assertStringContainsString('7,749.50', $emails()[1]->body);

        $upload = fn (string $name) => $this->post("/booking/{$first->reference}/proof", ['method' => 'gcash', 'proof' => UploadedFile::fake()->image($name)])->assertSessionHasNoErrors();
        $upload('blurry.jpg');
        $this->assertSame("We got your downpayment receipt for {$first->reference}", $subjects()[2]);
        $this->assertStringContainsString('7,749.50', $emails()[2]->body);

        $this->put('/admin/payments/'.Payment::first()->id.'/review', ['decision' => 'reject'])->assertSessionHasNoErrors();
        $this->assertSame("Please send your receipt again for {$first->reference}", $subjects()[3]);

        $upload('clear.jpg');
        $this->put('/admin/payments/'.Payment::where('status', 'PENDING')->first()->id.'/review', ['decision' => 'approve'])->assertSessionHasNoErrors();
        // Confirmed once for the whole booking, not once per facility.
        $confirmed = array_values(array_filter($subjects(), fn (string $s) => str_contains($s, 'is confirmed')));
        $this->assertCount(1, $confirmed);
        $this->assertCount(6, $emails());
        $this->assertSame(['Booking %s: downpayment needed', 'We got your downpayment receipt for %s', 'Please send your receipt again for %s', 'We got your downpayment receipt for %s'],
            array_map(fn (string $s) => str_replace($first->reference, '%s', $s), array_slice($subjects(), 1, 4)));
    }

    public function test_one_request_holds_several_facilities_together(): void
    {
        $first = $this->wedding();

        $group = Booking::orderBy('id')->get();
        $this->assertCount(3, $group);
        $this->assertCount(1, $group->pluck('group_code')->unique()->filter());
        $this->assertSame(['PENDING'], $group->pluck('status')->unique()->all());
        $this->assertSame(['7000.00', '3500.00', '4999.00'], $group->pluck('total')->all());
        $this->assertSame(['3500.00', '1750.00', '2499.50'], $group->pluck('deposit_amount')->all());
        // Only one "we received your request" email for the whole request.
        Mail::assertSent(GuestMessage::class, 1);
        $this->get('/booking/success/'.$first->reference)->assertInertia(fn (Assert $page) => $page->component('Public/Success')
            ->has('group', 3)->where('downpayment.stage', 'requested')->where('downpayment.total', 15499));
        foreach ([$this->hall, $this->house1, $this->house2] as $room) {
            $this->assertFalse(app(AvailabilityService::class)->available($room, $this->day(10), $this->day(11)));
        }
    }

    public function test_the_booking_page_checks_every_facility_in_one_call(): void
    {
        $this->request([$this->item($this->house1, 10, 11)])->assertSessionHasNoErrors();
        $query = http_build_query(['items' => [$this->item($this->house1, 10, 11), $this->item($this->hall, 10, 11, 40), $this->item($this->house2, 10, 11, 30), $this->item($this->resort, 10, 11, 20)]]);

        $this->getJson('/availability/batch?'.$query)->assertOk()->assertExactJson([
            ['room_id' => $this->house1->id, 'available' => false, 'message' => 'Already booked for these dates', 'total' => null, 'times' => null],
            // Free hours for the event, starting from the standard 8:00 AM to 5:00 PM.
            ['room_id' => $this->hall->id, 'available' => true, 'message' => null, 'total' => 7000, 'times' => ['event' => true, 'earliest' => '06:00', 'latest' => '22:00',
                'default_start' => '08:00', 'default_end' => '17:00', 'passed' => false, 'after' => null, 'before' => null]],
            ['room_id' => $this->house2->id, 'available' => false, 'message' => 'Fits up to 15 guests', 'total' => null, 'times' => null],
            // A single room is held that night, so the whole resort isn't free.
            ['room_id' => $this->resort->id, 'available' => false, 'message' => 'Already booked for these dates', 'total' => null, 'times' => null],
        ]);
    }

    public function test_checking_prices_never_blocks_sending_the_request_or_signing_in(): void
    {
        // Rate limits are counted per action: a guest comparing options must still be able to send, and staff on the same wifi to sign in.
        for ($i = 0; $i < 15; $i++) {
            $this->getJson('/availability/batch?'.http_build_query(['items' => [$this->item($this->house1, 10, 11)]]))->assertOk();
        }
        $this->request([$this->item($this->house1, 10, 11)])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'secret-password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_a_request_with_any_taken_facility_holds_nothing(): void
    {
        // Another customer's confirmed booking of AH Room 2.
        Booking::forceCreate(['tenant_id' => $this->tenant->id, 'property_id' => $this->house2->property_id, 'room_id' => $this->house2->id, 'guest_name' => 'Other Guest',
            'check_in' => $this->day(10), 'check_out' => $this->day(12), 'guests' => 2, 'reference' => 'ACV-OTHER', 'status' => 'CONFIRMED', 'total' => 9998]);

        $this->request([$this->item($this->hall, 10, 11, 40), $this->item($this->house2, 11, 12)])->assertSessionHasErrors('items');
        $this->assertSame(1, Booking::count());
        $this->assertTrue(app(AvailabilityService::class)->available($this->hall, $this->day(10), $this->day(11)));
    }

    public function test_the_whole_resort_and_a_single_room_cannot_share_dates(): void
    {
        $this->request([$this->item($this->resort, 10, 11, 30), $this->item($this->house1, 10, 11)])->assertSessionHasErrors('items');
        $this->assertSame(0, Booking::count());

        $this->request([$this->item($this->house1, 10, 11)])->assertSessionHasNoErrors();
        $this->request([$this->item($this->resort, 10, 11, 30)], '0918 000 1111')->assertSessionHasErrors();
        $this->assertSame(1, Booking::count());
    }

    public function test_the_owner_approves_the_dates_and_the_guest_uploads_a_receipt(): void
    {
        $first = $this->wedding();
        $this->actingAs($this->owner)->put("/admin/bookings/{$first->id}/status", ['status' => 'AWAITING_PAYMENT'])->assertSessionHasNoErrors();

        $group = Booking::orderBy('id')->get();
        $this->assertSame(['AWAITING_PAYMENT'], $group->pluck('status')->unique()->all());
        $this->assertTrue($group->every(fn (Booking $b) => $b->expires_at->greaterThan(now()->addHours(47))));
        Mail::assertSent(GuestMessage::class, fn (GuestMessage $mail) => str_contains($mail->subjectLine, 'downpayment needed') && str_contains($mail->body, '₱7,749.50') && str_contains($mail->body, '/booking'));

        // The guest, on their own phone, finds the booking and sees the downpayment and the GCash QR.
        auth()->logout();
        $this->flushSession();
        $this->get('/booking/success/'.$first->reference)->assertForbidden();
        $this->post('/booking', ['reference' => strtolower($first->reference), 'phone' => '+63 917 123 4567'])->assertRedirect('/booking/success/'.$first->reference);
        $this->get('/booking/success/'.$first->reference)->assertInertia(fn (Assert $page) => $page->where('downpayment.stage', 'due')
            ->where('downpayment.amount', 7749.5)->where('downpayment.options.0.method', 'gcash')->where('downpayment.options.0.qr_image', '/uploads/site/gcash-qr.png'));

        $this->post("/booking/{$first->reference}/proof", ['method' => 'gcash', 'payer_reference' => '1012345678901', 'proof' => UploadedFile::fake()->image('receipt.jpg', 800, 1400)])
            ->assertSessionHasNoErrors();
        $payments = Payment::orderBy('id')->get();
        $this->assertCount(3, $payments);
        $this->assertSame(['PENDING'], $payments->pluck('status')->unique()->all());
        $this->assertCount(1, $payments->pluck('batch')->unique());
        $this->assertSame([3500.0, 1750.0, 2499.5], $payments->pluck('amount')->map(fn ($amount) => (float) $amount)->all());
        Storage::disk('local')->assertExists($payments->first()->proof_path);
        $this->get('/booking/success/'.$first->reference)->assertInertia(fn (Assert $page) => $page->where('downpayment.stage', 'reviewing')->where('downpayment.sent', 7749.5));
        // One receipt per downpayment: a second upload waits for the owner's check.
        $this->post("/booking/{$first->reference}/proof", ['method' => 'gcash', 'proof' => UploadedFile::fake()->image('again.jpg')])->assertSessionHasErrors('proof');

        // The owner sees the receipt and approves it once for all three facilities.
        $this->actingAs($this->owner)->get("/admin/payments/{$payments->first()->id}/proof")->assertOk();
        $this->getJson("/admin/bookings/{$first->id}")->assertJsonPath('payments.0.covers', 3)->assertJsonPath('payments.0.batch_total', 7749.5);
        $this->put("/admin/payments/{$payments->first()->id}/review", ['decision' => 'approve'])->assertSessionHasNoErrors();
        $group = Booking::orderBy('id')->get();
        $this->assertSame(['CONFIRMED'], $group->pluck('status')->unique()->all());
        $this->assertSame(['PARTIALLY_PAID'], $group->pluck('payment_status')->unique()->all());
        $this->assertSame(7749.5, $group->sum(fn (Booking $b) => $b->balanceDue()));
    }

    public function test_a_rejected_receipt_can_be_sent_again(): void
    {
        $first = $this->wedding();
        $this->actingAs($this->owner)->put("/admin/bookings/{$first->id}/status", ['status' => 'AWAITING_PAYMENT']);
        $this->post("/booking/{$first->reference}/proof", ['method' => 'gcash', 'proof' => UploadedFile::fake()->image('blurry.jpg')])->assertSessionHasNoErrors();

        $this->put('/admin/payments/'.Payment::first()->id.'/review', ['decision' => 'reject'])->assertSessionHasNoErrors();
        $this->assertSame(['FAILED'], Payment::pluck('status')->unique()->all());
        $this->assertSame(['AWAITING_PAYMENT'], Booking::pluck('status')->unique()->all());
        $this->get('/booking/success/'.$first->reference)->assertInertia(fn (Assert $page) => $page->where('downpayment.stage', 'rejected'));

        $this->post("/booking/{$first->reference}/proof", ['method' => 'gcash', 'proof' => UploadedFile::fake()->image('clear.jpg')])->assertSessionHasNoErrors();
        $this->assertSame(3, Payment::where('status', 'PENDING')->count());
    }

    public function test_dates_stay_held_while_a_receipt_waits_past_the_hold_time(): void
    {
        $first = $this->wedding();
        $this->actingAs($this->owner)->put("/admin/bookings/{$first->id}/status", ['status' => 'AWAITING_PAYMENT']);
        $this->post("/booking/{$first->reference}/proof", ['method' => 'gcash', 'proof' => UploadedFile::fake()->image('receipt.jpg')]);
        Booking::query()->update(['expires_at' => now()->subHour()]);

        Artisan::call('bookings:expire-holds');
        $this->assertSame(['AWAITING_PAYMENT'], Booking::pluck('status')->unique()->all());
        $this->assertFalse(app(AvailabilityService::class)->available($this->hall, $this->day(10), $this->day(11)));
        $this->put('/admin/payments/'.Payment::first()->id.'/review', ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(['CONFIRMED'], Booking::pluck('status')->unique()->all());
    }

    public function test_receipts_are_photos_and_private_to_the_business(): void
    {
        $first = $this->wedding();
        $this->actingAs($this->owner)->put("/admin/bookings/{$first->id}/status", ['status' => 'AWAITING_PAYMENT']);
        $this->post("/booking/{$first->reference}/proof", ['method' => 'gcash', 'proof' => UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf')])->assertSessionHasErrors('proof');
        $this->post("/booking/{$first->reference}/proof", ['method' => 'cash', 'proof' => UploadedFile::fake()->image('r.jpg')])->assertSessionHasErrors('method');
        $this->post("/booking/{$first->reference}/proof", ['method' => 'gcash', 'proof' => UploadedFile::fake()->image('r.jpg')])->assertSessionHasNoErrors();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'business_name' => 'Other', 'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $stranger = User::create(['name' => 'Stranger', 'email' => 'x@example.com', 'password' => 'secret-password', 'role' => 'ADMIN', 'tenant_id' => $other->id]);
        $this->actingAs($stranger)->get('/admin/payments/'.Payment::first()->id.'/proof')->assertNotFound();
    }

    public function test_the_downpayment_can_be_changed_for_one_booking(): void
    {
        $this->request([$this->item($this->house1, 5, 7)])->assertSessionHasNoErrors();
        $booking = Booking::sole();
        $this->assertSame('3500.00', $booking->deposit_amount);

        $this->actingAs($this->owner)->put("/admin/bookings/{$booking->id}/deposit", ['amount' => 2000])->assertSessionHasNoErrors();
        $this->assertSame('2000.00', $booking->fresh()->deposit_amount);
        $this->put("/admin/bookings/{$booking->id}/deposit", ['amount' => 99999])->assertSessionHasErrors('amount');

        // Settings → Payments sets the standard share for new requests.
        $this->tenant->update(['settings' => [...$this->tenant->fresh()->settings, 'deposit_percent' => 30]]);
        $this->request([$this->item($this->house2, 5, 6)], '0918 222 3333')->assertSessionHasNoErrors();
        $this->assertSame('1499.70', Booking::latest('id')->first()->deposit_amount);
    }

    public function test_a_wrong_mobile_number_does_not_open_a_booking(): void
    {
        $first = $this->wedding();
        $this->flushSession();
        $this->post('/booking', ['reference' => $first->reference, 'phone' => '0999 999 9999'])->assertSessionHasErrors('reference');
        $this->get('/booking/success/'.$first->reference)->assertForbidden();
    }
}
