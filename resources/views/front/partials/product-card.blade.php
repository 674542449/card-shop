@php
    $stock = $product->stockCount();
@endphp

{{--
    Mobile / narrow-viewport product card. Included from front/home.blade.php and
    front/product/list.blade.php so image handling stays identical everywhere.
    The image slot is always rendered (image or placeholder) so every card in a
    grid row is the same height and the grid never goes ragged.
--}}
<div class="product-card">
    <a href="/product/{{ $product->slug }}">
        @if($product->image)
        <img src="{{ $product->image }}" alt="{{ $product->name }}" class="card-img"
             width="400" height="400" loading="lazy" decoding="async">
        @else
        @include('front.partials.image-placeholder', ['class' => 'card-img-placeholder'])
        @endif
        <div class="card-body">
            <div class="card-badge"><span class="badge-auto">自动发货</span></div>
            <div class="card-title">{{ $product->name }}</div>
            <div class="card-footer">
                <span class="card-stock">库存 {{ $stock }}</span>
                <span class="card-price">¥{{ number_format($product->price, 2) }}</span>
            </div>
        </div>
    </a>
</div>
