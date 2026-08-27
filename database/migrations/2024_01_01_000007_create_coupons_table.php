<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('type', 10);
            $table->decimal('value', 10, 2);
            $table->integer('max_uses')->default(0);
            $table->integer('used_count')->default(0);
            $table->decimal('min_amount', 10, 2)->default(0);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
