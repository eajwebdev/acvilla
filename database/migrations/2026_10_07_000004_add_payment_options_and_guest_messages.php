<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            $t->decimal('deposit_amount', 12, 2)->nullable();
            $t->date('balance_due_on')->nullable();
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->string('payer_reference', 100)->nullable();
        });
        Schema::create('message_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('event');
            $t->boolean('enabled')->default(true);
            $t->string('subject');
            $t->text('body');
            $t->unsignedSmallInteger('send_days_before')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'event']);
        });
        Schema::create('booking_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->string('event');
            $t->string('recipient');
            $t->string('subject');
            $t->string('status');
            $t->string('error')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['booking_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_messages');
        Schema::dropIfExists('message_templates');
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('payer_reference'));
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn(['deposit_amount', 'balance_due_on']));
    }
};
