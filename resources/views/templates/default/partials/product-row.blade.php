@php
    $stock = $product->stockCount();
    $minQty = max(1, (int) $product->min_quantity);
    $maxLimit = max($minQty, (int) $product->max_quantity);
    $buyable = $stock >= $minQty;
    $maxBuyable = $buyable ? min($maxLimit, $stock) : $minQty;
    $lowThreshold = array_key_exists('low_stock_threshold', $product->getAttributes())
        ? $product->low_stock_threshold
        : 5;
    $isLowStock = $buyable && $lowThreshold !== null && $stock <= (int) $lowThreshold;
    $tiers = $product->relationLoaded('wholesalePrices')
        ? $product->wholesalePrices->sortBy('min_quantity')->values()
        : collect();
    $hasWholesale = $tiers->isNotEmpty();
    $lowestPrice = $hasWholesale ? min((float) $product->price, (float) $tiers->min('price')) : (float) $product->price;
    $showFromUnit = $hasWholesale && $lowestPrice < (float) $product->price;

    $plainDesc = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $product->description)));
    $shortDesc = $plainDesc !== ''
        ? \Illuminate\Support\Str::limit($plainDesc, 64)
        : '订单提交后自动预留库存，支付核验完成后自动发放卡密凭据。';

    $catName = $product->category?->name ?? '默认分类';
    $searchKey = $product->name . ' ' . $catName . ' ' . $shortDesc;
    $prodImage = \App\Support\SafeUrl::asset($product->image);

    $payload = null;
    if ($buyable) {
        $payload = [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'url' => '/product/' . $product->slug,
            'categoryName' => (string) $catName,
            'image' => $prodImage,
            'price' => number_format((float) $product->price, 2, '.', ''),
            'stock' => (int) $stock,
            'isLowStock' => (bool) $isLowStock,
            'minQty' => (int) $minQty,
            'maxQty' => (int) $maxBuyable,
            'summary' => (string) $shortDesc,
            'checkoutKey' => app(\App\Services\CheckoutIntentService::class)->issue((int) $product->id),
            'tiers' => $tiers->map(fn ($t) => [
                'min_quantity' => (int) $t->min_quantity,
                'price' => number_format((float) $t->price, 2, '.', ''),
            ])->all(),
        ];
    }
@endphp

<article class="ws-item fb-listing-card {{ $buyable ? '' : 'is-soldout' }}"
         data-ws-item
         data-cat-id="{{ $product->category_id ?? 'other' }}"
         data-buyable="{{ $buyable ? '1' : '0' }}"
         data-search="{{ $searchKey }}"
         @if($payload) data-product-payload="{{ json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" @endif>

    {{-- Facebook Marketplace Listing Thumbnail --}}
    <a href="/product/{{ $product->slug }}" class="fb-listing-media" aria-label="查看 {{ $product->name }} 商品详情">
        @if($prodImage)
        <img src="{{ $prodImage }}" alt="{{ $product->name }}" width="640" height="400" @if(!empty($lcp)) fetchpriority="high" @elseif(empty($eager)) loading="lazy" @endif decoding="async">
        @else
        <div class="fb-listing-placeholder">
            @themeInclude('partials.image-placeholder', ['class' => 'prod-thumb-ph'])
        </div>
        @endif

        <span class="fb-stock-badge {{ $isLowStock ? 'is-low' : ($buyable ? 'is-ok' : 'is-out') }}">
            {{ $buyable ? ($isLowStock ? '库存紧张 · 余 ' . $stock . ' 件' : '库存 ' . $stock . ' 件') : '暂时缺货' }}
        </span>
        @if($hasWholesale)
        <span class="fb-tier-badge">支持阶梯价</span>
        @endif
    </a>

    {{-- Facebook Marketplace Hierarchy: Price First -> Title -> Meta --}}
    <div class="ws-item-body fb-listing-body">
        <div class="fb-listing-price-row">
            <div class="ws-price-box">
                <span class="ws-price-sym">&yen;</span>
                <span class="ws-price-val">{{ number_format($lowestPrice, 2) }}</span>
                @if($showFromUnit)
                <span class="ws-price-unit">起</span>
                @endif
            </div>
            <span class="fb-delivery-tag">自动发货</span>
        </div>

        <h3 class="ws-item-heading"><a href="/product/{{ $product->slug }}" class="ws-item-title">{{ $product->name }}</a></h3>

        <div class="fb-listing-meta">
            <span>{{ $catName }}</span>
            @if($minQty > 1)
            <span>&middot; {{ $minQty }} 件起购</span>
            @endif
        </div>

        <p class="ws-item-desc">{{ $shortDesc }}</p>
    </div>

    {{-- Full-Width Facebook Dual Buttons (#E4E6EB Secondary + #0866FF Primary) --}}
    <div class="ws-item-foot fb-listing-foot">
        <div class="ws-item-actions fb-listing-actions">
            @if($buyable)
            <a href="/product/{{ $product->slug }}" class="ws-btn ws-btn-secondary fb-card-btn">查看详情<span class="ws-sr-only">：{{ $product->name }}</span></a>
            <a href="/product/{{ $product->slug }}" class="ws-btn ws-btn-primary fb-card-btn" data-ws-quickbuy>
                <span>立即购买<span class="ws-sr-only"> {{ $product->name }}</span></span>
            </a>
            @else
            <a href="/product/{{ $product->slug }}" class="ws-btn ws-btn-secondary fb-card-btn is-full">查看详情<span class="ws-sr-only">：{{ $product->name }}</span></a>
            @endif
        </div>
    </div>
</article>
