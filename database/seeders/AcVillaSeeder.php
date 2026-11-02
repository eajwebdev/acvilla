<?php

namespace Database\Seeders;

use App\Models\AddOn;
use App\Models\Property;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The live AC Villa Private Resort & Events Place site: the resort, its rooms and halls at the published rates, and the owner's login.
 * Safe to re-run: rates and details are reset to the rate card, but photos the owner uploaded in Admin are kept.
 *
 *   ACVILLA_ADMIN_EMAIL=owner@example.com ACVILLA_ADMIN_PASSWORD=... php artisan db:seed --class=AcVillaSeeder
 */
class AcVillaSeeder extends Seeder
{
    /** Units from the first version of this seeder become the four rooms on the rate card, so their bookings stay on the same building. */
    private const RENAMED = ['a-house-1' => 'barkada-room', 'a-house-2' => 'ah-room-1', 'a-house-3' => 'ah-room-2', 'a-house-4' => 'ah-room-3'];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call(SubscriptionPlanSeeder::class);

            $tenant = Tenant::updateOrCreate(['slug' => 'ac-villa'], [
                'name' => 'AC Villa & Private Resort', 'business_name' => 'AC Villa & Private Resort',
                'business_email' => env('ACVILLA_EMAIL'), 'business_phone' => env('ACVILLA_PHONE', '0927 493 1583'),
                'business_address' => 'North Poblacion, Mabinay, Negros Oriental (beside NORSU)',
                'primary_color' => '#005A1C', 'secondary_color' => '#0070C0',
                'hero_headline' => 'Your Private Escape in Mabinay',
                'hero_description' => 'Relax, celebrate, and create unforgettable memories with your family, friends, and loved ones.',
                'hero_image' => '/images/acvilla/hero-mabinay.jpg',
                'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE',
                'subscription_started_at' => now()->startOfDay(), 'subscription_ends_at' => now()->addYears(10),
            ]);
            $tenant->update(['settings' => [...$tenant->settings, 'reference_prefix' => 'ACV', 'service_fee_percent' => 0,
                'check_in_time' => '14:00', 'check_out_time' => '12:00', 'request_hold_hours' => 48,
                // A 50% downpayment confirms the booking; the rest is paid on arrival.
                'deposit_percent' => 50, 'balance_due_days' => 0, 'manual_payment_hours' => 48,
                'amenities' => ['Private pool', 'Air conditioning', 'Wi-Fi', 'Hot shower', 'Private bathroom', 'Smart TV',
                    'Free parking', 'Function hall', 'Videoke', 'Towels & linens', 'Poolside cabanas', 'Garden',
                    'Chairs & tables', 'Sound system with microphone'],
                'house_rules' => "Check-in from 2:00 PM, check-out by 12:00 NN.\nExtra person: ₱250 each.\nNo smoking inside the rooms.\nPlease keep noise down after 10:00 PM.\nGuests are responsible for any damage to resort property.",
                'cancellation_policy' => "Reservation requests are free. A 50% downpayment confirms your booking and the balance is paid on arrival.
Cancellation terms are agreed with the owner when we call you."]]);
            // The resort's InstaPay QR for downpayments (works with GCash, Maya and bank apps). Only filled in when the
            // owner hasn't set up a QR yet, so later changes in Settings > Payments are kept.
            $methods = $tenant->settings['payment_methods'];
            if (blank($methods['gcash']['qr_image'] ?? null)) {
                $methods['gcash'] = [...$methods['gcash'], 'enabled' => true, 'qr_image' => '/images/acvilla/instapay-qr.jpg', 'account_name' => 'JOL***L A.',
                    'instructions' => 'Scan with GCash, Maya or your bank app (InstaPay). Put your booking reference in the message if your app allows it.'];
                $tenant->update(['settings' => [...$tenant->settings, 'payment_methods' => $methods]]);
            }

            $resort = Property::updateOrCreate(['slug' => 'ac-villa-private-resort'], [
                'tenant_id' => $tenant->id, 'name' => 'AC Villa Private Resort & Events Place', 'city' => 'Mabinay', 'province' => 'Negros Oriental',
                'address' => 'North Poblacion, beside NORSU',
                'short_description' => 'A-House rooms from ₱3,500, a function hall from ₱7,000 and the whole resort exclusively for ₱38,000.',
                'description' => "A private resort and events place in North Poblacion, Mabinay, beside NORSU. Stay in the Barkada Room or one of our A-House rooms, swim in the private pool, and celebrate in our function hall or grand hall.\n\nPerfect for family getaways, barkada trips, weddings, birthdays and reunions, or rent the whole resort exclusively for ₱38,000.",
                'cover_image' => '/images/acvilla/resort-sunset.jpg', 'status' => 'PUBLISHED', 'featured' => true,
            ]);

            foreach (self::RENAMED as $old => $new) {
                if (! Room::where('property_id', $resort->id)->where('slug', $new)->exists()) {
                    Room::where('property_id', $resort->id)->where('slug', $old)->update(['slug' => $new]);
                }
            }

            $img = fn (string ...$files) => array_map(fn ($file) => "/images/acvilla/{$file}.jpg", $files);
            $stay = ['Air conditioning', 'Wi-Fi', 'Hot shower', 'Private bathroom', 'Towels & linens'];
            // Every A-House shows the resort's own photos first (the front, the bedroom, the loft with its spiral stair),
            // each led by a different one so the room cards don't look identical.
            $own = ['aframe-front', 'aframe-bedroom', 'aframe-loft'];
            $aHouse = fn (int $lead, string ...$more) => $img(...array_slice($own, $lead), ...array_slice($own, 0, $lead), ...$more);
            $units = [
                $this->room($resort, 'barkada-room', 'Barkada Room', 'A spacious A-House room for your barkada. Good for 8, a few steps from the pool.', 3500, 8, $aHouse(0, 'aframe-day', 'pool-day'), $stay),
                $this->room($resort, 'ah-room-1', 'AH Room 1', 'A cozy A-House room for couples and small families. Good for 4.', 3500, 4, $aHouse(1, 'aframe-night', 'balcony-view'), $stay),
                $this->room($resort, 'ah-room-2', 'AH Room 2', 'A roomy A-House for the whole family. Good for 10, with a loft bedroom.', 4999, 10, $aHouse(2, 'villas-row', 'pool-day'), $stay),
                $this->room($resort, 'ah-room-3', 'AH Room 3', 'A roomy A-House for the whole family. Good for 10, with a loft bedroom.', 4999, 10, $aHouse(0, 'aframe-day', 'pool-night'), $stay),
                $this->room($resort, 'function-hall', 'Function Hall', "Day use from 8:00 AM to 5:00 PM for ₱7,000. Make it a day-to-night event for ₱9,000, with 50 chairs and a speaker with microphone included.", 7000, 50,
                    $img('hall-interior', 'living-hall', 'wedding-setup', 'balcony-view'), ['Air conditioning', 'Function hall', 'Chairs & tables', 'Sound system with microphone', 'Free parking'], 'venue'),
                $this->room($resort, 'grand-hall', 'Grand Hall', 'Our grand hall for weddings, debuts and big celebrations, for up to 200 guests: 8 hours with 100 chairs and 2 tables included.', 15000, 100,
                    $img('hall-interior', 'wedding-setup', 'living-hall', 'resort-night'), ['Air conditioning', 'Function hall', 'Chairs & tables', 'Free parking'], 'venue', capacity: 200),
                $this->room($resort, 'whole-resort', 'Exclusive Whole Resort Package', 'Rent the whole resort for ₱38,000: every room, the function hall and the private pool, just for your group. Perfect for weddings, reunions and company outings.', 38000, 60,
                    $img('resort-sunset', 'resort-night', 'aerial', 'cabana-pool', 'wedding-setup', 'pool-night'), [...$stay, 'Function hall', 'Free parking'], 'room', wholeProperty: true),
            ];
            // Units from earlier versions of this seeder are retired, not deleted, so past bookings keep their room.
            Room::where('property_id', $resort->id)->whereNotIn('id', collect($units)->pluck('id'))->update(['active' => false]);

            $hall = collect($units)->firstWhere('slug', 'function-hall');
            AddOn::updateOrCreate(['tenant_id' => $tenant->id, 'name' => 'Day-to-night use'], [
                'description' => 'Extend the function hall past 5:00 PM (₱9,000 in total). Includes 50 chairs and 1 speaker with microphone.',
                'price' => 2000, 'pricing' => 'per_stay', 'max_quantity' => 1, 'room_ids' => [$hall->id], 'active' => true, 'sort_order' => 0,
            ]);

            if (filled(env('ACVILLA_ADMIN_EMAIL')) && filled(env('ACVILLA_ADMIN_PASSWORD'))) {
                User::updateOrCreate(['email' => env('ACVILLA_ADMIN_EMAIL')], ['name' => 'AC Villa Owner',
                    'password' => Hash::make(env('ACVILLA_ADMIN_PASSWORD')), 'role' => 'ADMIN', 'tenant_id' => $tenant->id]);
            }
        });
    }

