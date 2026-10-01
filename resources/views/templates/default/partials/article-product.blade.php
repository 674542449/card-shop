<aside style="padding:20px;margin:20px 0;border:1px solid #e1e5eb;border-radius:6px;background:#f7faff">
@if($product)<strong>{{ $product->name }}</strong><p>¥{{ number_format($product->price, 2) }} · 库存 {{ $product->stockCount() }}</p><a href="/product/{{ $product->slug }}">查看商品 →</a>@else<p>该商品当前不可售，请浏览其他商品。</p>@endif
</aside>
