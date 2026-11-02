<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('business_name');
            $t->string('business_email')->nullable();
            $t->string('business_phone')->nullable();
            $t->string('status')->default('ACTIVE');
            $t->string('subscription_status')->default('TRIAL');
            $t->string('logo')->nullable();
            $t->string('favicon')->nullable();
            $t->string('primary_color')->default('#0A1134');
            $t->string('secondary_color')->default('#F3176C');
            $t->timestamp('subscription_started_at')->nullable();
            $t->timestamp('subscription_ends_at')->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $t->string('role')->default('GUEST')->index();
        });
        Schema::create('subscription_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code')->unique();
            $t->text('description')->nullable();
            $t->string('billing_interval');
            $t->decimal('base_monthly_price', 12, 2);
            $t->decimal('effective_monthly_price', 12, 2);
            $t->decimal('billing_price', 12, 2);
            $t->unsignedInteger('commitment_months');
            $t->string('discount_type')->default('NONE');
            $t->decimal('discount_value', 12, 2)->default(0);
            $t->boolean('active')->default(true);
            $t->boolean('featured')->default(false);
            $t->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('subscription_plan_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status')->index();
            $t->date('starts_at');
            $t->date('ends_at')->index();
            $t->date('renewal_at')->nullable();
            $t->string('billing_interval');
            $t->unsignedInteger('commitment_months');
            $t->decimal('base_price', 12, 2);
            $t->string('discount_type')->default('NONE');
            $t->decimal('discount_value', 12, 2)->default(0);
            $t->decimal('effective_price', 12, 2);
            $t->decimal('billing_total', 12, 2);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('properties', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('type')->default('resort');
            $t->string('short_description')->nullable();
            $t->text('description')->nullable();
            $t->string('address')->nullable();
            $t->string('city');
            $t->string('province');
            $t->string('country')->default('Philippines');
            $t->string('cover_image')->nullable();
            $t->string('status')->default('PUBLISHED');
            $t->boolean('featured')->default(false);
            $t->timestamps();
        });
        Schema::create('rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('property_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('slug');
            $t->text('description')->nullable();
            $t->unsignedInteger('maximum_occupancy')->default(2);
            $t->unsignedInteger('quantity')->default(1);
            $t->decimal('base_rate', 12, 2);
            $t->decimal('weekend_rate', 12, 2)->nullable();
            $t->decimal('cleaning_fee', 12, 2)->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['property_id', 'slug']);
        });
        Schema::create('bookings', function (Blueprint $t) {
            $t->id();
            $t->string('reference')->unique();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('property_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('guest_name');
            $t->string('guest_email');
            $t->string('guest_phone')->nullable();
            $t->date('check_in');
            $t->date('check_out');
            $t->unsignedInteger('guests');
            $t->string('status')->default('PENDING')->index();
            $t->string('payment_status')->default('UNPAID');
            $t->decimal('total', 12, 2);
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['room_id', 'check_in', 'check_out']);
        });
        Schema::create('booking_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $t->decimal('subtotal', 12, 2);
            $t->decimal('cleaning_fee', 12, 2)->default(0);
            $t->decimal('service_fee', 12, 2)->default(0);
            $t->decimal('tax', 12, 2)->default(0);
            $t->decimal('discount', 12, 2)->default(0);
            $t->decimal('total', 12, 2);
            $t->json('nightly_rates');
            $t->timestamps();
        });
        Schema::create('blocked_dates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('room_id')->constrained()->cascadeOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('reason')->nullable();
            $t->timestamps();
            $t->index(['room_id', 'starts_on', 'ends_on']);
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $t->string('method');
            $t->string('provider')->default('manual');
            $t->string('status')->default('PENDING');
            $t->string('reference')->nullable()->unique();
            $t->decimal('amount', 12, 2);
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->string('entity_type');
            $t->unsignedBigInteger('entity_id');
            $t->json('metadata')->nullable();
            $t->string('ip_address')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'payments', 'blocked_dates', 'booking_prices', 'bookings', 'rooms', 'properties', 'subscriptions', 'subscription_plans'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('tenant_id'));
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['role']);
            $t->dropColumn('role');
        });
        Schema::dropIfExists('tenants');
    }
};
