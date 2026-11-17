<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_checkout_requests', function (Blueprint $table) {
            $table->id();
            $table->string('owner_hash', 64);
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamps();
            $table->unique(['owner_hash', 'key_hash']);
        });
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->string('method', 30);
            $table->string('status', 20)->default('created');
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->text('response_payload')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->timestamps();
            $table->index(['status', 'lease_expires_at']);
        });
        Schema::table('api_order_requests', function (Blueprint $table) {
            $table->uuid('processing_token')->nullable();
            $table->timestamp('processing_expires_at')->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'expires_at', 'id'], 'orders_expiry_batch_index');
            $table->index(['ip', 'status'], 'orders_browser_quota_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_expiry_batch_index');
            $table->dropIndex('orders_browser_quota_index');
        });
        Schema::table('api_order_requests', fn (Blueprint $table) => $table->dropColumn(['processing_token', 'processing_expires_at']));
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('web_checkout_requests');
    }
};
