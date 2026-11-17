<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('product_name', 255)->nullable();
            $table->string('product_slug', 255)->nullable();
            $table->string('query_password_key', 64)->nullable()->index();
            $table->string('gateway_trade_no', 100)->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->text('reconciliation_error')->nullable();
        });
        DB::statement('UPDATE orders SET product_name = products.name, product_slug = products.slug FROM products WHERE orders.product_id = products.id');
        Schema::table('payment_receipts', function (Blueprint $table) {
            $table->decimal('actual_amount', 24, 8)->nullable();
            $table->string('currency', 16)->default('CNY');
            $table->string('network', 40)->nullable();
            $table->string('transaction_hash', 255)->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamp('review_resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
        });
        Schema::table('admins', function (Blueprint $table) {
            $table->string('role', 20)->default('owner');
            $table->json('permissions')->nullable();
            $table->boolean('is_active')->default(true);
        });
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('allowed_ips')->nullable();
        });
        Schema::create('seo_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->text('url');
            $table->string('dedupe_key', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
        Schema::create('service_heartbeats', function (Blueprint $table) {
            $table->string('name')->primary();
            $table->timestamp('last_seen_at');
            $table->json('details')->nullable();
        });
        Schema::create('order_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_receipt_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 20, 2);
            $table->string('status', 20)->default('requested');
            $table->string('source', 20)->default('admin');
            $table->text('reason');
            $table->string('reference', 255)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_refunds');
        Schema::dropIfExists('service_heartbeats');
        Schema::dropIfExists('seo_deliveries');
        Schema::table('api_tokens', fn (Blueprint $t) => $t->dropColumn(['expires_at', 'scopes', 'allowed_ips']));
        Schema::table('admins', fn (Blueprint $t) => $t->dropColumn(['role', 'permissions', 'is_active']));
        Schema::table('payment_receipts', fn (Blueprint $t) => $t->dropColumn(['actual_amount', 'currency', 'network', 'transaction_hash', 'review_reason', 'review_resolved_at', 'resolution_note']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['product_name', 'product_slug', 'query_password_key', 'gateway_trade_no', 'reconciled_at', 'reconciliation_error']));
    }
};
