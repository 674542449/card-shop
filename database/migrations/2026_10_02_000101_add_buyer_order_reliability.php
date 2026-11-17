<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_order_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_token_id')->constrained()->cascadeOnDelete();
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // Encrypted by the model; payment links must not become plaintext logs.
            $table->text('response_payload')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->timestamps();
            $table->unique(['api_token_id', 'key_hash']);
        });
        Schema::table('order_refunds', fn (Blueprint $table) => $table->text('customer_note')->nullable());
    }

    public function down(): void
    {
        Schema::table('order_refunds', fn (Blueprint $table) => $table->dropColumn('customer_note'));
        Schema::dropIfExists('api_order_requests');
    }
};
