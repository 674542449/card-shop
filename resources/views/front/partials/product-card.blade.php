{{-- Product Card Partial --}}
{{-- Expects: $product (with category relationship loaded) --}}

@php
    $stock = $product->stockCount();
    $inStock = $stock > 0;
@endphp

<div class="product-card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
            @if($product->category)
            <span class="category-tag">{{ $product->category->name }}</span>
            @endif
            <span class="stock-badge {{ $inStock ? 'in-stock' : 'out-of-stock' }}">
                <span class="dot"></span>
                {{ $inStock ? '有货' : '缺货' }}
            </span>
        </div>

        <h3 class="product-name">
            <a href="/product/{{ $product->slug }}">{{ $product->name }}</a>
        </h3>

        <div class="product-meta">
            <div class="product-price">
                ¥{{ number_format($product->price, 2) }}
                @if($product->wholesalePrices && $product->wholesalePrices->count() > 0)
                <small>起</small>
                @endif
            </div>

            <a href="/product/{{ $product->slug }}"
               class="btn btn-buy {{ !$inStock ? 'disabled' : '' }}"
               @if(!$inStock) tabindex="-1" aria-disabled="true" @endif>
                {{ $inStock ? '立即购买' : '暂时缺货' }}
            </a>
        </div>
    </div>
</div>
