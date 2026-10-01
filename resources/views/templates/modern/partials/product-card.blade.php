@php
    $stock = $product->stockCount();
    $buyable = $stock >= max(1, (int) $product->min_quantity);
    $excerpt = trim(strip_tags((string) $product->description));
@endphp
<article @class(['m-shop-card', 'is-unavailable' => !$buyable]) data-catalog-item data-search="{{ $product->name }} {{ $product->category->name ?? '' }}">
    <a class="m-shop-card-visual" href="/product/{{ $product->slug }}" aria-label="查看 {{ $product->name }}">
        @if($product->image)<img src="{{ $product->image }}" alt="" loading="lazy" decoding="async">@else @themeInclude('partials.image-placeholder') @endif
        <span class="m-shop-card-category">{{ $product->category->name ?? '数字商品' }}</span>
        <span class="m-shop-card-arrow" aria-hidden="true">↗</span>
    </a>
    <div class="m-shop-card-content">
        <span @class(['m-shop-card-stock', 'is-unavailable' => !$buyable])><span class="m-dot"></span>{{ $buyable ? '库存 ' . $stock : ($stock > 0 ? '库存不足起购量' : '暂时售罄') }}</span>
        <h3><a href="/product/{{ $product->slug }}">{{ $product->name }}</a></h3>
        <p class="m-shop-card-excerpt">{{ $excerpt !== '' ? \Illuminate\Support\Str::limit($excerpt, 68) : '数字卡密 · 付款后自动交付' }}</p>
        <div class="m-shop-card-bottom"><div class="m-shop-card-price"><small>¥</small>{{ number_format($product->price, 2) }}<span>/ 件</span></div>
            @if($buyable)<a class="m-shop-card-buy" href="/product/{{ $product->slug }}" aria-label="购买 {{ $product->name }}">选购 <span aria-hidden="true">→</span></a>@else<span class="m-muted">待补货</span>@endif
        </div>
        @if((int) $product->min_quantity > 1 || $product->wholesalePrices->isNotEmpty())
        <div class="m-shop-card-hint">{{ (int) $product->min_quantity > 1 ? $product->min_quantity . ' 件起购' : '1 件起购' }}@if($product->wholesalePrices->isNotEmpty()) · 多件享优惠@endif</div>
        @endif
    </div>
</article>