@if($product)
@php
    $embedStock = $product->stockCount();
    $embedMinQty = max(1, (int) $product->min_quantity);
    $embedBuyable = $embedStock >= $embedMinQty;
    $embedHasWholesale = $product->relationLoaded('wholesalePrices') && $product->wholesalePrices->isNotEmpty();
    $embedImg = \App\Support\SafeUrl::asset($product->image);
@endphp
<aside class="ml-embed-product">
    <div class="ml-embed-main">
        @if($embedImg)
        <a href="/product/{{ $product->slug }}" class="ml-embed-thumb" tabindex="-1" aria-hidden="true">
            <img src="{{ $embedImg }}" alt="" width="64" height="64" loading="lazy" decoding="async">
        </a>
        @endif
        <div class="ml-embed-info">
            @if($product->category)
            <span class="ml-embed-cat">{{ $product->category->name }}</span>
            @endif
            <strong class="ml-embed-name">{{ $product->name }}</strong>
            <div class="ml-embed-meta">
                <span class="ml-embed-price">¥{{ number_format($product->price, 2) }}{{ $embedHasWholesale ? ' 起' : '' }}</span>
                <span aria-hidden="true">·</span>
                <span @class(['ml-embed-stock', 'is-out' => !$embedBuyable])>
                    {{ $embedBuyable ? '现货 ' . $embedStock . ' 件' : '暂时缺货' }}
                </span>
            </div>
        </div>
    </div>
    <a class="ml-btn {{ $embedBuyable ? 'ml-btn-primary' : 'ml-btn-secondary' }} ml-btn-sm" href="/product/{{ $product->slug }}">
        {{ $embedBuyable ? '立即选购' : '查看详情' }}<span class="ml-sr-only">：{{ $product->name }}</span> →
    </a>
</aside>
@else
<aside class="ml-embed-product is-missing">
    <div class="ml-embed-info">
        <p class="ml-muted">该推荐商品当前暂不可售，欢迎前往商城目录浏览其他在售商品。</p>
    </div>
    <a class="ml-btn ml-btn-secondary ml-btn-sm" href="/#catalog">浏览全部商品 →</a>
</aside>
@endif
