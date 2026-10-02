<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Models\Order;
use App\Models\WebCheckoutRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutIntentService
{
    private function owner(): string
    {
        if (! session()->has('checkout_owner')) {
            session()->put('checkout_owner', Str::random(64));
        }
        return hash_hmac('sha256', session('checkout_owner'), (string) config('app.key'));
    }

    public function issue(int $productId): string
    {
        $id = (string) Str::uuid();
        return $id.'.'.hash_hmac('sha256', $this->owner().':'.$productId.':'.$id, (string) config('app.key'));
    }

    private function keyHash(string $key, int $productId): string
    {
        if (! preg_match('/\A([a-f0-9-]{36})\.([a-f0-9]{64})\z/D', $key, $parts)
            || ! hash_equals(hash_hmac('sha256', $this->owner().':'.$productId.':'.$parts[1], (string) config('app.key')), $parts[2])) {
            throw new IdempotencyConflictException('购买操作已失效，请刷新商品页面后重新下单。');
        }
        return hash('sha256', $key);
    }

    public function bound(string $key, int $productId): bool
    {
        try {
            $hash = $this->keyHash($key, $productId);
            return WebCheckoutRequest::where('owner_hash', $this->owner())->where('key_hash', $hash)->exists();
        } catch (IdempotencyConflictException) {
            return false;
        }
    }

    public function reserve(string $key, array $data, callable $create): Order
    {
        $keyHash = $this->keyHash($key, (int) $data['product_id']);
        $owner = $this->owner();
        $digest = hash_hmac('sha256', json_encode([
            (int) $data['product_id'], (int) $data['quantity'], mb_strtolower($data['email']),
            Order::passwordKey($data['email'], $data['query_password']), $data['coupon_code'] ?? '', $data['payment_method'],
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
        return DB::transaction(function () use ($keyHash, $owner, $digest, $create) {
            $inserted = DB::table('web_checkout_requests')->insertOrIgnore(['key_hash' => $keyHash, 'owner_hash' => $owner,
                'request_hash' => $digest, 'created_at' => now(), 'updated_at' => now()]);
            $intent = WebCheckoutRequest::where('owner_hash', $owner)->where('key_hash', $keyHash)->lockForUpdate()->firstOrFail();
            if (! hash_equals($intent->request_hash, $digest)) {
                throw new IdempotencyConflictException('此购买操作已提交，请查单；新的购买请刷新商品页面。');
            }
            if (! $intent->order_id) {
                if (! $inserted) {
                    throw new IdempotencyConflictException('原订单已归档，请联系店主；新的购买请刷新商品页面。');
                }
                $intent->update(['order_id' => $create()->id]);
            }
            return Order::findOrFail($intent->order_id);
        }, 3);
    }
}
