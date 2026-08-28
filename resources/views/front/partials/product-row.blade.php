@php
    $stock = $product->stockCount();
@endphp

{{--
    Desktop product table row. Included from front/home.blade.php and
    front/product/list.blade.php.

    The 40px thumbnail slot is always rendered — image or placeholder — so the
    product names stay on one vertical line down the table instead of jagging
    left and right depending on which rows happen to have an image.
--}}
<tr>
    <td>
        <div class="prod-name-cell">
            {{-- alt="" on purpose: the product name follows immediately in the same
                 cell, so the thumbnail is decorative to a screen reader. --}}
            @if($product->image)
            <img src="{{ $product->image }}" alt="" class="prod-thumb"
                 width="40" height="40" loading="lazy" decoding="async">
            @else
            @include('front.partials.image-placeholder', ['class' => 'prod-thumb-ph'])
            @endif
            <a href="/product/{{ $product->slug }}" class="prod-name">{{ $product->name }}</a>
        </div>
    </td>
    <td><span class="badge-auto">自动发货</span></td>
    <td class="stock-num">{{ $stock }}</td>
    <td class="prod-price">¥{{ number_format($product->price, 2) }}</td>
    <td>
        @if($stock > 0)
        <a href="/product/{{ $product->slug }}" class="btn-buy-sm">购买</a>
        @else
        <span class="text-muted" style="font-size:13px">缺货</span>
        @endif
    </td>
</tr>
