<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_wholesale_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('min_quantity');
            $table->decimal('price', 10, 2);

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_wholesale_prices');
    }
};
