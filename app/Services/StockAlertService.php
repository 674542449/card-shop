<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockAlertService
{
    public function __construct(private readonly NotificationQueue $queue, private readonly NotificationService $sender) {}

    public function scan(): int
    {
        $count = 0;
        Product::where('is_active', true)->whereNotNull('low_stock_threshold')->select('id')
            ->chunkById(100, function ($products) use (&$count) {
                foreach ($products as $product) {
                    if ($this->check($product->id)) {
                        $count++;
                    }
                }
            });
        return $count;
    }

    public function check(int $productId): bool
    {
        return DB::transaction(function () use ($productId) {
            $product = Product::whereKey($productId)->lockForUpdate()->first();
            if (!$product) {
                return false;
            }
            $stock = $product->cards()->where('status', 'unsold')->count();
            if (!$product->is_active || ($product->category && !$product->category->is_active) || $product->low_stock_threshold === null || $stock > $product->low_stock_threshold) {
                $product->forceFill(['low_stock_notified' => false])->save();
                return false;
            }
            if ($product->low_stock_notified || !$this->sender->telegramConfigured()) {
                return false;
            }
            $this->queue->enqueue('low-stock:'.$product->id.':'.Str::uuid(), 'low_stock', null, [
                'message' => "<b>库存预警</b>\n商品: ".e($product->name)."\n可售库存: {$stock}\n预警阈值: {$product->low_stock_threshold}\n请及时补充卡密。",
            ], $product->id);
            $product->forceFill(['low_stock_notified' => true])->save();
            return true;
        });
    }
}
