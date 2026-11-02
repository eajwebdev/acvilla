<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a unit is: a room guests stay in overnight, or a venue (function hall) rented per event. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', fn (Blueprint $t) => $t->string('kind', 20)->default('room')->after('whole_property'));
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn('kind'));
    }
};
