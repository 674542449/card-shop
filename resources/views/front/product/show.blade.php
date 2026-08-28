@extends('layouts.front')

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/product/' . $product->slug))
@section('og_type', 'product')

@php
    // Not `$stockCount > 0`. With min_quantity 5 and 3 cards left, every quantity the
    // form can submit is rejected by the order validator, so rendering the form only
    // offers the buyer a guaranteed failure. Treat it as out of stock — here and in
    // the schema.org availability below, which search results show to shoppers.
    $buyable = $stockCount >= max(1, (int) $product->min_quantity);
@endphp

@section('structured_data')
@php
    // See the note in front/home.blade.php: a literal "@context" in the template is
    // parsed as the @context Blade directive and produces an unclosed if().
    $structuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->name,
        // Fully qualified: this slim skeleton registers no class aliases, so a bare
        // Str:: in a compiled view resolves to the global \Str and fatals. It only
        // fires when seo_description is blank — which is a new product's default
        // state — so it hid behind the ?: on any product that had one.
        'description' => $product->seo_description ?: \Illuminate\Support\Str::limit(strip_tags($descriptionHtml), 200),
        'url' => url('/product/' . $product->slug),
        'offers' => [
            '@type' => 'Offer',
            'priceCurrency' => 'CNY',
            'price' => (string) $product->price,
            'availability' => $buyable
                ? 'https://schema.org/InStock'
                : 'https://schema.org/OutOfStock',
        ],
    ];

    // schema.org/Product accepts "image"; it wants an absolute URL, while
    // $product->image is stored site-relative ("/storage/uploads/..."). Set as an
    // array key rather than written into the markup, for the same reason as above.
    if (!empty($product->image)) {
        $structuredData['image'] = url($product->image);
    }
