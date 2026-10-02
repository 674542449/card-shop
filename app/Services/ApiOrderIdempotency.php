<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Models\ApiOrderRequest;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApiOrderIdempotency
{
    /** Commit the order mapping before starting a gateway transaction. */
    public function reserve(int $tokenId, string $key, array $data, callable $create): ApiOrderRequest
    {
        $canonical = [
            'product_id' => (int) $data['product_id'], 'quantity' => (int) $data['quantity'],
            'email' => mb_strtolower($data['email']),
            'password_proof' => Order::passwordKey($data['email'], $data['query_password']),
            'coupon_code' => $data['coupon_code'] ?? '', 'payment_method' => $data['payment_method'],
        ];
        $digest = hash_hmac('sha256', json_encode($canonical, JSON_THROW_ON_ERROR), (string) config('app.key'));
        $keyHash = hash('sha256', $key);

        return DB::transaction(function () use ($tokenId, $keyHash, $digest, $create) {
            // The unique index serializes simultaneous first use of the same key.
            DB::table('api_order_requests')->insertOrIgnore([
                'api_token_id' => $tokenId, 'key_hash' => $keyHash, 'request_hash' => $digest,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $record = ApiOrderRequest::where('api_token_id', $tokenId)->where('key_hash', $keyHash)->lockForUpdate()->firstOrFail();
            if (! hash_equals($record->request_hash, $digest)) {
                throw new IdempotencyConflictException('此 Idempotency-Key 已用于不同的下单参数，请使用新键。');
            }
            if ($record->response_payload === null && $record->order_id === null) {
                $record->update(['order_id' => $create()->id]);
            }
            return $record;
        });
    }

    /** Claim a short lease, then perform gateway I/O without holding row locks. */
    public function respond(ApiOrderRequest $request, callable $finish): array
    {
        $token = (string) Str::uuid();
        $claim = DB::transaction(function () use ($request, $token) {
            $record = ApiOrderRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($record->response_payload !== null) {
                return [$record->response_payload, $record->response_status, true];
            }
            if ($record->processing_token && $record->processing_expires_at?->isFuture()) {
                return [['message' => '付款正在创建，请使用同一 Idempotency-Key 重试。',
                    'data' => ['order_no' => Order::find($record->order_id)?->order_no, 'payment_initialization' => 'processing']], 202, true];
            }
            $record->update(['processing_token' => $token, 'processing_expires_at' => now()->addSeconds(60)]);
            return null;
        });
        if ($claim !== null) {
            if ($claim[1] === 202) {
                // Wait for a fast peer without a transaction or row lock. Slow
                // gateways return 202 so the client can retry the same key later.
                $deadline = microtime(true) + 2;
                do {
                    usleep(25000);
                    $record = ApiOrderRequest::findOrFail($request->id);
                    if ($record->response_payload !== null) {
                        return [$record->response_payload, $record->response_status, true];
                    }
                } while ($record->processing_token && microtime(true) < $deadline);
            }
            return $claim;
        }
        try {
            $order = Order::find($request->order_id);
            [$payload, $status] = $order ? $finish($order) : [['message' => '原订单已归档，请联系店主。'], 410];
            DB::transaction(function () use ($request, $token, $payload, $status) {
                $record = ApiOrderRequest::whereKey($request->id)->where('processing_token', $token)->lockForUpdate()->first();
                if ($record) {
                    $record->update(['processing_token' => null, 'processing_expires_at' => null]
                        + ($status === 202 ? [] : ['response_payload' => $payload, 'response_status' => $status]));
                }
            });
            return [$payload, $status, false];
        } finally {
            ApiOrderRequest::whereKey($request->id)->where('processing_token', $token)
                ->update(['processing_token' => null, 'processing_expires_at' => null]);
        }
    }
}
