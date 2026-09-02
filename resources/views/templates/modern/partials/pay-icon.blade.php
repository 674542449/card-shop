{{--
    支付渠道标记，内联，不多一个请求也不会 404。

    用几何原型拼，而不是描摹官方 logo 轮廓：这样在 24px 上仍然认得出，而且每个标记自带
    颜色，放在白底或深色底的方块里都不需要额外垫一层。

    本主题版与 templates/default 的差别只有一处：底盘统一成圆角方块（rx=7），不再是
    圆形和方形混着。模板的支付选项是一排 28px 的 .pay-icon-box，几个标记挨在一起时
    形状不统一会像是从两个图标库里拼的。颜色仍按各自网络区分——三个 USDT 通道必须一眼
    分得开，全部刷成 Tether 绿反而害人。

    class="pay-svg" 不能去：front.css 用它把标记撑满外层盒子（width/height 100%），
    去掉之后 svg 会掉回自己的固有尺寸。

    参数：
      $method - alipay | wechat | usdt_* 之一（其它值落到最后的中性卡片标记，
                所以一个没见过的渠道也还是画得出东西）
--}}
@php $payIconKey = (string) ($method ?? ''); @endphp

@if($payIconKey === 'alipay')
    {{-- 支付宝的识别点是蓝底加一个「支」。手描那一笔的路径出来既不像 logo 也不像字，
         所以直接用字形：任何装了中文字体的设备都渲染得对，而本店的访客都装了。 --}}
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
        <rect width="32" height="32" rx="7" fill="#1677ff"/>
        <text x="16" y="23" text-anchor="middle" fill="#fff"
              font-size="20" font-weight="700"
              font-family="-apple-system, BlinkMacSystemFont, 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif">支</text>
    </svg>
@elseif($payIconKey === 'wechat')
    {{-- 两个气泡的几何沿用 default 那版（已经调好了叠放关系），整体缩到 0.72 并居中，
         好让它落在圆角底盘里而不是顶到边。底盘绿、气泡白、眼睛回到底盘色。 --}}
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
        <rect width="32" height="32" rx="7" fill="#07c160"/>
        <g transform="translate(4.2 4) scale(0.72)">
            <g fill="#fff">
                <ellipse cx="12.5" cy="12" rx="9.5" ry="7.8"/>
                <path d="M6.4 17.4 4.2 21.3l4.6-2.2z"/>
                <ellipse cx="21.5" cy="20.5" rx="8.2" ry="6.8"/>
                <path d="M26.6 25.3 28.6 29l-4.3-2.1z"/>
            </g>
            <g fill="#07c160">
                <circle cx="9.4" cy="10.4" r="1.3"/>
                <circle cx="15.6" cy="10.4" r="1.3"/>
                <circle cx="18.8" cy="19" r="1.1"/>
                <circle cx="24.2" cy="19" r="1.1"/>
            </g>
        </g>
    </svg>
@elseif($payIconKey === 'usdt_trc20')
    {{-- 波场。它的标志是一个有棱角的三角形；描边而不是实心，22px 上才不会糊成一团。 --}}
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
        <rect width="32" height="32" rx="7" fill="#ef0027"/>
        <path d="M8 9.5 23.5 12.3 16.3 25.5Z" fill="none" stroke="#fff"
              stroke-width="1.9" stroke-linejoin="round"/>
        <path d="M8 9.5 13.6 14.4 23.5 12.3M13.6 14.4 16.3 25.5" fill="none" stroke="#fff"
              stroke-width="1.4" stroke-linejoin="round"/>
    </svg>
@elseif($payIconKey === 'usdt_bep20')
    {{-- BNB Chain：四颗菱形围着第五颗。直接写 polygon 顶点而不是旋转正方形，
         任何尺寸下几何都是准的。 --}}
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
        <rect width="32" height="32" rx="7" fill="#f0b90b"/>
        <g fill="#fff">
            <polygon points="16,6.4 19.1,9.5 16,12.6 12.9,9.5"/>
            <polygon points="16,19.4 19.1,22.5 16,25.6 12.9,22.5"/>
            <polygon points="9.5,12.9 12.6,16 9.5,19.1 6.4,16"/>
            <polygon points="22.5,12.9 25.6,16 22.5,19.1 19.4,16"/>
            <polygon points="16,12.9 19.1,16 16,19.1 12.9,16"/>
        </g>
    </svg>
@elseif($payIconKey === 'usdt_polygon')
    {{-- Polygon：一个六边形轮廓，就是这条链名字的来源。 --}}
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
        <rect width="32" height="32" rx="7" fill="#8247e5"/>
        <polygon points="16,6.8 23.9,11.4 23.9,20.6 16,25.2 8.1,20.6 8.1,11.4"
                 fill="none" stroke="#fff" stroke-width="2.2" stroke-linejoin="round"/>
        <circle cx="16" cy="16" r="2.6" fill="#fff"/>
    </svg>
@elseif(str_starts_with($payIconKey, 'usdt_'))
    {{-- 我们没画过的 USDT 网络：Tether 本身的标记仍然说明了这是哪种币。 --}}
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
        <rect width="32" height="32" rx="7" fill="#26a17b"/>
        <g fill="#fff">
            <rect x="7.5" y="8" width="17" height="3.4" rx="1"/>
            <rect x="14.2" y="8" width="3.6" height="16" rx="1"/>
        </g>
        <ellipse cx="16" cy="14.2" rx="7.4" ry="2.6" fill="none" stroke="#fff" stroke-width="2"/>
    </svg>
@else
    {{-- 认不出来的渠道。中性的板岩底盘 + 一张卡，不冒充任何一家的品牌色。 --}}
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
        <rect width="32" height="32" rx="7" fill="#475569"/>
        <rect x="6" y="9" width="20" height="14" rx="2.5" fill="none" stroke="#fff" stroke-width="1.8"/>
        <path d="M6 14h20" stroke="#fff" stroke-width="1.8"/>
        <path d="M9.5 18.5h5" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/>
    </svg>
@endif
