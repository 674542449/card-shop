<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = Arr::only($this->resource->attributesToArray(), ['id', 'order_no', 'api_token_id', 'product_id', 'product_name', 'product_slug',
            'email', 'quantity', 'unit_price', 'total_amount', 'coupon_id', 'discount_amount', 'payment_method', 'payment_no', 'status', 'ip',
            'paid_at', 'expires_at', 'gateway_trade_no', 'reconciled_at', 'reconciliation_error', 'payment_received_amount', 'payment_received_at',
            'payment_review_reason', 'payment_review_code', 'has_payment_review', 'cards_accessible', 'refund_enabled', 'refund_balance',
            'payment_initialization', 'created_at', 'updated_at']);
        // Never copy a PaymentAttempt model or its stored response to the browser.
        if (array_key_exists('payment_initialization_detail', $this->resource->getAttributes())) {
            $summary = $this->resource->getAttribute('payment_initialization_detail');
            $data['payment_initialization_detail'] = is_array($summary) ? Arr::only($summary, ['status', 'error_code', 'updated_at']) : null;
        }
        foreach (['product', 'coupon'] as $relation) {
            if ($this->resource->relationLoaded($relation)) {
                $value = $this->resource->getRelation($relation);
                $data[$relation] = $value ? (new AdminRecordResource($value))->resolve($request) : null;
            }
        }
        foreach (['cards' => CardResource::class, 'paymentReceipts' => AdminRecordResource::class, 'notifications' => AdminRecordResource::class,
            'refunds' => AdminRecordResource::class, 'cardReplacements' => AdminRecordResource::class] as $relation => $resource) {
            if ($this->resource->relationLoaded($relation)) {
                $data[\Illuminate\Support\Str::snake($relation)] = $resource::collection($this->resource->getRelation($relation))->resolve($request);
            }
        }
        return $data;
    }
}
