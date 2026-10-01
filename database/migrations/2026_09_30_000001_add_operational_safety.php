<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->unsignedInteger('requests_per_minute')->default(120);
            $table->unsignedInteger('orders_per_minute')->default(20);
            $table->unsignedInteger('max_pending_orders')->default(3);
            $table->unsignedInteger('max_pending_quantity')->default(100);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('api_token_id')->nullable()->constrained('api_tokens')->nullOnDelete();
            $table->string('payment_received_amount', 100)->nullable();
            $table->timestamp('payment_received_at')->nullable();
            $table->text('payment_review_reason')->nullable();
            $table->index(['api_token_id', 'status', 'expires_at']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('low_stock_threshold')->nullable()->default(5);
            $table->boolean('low_stock_notified')->default(false);
        });
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('channel', 20);
            $table->string('trade_no', 100);
            $table->string('amount', 100);
            $table->timestamp('received_at');
            $table->timestamps();
            $table->unique(['channel', 'trade_no']);
        });
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key', 150)->unique();
            $table->string('type', 30);
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('reserved_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('payment_receipts');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['low_stock_threshold', 'low_stock_notified']));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['api_token_id', 'status', 'expires_at']);
            $table->dropConstrainedForeignId('api_token_id');
            $table->dropColumn(['payment_received_amount', 'payment_received_at', 'payment_review_reason']);
        });
        Schema::table('api_tokens', fn (Blueprint $table) => $table->dropColumn(['requests_per_minute', 'orders_per_minute', 'max_pending_orders', 'max_pending_quantity']));
    }
};
