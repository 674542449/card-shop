<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductWholesalePrice extends Model
{
    protected $table = 'product_wholesale_prices';

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'min_quantity',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
