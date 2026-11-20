<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The removed ConflictTestSeeder put five sample bookings (references ACV-TEST-...) and their made-up guests
 * (Paolo Santos, Maria Dela Cruz, ...) on the live site. This deletes exactly those, and nothing a real guest made:
 * a sample customer is only removed when no other booking belongs to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $bookings = DB::table('bookings')->where('reference', 'like', 'ACV-TEST-%');
            $ids = $bookings->pluck('id');
            if ($ids->isEmpty()) {
                return;
            }
            $customers = $bookings->whereNotNull('customer_id')->pluck('customer_id')->unique();

            // These only lose their link (nullOnDelete), so remove them first rather than leave orphans behind.
            DB::table('cleaning_tasks')->whereIn('booking_id', $ids)->delete();
            DB::table('booking_notifications')->whereIn('booking_id', $ids)->delete();
            // Prices, payments, messages and reviews go with the booking (cascadeOnDelete). A hard delete, past soft deletes.
            DB::table('bookings')->whereIn('id', $ids)->delete();

            DB::table('customers')->whereIn('id', $customers)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('bookings')->whereColumn('bookings.customer_id', 'customers.id'))
                ->delete();
        });
    }

    public function down(): void
    {
        // Sample data is not put back.
    }
};
