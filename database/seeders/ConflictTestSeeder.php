<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Approved (downpayment paid) bookings on fixed November dates, for trying the conflict checks by hand:
 *
 *   Barkada Room                    Nov 5 to Nov 6 (out the morning the whole-resort group arrives)
 *   Exclusive Whole Resort Package  Nov 6 to Nov 7 (every facility is taken that night)
 *   AH Room 1, AH Room 2, AH Room 3 Nov 8 to Nov 9
 *
 * Run after AcVillaSeeder:  php artisan db:seed --class=ConflictTestSeeder
 * Running it again updates the same bookings (references ACV-TEST-...), it never adds duplicates.
 */
class ConflictTestSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'ac-villa')->firstOrFail();
        $year = (int) now(config('platform.timezone'))->year;
        $nov = fn (int $day) => CarbonImmutable::create($year, 11, $day)->toDateString();
        $percent = (float) ($tenant->settings['deposit_percent'] ?? 50);

        $stays = [
            ['ACV-TEST-BARKADA', 'barkada-room', 5, 6, 8, 'Paolo Santos', '0917 555 0101'],
            ['ACV-TEST-RESORT', 'whole-resort', 6, 7, 40, 'Maria Dela Cruz', '0917 555 0102'],
            ['ACV-TEST-AH1', 'ah-room-1', 8, 9, 4, 'Jose Ramos', '0917 555 0103'],
            ['ACV-TEST-AH2', 'ah-room-2', 8, 9, 10, 'Ana Villanueva', '0917 555 0104'],
            ['ACV-TEST-AH3', 'ah-room-3', 8, 9, 10, 'Mark Bautista', '0917 555 0105'],
        ];

        DB::transaction(function () use ($stays, $tenant, $nov, $percent) {
            foreach ($stays as [$reference, $slug, $in, $out, $guests, $name, $phone]) {
                $room = Room::where('tenant_id', $tenant->id)->where('slug', $slug)->firstOrFail();
                $guests = min($guests, $room->maximum_occupancy);
                $quote = app(PricingService::class)->quote($room, $nov($in), $nov($out), $guests);
                $customer = Customer::fromGuest($tenant->id, ['guest_name' => $name, 'guest_phone' => $phone]);
                $deposit = round($quote['total'] * $percent / 100, 2);

                $booking = Booking::withTrashed()->updateOrCreate(['reference' => $reference], [
                    'tenant_id' => $tenant->id, 'property_id' => $room->property_id, 'room_id' => $room->id, 'customer_id' => $customer->id,
                    'guest_name' => $name, 'guest_phone' => $phone, 'check_in' => $nov($in), 'check_out' => $nov($out), 'guests' => $guests,
                    'status' => 'CONFIRMED', 'payment_status' => 'PARTIALLY_PAID', 'source' => 'website',
                    'total' => $quote['total'], 'deposit_amount' => $deposit, 'expires_at' => null, 'deleted_at' => null,
                ]);
                $booking->price()->updateOrCreate([], $quote);
                Payment::updateOrCreate(['reference' => 'PAY-'.$reference], [
                    'tenant_id' => $tenant->id, 'booking_id' => $booking->id, 'method' => 'gcash', 'provider' => 'manual',
                    'status' => 'PAID', 'amount' => $deposit, 'paid_at' => now(),
                ]);
                $booking->syncPaymentStatus();
            }
        });

        $this->command?->info('Seeded 5 approved test bookings: Barkada Nov 5-6, Whole Resort Nov 6-7, AH Rooms 1-3 Nov 8-9.');
    }
}
