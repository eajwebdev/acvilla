<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The brand colours sampled from the logo. Tenants on the previous defaults move over; colours an admin picked are kept. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('primary_color')->default('#005A1C')->change();
            $t->string('secondary_color')->default('#0070C0')->change();
        });
        DB::table('tenants')->where('primary_color', '#0B4F24')->update(['primary_color' => '#005A1C']);
        DB::table('tenants')->where('secondary_color', '#0F55A8')->update(['secondary_color' => '#0070C0']);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('primary_color')->default('#0B4F24')->change();
            $t->string('secondary_color')->default('#0F55A8')->change();
        });
        DB::table('tenants')->where('primary_color', '#005A1C')->update(['primary_color' => '#0B4F24']);
        DB::table('tenants')->where('secondary_color', '#0070C0')->update(['secondary_color' => '#0F55A8']);
    }
};
