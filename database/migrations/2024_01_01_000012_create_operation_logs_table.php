<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('target_type', 50)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('detail')->nullable();
            $table->string('ip', 45);
            $table->timestamp('created_at')->nullable();

            $table->index('admin_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_logs');
    }
};