    /** Rates come from the published rate card; rooms charge ₱250 for each guest over the rate's headcount. */
    private function room(Property $property, string $slug, string $name, string $description, int $rate, int $pax, array $photos, array $amenities, string $kind = 'room', bool $wholeProperty = false, ?int $capacity = null): Room
    {
        $room = $kind === 'room' && ! $wholeProperty;
        // Only seeded photos are replaced; once the owner uploads their own, those stay.
        $current = Room::where('property_id', $property->id)->where('slug', $slug)->first()?->photos ?? [];
        if (collect($current)->contains(fn ($src) => ! str_starts_with($src, '/images/acvilla/'))) {
            $photos = $current;
        }

        return Room::updateOrCreate(['property_id' => $property->id, 'slug' => $slug], [
            'tenant_id' => $property->tenant_id, 'name' => $name, 'description' => $description, 'kind' => $kind,
            'maximum_occupancy' => $capacity ?? ($room ? 15 : $pax), 'base_guests' => $pax, 'extra_guest_fee' => $room ? 250 : 0,
            'quantity' => 1, 'base_rate' => $rate, 'weekend_rate' => null, 'cleaning_fee' => 0, 'active' => true, 'whole_property' => $wholeProperty,
            'photos' => $photos, 'amenities' => $amenities,
        ]);
    }
}
