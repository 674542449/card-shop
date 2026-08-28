<?php

namespace App\Services;

use App\Models\Card;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

class CardService
{
    /**
     * Lock N unsold cards for a product using Redis to prevent race conditions.
     *
     * @throws RuntimeException If insufficient stock or lock cannot be acquired.
     */
    public function lockCards(int $productId, int $quantity): Collection
    {
        $lockKey = "card_lock:product:{$productId}";
        $lockValue = uniqid((string) $productId, true);
        $lockTtl = 30; // seconds

        // Acquire Redis lock (SETNX equivalent)
        $acquired = Redis::set($lockKey, $lockValue, 'EX', $lockTtl, 'NX');

        if (!$acquired) {
            throw new RuntimeException('系统繁忙，请稍后再试');
        }

        try {
            $cards = Card::where('product_id', $productId)
                ->where('status', 'unsold')
                ->orderBy('id')
                ->limit($quantity)
                ->lockForUpdate()
                ->get();

            if ($cards->count() < $quantity) {
                throw new RuntimeException(
                    "库存不足，当前库存: {$cards->count()}, 需要: {$quantity}"
                );
            }

            $now = now();
            Card::whereIn('id', $cards->pluck('id'))
                ->update([
                    'status' => 'locked',
                    'locked_at' => $now,
                ]);

            // Refresh the collection to reflect the updated status
            $cards->each(function (Card $card) use ($now) {
                $card->status = 'locked';
                $card->locked_at = $now;
            });

            return $cards;
        } finally {
            // Release lock only if we still own it (compare-and-delete)
            $currentValue = Redis::get($lockKey);
            if ($currentValue === $lockValue) {
                Redis::del($lockKey);
            }
        }
    }

    /**
     * Release locked cards back to unsold status.
     */
    public function releaseCards(Collection $cards): void
    {
        if ($cards->isEmpty()) {
            return;
        }

        Card::whereIn('id', $cards->pluck('id'))
            ->where('status', 'locked')
            ->update([
                'status' => 'unsold',
                'order_id' => null,
                'locked_at' => null,
            ]);
    }

    /**
     * Import cards from raw text content into a product.
     *
     * @return int Number of cards imported.
     */
    public function importCards(int $productId, string $content, string $delimiter = "\n"): int
    {
        $lines = array_filter(
            array_map('trim', explode($delimiter, $content)),
            fn (string $line) => $line !== ''
        );

        if (empty($lines)) {
            return 0;
        }

        $records = [];
        $now = now();
        foreach ($lines as $line) {
            $records[] = [
                'product_id' => $productId,
                'content' => $line,
                'status' => 'unsold',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Insert in chunks to avoid memory issues with large imports
        foreach (array_chunk($records, 500) as $chunk) {
            Card::insert($chunk);
        }

        return count($records);
    }

    /**
     * Get the count of unsold cards for a product.
     */
    public function getStockCount(int $productId): int
    {
        return Card::where('product_id', $productId)
            ->where('status', 'unsold')
            ->count();
    }
}
