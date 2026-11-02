<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $t) {
            $t->string('source', 20)->default('direct')->index();
            $t->string('check_in_time', 5)->nullable();
            $t->string('check_out_time', 5)->nullable();
            $t->string('group_code', 30)->nullable()->index();
            $t->text('notes')->nullable();
        });
        // Walk-ins and phone bookings often only leave a mobile number or Facebook name.
        Schema::table('bookings', function (Blueprint $t) {
            $t->string('guest_email')->nullable()->change();
        });
        Schema::create('cleaning_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('booking_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->date('date')->index();
            $t->string('status', 20)->default('NOT_STARTED');
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->json('checklist')->nullable();
            $t->string('notes', 500)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $t->date('spent_on')->index();
            $t->string('category', 40);
            $t->decimal('amount', 12, 2);
            $t->string('description', 200)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('pos_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->string('category', 40)->default('General');
            $t->decimal('price', 12, 2);
            $t->boolean('track_stock')->default(false);
            $t->integer('stock')->default(0);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('pos_sales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('number', 20);
            $t->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $t->string('customer_name', 120)->nullable();
            $t->decimal('subtotal', 12, 2);
            $t->decimal('discount', 12, 2)->default(0);
            $t->decimal('total', 12, 2);
            // PAID at the counter, UNPAID while charged to a room folio, VOID when cancelled.
            $t->string('status', 10)->index();
            $t->string('payment_method', 20)->nullable();
            $t->string('payment_reference', 100)->nullable();
            $t->decimal('amount_tendered', 12, 2)->nullable();
            $t->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('voided_at')->nullable();
            $t->string('void_reason', 200)->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
        });
        Schema::create('pos_sale_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pos_sale_id')->constrained()->cascadeOnDelete();
            $t->foreignId('pos_item_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 80);
            $t->decimal('unit_price', 12, 2);
            $t->unsignedInteger('quantity');
            $t->decimal('amount', 12, 2);
        });
    }

    public function down(): void
    {
        foreach (['pos_sale_items', 'pos_sales', 'pos_items', 'expenses', 'cleaning_tasks'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('bookings', function (Blueprint $t) {
            $t->dropIndex(['source']);
            $t->dropIndex(['group_code']);
            $t->dropColumn(['source', 'check_in_time', 'check_out_time', 'group_code', 'notes']);
        });
    }
};
