<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Guests sign in with Google and add a Philippine mobile number before their first booking. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('google_id')->nullable()->unique()->after('email');
            $t->string('avatar', 500)->nullable()->after('google_id');
            $t->string('phone', 20)->nullable()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['google_id']);
            $t->dropColumn(['google_id', 'avatar', 'phone']);
        });
    }
};
