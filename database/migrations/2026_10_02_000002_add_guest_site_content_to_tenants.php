<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('hero_headline')->nullable();
            $table->text('hero_description')->nullable();
            $table->string('hero_image', 2048)->nullable();
            $table->string('business_address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn([
            'hero_headline', 'hero_description', 'hero_image', 'business_address',
        ]));
    }
};
