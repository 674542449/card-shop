@php
    $stock = $product->stockCount();
    // 不是 $stock > 0。起购数量 5、只剩 3 张的商品根本买不了——下单表单会拒绝任何
    // 数量——所以给它显示「购买」等于把买家送进一个必定失败的页面。它在实际意义上
    // 就是缺货。这条判断与 default 模板一致，两边不能有分歧。
    $buyable = $stock >= max(1, (int) $product->min_quantity);
@endphp

{{--
    商品卡片。整张卡可点：.pcard-link::after 铺满卡片做点击区，而「购买」按钮用
    z-index 浮在它上面。这样一张卡片只有两个键盘停靠点（卡片本身 + 按钮），
    而不是缩略图、名称、按钮各停一次。
--}}
<article class="pcard {{ $buyable ? '' : 'is-out' }}">
    <div class="pcard-head">
        @if($product->image)
        {{-- alt="" 是有意的：商品名紧跟在同一张卡片里，缩略图对读屏软件是装饰。 --}}
        <img src="{{ $product->image }}" alt="" class="pcard-thumb"
             width="44" height="44" loading="lazy" decoding="async">
        @else
        <span class="pcard-thumb-ph" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"
                 stroke-linecap="round" stroke-linejoin="round" focusable="false">
                <rect x="2.5" y="5.5" width="19" height="13" rx="2"></rect>
                <path d="M2.5 10h19"></path>
                <path d="M6.5 14.5h4"></path>
            </svg>
        </span>
        @endif

        <a href="/product/{{ $product->slug }}" class="pcard-name pcard-link">{{ $product->name }}</a>
    </div>

    <div class="pcard-meta">
        <span class="pcard-badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M13 2 4 14h7l-1 8 9-12h-7l1-8z"></path>
            </svg>
            自动发货
        </span>
        <span class="pcard-stock">库存 <strong>{{ $stock }}</strong></span>
    </div>

    <div class="pcard-foot">
        {{-- 货币符号单独一个 span 并调小：价格里真正要被读到的是数字，
             ¥ 只是单位。等宽 + tabular-nums 让一排卡片的价格竖着对齐。 --}}
        <span class="pcard-price"><span class="cur">¥</span>{{ number_format($product->price, 2) }}</span>

        @if($buyable)
        <a href="/product/{{ $product->slug }}" class="pcard-btn">购买</a>
        @else
        {{-- 与按钮同尺寸的占位盒，否则同一行里缺货卡片会比有货的矮一截。 --}}
        <span class="pcard-out">缺货</span>
        @endif
    </div>
</article>
