<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A whole-property unit (e.g. the exclusive whole-resort package) blocks, and is blocked by, every other unit of its property. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', fn (Blueprint $t) => $t->boolean('whole_property')->default(false)->after('active'));
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn('whole_property'));
    }
};
