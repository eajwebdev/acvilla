<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Besides the turnover made automatically for each check-out, the owner, a manager or the platform owner can add a
 * task by hand ("Clean the pool", "Fix the aircon in AH Room 1"): it has a title, may have no room, and may be due
 * at a set time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_tasks', function (Blueprint $t) {
            $t->foreignId('room_id')->nullable()->change();
            $t->string('title', 120)->nullable()->after('booking_id');
            $t->string('due_time', 5)->nullable()->after('date');
            $t->foreignId('created_by')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_tasks', function (Blueprint $t) {
            $t->dropConstrainedForeignId('created_by');
            $t->dropColumn(['title', 'due_time']);
        });
    }
};
