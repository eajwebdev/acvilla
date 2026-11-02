<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin notification bell: one row per step of a booking (new request, receipt uploaded, dates approved,
 * payment approved, check-in…). Each user's "seen up to" notification decides what is still unread for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->string('event', 40);
            $t->string('title', 160);
            $t->string('detail', 500)->nullable();
            $t->string('actor', 120)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['tenant_id', 'created_at']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->unsignedBigInteger('notifications_seen_id')->nullable()->after('remember_token'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notifications_seen_id'));
        Schema::dropIfExists('booking_notifications');
    }
};
