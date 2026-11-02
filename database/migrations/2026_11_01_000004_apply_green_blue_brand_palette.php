<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The AC Villa logo's leaf green and sea blue replace the navy and gold palette as guest-site defaults. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('primary_color')->default('#0B4F24')->change();
            $t->string('secondary_color')->default('#0F55A8')->change();
        });
        // Tenants still on an earlier default move over; colours an admin picked are kept.
        DB::table('tenants')->whereIn('primary_color', ['#0E2A4F', '#0A1134'])->update(['primary_color' => '#0B4F24']);
        DB::table('tenants')->whereIn('secondary_color', ['#B8862B', '#F3176C'])->update(['secondary_color' => '#0F55A8']);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('primary_color')->default('#0E2A4F')->change();
            $t->string('secondary_color')->default('#B8862B')->change();
        });
    }
};
