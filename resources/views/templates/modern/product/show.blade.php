@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', \App\Support\Seo::url('/product/' . $product->slug))
@section('og_image', (string) $product->image)

@php
    $min = max(1, (int) $product->min_quantity);
    $max = min((int) $product->max_quantity, $stockCount);
    $buyable = $stockCount >= $min;
    $lowThreshold = array_key_exists('low_stock_threshold', $product->getAttributes())
        ? $product->low_stock_threshold
        : 5;
    $lowStock = $buyable && $lowThreshold !== null && $stockCount <= (int) $lowThreshold;
    $payMethods = \App\Support\PaymentMethods::availableForCheckout();
    $selectedPay = old('payment_method', array_key_first($payMethods));
    $safeProductImg = \App\Support\SafeUrl::asset($product->image);
@endphp

@section('structured_data')
@php
    $productData = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->name,
        'description' => $seoDescription,
        'sku' => (string) $product->id,
        'offers' => [
            '@type' => 'Offer',
            'price' => number_format($product->price, 2, '.', ''),
            'priceCurrency' => 'CNY',
            'availability' => $buyable ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
            'url' => \App\Support\Seo::url('/product/' . $product->slug),
        ],
    ];
    if ($productImgUrl = \App\Support\Seo::assetUrl($product->image)) {
        $productData['image'] = $productImgUrl;
    }
