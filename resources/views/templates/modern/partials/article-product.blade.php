<aside class="m-panel" style="padding:24px;margin:24px 0">
<span class="m-eyebrow">文章中的好物</span>@if($product)<h3>{{ $product->name }}</h3><p class="m-muted">¥{{ number_format($product->price, 2) }} · 可售 {{ $product->stockCount() }} 件</p><a class="m-button m-button-primary" href="/product/{{ $product->slug }}">看看这份好物 ↗</a>@else<p class="m-muted">这份商品暂时不可售。</p><a class="m-text-link" href="/#catalog">浏览其他好物 ↗</a>@endif
</aside>
