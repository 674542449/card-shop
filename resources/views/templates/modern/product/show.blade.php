@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/product/' . $product->slug))

@php
    // 与列表页同一套三档库存判断。两处必须一致，否则卡片说「库存紧张」而详情页说
    // 「有货」，买家会以为价格或库存被改过。
    $min     = max(1, (int) $product->min_quantity);
    $buyable = $stockCount >= $min;
    $lowAt   = max(5, $min * 2);
    if (!$buyable)                 { $stockTone = 'danger';  $stockLabel = '售罄'; }
    elseif ($stockCount <= $lowAt) { $stockTone = 'warning'; $stockLabel = '库存紧张（剩 ' . $stockCount . '）'; }
    else                           { $stockTone = 'success'; $stockLabel = '有库存 ' . $stockCount . ' 件'; }
@endphp

@section('content')

    {{--
        两栏：左边一整张卡片讲清楚「这是什么」，右边一个吸顶盒子负责「怎么买」。
        右栏 .pd-buy-box 是 position: sticky，左栏再长，下单入口都在视野里；
        窄屏（<=900px）自动落回单栏并解除吸顶，下单盒排在说明后面。
    --}}
    <div class="product-detail-layout">
        <div class="pd-main-col">
            <div class="pd-main-card">

                {{-- 没有商品图就整块不渲染。这个槽位是 180px 的深色横幅，空着比没有更难看，
                     也不传达任何信息。有图时用 front.css 的 .pd-image：它是 contain
                     letterbox，上传的图不管是横幅还是竖图都不会被裁掉主体或撑破槽位。 --}}
                @if($product->image)
                <div class="pd-hero-showcase">
                    <img src="{{ $product->image }}" alt="{{ $product->name }} 商品图"
                         class="pd-image" decoding="async" fetchpriority="high">
                </div>
                @endif

                <h1 class="pd-title">{{ $product->name }}</h1>

                <div class="pd-facts-wrap">
                    <span class="badge-auto">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        自动发货
                    </span>

                    @if($buyable)
                    <span class="badge-stock">当前库存：<strong>{{ $stockCount }}</strong> 件@if($stockTone === 'warning')（库存紧张）@endif</span>
                    @else
                    <span class="badge-stock out-of-stock">{{ $stockLabel }}</span>
                    @endif

                    {{-- 分类入口。.tag-item 就是本站给「分类胶囊」用的类，语义对得上，
                         买家从详情页退回同类列表只有这一条路。 --}}
                    <a href="/category/{{ $product->category->slug ?? '' }}" class="tag-item">
                        {{ $product->category->name ?? '未分类' }}
                    </a>
                </div>

                <div class="pd-price-row">
                    <span class="pd-price-unit">售价</span>
                    <div class="pd-price-val">¥{{ number_format($product->price, 2) }}</div>
                </div>

                {{-- 四条保障写的都是这套后台真做得到的事：付款回调后自动发卡、卡密在结果页
                     直接显示、同时发一份到下单邮箱、凭邮箱 + 查询密码可以随时找回。
                     模板原稿那四条（原生邮箱原件 / 首登 24h 质保 之类）是另一家店的承诺，
                     这里没有任何质保或换新逻辑，照抄就是对买家撒谎。 --}}
                <ul class="pd-guarantees-grid">
                    <li class="pd-guarantee-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linejoin="round" aria-hidden="true">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                        </svg>
                        <span>付款后自动发货</span>
                    </li>
                    <li class="pd-guarantee-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="2.5" y="4" width="19" height="14" rx="2"></rect>
                            <path d="M8 20h8"></path><path d="M12 18v2"></path>
                        </svg>
                        <span>卡密页面直接显示</span>
                    </li>
                    <li class="pd-guarantee-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="2.5" y="5" width="19" height="14" rx="2"></rect>
                            <path d="M3 7l9 6 9-6"></path>
                        </svg>
                        <span>同时发一份到邮箱</span>
                    </li>
                    <li class="pd-guarantee-item">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="3" y="11" width="18" height="11" rx="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <span>邮箱 + 查询密码找回</span>
                    </li>
                </ul>

                @if($product->wholesalePrices->isNotEmpty())
                <div class="tier-price-box">
                    <div class="tier-price-title">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 14 14"></polyline>
                        </svg>
                        阶梯价 · 买得多单价更低
                    </div>
                    {{-- 不给任何一档加 .active。front.js 只改 #total-price，不会跟着数量挪这个
                         高亮；写死一档，买家把数量改到别的区间之后高亮就是错的。 --}}
                    <div class="tier-price-items">
                        {{-- 第一行用起购量，不写死「1 件起」：起购 5 件的商品，
                             「1 件起」那一行是买不到的价格。 --}}
                        <span class="tier-item">{{ $min }} 件起：<strong>¥{{ number_format($product->price, 2) }}/件</strong></span>
                        @foreach($product->wholesalePrices as $wp)
                        <span class="tier-item">满 {{ $wp->min_quantity }} 件：<strong>¥{{ number_format($wp->price, 2) }}/件</strong></span>
                        @endforeach
                    </div>
                </div>
                @endif

                <div class="purchase-steps-flow">
                    <div class="steps-flow-title">下单到拿卡只有三步</div>
                    {{-- 有先后顺序，所以是 <ol> 而不是三个 div：读屏软件会报出「列表，3 项」。 --}}
                    <ol class="steps-flow-grid">
                        <li class="step-flow-item">
                            <div class="step-flow-num">1</div>
                            <div class="step-flow-text">填写邮箱、查询密码和数量</div>
                        </li>
                        <li class="step-flow-item">
                            <div class="step-flow-num">2</div>
                            <div class="step-flow-text">选择支付方式并完成付款</div>
                        </li>
                        <li class="step-flow-item">
                            <div class="step-flow-num">3</div>
                            <div class="step-flow-text">页面自动显示卡密并发送邮件</div>
                        </li>
                    </ol>
                </div>

                @if(trim(strip_tags($descriptionHtml)) !== '')
                <div class="pd-section-box">
                    <h2 class="pd-section-heading">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                        商品说明
                    </h2>
                    {{-- 后台富文本已经在 ContentRenderer 里过滤过，这里只负责排版容器。 --}}
                    <div class="rich-content">{!! $descriptionHtml !!}</div>
                </div>
                @endif

                {{-- 这条查询没有控制器供数据，只有视图里这一处。删掉它「购买须知」整块就没了。 --}}
                @php $sidebarArticles = \App\Models\Article::published()->recent()->limit(5)->get(); @endphp
                @if($sidebarArticles->isNotEmpty())
                <div class="pd-section-box">
                    <h2 class="pd-section-heading">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M4 4h11l5 5v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z"></path>
                            <path d="M14 4v6h6M8 14h8M8 17h5"></path>
                        </svg>
                        购买须知
                    </h2>
                    {{-- 用 .widget-list：全局 `a { color: inherit; text-decoration: none }` 之下，
                         裸的 <a> 和正文长得一模一样，没人知道那是能点的。 --}}
                    <ul class="widget-list">
                        @foreach($sidebarArticles as $art)
                        <li><a href="/articles/{{ $art->slug }}">{{ $art->title }}</a></li>
                        @endforeach
                    </ul>
                </div>
                @endif

                <div class="notice-callout">
                    <h2 class="notice-callout-header">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                        下单前请先看这几条
                    </h2>
                    <ul>
                        <li>卡密是虚拟商品，页面显示出来即视为已交付。</li>
                        <li>查询密码由你自己设定，请连同下单邮箱一起记牢——之后在「查询订单」里要这两样同时正确才能取回卡密。</li>
                        <li>付款完成后页面会自动跳到卡密页；万一中途关掉了，用邮箱加查询密码同样能找回。</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="pd-buy-col">
            <div class="pd-buy-box" id="pd-buy">
                <h2 class="pd-buy-title">{{ $buyable ? '填写购买信息' : '暂时无法购买' }}</h2>

                @if(!$buyable)
                {{-- 售罄不是把按钮变灰就完事：要说清楚为什么，以及还能做什么。 --}}
                <div class="empty-state">
                    <svg class="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 7h18l-1.5 13H4.5L3 7z"></path>
                        <path d="M8 7V5a4 4 0 0 1 8 0v2"></path>
                        <path d="M9 12l6 6M15 12l-6 6"></path>
                    </svg>
                    <h3>
                        @if($stockCount > 0)
                        库存不足（剩 {{ $stockCount }} 件，起购 {{ $min }} 件）
                        @else
                        该商品已售罄
                        @endif
                    </h3>
                    <p class="mt-1">补货时间不定，可以先看看同分类的其它商品。</p>
                    <a href="/category/{{ $product->category->slug ?? '' }}" class="btn-buy mt-2">看看同类商品</a>
                </div>
                @else

                {{-- 不要给这个 form 加 class="pd-form"：front.css 里 `.pd-form .form-group`
                     比模板的 `.form-group` 更具体，会把每个字段压成「80px 标签 + 输入框」
                     的横排，整栏塌掉。这里只需要 data-guard 给 front.js 防重复提交。 --}}
                <form action="/order/create" method="POST" data-guard>
                    @csrf
                    <input type="hidden" name="product_id" id="product_id" value="{{ $product->id }}">
                    {{-- front.js 靠这两个隐藏字段算实时总价，id 不能改。丢了不报错，
                         只是「应付金额」一直显示 ¥0.00 而服务端照原价收钱。 --}}
                    <input type="hidden" id="product-base-price" value="{{ $product->price }}">
                    <input type="hidden" id="wholesale-prices-data" value="{{ $product->wholesalePrices->toJson() }}">

                    <div class="form-group">
                        <label class="form-label" for="quantity">购买数量</label>
                        {{-- 模板的 .quantity-stepper 本来左右各有一个 +/- 按钮，靠它自带的 app.js
                             驱动；本站没有那段脚本，所以只留输入框——type=number 自带的上下箭头
                             就是同一件事，加两个点了没反应的按钮才是真问题。 --}}
                        <div class="quantity-stepper">
                            <input type="number" name="quantity" id="quantity"
                                   class="stepper-input @error('quantity') is-invalid @enderror"
                                   value="{{ old('quantity', $min) }}"
                                   min="{{ $min }}" max="{{ min((int) $product->max_quantity, $stockCount) }}" required>
                        </div>
                        @error('quantity')
                        <div class="field-error">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                            {{ $message }}
                        </div>
                        @else
                        <div class="field-hint">起购 {{ $min }} 件，单次最多 {{ min((int) $product->max_quantity, $stockCount) }} 件</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="email">接收邮箱</label>
                        <input type="email" name="email" id="email"
                               class="form-input @error('email') is-invalid @enderror"
                               value="{{ old('email') }}" placeholder="接收卡密信息"
                               autocomplete="email" required>
                        @error('email')
                        <div class="field-error">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                            {{ $message }}
                        </div>
                        @else
                        <div class="field-hint">卡密会同时显示在页面上并发到这个邮箱</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="query_password">查询密码</label>
                        {{-- autocomplete="new-password"：这是这一单现设的取件口令，不是账号密码，
                             不能让密码管理器把登录密码填进来。也刻意不回填 old()。 --}}
                        <input type="password" name="query_password" id="query_password"
                               class="form-input @error('query_password') is-invalid @enderror"
                               placeholder="至少 6 位" autocomplete="new-password" required>
                        @error('query_password')
                        <div class="field-error">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                            {{ $message }}
                        </div>
                        @else
                        <div class="field-hint">之后凭邮箱 + 这个密码在「查询订单」里找回卡密，请记牢</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="coupon_code">优惠码（选填）</label>
                        <input type="text" name="coupon_code" id="coupon_code"
                               class="form-input @error('coupon_code') is-invalid @enderror"
                               value="{{ old('coupon_code') }}" placeholder="没有可留空">
                        @error('coupon_code')
                        <div class="field-error">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                            {{ $message }}
                        </div>
                        @else
                        {{-- 说明优惠在下一步才结算。之前这里什么都不说，而它上方的
                             「应付金额」看起来像最终值，填了优惠码却不变，像是没生效。 --}}
                        <div class="field-hint">优惠码在提交后校验并抵扣，下方金额暂不含优惠</div>
                        @enderror
                    </div>

                    @php
                        // CreateOrderRequest 要求 payment_method，所以这个字段不是可选的。
                        $payMethods = [];
                        if (setting('epay_api_url') && setting('epay_merchant_id') && setting('epay_merchant_key')) {
                            $payMethods['alipay'] = '支付宝';
                            $payMethods['wechat'] = '微信';
                        }
                        if (setting('epusdt_api_url') && setting('epusdt_api_token')) {
                            $payMethods['usdt_trc20'] = 'TRC20';
                            $payMethods['usdt_bep20'] = 'BEP20';
                            $payMethods['usdt_polygon'] = 'Polygon';
                        }
                        if (empty($payMethods)) {
                            $payMethods = ['alipay' => '支付宝', 'wechat' => '微信'];
                        }
                        $selectedPay = old('payment_method', array_key_first($payMethods));
                    @endphp

                    <div class="form-group">
                        <span class="form-label">支付方式</span>
                        {{-- 通道是由后台配了哪个网关决定的，不是模板里写死的那四个；也不挂费率标签，
                             这套后台根本没有手续费逻辑，写一个数字上去就是和实际扣款对不上。 --}}
                        <div class="payment-grid" role="radiogroup" aria-label="支付方式">
                            @foreach($payMethods as $value => $label)
                            <label class="pay-card-option">
                                <input type="radio" name="payment_method" value="{{ $value }}"
                                       class="pay-radio" @checked($selectedPay === $value) required>
                                {{-- 同时带 .pay-option-inner：单选框被藏成 0x0，通用焦点环画在它身上
                                     等于没画，只有 front.css 的 `.pay-radio:focus-visible + .pay-option-inner`
                                     能让键盘用户看见自己停在哪一张卡上。选中态仍由模板那条更具体的
                                     `.pay-card-option input:checked + .pay-card-inner` 说了算。 --}}
                                <span class="pay-card-inner pay-option-inner">
                                    <span class="pay-icon-box">@themeInclude('partials.pay-icon', ['method' => $value])</span>
                                    <span class="pay-title">{{ $label }}</span>
                                </span>
                            </label>
                            @endforeach
                        </div>
                        @error('payment_method')
                        <div class="field-error">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                            {{ $message }}
                        </div>
                        @enderror
                    </div>

                    @if(setting('turnstile_site_key'))
                    <div class="form-group">
                        <div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}"></div>
                        {{-- 键名是 turnstile，不是 cf-turnstile-response：VerifyTurnstile 中间件
                             用 withErrors(['turnstile' => …]) 回跳，写成字段名那条永远不会命中。 --}}
                        @error('turnstile')
                        <div class="field-error">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                            {{ $message }}
                        </div>
                        @enderror
                    </div>
                    @endif

                    {{-- 结算区只有两行，没有「通道手续费」那一行：服务端不收也不算手续费，
                         多一行就是凭空多一个和实际扣款对不上的数字。 --}}
                    <div class="checkout-summary">
                        <div class="summary-row">
                            <span>商品单价</span>
                            <span>¥{{ number_format($product->price, 2) }}</span>
                        </div>
                        <div class="summary-row summary-total-row">
                            <span>应付金额</span>
                            <span id="total-price" class="summary-total-price">¥{{ number_format($product->price * $min, 2) }}</span>
                        </div>
                    </div>

                    {{-- 必须是 <button type="submit">：front.js 的防重复提交是靠
                         form.querySelector('button[type="submit"]') 找到它的。 --}}
                    <button type="submit" class="btn-checkout-submit">
                        <span>立即购买</span>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </button>
                </form>
                @endif
            </div>
        </div>
    </div>

    @if($buyable)
    {{-- 窄屏下两栏叠成一栏，下单盒排在长长的说明后面，这条常驻栏是回到表单的近路。
         它只放挂牌单价和一个锚点，不放实付金额：没有脚本会跟着数量更新它，
         摆一个不会变的「实付」金额比不摆更糟。 --}}
    <div class="mobile-bottom-bar">
        <div class="mobile-bottom-content">
            <div class="pd-price-unit">售价 ¥{{ number_format($product->price, 2) }}</div>
            <a href="#pd-buy" class="btn-buy">前往下单</a>
        </div>
    </div>
    @endif

@endsection
