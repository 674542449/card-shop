<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payment_reconciliation_jobs', function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable();
        });
        DB::table('payment_reconciliation_jobs')->whereNull('queued_at')->update(['queued_at' => DB::raw('created_at')]);
    }
    public function down(): void
    {
        Schema::table('payment_reconciliation_jobs', fn (Blueprint $table) => $table->dropColumn('queued_at'));
    }
};
