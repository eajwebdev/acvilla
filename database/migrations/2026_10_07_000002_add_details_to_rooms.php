<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedTinyInteger('bedrooms')->default(1);
            $table->decimal('bathrooms', 3, 1)->default(1);
            $table->json('beds')->nullable();
            $table->unsignedInteger('size_sqm')->nullable();
            $table->unsignedInteger('base_guests')->nullable();
            $table->decimal('extra_guest_fee', 12, 2)->default(0);
            $table->unsignedInteger('min_nights')->nullable();
            $table->unsignedInteger('max_nights')->nullable();
            $table->json('amenities')->nullable();
            $table->json('photos')->nullable();
        });
        Schema::table('booking_prices', function (Blueprint $table) {
            $table->decimal('extra_guest_fee', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('booking_prices', fn (Blueprint $table) => $table->dropColumn('extra_guest_fee'));
        Schema::table('rooms', fn (Blueprint $table) => $table->dropColumn([
            'bedrooms', 'bathrooms', 'beds', 'size_sqm', 'base_guests', 'extra_guest_fee',
            'min_nights', 'max_nights', 'amenities', 'photos',
        ]));
    }
};
