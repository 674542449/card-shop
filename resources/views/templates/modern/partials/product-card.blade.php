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

{{--
    商品卡片。整卡可点：.mcard-title::after 铺满卡片做点击区，购买按钮用 z-index
    浮在它上面。这样一张卡片只有两个键盘停靠点，而不是每张卡片停三次。
--}}
<article class="mcard {{ $buyable ? '' : 'is-out' }}">
    {{-- 商品图。这一套模板此前是三套里唯一完全不显示商品图的——列表卡没有、详情页
         没有，运营在后台传的图等于白传。

         槽位固定 4:3 并且**始终渲染**（没有图就放占位图）：上传的图尺寸各不相同，
         让它自然撑开就会每张卡高度不一，整排参差。object-fit:cover 把任意比例填满
         同一个槽，所以不管传的是 1200x400 的横幅还是 400x900 的竖图，卡片骨架都
         一样。
         这里用 cover 而不是详情页那样的 contain：列表要的是整齐，详情页要的是不裁
         掉信息，两者取舍不同。 --}}
    <div class="mcard-media">
        @if($product->image)
        <img src="{{ $product->image }}" alt="" class="mcard-img"
             loading="lazy" decoding="async">
        @else
        @themeInclude('partials.image-placeholder', ['class' => 'mcard-img-ph'])
        @endif
    </div>

    <div class="mcard-eyebrow">分类 · {{ $product->category->name ?? '未分类' }}</div>

    <a href="/product/{{ $product->slug }}" class="mcard-title">{{ $product->name }}</a>

    <div class="mcard-badges">
        <span class="badge badge-info">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"></path>
            </svg>
            自动交付
        </span>
        <span class="badge badge-{{ $stockTone }}">{{ $stockLabel }}</span>
    </div>

    @if($desc !== '')
    <p class="mcard-desc">{{ $desc }}</p>
    @endif

    <div class="mcard-foot">
        <div>
            <span class="mcard-price-label">价格</span>
            {{-- 数字等宽：一排卡片的价格要竖着对齐，比例数字下 1 比 8 窄就会参差。 --}}
            <span class="mcard-price">{{ number_format($product->price, 2) }}<span class="cur">CNY</span></span>
        </div>

        @if($buyable)
        <a href="/product/{{ $product->slug }}" class="mcard-buy" aria-label="购买 {{ $product->name }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 8H6"></path>
                <circle cx="10" cy="20" r="1.2"></circle><circle cx="18" cy="20" r="1.2"></circle>
            </svg>
        </a>
        @else
        {{-- 同尺寸的禁用态而不是直接隐藏：一行里卡片的底边要齐，隐藏会让售罄的卡片
             比旁边的矮一截。 --}}
        <span class="mcard-buy is-disabled" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L21 8H6"></path>
                <circle cx="10" cy="20" r="1.2"></circle><circle cx="18" cy="20" r="1.2"></circle>
            </svg>
        </span>
        @endif
    </div>
</article>
