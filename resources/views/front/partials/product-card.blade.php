@php
    $stock = $product->stockCount();
@endphp

<div class="product-card">
    <a href="/product/{{ $product->slug }}">
        @if($product->image)
        <img src="{{ $product->image }}" alt="{{ $product->name }}" class="card-img">
        @else
        <div class="card-img-placeholder">&#128230;</div>
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
