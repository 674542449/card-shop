<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->string('two_factor_revision', 64)->nullable();
            $table->unsignedBigInteger('two_factor_last_counter')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('admins', fn (Blueprint $table) => $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_revision', 'two_factor_last_counter', 'two_factor_confirmed_at']));
    }
};
