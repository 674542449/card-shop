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
<aside class="n-embed-product">
    <div class="n-embed-product-main">
        @if($embedImg)
        <a href="/product/{{ $product->slug }}" class="n-embed-product-thumb" tabindex="-1" aria-hidden="true">
            <img src="{{ $embedImg }}" alt="" width="54" height="54" loading="lazy" decoding="async">
        </a>
        @endif
        <div class="n-embed-product-info">
            @if($product->category)
            <span class="n-embed-product-cat">{{ $product->category->name }}</span>
            @endif
            <strong class="n-embed-product-name">{{ $product->name }}</strong>
            <div class="n-embed-product-meta">
                <span class="n-embed-product-price">¥{{ number_format($embedLowestPrice, 2) }}{{ $embedShowFrom ? ' 起' : '' }}</span>
                <span aria-hidden="true">·</span>
                <span @class(['n-embed-product-stock', 'is-unavailable' => !$embedBuyable])>
                    {{ $embedBuyable ? '库存 ' . $embedStock . ' 件' : '暂时缺货' }}
                </span>
            </div>
        </div>
    </div>
    <a class="n-button {{ $embedBuyable ? 'n-button-primary' : 'n-button-secondary' }} n-button-sm" href="/product/{{ $product->slug }}">
        {{ $embedBuyable ? '立即选购' : '查看详情' }}<span class="n-sr">：{{ $product->name }}</span> <span aria-hidden="true">→</span>
    </a>
</aside>
@else
<aside class="n-embed-product is-missing">
    <div class="n-embed-product-info">
        <p class="n-hint">该关联商品当前暂不可售，请返回商品目录浏览其他在售商品。</p>
    </div>
    <a class="n-button n-button-secondary n-button-sm" href="/#catalog">返回商品目录 →</a>
</aside>
@endif
