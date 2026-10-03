<?php

namespace App\Http\Resources\Admin;

use App\Models\{Admin, ApiToken, BackupRun, Coupon, NotificationDelivery, Order, OrderCardReplacement,
    OrderCardReplacementItem, OrderRefund, PaymentReceipt, PaymentReconciliationJob, Product, SeoDelivery};
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** Explicit fields protect responses even when a model later gains new columns. */
class AdminRecordResource extends JsonResource
{
    private const FIELDS = [
        Admin::class => ['id', 'username', 'role', 'permissions', 'is_active', 'last_login_at', 'last_login_ip', 'two_factor_confirmed_at', 'created_at', 'updated_at'],
        ApiToken::class => ['id', 'name', 'is_active', 'last_used_at', 'requests_per_minute', 'orders_per_minute', 'max_pending_orders', 'max_pending_quantity', 'expires_at', 'scopes', 'allowed_ips', 'created_at', 'updated_at'],
        BackupRun::class => ['id', 'source', 'requested_by', 'status', 'progress', 'phase', 'attempts', 'lease_expires_at', 'filename', 'size', 'sync_status', 'last_error', 'started_at', 'finished_at', 'synced_at', 'health_acknowledged_at', 'created_at', 'updated_at'],
        NotificationDelivery::class => ['id', 'type', 'order_id', 'product_id', 'status', 'attempts', 'available_at', 'reserved_at', 'sent_at', 'last_error', 'health_acknowledged_at', 'created_at', 'updated_at'],
        SeoDelivery::class => ['id', 'provider', 'url', 'status', 'attempts', 'available_at', 'reserved_at', 'sent_at', 'last_error', 'health_acknowledged_at', 'created_at', 'updated_at'],
        PaymentReconciliationJob::class => ['id', 'order_id', 'status', 'attempts', 'available_at', 'queued_at', 'finished_at', 'last_error', 'created_at', 'updated_at'],
        PaymentReceipt::class => ['id', 'order_id', 'channel', 'trade_no', 'amount', 'received_at', 'actual_amount', 'currency', 'network', 'transaction_hash', 'review_reason', 'review_code', 'review_resolved_at', 'resolution_note', 'refund_balance', 'created_at', 'updated_at'],
        OrderRefund::class => ['id', 'order_id', 'payment_receipt_id', 'amount', 'status', 'source', 'reason', 'reference', 'note', 'customer_note', 'admin_id', 'completed_at', 'created_at', 'updated_at'],
        OrderCardReplacement::class => ['id', 'order_id', 'admin_id', 'reason', 'created_at', 'updated_at'],
        OrderCardReplacementItem::class => ['id', 'replacement_id', 'old_card_id', 'new_card_id'],
        Product::class => ['id', 'category_id', 'name', 'slug', 'description', 'image', 'price', 'min_quantity', 'max_quantity', 'is_active', 'sort_order', 'seo_title', 'seo_description', 'seo_keywords', 'low_stock_threshold', 'low_stock_notified', 'created_at', 'updated_at'],
        Coupon::class => ['id', 'code', 'type', 'value', 'max_uses', 'used_count', 'min_amount', 'product_id', 'starts_at', 'expires_at', 'is_active', 'created_at', 'updated_at'],
    ];

    public function toArray(Request $request): array
    {
        $fields = self::FIELDS[$this->resource::class] ?? throw new \LogicException('No admin response contract for this record.');
        $result = Arr::only($this->resource->attributesToArray(), $fields);
        foreach (['product', 'admin', 'items'] as $relation) {
            if (! $this->resource->relationLoaded($relation)) { continue; }
            $value = $this->resource->getRelation($relation);
            $result[$relation] = $value === null ? null : ($value instanceof \Illuminate\Support\Collection
                ? self::collection($value)->resolve($request) : (new self($value))->resolve($request));
        }
        if ($this->resource->relationLoaded('order')) {
            $order = $this->resource->getRelation('order');
            $result['order'] = $order ? Arr::only($order->attributesToArray(), ['id', 'order_no', 'status', 'product_name', 'email']) : null;
        }
        return $result;
    }
}
