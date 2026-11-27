@if($product)
@php
    $embedStock = $product->stockCount();
    $embedMinQty = max(1, (int) $product->min_quantity);
    $embedBuyable = $embedStock >= $embedMinQty;
    $embedHasWholesale = $product->relationLoaded('wholesalePrices') && $product->wholesalePrices->isNotEmpty();
    $embedLowestPrice = $embedHasWholesale ? min((float) $product->price, (float) $product->wholesalePrices->min('price')) : (float) $product->price;
    $embedShowFrom = $embedHasWholesale && $embedLowestPrice < (float) $product->price;
    $embedImg = \App\Support\SafeUrl::asset($product->image);
@endphp
<aside class="m-embed-product">
    <div class="m-embed-product-main">
        @if($embedImg)
        <a href="/product/{{ $product->slug }}" class="m-embed-product-thumb" tabindex="-1" aria-hidden="true">
            <img src="{{ $embedImg }}" alt="" width="56" height="56" loading="lazy" decoding="async">
        </a>
        @endif
        <div class="m-embed-product-info">
            @if($product->category)
            <span class="m-embed-product-cat">{{ $product->category->name }}</span>
            @endif
            <strong class="m-embed-product-name">{{ $product->name }}</strong>
            <div class="m-embed-product-meta">
                <span class="m-embed-product-price">¥{{ number_format($embedLowestPrice, 2) }}{{ $embedShowFrom ? ' 起' : '' }}</span>
                <span aria-hidden="true">·</span>
                <span @class(['m-embed-product-stock', 'is-unavailable' => !$embedBuyable])>
                    {{ $embedBuyable ? '库存 ' . $embedStock . ' 件' : '暂时缺货' }}
                </span>
            </div>
        </div>
    </div>
    <a class="m-button {{ $embedBuyable ? 'm-button-primary' : 'm-button-secondary' }} m-button-sm" href="/product/{{ $product->slug }}">
        {{ $embedBuyable ? '立即选购' : '查看详情' }}<span class="m-sr-only">：{{ $product->name }}</span> <span aria-hidden="true">→</span>
    </a>
</aside>
@else
<aside class="m-embed-product is-missing">
    <div class="m-embed-product-info">
        <p class="m-muted">该关联商品当前暂不可售，请前往商品目录浏览其他在售商品。</p>
    </div>
    <a class="m-button m-button-secondary m-button-sm" href="/#catalog">浏览其他商品 →</a>
</aside>
@endif
