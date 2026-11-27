@php
    $stock = $product->stockCount();
    $minQty = max(1, (int) $product->min_quantity);
    $buyable = $stock >= $minQty;
    $excerpt = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $product->description)));
    $img = \App\Support\SafeUrl::asset($product->image);
@endphp
<article @class(['n-product-card', 'is-unavailable' => !$buyable])>
    <div class="n-product-thumb">
        @if($img)
        <img src="{{ $img }}" alt="" loading="{{ !empty($lcp) ? 'eager' : 'lazy' }}" @if(!empty($lcp)) fetchpriority="high" @endif decoding="async">
        @else
        @themeInclude('partials.image-placeholder')
        @endif
    </div>
    <div class="n-product-card-info">
        <span class="n-product-category">{{ $product->category->name ?? '数字商品' }}</span>
        <h3><a class="n-card-link" href="/product/{{ $product->slug }}">{{ $product->name }}</a></h3>
        <p>{{ $excerpt !== '' ? \Illuminate\Support\Str::limit($excerpt, 74) : '付款后自动交付数字卡密' }}</p>
        <div class="n-product-stock">
            <span class="n-dot {{ !$buyable ? 'is-muted' : '' }}" aria-hidden="true"></span>
            {{ $buyable ? '库存 ' . $stock . ' 件' : ($stock > 0 ? '库存不足起购量' : '暂时售罄') }}
            @if($minQty > 1)<span> · {{ $minQty }} 件起购</span>@endif
            @if($product->wholesalePrices->isNotEmpty())<span> · 阶梯优惠</span>@endif
        </div>
    </div>
    <div class="n-product-card-bottom">
        <strong><small>¥</small>{{ number_format($product->price, 2) }}<span>/ 件</span></strong>
        <span class="n-product-card-link">{{ $buyable ? '选购 →' : '查看详情' }}</span>
    </div>
</article>
