<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('add_ons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('description', 300)->nullable();
            $t->decimal('price', 12, 2);
            $t->string('pricing');
            $t->unsignedTinyInteger('max_quantity')->default(1);
            $t->json('room_ids')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::table('booking_prices', function (Blueprint $t) {
            $t->json('add_ons')->nullable();
            $t->decimal('add_ons_total', 12, 2)->default(0);
        });
        Schema::table('bookings', function (Blueprint $t) {
            $t->timestamp('policies_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn('policies_accepted_at'));
        Schema::table('booking_prices', fn (Blueprint $t) => $t->dropColumn(['add_ons', 'add_ons_total']));
        Schema::dropIfExists('add_ons');
    }
};
