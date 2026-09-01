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
        商品页版式：一整块面板对半分，图在左、购买链路在右一条竖流。

        改之前是「小图 + 名称 + 价格」一条横幅，横幅下面再分成左右两栏（描述和表单在
        左、阶梯价和须知在右）。那样买家的视线要在两栏之间来回跳：看完左边的描述，
        再挪到右边看阶梯价，再回到左边填表单。
        现在把「决定买不买 + 怎么买」需要的东西全部收进右栏一条竖流：
        名称 → 徽章 → 价格 → 阶梯价 → 描述 → 下单，从上往下读一遍就走完了。
        阶梯价从侧栏挪进来，因为它是定价的一部分，本来就该紧跟价格。

        左栏是浅色底并拉伸到与右栏等高（grid 默认 stretch），图片在栏内 sticky：
        右栏很长，不 sticky 的话往下填表单时左边就是一大片空色块。
    --}}
    <section class="pdx">
        <div class="pdx-media">
            {{-- 槽位固定 4:3 且始终渲染，没有图就放占位图，有图没图的骨架一致。
                 contain letterbox 而不是列表卡的 cover：详情页要的是不裁掉信息
                 （商品图上常写着规格），列表页要的是整排整齐。 --}}
            <div class="pdx-media-inner">
                @if($product->image)
                <img src="{{ $product->image }}" alt="{{ $product->name }} 商品图"
                     class="pdx-img" decoding="async" fetchpriority="high">
                @else
                @themeInclude('partials.image-placeholder', ['class' => 'pdx-img-ph'])
                @endif
            </div>
        </div>

        <div class="pdx-info">
            <div class="pdx-eyebrow">
                分类 ·
                <a href="/category/{{ $product->category->slug ?? '' }}">{{ $product->category->name ?? '未分类' }}</a>
            </div>

            <h1 class="pdx-name">{{ $product->name }}</h1>

            <div class="pd-badges">
                <span class="badge badge-info">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"></path>
                    </svg>
                    自动发货
                </span>
                <span class="badge badge-{{ $stockTone }}">{{ $stockLabel }}</span>
            </div>

            <div class="pdx-price-label">价格</div>
            <div class="pdx-price">
                {{ number_format($product->price, 2) }}<span class="cur">CNY</span>
            </div>
            @if($product->wholesalePrices->isNotEmpty())
            <div class="pdx-price-note">买得多更便宜，见下方阶梯价</div>
            @endif

            @if($product->wholesalePrices->isNotEmpty())
            <div class="pdx-sec">
                <h2 class="pdx-label">阶梯价</h2>
                <table class="wholesale-table">
                    <thead><tr><th>数量</th><th>单价</th></tr></thead>
                    <tbody>
                        {{-- 第一行用起购量，不写死「1 件起」：起购 5 件的商品，
                             「1 件起」那一行是买不到的价格。 --}}
                        <tr><td>{{ $min }} 件起</td><td>¥{{ number_format($product->price, 2) }}</td></tr>
                        @foreach($product->wholesalePrices as $wp)
                        <tr><td>{{ $wp->min_quantity }} 件起</td><td>¥{{ number_format($wp->price, 2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            @if(trim(strip_tags($descriptionHtml)) !== '')
            <div class="pdx-sec">
                <h2 class="pdx-label">商品描述</h2>
                <div class="pd-tab-content rich-text">{!! $descriptionHtml !!}</div>
            </div>
            @endif

            <div class="pdx-sec">
                <h2 class="pdx-label">
                    下单
                    @if(!$buyable)
                    <span class="badge badge-danger">暂时无法购买</span>
                    @endif
                </h2>

                @if(!$buyable)
                {{-- 售罄不是把按钮变灰就完事：要说清楚为什么，以及还能做什么。 --}}
                <div class="state" style="padding: var(--sp-6) 0;">
                    <div class="state-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 7h18l-1.5 13H4.5L3 7z"></path><path d="M8 7V5a4 4 0 0 1 8 0v2"></path>
                            <path d="M9 12l6 6M15 12l-6 6"></path>
                        </svg>
                    </div>
                    <div class="state-title">
                        @if($stockCount > 0)
                        库存不足（剩 {{ $stockCount }} 件，起购 {{ $min }} 件）
                        @else
                        该商品已售罄
                        @endif
                    </div>
                    <p class="state-desc">补货时间不定，可以先看看同分类的其它商品。</p>
                    <a href="/category/{{ $product->category->slug ?? '' }}" class="btn btn-secondary">看看同类商品</a>
                </div>
                @else

                <form class="pd-form" action="/order/create" method="POST" data-guard>
                    @csrf
                    <input type="hidden" name="product_id" id="product_id" value="{{ $product->id }}">
                    {{-- front.js 靠这两个隐藏字段算实时总价，id 不能改。 --}}
                    <input type="hidden" id="product-base-price" value="{{ $product->price }}">
                    <input type="hidden" id="wholesale-prices-data" value="{{ $product->wholesalePrices->toJson() }}">

                    <div class="field">
                        <label class="field-label" for="quantity">购买数量<span class="req">*</span></label>
                        <input type="number" name="quantity" id="quantity"
                               class="input @error('quantity') is-invalid @enderror"
                               value="{{ old('quantity', $min) }}"
                               min="{{ $min }}" max="{{ min((int) $product->max_quantity, $stockCount) }}" required>
                        @error('quantity')
                        <div class="field-error">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                            {{ $message }}
                        </div>
                        @else
                        <div class="field-hint">起购 {{ $min }} 件，单次最多 {{ min((int) $product->max_quantity, $stockCount) }} 件</div>
                        @enderror
                    </div>

                    <div class="field">
                        <label class="field-label" for="email">邮箱<span class="req">*</span></label>
                        <input type="email" name="email" id="email"
                               class="input @error('email') is-invalid @enderror"
                               value="{{ old('email') }}" placeholder="接收卡密信息" required>
                        @error('email')
                        <div class="field-error">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                            {{ $message }}
                        </div>
                        @else
                        <div class="field-hint">卡密会同时显示在页面上并发到这个邮箱</div>
                        @enderror
                    </div>

                    <div class="field">
                        <label class="field-label" for="query_password">查询密码<span class="req">*</span></label>
                        <input type="password" name="query_password" id="query_password"
                               class="input @error('query_password') is-invalid @enderror"
                               placeholder="至少 6 位" required>
                        @error('query_password')
                        <div class="field-error">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                            {{ $message }}
                        </div>
                        @else
                        <div class="field-hint">之后凭邮箱 + 这个密码在「查询订单」里找回卡密，请记牢</div>
                        @enderror
                    </div>

                    <div class="field">
                        <label class="field-label" for="coupon_code">优惠码</label>
                        <input type="text" name="coupon_code" id="coupon_code"
                               class="input @error('coupon_code') is-invalid @enderror"
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

                    <div class="field">
                        <span class="field-label">支付方式<span class="req">*</span></span>
                        <div class="pay-options" role="radiogroup" aria-label="支付方式">
                            @foreach($payMethods as $value => $label)
                            <label class="pay-option">
                                <input type="radio" name="payment_method" value="{{ $value }}"
                                       class="pay-radio" @checked($selectedPay === $value) required>
                                <span class="pay-option-inner">
                                    <span class="pay-icon">@themeInclude('partials.pay-icon', ['method' => $value])</span>
                                    <span class="pay-label">{{ $label }}</span>
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
                    <div class="field">
                        <div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}"></div>
                        @error('cf-turnstile-response')
                        <div class="field-error">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16h.01"></path></svg>
                            {{ $message }}
                        </div>
                        @enderror
                    </div>
                    @endif

                    {{-- 结算条。金额是这一屏第二重要的信息（仅次于「买」这个动作本身），
                         所以给它和商品名同一量级的字号，而不是像之前那样比单价还小。 --}}
                    <div class="pd-total">
                        <span class="pd-total-label">应付金额</span>
                        <span id="total-price" class="pd-total-value">¥{{ number_format($product->price * $min, 2) }}</span>
                    </div>

                    <button type="submit" class="btn btn-lg btn-block">立即购买</button>
                </form>
                @endif
            </div>
        </div>
    </section>

    {{-- 购买须知移到面板下方通栏。它不是这次购买的决策依据，是通用条款，
         留在右栏会把「名称 → 价格 → 描述 → 下单」这条竖流拉长。 --}}
    @php $sidebarArticles = \App\Models\Article::published()->recent()->limit(5)->get(); @endphp
    @if($sidebarArticles->isNotEmpty())
    <section class="panel pdx-notes">
        <div class="panel-head">购买须知</div>
        <div class="panel-body">
            <ul class="pd-links">
                @foreach($sidebarArticles as $art)
                <li><a href="/articles/{{ $art->slug }}">{{ $art->title }}</a></li>
                @endforeach
            </ul>
        </div>
    </section>
    @endif

@endsection
