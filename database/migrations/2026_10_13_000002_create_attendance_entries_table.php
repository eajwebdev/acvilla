<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->timestamp('clocked_in_at');
            $table->timestamp('clocked_out_at')->nullable();
            $table->string('method', 20)->default('manual');
            $table->timestamps();
            $table->unique(['user_id', 'work_date']);
            $table->index(['tenant_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_entries');
    }
};
