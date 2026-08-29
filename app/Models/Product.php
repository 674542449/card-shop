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
        'image',
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

    /**
     * sort_order alone is not a total order — every product ships with 0. PostgreSQL
     * is free to return tied rows in any order per query, so a paginated admin list
     * could show the same product on two pages and never show another.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Get the count of unsold cards for this product.
     */
    public function stockCount(): int
    {
        // Prefer a count the query already loaded as `stock_count`. The product row
        // partial calls this for every row, so on a list page without it this was one
        // COUNT per product — the homepage issued 12 queries for 5 products and 32 for
        // 25, growing one-for-one with the catalogue.
        if (array_key_exists('stock_count', $this->attributes)) {
            return (int) $this->attributes['stock_count'];
        }

        return $this->cards()->where('status', 'unsold')->count();
    }

    /**
     * Load the unsold-card count alongside the products, as `stock_count`.
     *
     * Named so every list that renders product rows asks for it the same way; the
     * admin product list already did this inline and the storefront did not.
     */
    public function scopeWithStock(Builder $query): Builder
    {
        return $query->withCount(['cards as stock_count' => fn ($q) => $q->where('status', 'unsold')]);
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
