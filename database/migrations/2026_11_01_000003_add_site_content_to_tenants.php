<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Editable guest-website copy, photos and options (Admin → Website content). Null means the defaults. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->json('site_content')->nullable());
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('site_content'));
    }
};
