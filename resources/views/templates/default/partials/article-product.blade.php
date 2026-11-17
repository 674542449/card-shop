<aside class="embed-product-card">
    @if($product)
    @php
        $embedStock = $product->stockCount();
        $embedMinQty = max(1, (int) $product->min_quantity);
        $embedBuyable = $embedStock >= $embedMinQty;
        $embedHasWholesale = $product->relationLoaded('wholesalePrices') && $product->wholesalePrices->isNotEmpty();
        $embedImg = \App\Support\SafeUrl::asset($product->image);
    @endphp
    <div class="embed-product-left">
        @if($embedImg)
        <a href="/product/{{ $product->slug }}" class="embed-product-thumb" tabindex="-1" aria-hidden="true">
            <img src="{{ $embedImg }}" alt="" width="50" height="50" loading="lazy" decoding="async">
        </a>
        @endif
        <div class="embed-product-info">
            @if($product->category)
            <span class="ws-pill ws-pill-neutral" style="margin-bottom:4px;">{{ $product->category->name }}</span>
            @endif
            <strong>{{ $product->name }}</strong>
            <p>
                <span class="embed-product-price">&yen;{{ number_format($product->price, 2) }}{{ $embedHasWholesale ? ' 起' : '' }}</span>
                &middot;
                @if($embedBuyable)
                <span>库存 {{ $embedStock }} 件</span>
                @else
                <span style="color:var(--ws-danger);">暂时缺货</span>
                @endif
            </p>
        </div>
    </div>
    <a href="/product/{{ $product->slug }}" class="ws-btn {{ $embedBuyable ? 'ws-btn-primary' : 'ws-btn-secondary' }} ws-btn-sm">
        {{ $embedBuyable ? '选购商品' : '查看详情' }}<span class="ws-sr-only">：{{ $product->name }}</span> &rarr;
    </a>
    @else
    <div class="embed-product-info">
        <p>该关联商品当前暂不可售，请前往商品目录浏览其他在售商品。</p>
    </div>
    <a href="/" class="ws-btn ws-btn-secondary ws-btn-sm">返回商品目录</a>
    @endif
</aside>
