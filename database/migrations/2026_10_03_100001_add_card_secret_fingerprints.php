<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            // Nullable during explicit conversion. Retain historical duplicate
            // rows instead of deleting already-delivered business evidence.
            $table->string('content_fingerprint', 64)->nullable();
            $table->index(['product_id', 'content_fingerprint'], 'cards_product_secret_fingerprint_index');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex('cards_product_secret_fingerprint_index');
            $table->dropColumn('content_fingerprint');
        });
    }
};
