<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Exceptions\CheckoutException;
use App\Rules\Utf8Text;

class CardService
{
    /**
     * Lock N unsold cards for a product using Redis to prevent race conditions.
     *
     * @throws CheckoutException If insufficient stock or lock cannot be acquired.
     */
    public function lockCards(int $productId, int $quantity): Collection
    {
        $lockKey = "card_lock:product:{$productId}";
        $lockValue = uniqid((string) $productId, true);
        $lockTtl = 30; // seconds

        // Acquire Redis lock (SETNX equivalent)
        $acquired = Redis::set($lockKey, $lockValue, 'EX', $lockTtl, 'NX');

        if (!$acquired) {
            throw new CheckoutException('系统繁忙，请稍后再试');
        }

        try {
            $cards = Card::where('product_id', $productId)
                ->where('status', 'unsold')
                ->orderBy('id')
                ->limit($quantity)
                ->lockForUpdate()
                ->get();

            if ($cards->count() < $quantity) {
                throw new CheckoutException(
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
            // Compare and delete in one Redis command. A TTL expiry/new owner
            // between separate GET/DEL operations must not lose its mutex.
            Redis::eval("if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end return 0", 1, $lockKey, $lockValue);
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
        return $this->importCardsWithResult($productId, $content, $delimiter)['count'];
    }

    /** Import once per secret, including secrets already sold or held by orders. */
    public function importCardsWithResult(int $productId, string $content, string $delimiter = "\n"): array
    {
        if (! Utf8Text::isValid($content) || ! Utf8Text::isValid($delimiter)) {
            throw new \InvalidArgumentException('卡密内容必须是有效 UTF-8 文本，且不能包含空字符。');
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $lines = array_values(array_filter(
            array_map('trim', $delimiter === "\n" ? preg_split('/\r\n|\r|\n/', $content) : explode($delimiter, $content)),
            fn (string $line) => $line !== ''
        ));

        if (empty($lines)) {
            return ['count' => 0, 'skipped' => 0, 'total' => 0];
        }

        return DB::transaction(function () use ($productId, $lines) {
            // The product row serializes imports for this product. Checking for
            // duplicates before taking this lock lets two imports add the same key.
            Product::whereKey($productId)->lockForUpdate()->firstOrFail();
            $count = 0;
            $now = now();
            foreach (array_chunk(array_values(array_unique($lines, SORT_STRING)), 500) as $chunk) {
                $cipher = app(\App\Security\SecretCipher::class);
                $fingerprints = array_map(fn (string $line) => $cipher->fingerprint($line), $chunk);
                $existing = Card::where('product_id', $productId)->whereIn('content_fingerprint', $fingerprints)
                    ->pluck('content_fingerprint')->all();
                $seen = array_fill_keys($existing, true);
                $records = [];
                foreach ($chunk as $line) {
                    if (isset($seen[$cipher->fingerprint($line)])) {
                        continue;
                    }
                    $records[] = [
                        'product_id' => $productId, 'content' => $line, 'status' => 'unsold',
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                if ($records !== []) {
                    Card::insert($records);
                    $count += count($records);
                }
            }

            // Refills rearm alerts before the next scheduled scan.
            $product = Product::whereKey($productId)->firstOrFail();
            if ($product->low_stock_threshold !== null && $product->stockCount() > $product->low_stock_threshold) {
                $product->forceFill(['low_stock_notified' => false])->save();
            }
            return ['count' => $count, 'skipped' => count($lines) - $count, 'total' => count($lines)];
        });
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
