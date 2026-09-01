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
    ];

    protected $hidden = [
        'query_password',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'quantity' => 'integer',
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
