<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_messages', function (Blueprint $t) {
            $t->unsignedTinyInteger('attempts')->default(1);
            $t->timestamp('last_attempt_at')->nullable();
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('booking_messages', function (Blueprint $t) {
            $t->dropIndex(['status', 'created_at']);
            $t->dropColumn(['attempts', 'last_attempt_at']);
        });
    }
};
