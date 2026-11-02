<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** AC Villa navy and gold become the default guest-site colours, replacing the old navy and pink. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('primary_color')->default('#0E2A4F')->change();
            $t->string('secondary_color')->default('#B8862B')->change();
        });
        // Only tenants still on the untouched old defaults move over; colours an admin chose are kept.
        DB::table('tenants')->where('primary_color', '#0A1134')->update(['primary_color' => '#0E2A4F']);
        DB::table('tenants')->where('secondary_color', '#F3176C')->update(['secondary_color' => '#B8862B']);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('primary_color')->default('#0A1134')->change();
            $t->string('secondary_color')->default('#F3176C')->change();
        });
    }
};
