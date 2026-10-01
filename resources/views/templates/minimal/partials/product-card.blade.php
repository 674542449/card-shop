@php
    $stock = $product->stockCount();
    $buyable = $stock >= max(1, (int) $product->min_quantity);
    $excerpt = trim(strip_tags((string) $product->description));
@endphp
<article class="n-product-card {{ !$buyable ? 'is-unavailable' : '' }}" data-catalog-item data-search="{{ $product->name }} {{ $product->category->name ?? '' }}">
    <a href="/product/{{ $product->slug }}" class="n-product-thumb" aria-label="查看 {{ $product->name }}">@if($product->image)<img src="{{ $product->image }}" alt="" loading="lazy" decoding="async">@else @themeInclude('partials.image-placeholder') @endif</a>
    <div class="n-product-card-info"><span class="n-product-category">{{ $product->category->name ?? '数字商品' }}</span><h3><a href="/product/{{ $product->slug }}">{{ $product->name }}</a></h3><p>{{ $excerpt !== '' ? \Illuminate\Support\Str::limit($excerpt, 82) : '付款后自动交付数字卡密' }}</p><div class="n-product-stock"><span class="n-dot {{ !$buyable ? 'is-muted' : '' }}" aria-hidden="true"></span>{{ $buyable ? '库存 ' . $stock . ' 件' : ($stock > 0 ? '库存不足起购量' : '暂时售罄') }}@if((int) $product->min_quantity > 1)<span> · {{ $product->min_quantity }} 件起购</span>@endif @if($product->wholesalePrices->isNotEmpty())<span> · 阶梯优惠</span>@endif</div></div>
    <div class="n-product-card-bottom"><strong><small>¥</small>{{ number_format($product->price, 2) }}<span>/ 件</span></strong><a href="/product/{{ $product->slug }}" class="n-product-card-link">{{ $buyable ? '选购' : '查看详情' }} <span aria-hidden="true">→</span></a></div>
</article>