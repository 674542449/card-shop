<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 30)->unique();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('email', 200);
            $table->string('query_password', 255);
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_amount', 10, 2);
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_no', 100)->nullable();
            $table->string('status', 10)->default('pending');
            $table->string('ip', 45);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('coupon_id')->references('id')->on('coupons')->nullOnDelete();
            $table->index('email');
            $table->index('status');
            $table->index('order_no');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