@endphp
<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@section('content')
    @php $announcement = setting('site_announcement', ''); @endphp
    @if($announcement)
    {{-- See the note in front/home.blade.php: sanitised editor HTML, not raw text. --}}
    <blockquote class="site-quote">{!! \App\Support\ContentRenderer::toHtml($announcement) !!}</blockquote>
    @endif

    <div class="pd-layout">
        {{-- Left: Product Info + Order Form --}}
        <div class="pd-main">
            <div class="pd-info">
                {{--
                    Image sits beside the identity block (badge / stock / title /
                    price), not above it, so the price stays at the top of the
                    viewport on every screen size. .pd-head is a plain flex row:
                    with no image, .pd-head-info simply takes the full width and
                    the block reads exactly as it did before — no reserved gap.
                    The image itself is letterboxed inside a fixed square plate
                    (see .pd-image-wrap) so neither a tall nor a wide upload can
                    stretch the page or distort itself.

                    No width/height attributes here on purpose: the plate is a
                    fixed square, so it already reserves the space and there is no
                    shift to prevent, and hardcoding a ratio we do not know would
                    make the browser reserve the wrong one. Not lazy-loaded either
                    — this is the page's main image, above the fold.
                --}}
                <div class="pd-head">
                    @if($product->image)
                    <div class="pd-image-wrap">
                        <img src="{{ $product->image }}" alt="{{ $product->name }} 商品图"
                             class="pd-image" decoding="async" fetchpriority="high">
                    </div>
                    @endif
                    <div class="pd-head-info">
                        <div class="pd-meta">
                            <span class="badge-auto">自动发货</span>
                            <span class="pd-stock">库存 <strong>{{ $stockCount }}</strong> 件</span>
                        </div>
                        <h2 class="pd-title">{{ $product->name }}</h2>
                        <div class="pd-price-row">
                            <span class="pd-price">¥{{ number_format($product->price, 2) }}</span>
                        </div>
                    </div>
                </div>

                @if($product->wholesalePrices->count() > 0)
                <table class="wholesale-table">
                    <thead>
                        <tr>
                            <th>数量</th>
                            <th>单价</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>1 件起</td>
                            <td>¥{{ number_format($product->price, 2) }}</td>
                        </tr>
                        @foreach($product->wholesalePrices as $wp)
                        <tr>
                            <td>{{ $wp->min_quantity }} 件起</td>
                            <td>¥{{ number_format($wp->price, 2) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif

                @if($buyable)
                <form class="pd-form" action="/order/create" method="POST" data-guard>
                    @csrf
                    <input type="hidden" name="product_id" id="product_id" value="{{ $product->id }}">
                    <input type="hidden" id="product-base-price" value="{{ $product->price }}">
                    <input type="hidden" id="wholesale-prices-data" value="{{ $product->wholesalePrices->toJson() }}">

                    <div class="form-group">
                        <label class="form-label">数量</label>
                        <input type="number" name="quantity" id="quantity" class="form-input"
                               value="{{ old('quantity', $product->min_quantity) }}"
                               min="{{ $product->min_quantity }}"
                               {{-- max_quantity is nullable; min(null, 5) is null in PHP,
                                    which rendered max="" and left the box uncapped. --}}
                               max="{{ $product->max_quantity ? min((int) $product->max_quantity, $stockCount) : $stockCount }}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">邮箱</label>
                        <input type="email" name="email" id="email" class="form-input"
                               value="{{ old('email') }}" required placeholder="接收卡密信息">
                    </div>

                    {{--
                        No .form-input-wrap here. .pd-form .form-group is a flex row and
                        .pd-form .form-input carries flex:1, so the input has to be a
                        DIRECT child of .form-group to stretch. Wrapped, it fell back to
                        its intrinsic width and rendered ~37px narrower than every other
                        field in the column — a permanent visible misalignment.

                        The wrapper only ever existed as the positioning context for a
                        password visibility toggle. Nothing in public/js/front.js creates
                        that toggle and no template renders one, so the wrapper was
                        carrying a feature that does not exist. It and its stylesheet
                        rules are gone.
                    --}}
                    <div class="form-group">
                        <label class="form-label">查询密码</label>
                        <input type="password" name="query_password" id="query_password" class="form-input"
                               minlength="6" required placeholder="至少6位，用于查询订单">
                    </div>

                    <div class="form-group">
                        <label class="form-label">优惠码</label>
                        <input type="text" name="coupon_code" id="coupon_code" class="form-input"
                               value="{{ old('coupon_code') }}" placeholder="可选">
                    </div>

                    @php
                        // CreateOrderRequest requires payment_method, so this field is not
                        // optional — without it every checkout fails validation.
                        $payMethods = [];
                        if (setting('epay_api_url') && setting('epay_merchant_id') && setting('epay_merchant_key')) {
                            $payMethods['alipay'] = '支付宝';
                            $payMethods['wechat'] = '微信';
                        }
                        // Network names only. The Tether mark beside each one already
                        // says USDT, and repeating it five characters at a time is what
                        // pushed the row past the width it has.
                        if (setting('epusdt_api_url') && setting('epusdt_api_token')) {
                            $payMethods['usdt_trc20'] = 'TRC20';
                            $payMethods['usdt_bep20'] = 'BEP20';
                            $payMethods['usdt_polygon'] = 'Polygon';
                        }
                        // Nothing configured yet: still offer the default gateways so the form
                        // stays usable and the operator sees a payment error rather than a
                        // validation error they cannot act on.
                        if (empty($payMethods)) {
                            $payMethods = ['alipay' => '支付宝', 'wechat' => '微信'];
                        }

                        $selectedPay = old('payment_method', array_key_first($payMethods));
                    @endphp

                    {{-- Laid out as visible radio tiles rather than a <select>: every gateway
                         is readable at a glance and one tap selects it, instead of a dropdown
                         that hides the choices behind an extra interaction. The radio is the
                         real control; .pay-option-inner is the tile it draws, styled from the
                         adjacent-sibling checked state so no :has() support is required. --}}
                    <div class="form-group form-group-block">
                        <label class="form-label">支付方式</label>
                        <div class="pay-options" role="radiogroup" aria-label="支付方式">
                            @foreach($payMethods as $value => $label)
                            <label class="pay-option">
                                <input type="radio" name="payment_method" value="{{ $value }}"
                                       class="pay-radio" @checked($selectedPay === $value) required>
                                <span class="pay-option-inner">
                                    <span class="pay-icon">
                                        @include('front.partials.pay-icon', ['method' => $value])
                                    </span>
                                    <span class="pay-label">{{ $label }}</span>
                                </span>
                            </label>
                            @endforeach
                        </div>
                    </div>

                    @if(setting('turnstile_site_key'))
                    {{-- Same wrapper class as the one on front/order/query.blade.php so a
                         single rule indents the widget to the form's 80px label column on
                         both pages instead of two different inline margins. --}}
                    <div class="form-turnstile">
                        <div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}"></div>
                    </div>
                    @endif

                    {{-- Left inline: this row appears on no other page, so pulling it into
                         front.css would buy nothing but would put the buy form's headline
                         figure at the mercy of a rule landing. The classes worth sharing
                         (.form-turnstile, .form-error) are the ones that repeat. --}}
                    <div style="display:flex;justify-content:space-between;align-items:baseline;margin:15px 0 10px;">
                        <span style="color:var(--text-muted);font-size:14px;">应付金额</span>
                        <span id="total-price" style="font-size:22px;font-weight:700;color:var(--price-color);">¥{{ number_format($product->price * $product->min_quantity, 2) }}</span>
                    </div>

                    <button type="submit" class="btn-submit" id="submit-btn">立即购买</button>
                </form>
                @else
                <div class="alert alert-warning mt-2">当前商品暂时缺货，请稍后再来。</div>
                @endif
            </div>

            {{-- Product Description Tabs --}}
            @if($descriptionHtml)
            <div class="pd-tabs">
                <div class="pd-tab-header">
                    <span class="active">商品描述</span>
                </div>
                {{--
                    .rich-text is the shared marker for a block that prints operator
                    HTML from the admin editor unescaped. The article body carries it
                    too (front/article/show.blade.php); those two are the only such
                    blocks on the site, and any third one must carry it as well.

                    It exists because that HTML arrives with hard inline dimensions —
                    wangEditor writes style="width:1600px;height:1200px" and
                    HTMLPurifier passes width/height through — which outrank the
                    stylesheet's `img { height: auto }` and stretch the picture.
                    front.css handles that today by naming .pd-tab-content and
                    .article-detail .content side by side; .rich-text is the single
                    hook those two can collapse into. Keep the page class as well —
                    it is what the existing padding and type rules key on.
                --}}
                <div class="pd-tab-content rich-text">
                    {!! $descriptionHtml !!}
                </div>
            </div>
            @endif
        </div>

        {{-- Right: Sidebar --}}
        <div class="pd-sidebar">
            @php
                $sidebarArticles = \App\Models\Article::published()->recent()->limit(5)->get();
            @endphp
            @if($sidebarArticles->isNotEmpty())
            <div class="sidebar-card">
                <div class="sidebar-card-header">使用教程</div>
                <ul class="sidebar-article-list">
                    @foreach($sidebarArticles as $art)
                    <li>
                        <a href="/articles/{{ $art->slug }}">{{ $art->title }}</a>
                        <div class="date">{{ $art->created_at->format('Y-m-d') }}</div>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif
        </div>
    </div>
@endsection
