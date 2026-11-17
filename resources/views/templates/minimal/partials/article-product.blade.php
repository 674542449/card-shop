<aside class="n-panel" style="padding:24px;margin:24px 0;border-left:3px solid currentColor">
<span class="n-kicker">RELATED PRODUCT</span>@if($product)<h3>{{ $product->name }}</h3><p>¥{{ number_format($product->price, 2) }} / 件 · 库存 {{ $product->stockCount() }}</p><a class="n-button n-button-primary" href="/product/{{ $product->slug }}">查看商品 →</a>@else<p>商品暂时不可售。</p><a href="/#catalog">返回商品目录 →</a>@endif
</aside>
