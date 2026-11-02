<?php

namespace Tests\Feature;

use App\Mail\GuestMessage;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentsAndMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const GCASH = ['enabled' => true, 'account_name' => 'Juan Dela Cruz', 'account_number' => '0917 555 0101', 'instructions' => 'Add your booking reference.', 'qr_image' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function room(array $settings = []): Room
    {
        $tenant = Tenant::create(['name' => 'Demo', 'slug' => 'demo', 'business_name' => 'Bay Resort', 'business_phone' => '+63 2 8000',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear(),
            'settings' => $settings + ['service_fee_percent' => 0]]);
        $property = Property::create(['tenant_id' => $tenant->id, 'name' => 'Bay Villas', 'slug' => 'bay', 'city' => 'Cebu', 'province' => 'Cebu', 'status' => 'PUBLISHED']);

        return Room::create(['tenant_id' => $tenant->id, 'property_id' => $property->id, 'name' => 'Suite', 'slug' => 'suite',
            'quantity' => 5, 'maximum_occupancy' => 4, 'base_rate' => 1000, 'cleaning_fee' => 0, 'active' => true]);
    }

    private function book(Room $room, int $in = 20, int $out = 22): Booking
    {
        $this->post('/bookings', ['room_id' => $room->id, 'guests' => 2, 'guest_name' => 'Ana Reyes', 'guest_email' => 'ana@example.com', 'guest_phone' => '0917 123 4567',
            'check_in' => today()->addDays($in)->toDateString(), 'check_out' => today()->addDays($out)->toDateString()])->assertRedirect();

        return Booking::latest('id')->firstOrFail();
    }

    private function user(Room $room, string $role = 'ADMIN'): User
    {
        return User::create(['name' => $role, 'email' => strtolower($role).'@bay.test', 'password' => 'Password123!', 'role' => $role, 'tenant_id' => $room->tenant_id]);
    }

    private function sent(string $subjectFragment): int
    {
        return Mail::sent(GuestMessage::class, fn (GuestMessage $mail) => str_contains($mail->subjectLine, $subjectFragment))->count();
    }

    public function test_deposit_is_taken_upfront_and_confirms_the_booking(): void
    {
        $room = $this->room(['deposit_percent' => 30, 'balance_due_days' => 7]);
        $booking = $this->book($room);
        $this->assertEquals(600, $booking->deposit_amount);
        $this->assertSame(today()->addDays(13)->toDateString(), $booking->balance_due_on->toDateString());
        $this->get("/checkout/{$booking->reference}")->assertInertia(fn (Assert $page) => $page->where('amountDueNow', 600)
            ->where('paymentOptions.0.method', 'mock')->where('paymentOptions.1.method', 'manual'));

        $this->post("/checkout/{$booking->reference}/pay", ['method' => 'mock'])->assertRedirect();
        $booking->refresh();
        $this->assertSame(['CONFIRMED', 'PARTIALLY_PAID'], [$booking->status, $booking->payment_status]);
        $this->assertEquals(600, Payment::where('booking_id', $booking->id)->value('amount'));
        $this->assertSame(1, $this->sent('is confirmed'));
        $this->assertDatabaseHas('booking_messages', ['booking_id' => $booking->id, 'event' => 'booking_confirmed', 'status' => 'SENT']);
        Mail::assertSent(GuestMessage::class, fn (GuestMessage $mail) => str_contains($mail->body, 'Balance: ₱1,400.00 (due by'));
    }

    public function test_gcash_payment_is_reported_then_approved_by_staff(): void
    {
        $room = $this->room(['test_payments' => false, 'payment_methods' => ['gcash' => self::GCASH]]);
        $booking = $this->book($room);
        $this->get("/checkout/{$booking->reference}")->assertInertia(fn (Assert $page) => $page->has('paymentOptions', 1)
            ->where('paymentOptions.0.account_number', '0917 555 0101'));
        $this->post("/checkout/{$booking->reference}/pay", ['method' => 'mock'])->assertSessionHasErrors('method');
        $this->post("/checkout/{$booking->reference}/pay", ['method' => 'manual'])->assertSessionHasErrors('method');

        $this->post("/checkout/{$booking->reference}/pay", ['method' => 'gcash', 'payer_reference' => '1009 2231'])->assertRedirect();
        $payment = Payment::firstOrFail();
        $this->assertSame(['PENDING', 'gcash', '1009 2231'], [$payment->status, $payment->method, $payment->payer_reference]);
        $this->assertSame('AWAITING_PAYMENT', $booking->fresh()->status);
        Mail::assertSent(GuestMessage::class, fn (GuestMessage $mail) => str_contains($mail->subjectLine, 'payment needed')
            && str_contains($mail->body, 'Account number: 0917 555 0101') && str_contains($mail->body, 'send ₱2,000.00 by'));
        $this->get("/booking/success/{$booking->reference}")->assertInertia(fn (Assert $page) => $page->where('pendingPayment.option.label', 'GCash / InstaPay'));

        $staff = $this->user($room, 'STAFF');
        $this->actingAs($staff)->get('/admin/bookings')->assertInertia(fn (Assert $page) => $page->where('bookings.data.0.payments.0.id', $payment->id));
        $this->actingAs($staff)->put("/admin/payments/{$payment->id}/review", ['decision' => 'approve'])->assertRedirect();
        $this->assertSame(['CONFIRMED', 'PAID'], [$booking->fresh()->status, $booking->fresh()->payment_status]);
        $this->assertSame(1, $this->sent('is confirmed'));
        $this->assertSame(0, $this->sent('Payment received'));
        $this->actingAs($staff)->put("/admin/payments/{$payment->id}/review", ['decision' => 'approve'])->assertStatus(422);
    }

    public function test_rejected_payment_lets_the_guest_try_again_and_balance_payments_send_receipts(): void
    {
        $room = $this->room(['deposit_percent' => 50, 'payment_methods' => ['gcash' => self::GCASH]]);
        $booking = $this->book($room);
        $this->post("/checkout/{$booking->reference}/pay", ['method' => 'gcash']);
        $admin = $this->user($room);
        $this->actingAs($admin)->put('/admin/payments/'.Payment::firstOrFail()->id.'/review', ['decision' => 'reject'])->assertRedirect();
        $this->assertSame(['FAILED', 'UNPAID'], [Payment::firstOrFail()->status, $booking->fresh()->payment_status]);

        $this->post("/checkout/{$booking->reference}/pay", ['method' => 'mock'])->assertRedirect();
        $this->assertSame('CONFIRMED', $booking->fresh()->status);
        $this->actingAs($admin)->post("/admin/bookings/{$booking->id}/payments", ['amount' => 1000, 'method' => 'cash', 'payer_reference' => 'OR-55'])->assertRedirect();
        $this->assertSame('PAID', $booking->fresh()->payment_status);
        $this->post("/admin/bookings/{$booking->id}/payments", ['amount' => 0.001, 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->assertSame(1, $this->sent('Payment received'));
        $this->assertDatabaseHas('payments', ['booking_id' => $booking->id, 'payer_reference' => 'OR-55']);
    }

    public function test_templates_can_be_customized_or_turned_off_and_status_emails_fire_once(): void
    {
        $room = $this->room();
        MessageTemplate::create(['tenant_id' => $room->tenant_id, 'event' => 'booking_confirmed', 'enabled' => true,
            'subject' => 'Salamat {guest_name}!', 'body' => '{room_name} from {check_in_time}. Call {business_phone}.']);
        MessageTemplate::create(['tenant_id' => $room->tenant_id, 'event' => 'booking_cancelled', 'enabled' => false, 'subject' => 'x', 'body' => 'x']);
        $booking = $this->book($room);
        $this->post("/checkout/{$booking->reference}/pay", ['method' => 'mock']);
        Mail::assertSent(GuestMessage::class, fn (GuestMessage $mail) => $mail->subjectLine === 'Salamat Ana Reyes!'
            && $mail->body === 'Suite from 2:00 PM. Call +63 2 8000.' && $mail->hasTo('ana@example.com'));

        $admin = $this->user($room);
        $this->actingAs($admin)->put("/admin/bookings/{$booking->id}/status", ['status' => 'CHECKED_IN']);
        $this->actingAs($admin)->put("/admin/bookings/{$booking->id}/status", ['status' => 'CHECKED_OUT']);
        $this->assertSame(1, $this->sent('Thank you for staying'));

        $other = $this->book($room, 30, 32);
        $this->actingAs($admin)->put("/admin/bookings/{$other->id}/status", ['status' => 'CANCELLED'])->assertRedirect();
        $this->assertSame(0, $this->sent('cancelled'));
        // Each website request also gets its own one-time "request received" email.
        $this->assertSame(2, BookingMessage::where('event', '!=', 'booking_requested')->count());
    }

    public function test_pre_arrival_reminders_go_out_once_inside_the_window(): void
    {
        $room = $this->room();
        MessageTemplate::create(['tenant_id' => $room->tenant_id, 'event' => 'pre_arrival', 'enabled' => true, 'send_days_before' => 3,
            'subject' => 'See you {check_in}', 'body' => 'Hi']);
        $soon = $this->book($room, 2, 4);
        $later = $this->book($room, 10, 12);
        Booking::query()->update(['status' => 'CONFIRMED', 'expires_at' => null]);

        $this->artisan('bookings:send-reminders')->expectsOutput('Sent 1 pre-arrival reminders.')->assertExitCode(0);
        $this->artisan('bookings:send-reminders')->expectsOutput('Sent 0 pre-arrival reminders.');
        $this->assertDatabaseHas('booking_messages', ['booking_id' => $soon->id, 'event' => 'pre_arrival']);
        $this->assertDatabaseMissing('booking_messages', ['booking_id' => $later->id, 'event' => 'pre_arrival']);
    }

    public function test_admin_edits_payment_settings_and_message_templates(): void
    {
        $room = $this->room();
        $admin = $this->user($room);
        $methods = ['gcash' => ['enabled' => true, 'account_number' => ''], 'maya' => ['enabled' => false], 'bank_transfer' => ['enabled' => false], 'cash' => ['enabled' => true]];
        $payload = ['deposit_percent' => 50, 'balance_due_days' => 3, 'test_payments' => false, 'payment_methods' => $methods];
        $this->actingAs($admin)->put('/admin/settings/payments', $payload)->assertSessionHasErrors('payment_methods.gcash.account_number');
        $this->actingAs($admin)->put('/admin/settings/payments', ['payment_methods' => $methods + ['paypal' => ['enabled' => true]]] + $payload)->assertSessionHasErrors('payment_methods');
        // A QR alone is enough to pay by: no number needed, and the name guests see when they scan it is kept.
        $methods['gcash'] = ['enabled' => true, 'account_number' => '', 'account_name' => 'JOL***L A.', 'qr_image' => '/images/acvilla/instapay-qr.jpg'];
        $this->actingAs($admin)->put('/admin/settings/payments', ['payment_methods' => $methods] + $payload)->assertSessionHasNoErrors();
        $this->assertSame('JOL***L A.', $room->property->tenant->fresh()->settings['payment_methods']['gcash']['account_name']);
        $methods['gcash']['qr_image'] = '/etc/passwd';
        $this->actingAs($admin)->put('/admin/settings/payments', ['payment_methods' => $methods] + $payload)->assertSessionHasErrors('payment_methods.gcash.qr_image');
        $methods['gcash'] = ['enabled' => true, 'account_number' => '0917', 'qr_image' => 'https://example.com/qr.png'];
        $this->actingAs($admin)->put('/admin/settings/payments', ['payment_methods' => $methods] + $payload)->assertSessionHasNoErrors();
        $settings = $room->property->tenant->fresh()->settings;
        $this->assertSame([50.0, false, true, '0917', true], [$settings['deposit_percent'], $settings['test_payments'],
            $settings['payment_methods']['gcash']['enabled'], $settings['payment_methods']['gcash']['account_number'], $settings['payment_methods']['cash']['enabled']]);

        $this->actingAs($admin)->get('/admin/settings/messages')->assertInertia(fn (Assert $page) => $page->component('Admin/Messages')
            ->has('templates', 9)->where('templates.7.event', 'pre_arrival')->where('templates.7.send_days_before', 2)->where('sample.guest_name', 'Maria Santos'));
        $this->actingAs($admin)->put('/admin/settings/messages/pre_arrival', ['enabled' => true, 'subject' => 'Hi', 'body' => 'Soon', 'send_days_before' => 40])->assertSessionHasErrors('send_days_before');
        $this->actingAs($admin)->put('/admin/settings/messages/thank_you', ['enabled' => false, 'subject' => 'Bye', 'body' => 'Thanks', 'send_days_before' => 1])->assertSessionHasErrors('send_days_before');
        $this->actingAs($admin)->put('/admin/settings/messages/thank_you', ['enabled' => false, 'subject' => 'Bye', 'body' => 'Thanks'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('message_templates', ['tenant_id' => $room->tenant_id, 'event' => 'thank_you', 'enabled' => false]);
        $this->actingAs($admin)->delete('/admin/settings/messages/thank_you')->assertRedirect();
        $this->assertDatabaseCount('message_templates', 0);
        $this->actingAs($admin)->put('/admin/settings/messages/nope', ['enabled' => true, 'subject' => 'x', 'body' => 'x'])->assertNotFound();

        $this->actingAs($admin)->post('/admin/settings/messages/booking_confirmed/test', ['subject' => 'Hello {guest_name}', 'body' => 'Ref {booking_reference}'])->assertRedirect();
        Mail::assertSent(GuestMessage::class, fn (GuestMessage $mail) => $mail->subjectLine === '[Test] Hello Maria Santos' && $mail->hasTo('admin@bay.test'));

        $staff = $this->user($room, 'STAFF');
        $this->actingAs($staff)->get('/admin/settings/messages')->assertForbidden();
        $this->actingAs($staff)->put('/admin/settings/payments', $payload)->assertForbidden();
    }
}
