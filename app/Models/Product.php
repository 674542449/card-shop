<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $table = 'products';

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'min_quantity',
        'max_quantity',
        'is_active',
        'sort_order',
        'seo_title',
        'seo_description',
        'seo_keywords',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function wholesalePrices(): HasMany
    {
        return $this->hasMany(ProductWholesalePrice::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Get the count of unsold cards for this product.
     */
    public function stockCount(): int
    {
        return $this->cards()->where('status', 'unsold')->count();
    }

    /**
     * Get the effective price for a given quantity.
     * Returns the wholesale price if a matching tier exists, otherwise the regular price.
     */
    public function getEffectivePrice(int $quantity): string
    {
        $wholesalePrice = $this->wholesalePrices()
            ->where('min_quantity', '<=', $quantity)
            ->orderByDesc('min_quantity')
            ->first();

        return $wholesalePrice ? $wholesalePrice->price : $this->price;
    }
}
