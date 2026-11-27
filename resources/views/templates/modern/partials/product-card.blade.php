@php
    $stock = $product->stockCount();
    $minQty = max(1, (int) $product->min_quantity);
    $buyable = $stock >= $minQty;
    $descRaw = (string) preg_replace('/<\/(?:h[1-6]|p|div|li|tr|blockquote)>|<br\s*\/?>/i', ' ', (string) $product->description);
    $excerpt = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($descRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $img = \App\Support\SafeUrl::asset($product->image);
@endphp
<article @class(['m-shop-card', 'is-unavailable' => !$buyable])>
    <div class="m-shop-card-visual">
        @if($img)
        <img src="{{ $img }}" alt="" width="400" height="250" @if(!empty($lcp)) fetchpriority="high" @else loading="lazy" @endif decoding="async">
        @else
        @themeInclude('partials.image-placeholder')
        @endif
        @if($product->category)
        <span class="m-shop-card-category">{{ $product->category->name }}</span>
        @endif
    </div>
    <div class="m-shop-card-content">
        <span @class(['m-shop-card-stock', 'is-unavailable' => !$buyable])>
            <span class="m-dot" aria-hidden="true"></span>
            {{ $buyable ? '库存 ' . $stock . ' 件' : ($stock > 0 ? '库存不足起购量' : '暂时售罄') }}
        </span>
        <h3><a class="m-card-link" href="/product/{{ $product->slug }}">{{ $product->name }}</a></h3>
        <p class="m-shop-card-excerpt">{{ $excerpt !== '' ? \Illuminate\Support\Str::limit($excerpt, 60) : '数字卡密 · 付款后自动交付' }}</p>
        <div class="m-shop-card-bottom">
            <div class="m-shop-card-price"><small>¥</small>{{ number_format($product->price, 2) }}<span>/ 件</span></div>
            <span class="m-shop-card-buy">{{ $buyable ? '选购 →' : '查看详情' }}</span>
        </div>
        @if($minQty > 1 || $product->wholesalePrices->isNotEmpty())
        <div class="m-shop-card-hint">{{ $minQty > 1 ? $minQty . ' 件起购' : '1 件起购' }}@if($product->wholesalePrices->isNotEmpty()) · 多件阶梯价@endif</div>
        @endif
    </div>
</article>
