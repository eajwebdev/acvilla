<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A guest's downpayment comes with a photo of the GCash (or bank) receipt. One upload can cover several
 * facilities booked together, so their payments share a batch and are approved or rejected together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->string('proof_path')->nullable()->after('payer_reference');
            $t->string('batch', 40)->nullable()->after('proof_path')->index();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropIndex(['batch']);
            $t->dropColumn(['proof_path', 'batch']);
        });
    }
};
