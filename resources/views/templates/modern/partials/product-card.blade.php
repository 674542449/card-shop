@php
    $stock = $product->stockCount();
    // 不是 $stock > 0。起购 5 张、只剩 3 张的商品根本下不了单，给它显示可购等于把
    // 买家送进一个必定失败的页面。与 default 模板同一条判断，两边不能有分歧。
    $buyable = $stock >= max(1, (int) $product->min_quantity);

    // 三档库存状态，对齐目标站：有货 / 库存紧张（剩 N）/ 售罄。
    // 阈值取起购量的两倍且至少 5——「紧张」要对买家有意义，而对一个起购 10 张的
    // 商品来说，剩 6 张就已经只够买一单了。
    $lowAt = max(5, (int) $product->min_quantity * 2);
    if (!$buyable) {
        $stockLabel = '售罄';
        $stockTone  = 'danger';
    } elseif ($stock <= $lowAt) {
        $stockLabel = '库存紧张（剩 ' . $stock . '）';
        $stockTone  = 'warning';
    } else {
        $stockLabel = '有库存';
        $stockTone  = 'success';
    }

    $desc = trim(strip_tags((string) $product->description));
@endphp

<article class="product-card">
    {{-- 封面槽位固定高度并且**始终渲染**（没有图就放占位图）：运营上传的图尺寸各不
         相同，让它自然撑开会让整排卡片参差。.product-card-poster 已经把槽位定成
         138px + overflow:hidden，图自己再补 object-fit:cover 就能把任意比例填满同一
         个槽——不管传的是 1200x400 的横幅还是 400x900 的竖图，卡片骨架都一样。

         这三条为什么写在行内：样式表只给 .product-card-poster 里的 <svg> 定了尺寸
         （模板的封面原本是内联 SVG 海报，没有 <img> 这种情况），本次改动不许动
         style.css，所以这是整个文件里唯一没有类可用的地方。 --}}
    @if($product->image)
    <div class="product-card-poster">
        <img src="{{ $product->image }}" alt="" loading="lazy" decoding="async"
             style="width:100%;height:100%;object-fit:cover;">
    </div>
    @else
    {{-- 占位图这里让 image-placeholder 自己**就是**封面槽（两个类一起给它），而不是
         再套一层 .product-card-poster：套起来的话占位 <div> 是个收缩到内容宽度的
         flex 项，而 .product-card-poster svg 又要求 svg 撑满 100%，两者互相求解，
         实测会得到一个被裁掉上下的 300px 大图标。 --}}
    @themeInclude('partials.image-placeholder', ['class' => 'product-card-poster empty-state-glyph'])
    @endif

    {{-- 分类角标是封面的兄弟节点而不是子节点：image-placeholder 渲染出来是个闭合
         元素，没法往里塞东西。反正 .product-card 自己是 position:relative，角标的
         定位基准两种分支下都是同一个盒子，落点完全一致。 --}}
    <span class="product-poster-badge">{{ $product->category->name ?? '未分类' }}</span>

    <div class="product-card-body">
        <a href="/product/{{ $product->slug }}" class="product-title"
           title="{{ $product->name }}">{{ $product->name }}</a>

        @if($desc !== '')
        <p class="product-summary-text">{{ $desc }}</p>
        @endif

        <div class="product-meta-row">
            <span class="badge-auto">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                自动发货
            </span>

            @if($stockTone === 'danger')
            <span class="badge-stock out-of-stock">{{ $stockLabel }}</span>
            @else
            <span class="badge-stock">{{ $stockLabel }}</span>
            @endif
        </div>
    </div>

    <div class="product-card-footer">
        <div class="product-price-block">
            <span class="product-price-label">售价</span>
            <div class="product-price"><small>¥</small>{{ number_format($product->price, 2) }}</div>
        </div>

        @if($buyable)
        {{-- 一排卡片上「购买」两个字重复出现，读屏器听下来分不清是哪一张，
             补一个带商品名的可访问名称。 --}}
        <a href="/product/{{ $product->slug }}" class="btn-buy"
           aria-label="购买 {{ $product->name }}">购买</a>
        @else
        {{-- 同尺寸的禁用态而不是直接隐藏：一行里卡片的底边要齐，隐藏会让售罄的卡片
             比旁边的矮一截。 --}}
        <button type="button" class="btn-buy disabled" disabled>售罄</button>
        @endif
    </div>
</article>
