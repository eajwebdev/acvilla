<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Proof-of-work photos taken by housekeeping; files live on the private disk.
        Schema::create('cleaning_task_photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cleaning_task_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_task_photos');
    }
};
