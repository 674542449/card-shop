<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $table = 'orders';

    protected $fillable = [
        'order_no',
        'product_id',
        'product_name', 'product_slug', 'query_password_key', 'gateway_trade_no', 'reconciled_at', 'reconciliation_error',
        'email',
        'query_password',
        'quantity',
        'unit_price',
        'total_amount',
        'coupon_id',
        'discount_amount',
        'payment_method',
        'payment_no',
        'status',
        'ip',
        'paid_at',
        'expires_at',
        'api_token_id',
        'payment_received_amount',
        'payment_received_at',
        'payment_review_reason',
    ];

    protected $hidden = [
        'query_password', 'query_password_key',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $product = $order->product;
            $order->product_name ??= $product?->name;
            $order->product_slug ??= $product?->slug;
        });
    }

    public static function passwordKey(string $email, string $password): string
    {
        return hash_hmac('sha256', mb_strtolower($email)."\0".$password, (string) config('app.key'));
    }

    public function displayName(): string { return $this->product_name ?: ($this->product?->name ?? '商品'); }

    public function scopePaymentReview(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where(fn ($p) => $p->where('status', '!=', 'paid')->whereNotNull('payment_no'))
            ->orWhereHas('paymentReceipts', fn ($r) => $r->whereNotNull('review_reason')->whereNull('review_resolved_at')));
    }

    public function refunds(): HasMany { return $this->hasMany(OrderRefund::class); }

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'quantity' => 'integer',
            'payment_received_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function paymentReceipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class)->orderByDesc('id');
    }

    /**
     * Check if the order has expired.
     */
    public function isExpired(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isPast();
    }

    /**
     * Check if the order has been paid.
     */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * 最新在前。
     *
     * ->orderByDesc('id') 是必需的 tiebreaker，不是可选的美化：created_at 是秒级
     * 精度，批量导入、脚本迁移、或者两个人在同一秒发布，都会产生并列，而 PostgreSQL
     * 对并列行的返回顺序不作保证。分页时的后果是同一条既可能在两页里重复出现，也
     * 可能一页都不出现——实测 63 篇同秒文章翻 7 页：5 篇重复、5 篇彻底消失。
     * Product / Category / ArticleCategory 的 ordered() 早就这么写了，唯独真正被
     * paginate() 用到的这两个 recent() 漏了。
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
