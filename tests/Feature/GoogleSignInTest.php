<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PhilippinePhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

/** Guests sign in with Google, add a Philippine mobile number once, and their bookings are on their account. */
class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private Room $house;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret', 'platform.site_tenant' => 'ac-villa', 'platform.require_guest_login' => true]);
        $tenant = Tenant::create(['name' => 'AC Villa', 'slug' => 'ac-villa', 'business_name' => 'AC Villa & Private Resort',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE', 'subscription_ends_at' => now()->addYear()]);
        $property = Property::create(['tenant_id' => $tenant->id, 'name' => 'AC Villa', 'slug' => 'ac-villa', 'city' => 'Mabinay', 'province' => 'Negros Oriental', 'status' => 'PUBLISHED']);
        $this->house = Room::create(['tenant_id' => $tenant->id, 'property_id' => $property->id, 'name' => 'AH Room 1', 'slug' => 'ah-room-1',
            'quantity' => 1, 'maximum_occupancy' => 4, 'base_guests' => 4, 'base_rate' => 3500, 'cleaning_fee' => 0, 'active' => true]);
    }

    private function google(string $email = 'edwin@gmail.com', string $id = 'g-123', bool $verified = true): void
    {
        Socialite::fake('google', (new GoogleUser)->map(['id' => $id, 'name' => 'Edwin Abril Jr', 'email' => $email, 'avatar' => 'https://lh3.googleusercontent.com/a/x'])
            ->setRaw(['email_verified' => $verified, 'given_name' => 'Edwin', 'family_name' => 'Abril Jr']));
    }

    private function request(int $from = 5, array $extra = [])
    {
        return $this->post('/bookings', ['items' => [['room_id' => $this->house->id, 'check_in' => today()->addDays($from)->toDateString(), 'check_out' => today()->addDays($from + 1)->toDateString(), 'guests' => 2]],
            'guest_name' => 'Someone Else', 'guest_phone' => '0917 000 0000', 'guest_email' => 'other@example.com', ...$extra]);
    }

    public function test_google_always_gets_the_one_configured_callback_address(): void
    {
        config(['services.google.redirect' => 'http://127.0.0.1:8000/auth/google/callback']);
        // Opened as localhost: moved to 127.0.0.1:8000 first, so the session survives the trip to Google.
        $this->get('http://localhost/auth/google?return=/book')->assertRedirect('http://127.0.0.1:8000/auth/google?return=/book');

        $to = $this->get('http://127.0.0.1:8000/auth/google?return=/book')->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($to, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('https://accounts.google.com/', $to);
        $this->assertSame('http://127.0.0.1:8000/auth/google/callback', $query['redirect_uri']);
    }

    public function test_philippine_mobile_numbers_are_recognised_in_every_common_format(): void
    {
        foreach (['0906 308 4301', '906 308 4301', '+63 906 308 4301', '63-906-308-4301', '9063084301'] as $input) {
            $this->assertSame('+639063084301', PhilippinePhone::normalize($input), $input);
        }
        foreach (['0806 308 4301', '906 308 430', '1234567', '+1 415 555 0100', ''] as $input) {
            $this->assertNull(PhilippinePhone::normalize($input), $input);
        }
        $this->assertSame('+63 906 308 4301', PhilippinePhone::format('09063084301'));
    }

    public function test_first_google_sign_in_creates_a_guest_account_and_asks_for_name_and_mobile_number(): void
    {
        $this->get('/auth/google?return=/book')->assertRedirect();
        $this->google();
        $this->get('/auth/google/callback')->assertRedirect('/account/complete');
        $user = User::sole();
        $this->assertSame(['GUEST', 'edwin@gmail.com', 'g-123', null], [$user->role, $user->email, $user->google_id, $user->phone]);
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);

        $this->get('/account/complete')->assertInertia(fn (Assert $page) => $page->component('Guest/CompleteProfile')
            ->where('first_name', 'Edwin')->where('last_name', 'Abril Jr')->where('email', 'edwin@gmail.com')->where('first', true));
        $this->put('/account/complete', ['first_name' => 'Edwin', 'last_name' => 'Abril Jr', 'phone' => '0806 308 4301'])
            ->assertSessionHasErrors(['phone' => 'Enter a Philippine mobile number, e.g. 917 123 4567.']);
        $this->put('/account/complete', ['first_name' => 'Edwin', 'last_name' => 'Abril Jr', 'phone' => ''])->assertSessionHasErrors('phone');
        $this->put('/account/complete', ['first_name' => 'Edwin', 'last_name' => 'Abril Jr', 'phone' => '906 308 4301'])->assertRedirect('/book');
        $this->assertSame(['Edwin Abril Jr', '+639063084301'], [$user->fresh()->name, $user->fresh()->phone]);

        // Next time: straight back to where they were, no questions.
        auth()->logout();
        $this->get('/auth/google?return=/booking');
        $this->google();
        $this->get('/auth/google/callback')->assertRedirect('/booking');
        $this->assertSame(1, User::count());
    }

    public function test_a_request_needs_a_signed_in_guest_with_a_mobile_number_and_uses_the_account_details(): void
    {
        $this->request()->assertSessionHasErrors(['account' => 'Sign in with Google to send your request.']);

        $guest = User::create(['name' => 'Edwin Abril Jr', 'email' => 'edwin@gmail.com', 'password' => 'x-password-123', 'role' => 'GUEST']);
        $this->actingAs($guest)->request()->assertSessionHasErrors(['account' => 'Add your mobile number to your account first.']);
        $this->assertSame(0, Booking::count());

        $guest->update(['phone' => '+639063084301']);
        $this->actingAs($guest)->request()->assertSessionHasNoErrors()->assertRedirect();
        $booking = Booking::sole();
        $this->assertSame([$guest->id, 'Edwin Abril Jr', 'edwin@gmail.com', '+639063084301'], [$booking->user_id, $booking->guest_name, $booking->guest_email, $booking->guest_phone]);
        Mail::assertSent(\App\Mail\GuestMessage::class, fn ($mail) => $mail->hasTo('edwin@gmail.com'));
    }

    public function test_my_bookings_lists_the_accounts_bookings_without_a_reference_on_any_device(): void
    {
        $guest = User::create(['name' => 'Edwin Abril Jr', 'email' => 'edwin@gmail.com', 'password' => 'x-password-123', 'role' => 'GUEST', 'phone' => '+639063084301']);
        $guest->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($guest)->request(5)->assertSessionHasNoErrors();
        $mine = Booking::sole();
        // Booked before the account existed, with the same email: also theirs. Someone else's is not shown.
        $earlier = Booking::create(['tenant_id' => $this->house->tenant_id, 'property_id' => $this->house->property_id, 'room_id' => $this->house->id, 'guest_name' => 'Edwin',
            'guest_email' => 'EDWIN@gmail.com', 'check_in' => today()->addDays(20), 'check_out' => today()->addDays(21), 'guests' => 2, 'reference' => 'ACV-OLD-1', 'status' => 'CONFIRMED', 'total' => 3500]);
        Booking::create(['tenant_id' => $this->house->tenant_id, 'property_id' => $this->house->property_id, 'room_id' => $this->house->id, 'guest_name' => 'Stranger',
            'guest_email' => 'stranger@example.com', 'check_in' => today()->addDays(30), 'check_out' => today()->addDays(31), 'guests' => 2, 'reference' => 'ACV-NOT-MINE', 'status' => 'CONFIRMED', 'total' => 3500]);

        // A fresh session (another phone): the account is enough.
        $this->flushSession();
        $this->actingAs($guest)->get('/booking')->assertInertia(fn (Assert $page) => $page->component('Public/FindBooking')
            ->has('account', 2)->where('account.0.reference', 'ACV-OLD-1')->where('account.1.reference', $mine->reference));
        $this->get("/booking/success/{$mine->reference}")->assertOk();
        $this->get("/booking/success/{$earlier->reference}")->assertOk();
        $this->get('/booking/success/ACV-NOT-MINE')->assertForbidden();
        $this->get('/account/bookings')->assertRedirect('/booking');
    }

    public function test_unverified_google_emails_and_deactivated_accounts_cannot_sign_in(): void
    {
        $this->google('new@gmail.com', 'g-9', verified: false);
        $this->get('/auth/google/callback')->assertRedirect('/booking');
        $this->assertGuest();
        $this->assertSame(0, User::count());

        User::create(['name' => 'Old', 'email' => 'old@gmail.com', 'password' => 'x-password-123', 'role' => 'GUEST', 'deactivated_at' => now()]);
        $this->google('old@gmail.com', 'g-10');
        $this->get('/auth/google/callback');
        $this->assertGuest();
    }

    public function test_the_owner_can_use_google_too_and_goes_to_the_admin(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@gmail.com', 'password' => 'x-password-123', 'role' => 'ADMIN', 'tenant_id' => $this->house->tenant_id]);
        $this->google('owner@gmail.com', 'g-owner');
        $this->get('/auth/google/callback')->assertRedirect('/admin');
        $this->assertAuthenticatedAs($owner);
        $this->assertSame('g-owner', $owner->fresh()->google_id);
        // Staff and owners keep booking for walk-ins from the website form with any contact details.
        $this->request()->assertSessionHasNoErrors();
        $this->assertSame('Someone Else', Booking::sole()->guest_name);
    }

    public function test_without_google_keys_an_account_is_still_required_and_made_with_email(): void
    {
        config(['services.google.client_id' => null]);
        $this->get('/auth/google')->assertNotFound();
        $this->request()->assertSessionHasErrors(['account' => 'Sign in or create an account to send your request.']);
        $this->assertSame(0, Booking::count());

        // Sent from the booking page: create an account, add the mobile number, back to the booking.
        $this->get('/register?return=/book')->assertInertia(fn (Assert $page) => $page->component('Auth/Register')->where('google', false)->where('return', '/book'));
        $this->post('/register', ['name' => 'Jochel Agnes', 'email' => 'jochel@example.com', 'password' => 'Secret-Pass-2026', 'password_confirmation' => 'Secret-Pass-2026'])
            ->assertRedirect('/account/complete');
        $this->put('/account/complete', ['first_name' => 'Jochel', 'last_name' => 'Agnes', 'phone' => '0906 308 4301'])->assertRedirect('/book');
        $this->request()->assertSessionHasNoErrors();
        $this->assertSame(['Jochel Agnes', 'jochel@example.com', '+639063084301'], [Booking::sole()->guest_name, Booking::sole()->guest_email, Booking::sole()->guest_phone]);

        // Signing in again later goes straight back to where the guest was.
        auth()->logout();
        $this->get('/login?return=/book');
        $this->post('/login', ['email' => 'jochel@example.com', 'password' => 'Secret-Pass-2026'])->assertRedirect('/book');
        $this->get('/login?return=https://evil.example/steal');
        auth()->logout();
        $this->post('/login', ['email' => 'jochel@example.com', 'password' => 'Secret-Pass-2026'])->assertRedirect('/booking');
    }

    public function test_staff_book_walk_ins_who_have_no_account(): void
    {
        $tenant = $this->house->tenant_id;
        $staff = User::create(['name' => 'Front Desk', 'email' => 'desk@example.com', 'password' => 'x-password-123', 'role' => 'STAFF', 'tenant_id' => $tenant]);
        // Name only, no email or number: a walk-in arriving today.
        $this->actingAs($staff)->post('/admin/bookings', ['room_id' => $this->house->id, 'check_in' => today()->toDateString(), 'check_out' => today()->addDay()->toDateString(),
            'guests' => 2, 'guest_name' => 'Walk-in Guest', 'source' => 'walk_in', 'confirm' => true])->assertSessionHasNoErrors();
        $walkIn = Booking::sole();
        $this->assertSame(['Walk-in Guest', null, null, 'walk_in', 'CONFIRMED'], [$walkIn->guest_name, $walkIn->guest_email, $walkIn->user_id, $walkIn->source, $walkIn->status]);

        // Booked by the owner with the guest's email: it shows up on their account once they sign in with Google.
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@resort.ph', 'password' => 'x-password-123', 'role' => 'ADMIN', 'tenant_id' => $tenant]);
        $this->actingAs($owner)->post('/admin/bookings', ['room_id' => $this->house->id, 'check_in' => today()->addDays(10)->toDateString(), 'check_out' => today()->addDays(11)->toDateString(),
            'guests' => 2, 'guest_name' => 'Edwin Abril Jr', 'guest_email' => 'edwin@gmail.com', 'guest_phone' => '0906 308 4301', 'source' => 'phone'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->google();
        $this->get('/auth/google/callback');
        $this->put('/account/complete', ['first_name' => 'Edwin', 'last_name' => 'Abril Jr', 'phone' => '0906 308 4301']);
        $this->get('/booking')->assertInertia(fn (Assert $page) => $page->has('account', 1)->where('account.0.facilities.0', 'AH Room 1'));
    }

    public function test_the_owner_can_switch_the_account_requirement_off(): void
    {
        config(['platform.require_guest_login' => false]);
        $this->request()->assertSessionHasNoErrors();
        $this->assertSame('Someone Else', Booking::sole()->guest_name);
    }
}
