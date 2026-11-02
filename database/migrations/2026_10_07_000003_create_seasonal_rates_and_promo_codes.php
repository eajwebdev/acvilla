<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasonal_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('adjustment_type');
            $t->decimal('adjustment_value', 12, 2);
            $t->unsignedInteger('min_nights')->nullable();
            $t->json('room_ids')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index(['tenant_id', 'starts_on', 'ends_on']);
        });
        Schema::create('promo_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('code');
            $t->string('discount_type');
            $t->decimal('discount_value', 12, 2);
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->unsignedInteger('min_nights')->nullable();
            $t->unsignedInteger('max_uses')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });
        Schema::table('bookings', function (Blueprint $t) {
            $t->foreignId('promo_code_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('booking_prices', function (Blueprint $t) {
            $t->json('discounts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_prices', fn (Blueprint $t) => $t->dropColumn('discounts'));
        Schema::table('bookings', fn (Blueprint $t) => $t->dropConstrainedForeignId('promo_code_id'));
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('seasonal_rates');
    }
};
