<?php

namespace Database\Seeders;

use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PosItem;
use App\Models\Property;
use App\Models\Room;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \LogicException('Demo data cannot be seeded in production.');
        }

        DB::transaction(function (): void {
            $this->call(SubscriptionPlanSeeder::class);

            $island = $this->tenant('island-collection', 'Island Collection Hospitality', 'hello@islandcollection.example');
            $heritage = $this->tenant('cebu-heritage-stays', 'Cebu Heritage Stays', 'hello@cebuheritage.example');

            $owner = $this->user('superadmin@example.com', 'Demo Superadmin', 'SUPERADMIN');
            $this->user('admin@example.com', 'Demo Administrator', 'ADMIN', $island);
            $this->user('staff@example.com', 'Demo Staff', 'STAFF', $island);
            $guest = $this->user('guest@example.com', 'Demo Guest', 'GUEST');
            $this->user('admin2@example.com', 'Cebu Demo Administrator', 'ADMIN', $heritage);

            $this->subscription($island, SubscriptionPlan::where('code', 'MONTHLY')->firstOrFail(), $owner);
            $this->subscription($heritage, SubscriptionPlan::where('code', 'ANNUAL')->firstOrFail(), $owner);

            $azure = $this->property($island, 'azure-coast-villas', 'Azure Coast Villas', 'Panglao', 'Bohol',
                'Private villas steps from the turquoise coast.',
                'https://images.unsplash.com/photo-1571896349842-33c89424de2d?w=1600&q=85');
            $sierra = $this->property($island, 'sierra-haven', 'Sierra Haven', 'Valencia', 'Negros Oriental',
                'A quiet mountain retreat among lush gardens.',
                'https://images.unsplash.com/photo-1540541338287-41700207dee6?w=1600&q=85');
            $siargao = $this->property($island, 'island-house-siargao', 'Island House Siargao', 'General Luna', 'Siargao',
                'An easygoing island hideaway for surf and slow mornings.',
                'https://images.unsplash.com/photo-1520250497591-112f2f40a3f4?w=1600&q=85');
            $cebu = $this->property($heritage, 'cebu-heritage-house', 'Cebu Heritage House', 'Cebu City', 'Cebu',
                'An intimate city stay close to historic streets and local food.',
                'https://images.unsplash.com/photo-1566073771259-6a8506099945?w=1600&q=85');

            $azureSuite = $this->room($azure, 'signature-suite', 'Signature Suite', 6500, 2, 4);
            $this->room($azure, 'garden-room', 'Garden Room', 4200, 3, 2);
            $this->room($sierra, 'signature-suite', 'Signature Suite', 4200, 2, 4);
            $this->room($sierra, 'mountain-studio', 'Mountain Studio', 3100, 2, 2);
            $this->room($siargao, 'signature-suite', 'Signature Suite', 5200, 2, 4);
            $this->room($siargao, 'surf-loft', 'Surf Loft', 3500, 3, 2);
            $cebuSuite = $this->room($cebu, 'heritage-suite', 'Heritage Suite', 3900, 2, 3);

            $this->booking('EAJ-DEMO-CONFIRMED', $azureSuite, $guest, 8, 10, 'CONFIRMED', 'PAID', null, 'mock');
            $this->booking('EAJ-DEMO-PENDING', $azureSuite, $guest, 15, 17, 'PENDING', 'UNPAID', now()->addMinutes(15));
            $this->booking('EAJ-DEMO-CANCELLED', $azureSuite, $guest, 22, 24, 'CANCELLED', 'UNPAID');
            $this->booking('EAJ-DEMO-COMPLETED', $azureSuite, $guest, -12, -10, 'COMPLETED', 'PAID', null, 'cash');
            $this->booking('EAJ-DEMO-MANUAL', $cebuSuite, $guest, 11, 13, 'AWAITING_PAYMENT', 'PENDING', now()->addHours(24), 'manual');

            // On-site POS catalog: minibar, food, services.
            foreach ([['Bottled water', 'Minibar', 40, true, 48], ['Soft drink', 'Minibar', 60, true, 36], ['San Miguel beer', 'Minibar', 90, true, 24],
                ['Instant noodles', 'Snacks', 55, true, 20], ['Chips', 'Snacks', 70, true, 2], ['Breakfast set', 'Food', 350, false, 0],
                ['Coffee', 'Food', 120, false, 0], ['Laundry (per kg)', 'Services', 80, false, 0], ['Airport transfer', 'Services', 1500, false, 0],
                ['Late check-out (per hour)', 'Services', 300, false, 0], ['Extra towel set', 'Housekeeping', 150, false, 0]] as $i => [$name, $category, $price, $tracked, $stock]) {
                PosItem::updateOrCreate(['tenant_id' => $island->id, 'name' => $name],
                    ['category' => $category, 'price' => $price, 'track_stock' => $tracked, 'stock' => $stock, 'active' => true, 'sort_order' => $i]);
            }

            BlockedDate::updateOrCreate(['room_id' => $cebuSuite->id, 'reason' => 'Demo maintenance'], [
                'tenant_id' => $heritage->id, 'starts_on' => today()->addDays(18)->toDateString(),
                'ends_on' => today()->addDays(20)->toDateString(),
            ]);
        });
    }

    private function tenant(string $slug, string $name, string $email): Tenant
    {
        return Tenant::updateOrCreate(['slug' => $slug], [
            'name' => $name, 'business_name' => $name, 'business_email' => $email,
            'primary_color' => '#0A2266',
            'secondary_color' => '#0039D5',
            'status' => 'ACTIVE', 'subscription_status' => 'ACTIVE',
            'subscription_started_at' => now()->subMonth(), 'subscription_ends_at' => now()->addYear(),
        ]);
    }

    private function user(string $email, string $name, string $role, ?Tenant $tenant = null): User
    {
        return User::updateOrCreate(['email' => $email], [
            'name' => $name, 'password' => Hash::make('Password123!'),
            'role' => $role, 'tenant_id' => $tenant?->id,
        ]);
    }

    private function subscription(Tenant $tenant, SubscriptionPlan $plan, User $owner): void
    {
        $start = today();
        $end = $start->copy()->addMonthsNoOverflow($plan->commitment_months);
        $tenant->subscriptions()->updateOrCreate(['subscription_plan_id' => $plan->id], [
            'status' => 'ACTIVE', 'starts_at' => $start->toDateString(),
            'ends_at' => $end->toDateString(), 'renewal_at' => $end->toDateString(),
            'billing_interval' => $plan->billing_interval, 'commitment_months' => $plan->commitment_months,
            'base_price' => $plan->base_monthly_price, 'discount_type' => $plan->discount_type,
            'discount_value' => $plan->discount_value, 'effective_price' => $plan->effective_monthly_price,
            'billing_total' => $plan->billing_price, 'created_by' => $owner->id,
        ]);
        $tenant->update(['subscription_started_at' => $start, 'subscription_ends_at' => $end]);
    }

    private function property(Tenant $tenant, string $slug, string $name, string $city, string $province, string $description, string $image): Property
    {
        return Property::updateOrCreate(['slug' => $slug], [
            'tenant_id' => $tenant->id, 'name' => $name, 'city' => $city, 'province' => $province,
            'short_description' => $description, 'description' => $description.' Thoughtful comfort, local character, and room to unwind.',
            'cover_image' => $image, 'status' => 'PUBLISHED', 'featured' => true,
        ]);
    }

    private function room(Property $property, string $slug, string $name, int $rate, int $quantity, int $occupancy): Room
    {
        return Room::updateOrCreate(['property_id' => $property->id, 'slug' => $slug], [
            'tenant_id' => $property->tenant_id, 'name' => $name,
            'description' => 'A spacious retreat with modern comforts.',
            'maximum_occupancy' => $occupancy, 'quantity' => $quantity,
            'base_rate' => $rate, 'weekend_rate' => $rate + 500,
            'cleaning_fee' => 350, 'active' => true,
            'bedrooms' => $occupancy > 2 ? 2 : 1, 'bathrooms' => $occupancy > 2 ? 1.5 : 1,
            'beds' => $occupancy > 2 ? [['type' => 'king', 'count' => 1], ['type' => 'single', 'count' => 2]] : [['type' => 'queen', 'count' => 1]],
            'size_sqm' => $occupancy > 2 ? 55 : 28, 'base_guests' => 2, 'extra_guest_fee' => $occupancy > 2 ? 800 : 0,
            'amenities' => $occupancy > 2
                ? ['Air conditioning', 'Wi-Fi', 'Hot shower', 'Private bathroom', 'Smart TV', 'Kitchen', 'Balcony', 'Towels & linens']
                : ['Air conditioning', 'Wi-Fi', 'Hot shower', 'Private bathroom', 'Towels & linens'],
        ]);
    }

    private function booking(string $reference, Room $room, User $guest, int $in, int $out, string $status, string $paymentStatus, ?\DateTimeInterface $expiresAt = null, ?string $method = null): void
    {
        $checkIn = today()->addDays($in)->toDateString();
        $checkOut = today()->addDays($out)->toDateString();
        $quote = app(PricingService::class)->quote($room, $checkIn, $checkOut, 2);
        $customer = Customer::fromGuest($room->tenant_id, ['guest_name' => $guest->name, 'guest_email' => $guest->email], $guest->id);
        $booking = Booking::updateOrCreate(['reference' => $reference], [
            'tenant_id' => $room->tenant_id, 'property_id' => $room->property_id, 'room_id' => $room->id,
            'user_id' => $guest->id, 'customer_id' => $customer->id, 'guest_name' => $guest->name, 'guest_email' => $guest->email,
            'check_in' => $checkIn, 'check_out' => $checkOut, 'guests' => 2,
            'status' => $status, 'payment_status' => $paymentStatus,
            'total' => $quote['total'], 'expires_at' => $expiresAt,
        ]);
        $booking->price()->updateOrCreate([], $quote);
        if ($method) {
            Payment::updateOrCreate(['reference' => 'PAY-'.$reference], [
                'tenant_id' => $room->tenant_id, 'booking_id' => $booking->id,
                'method' => $method, 'provider' => $method === 'mock' ? 'mock' : 'manual',
                'status' => $paymentStatus === 'PAID' ? 'PAID' : 'PENDING',
                'amount' => $quote['total'], 'paid_at' => $paymentStatus === 'PAID' ? now() : null,
            ]);
        }
    }
}
