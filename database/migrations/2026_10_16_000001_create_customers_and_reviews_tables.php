<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('identity_key');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('status')->default('ACTIVE')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'identity_key']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('approved')->default(false)->index();
            $table->timestamps();
            $table->index(['tenant_id', 'approved', 'created_at']);
        });

        // Existing reservations become customer records immediately after deployment.
        DB::table('bookings')->orderBy('id')->each(function (object $booking): void {
            $email = filled($booking->guest_email) ? strtolower(trim($booking->guest_email)) : null;
            $digits = preg_replace('/\D+/', '', (string) $booking->guest_phone);
            $phone = $digits !== '' ? ($digits[0] === '0' ? '+63'.substr($digits, 1) : ($digits === '63' || str_starts_with($digits, '63') ? '+'.$digits : '+'.$digits)) : null;
            $identity = $email ? 'email:'.$email : ($phone ? 'phone:'.$phone : 'name:'.strtolower(trim($booking->guest_name)));
            $now = now();

            DB::table('customers')->updateOrInsert(
                ['tenant_id' => $booking->tenant_id, 'identity_key' => $identity],
                ['user_id' => $booking->user_id, 'name' => $booking->guest_name, 'email' => $email,
                    'phone' => $phone, 'updated_at' => $now, 'created_at' => $now]
            );
            $customerId = DB::table('customers')->where('tenant_id', $booking->tenant_id)->where('identity_key', $identity)->value('id');
            DB::table('bookings')->where('id', $booking->id)->update(['customer_id' => $customerId]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::table('bookings', fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_id'));
        Schema::dropIfExists('customers');
    }
};
