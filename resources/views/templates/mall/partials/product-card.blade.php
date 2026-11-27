@php
    $stock = $product->stockCount();
    $minQty = max(1, (int) $product->min_quantity);
    $buyable = $stock >= $minQty;
    $hasWholesale = $product->relationLoaded('wholesalePrices') && $product->wholesalePrices->isNotEmpty();
    $lowestPrice = $hasWholesale
        ? min((float) $product->price, (float) $product->wholesalePrices->min('price'))
        : (float) $product->price;
    $descRaw = (string) preg_replace('/<\/(?:h[1-6]|p|div|li|tr|blockquote)>|<br\s*\/?>/i', ' ', (string) $product->description);
    $excerpt = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($descRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $img = \App\Support\SafeUrl::asset($product->image);
@endphp
<article @class(['ml-product-card', 'is-unavailable' => !$buyable])>
    <div class="ml-product-media">
        @if($img)
        <img src="{{ $img }}" alt="{{ $product->name }}" width="400" height="300" @if(!empty($lcp)) fetchpriority="high" @elseif(empty($eager)) loading="lazy" @endif decoding="async">
        @else
        @themeInclude('partials.image-placeholder')
        @endif
        @if(!$buyable)
        <span class="ml-media-tag is-soldout">已售罄</span>
        @elseif($hasWholesale)
        <span class="ml-media-tag is-promo">多件优惠</span>
        @endif
    </div>
    <div class="ml-product-body">
        <div class="ml-product-meta-top">
            <span class="ml-product-cat">{{ $product->category->name ?? '数字商品' }}</span>
            <span @class(['ml-stock-dot', 'is-out' => !$buyable])>{{ $buyable ? '库存 ' . $stock : '缺货' }}</span>
        </div>
        <h3><a class="ml-card-link" href="/product/{{ $product->slug }}">{{ $product->name }}</a></h3>
        <p class="ml-product-excerpt">{{ $excerpt !== '' ? \Illuminate\Support\Str::limit($excerpt, 56) : '24 小时自动发货 · 支付即领卡密' }}</p>
        <div class="ml-product-foot">
            <div class="ml-price">
                <small>¥</small><strong>{{ number_format($lowestPrice, 2) }}</strong>
                @if($hasWholesale && $lowestPrice < (float) $product->price)
                <span>起</span>
                @else
                <span>/件</span>
                @endif
            </div>
            <span class="ml-card-cta">{{ $buyable ? '立即选购 →' : '查看详情' }}</span>
        </div>
    </div>
</article>
