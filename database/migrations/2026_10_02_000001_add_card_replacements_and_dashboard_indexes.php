<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->timestamp('replaced_at')->nullable();
            $table->index(['order_id', 'status', 'replaced_at'], 'cards_current_delivery_index');
        });
        Schema::create('order_card_replacements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('admin_id')->constrained()->restrictOnDelete();
            $table->uuid('request_token');
            $table->text('reason');
            $table->timestamps();
            $table->unique(['order_id', 'request_token']);
        });
        Schema::create('order_card_replacement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('replacement_id')->constrained('order_card_replacements')->restrictOnDelete();
            $table->foreignId('old_card_id')->constrained('cards')->restrictOnDelete()->unique();
            $table->foreignId('new_card_id')->constrained('cards')->restrictOnDelete()->unique();
        });
        Schema::table('orders', fn (Blueprint $table) => $table->index(['status', 'paid_at'], 'orders_sales_paid_at_index'));
        Schema::table('order_refunds', fn (Blueprint $table) => $table->index(['status', 'completed_at'], 'refunds_completed_at_index'));
    }

    public function down(): void
    {
        Schema::table('order_refunds', fn (Blueprint $table) => $table->dropIndex('refunds_completed_at_index'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropIndex('orders_sales_paid_at_index'));
        Schema::dropIfExists('order_card_replacement_items');
        Schema::dropIfExists('order_card_replacements');
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex('cards_current_delivery_index');
            $table->dropColumn('replaced_at');
        });
    }
};