@endphp
<script type="application/ld+json">{!! json_encode($productData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@section('content')
<nav class="m-breadcrumb" aria-label="面包屑">
    <a href="/">全部商品</a><span aria-hidden="true">/</span>
    @if($product->category)
    <a href="/category/{{ $product->category->slug }}">{{ $product->category->name }}</a><span aria-hidden="true">/</span>
    @endif
    <span aria-current="page">{{ $product->name }}</span>
</nav>

<div class="m-product-layout">
    <div class="m-product-overview">
        <header class="m-panel m-product-head-card">
            <div class="m-product-showcase">
                @if($safeProductImg)
                <img src="{{ $safeProductImg }}" alt="{{ $product->name }} 商品图" decoding="async" fetchpriority="high">
                @else
                @themeInclude('partials.image-placeholder')
                @endif
            </div>
            <div class="m-product-heading">
                <div class="m-eyebrow">{{ $product->category->name ?? '数字商品' }} · 自动发货</div>
                <h1 class="m-product-title">{{ $product->name }}</h1>
                <div class="m-product-facts">
                    @if($buyable)
                    <span class="m-pill {{ $lowStock ? 'm-pill-muted' : 'm-pill-success' }}">
                        <span class="m-product-stock-dot" aria-hidden="true"></span>
                        {{ $lowStock ? '库存紧张' : '现货充足' }} · {{ $stockCount }} 件
                    </span>
                    @else
                    <span class="m-pill m-pill-danger">{{ $stockCount > 0 ? '库存不足 · 剩 ' . $stockCount . ' 件' : '已售罄' }}</span>
                    @endif
                    <span class="m-muted">{{ $min }} 件起购</span>
                </div>
                <div class="m-product-price"><span>¥{{ number_format($product->price, 2) }}</span><small>/ 件</small></div>
            </div>
        </header>

        @if($product->wholesalePrices->isNotEmpty())
        <section class="m-product-section" aria-labelledby="m-product-pricing-title">
            <div class="m-product-section-head">
                <h2 class="m-section-title" id="m-product-pricing-title">阶梯优惠</h2>
                <span class="m-hint">按购买数量自动匹配单价</span>
            </div>
            <table class="m-product-pricing wholesale-table">
                <thead><tr><th scope="col">购买数量</th><th scope="col">每件单价</th></tr></thead>
                <tbody>
                    <tr data-min-qty="{{ $min }}"><td>{{ $min }} 件起</td><td>¥{{ number_format($product->price, 2) }}</td></tr>
                    @foreach($product->wholesalePrices as $wp)
                    <tr data-min-qty="{{ $wp->min_quantity }}"><td>{{ $wp->min_quantity }} 件起</td><td>¥{{ number_format($wp->price, 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        @endif

        @if(trim($descriptionHtml) !== '')
        <section class="m-product-section" aria-labelledby="m-product-description-title">
            <div class="m-product-section-head">
                <h2 class="m-section-title" id="m-product-description-title">商品说明</h2>
            </div>
            <div class="m-rich-text m-product-description">{!! $descriptionHtml !!}</div>
        </section>
        @endif

        <div class="m-product-reminder">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1" stroke-linecap="round"/></svg>
            <p>付款成功后自动发放卡密并同步发送至接收邮箱；关闭页面后也可随时凭邮箱和查询密码在 <a href="/order/query">订单查询</a> 中找回。</p>
        </div>
    </div>

    <aside class="m-product-buy-column" aria-labelledby="m-product-buy-title">
        <section class="m-panel m-product-buy-panel" id="m-product-buy">
            <div class="m-product-buy-heading">
                <h2 id="m-product-buy-title">{{ $buyable ? '提交订单' : '暂时无法购买' }}</h2>
                <p class="m-hint">{{ $buyable ? '填写接收信息并选择支付方式。' : '库存补充后即可再次购买。' }}</p>
            </div>
            @if(!$buyable)
            <div class="m-empty m-product-unavailable">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M4 7h16l-1 13H5L4 7Z"/><path d="M8 7V5a4 4 0 0 1 8 0v2m-7 5 6 6m0-6-6 6" stroke-linecap="round"/></svg>
                <h3>{{ $stockCount > 0 ? '库存未达到起购数量' : '这件商品已售罄' }}</h3>
                <p>当前剩余 {{ $stockCount }} 件，需至少购买 {{ $min }} 件。</p>
                <a href="{{ $product->category ? '/category/' . $product->category->slug : '/' }}" class="m-button m-button-secondary m-button-wide">浏览其他商品</a>
            </div>
            @else
            <form action="/order/create" method="POST" class="m-form m-product-form" data-guard>
                @csrf
                <input type="hidden" name="checkout_key" value="{{ app(\App\Services\CheckoutIntentService::class)->issue($product->id) }}" data-intent-product="{{ $product->id }}">
                <div class="m-sr-only" aria-hidden="true"><label for="m-hp-website">请勿填写</label><input type="text" id="m-hp-website" name="website" value="" tabindex="-1" autocomplete="off"></div>
                <input type="hidden" name="product_id" id="product_id" value="{{ $product->id }}">
                <input type="hidden" id="product-base-price" value="{{ $product->price }}">
                <input type="hidden" id="wholesale-prices-data" value="{{ $product->wholesalePrices->toJson() }}">

                <div class="m-field">
                    <label class="m-label" for="quantity">购买数量</label>
                    <div class="m-product-quantity">
                        <button type="button" data-qty-step="-1" aria-label="减少数量">−</button>
                        <input type="number" name="quantity" id="quantity" value="{{ old('quantity', $min) }}" min="{{ $min }}" max="{{ $max }}" required step="1" aria-describedby="m-product-quantity-hint" @error('quantity') aria-invalid="true" @enderror>
                        <button type="button" data-qty-step="1" aria-label="增加数量">+</button>
                    </div>
                    @error('quantity')<p class="m-field-error" id="m-product-quantity-hint" role="alert">{{ $message }}</p>@else<p class="m-hint" id="m-product-quantity-hint">{{ $min }} 件起购 · 单次最多 {{ $max }} 件</p>@enderror
                </div>

                <div class="m-field">
                    <label class="m-label" for="email">接收邮箱</label>
                    <input type="email" name="email" id="email" maxlength="200" class="m-input" value="{{ old('email') }}" placeholder="you@example.com" autocomplete="email" required aria-describedby="m-product-email-hint" @error('email') aria-invalid="true" @enderror>
                    @error('email')<p class="m-field-error" id="m-product-email-hint" role="alert">{{ $message }}</p>@else<p class="m-hint" id="m-product-email-hint">用于接收卡密和查询订单。</p>@enderror
                </div>

                <div class="m-field">
                    <label class="m-label" for="query_password">查询密码</label>
                    <input type="password" name="query_password" id="query_password" class="m-input" placeholder="设置至少 6 位密码" autocomplete="new-password" minlength="6" maxlength="50" required aria-describedby="m-product-password-hint" @error('query_password') aria-invalid="true" @enderror>
                    @error('query_password')<p class="m-field-error" id="m-product-password-hint" role="alert">{{ $message }}</p>@else<p class="m-hint" id="m-product-password-hint">请记住该密码，后续可凭它找回卡密。</p>@enderror
                </div>

                <div class="m-field">
                    <label class="m-label" for="coupon_code">优惠码 <span class="m-muted">· 选填</span></label>
                    <div class="m-coupon-row">
                        <input type="text" name="coupon_code" id="coupon_code" maxlength="50" class="m-input" value="{{ old('coupon_code') }}" placeholder="输入优惠码" aria-describedby="m-product-coupon-hint" @error('coupon_code') aria-invalid="true" @enderror>
                        <button type="button" class="m-button m-button-secondary m-button-sm" data-checkout-quote>试算优惠</button>
                    </div>
                    @error('coupon_code')<p class="m-field-error" id="m-product-coupon-hint" role="alert">{{ $message }}</p>@enderror
                    <p class="m-hint" id="m-product-coupon-hint" data-quote-status role="status" aria-live="polite">可试算优惠，不占用库存或优惠次数。</p>
                </div>

                <fieldset class="m-product-payments">
                    <legend class="m-label">支付方式</legend>
                    <div class="m-product-payment-grid">
                        @foreach($payMethods as $value => $label)
                        <label class="m-product-payment-choice">
                            <input type="radio" name="payment_method" value="{{ $value }}" @checked($selectedPay === $value) required>
                            <span class="m-product-payment-face"><span class="m-product-payment-icon">@themeInclude('partials.pay-icon', ['method' => $value])</span><span>{{ $label }}</span><svg class="m-product-payment-check" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m3 8 3 3 7-7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                        </label>
                        @endforeach
                    </div>
                    @error('payment_method')<p class="m-field-error" role="alert">{{ $message }}</p>@enderror
                </fieldset>

                @if(turnstile_site_key())
                <div class="m-field m-product-turnstile">
                    <div class="cf-turnstile" data-sitekey="{{ turnstile_site_key() }}" data-size="compact"></div>
                    @error('turnstile')<p class="m-field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                @endif

                <div class="m-product-summary" aria-live="polite" aria-atomic="true">
                    <div><span>当前单价</span><strong id="unit-price">¥{{ number_format($product->price, 2) }}</strong></div>
                    <div class="m-product-summary-total"><span data-checkout-total-label>商品小计</span><strong id="total-price">¥{{ number_format($product->price * $min, 2) }}</strong></div>
                </div>
                <button type="submit" class="m-button m-button-primary m-button-wide m-product-submit"><span>立即购买</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
            </form>
            @endif
        </section>
    </aside>
</div>

@if($buyable)
<div class="m-product-mobile-bar">
    <div><span class="m-hint">商品单价</span><strong>¥{{ number_format($product->price, 2) }}<small>/ 件</small></strong></div>
    <a href="#m-product-buy" class="m-button m-button-primary m-button-sm">填写购买信息 <span aria-hidden="true">↗</span></a>
</div>
@endif
@endsection
