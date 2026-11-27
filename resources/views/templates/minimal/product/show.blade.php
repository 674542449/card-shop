@extends(theme_view_path('layout'))
@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', \App\Support\Seo::url('/product/' . $product->slug))
@section('og_image', (string) $product->image)
@section('og_type', 'product')
@php
    $min = max(1, (int) $product->min_quantity);
    $max = min((int) $product->max_quantity, $stockCount);
    $buyable = $stockCount >= $min;
    $payMethods = \App\Support\PaymentMethods::availableForCheckout();
    $selectedPay = old('payment_method', array_key_first($payMethods));
    $safeProductImg = \App\Support\SafeUrl::asset($product->image);
@endphp
@section('structured_data')
{{ \App\Support\Seo::jsonLdScript(\App\Support\Seo::productSchema($product, (string) $seoDescription, $buyable)) }}
@endsection
@section('content')
<nav class="n-breadcrumb" aria-label="面包屑">
    <a href="/">商品目录</a><span aria-hidden="true">/</span>
    @if($product->category)<a href="/category/{{ $product->category->slug }}">{{ $product->category->name }}</a><span aria-hidden="true">/</span>@endif
    <span aria-current="page">{{ $product->name }}</span>
</nav>

<header class="n-product-hero">
    <div class="n-product-hero-image">
        @if($safeProductImg)<img src="{{ $safeProductImg }}" alt="{{ $product->name }} 商品图" decoding="async" fetchpriority="high">@else @themeInclude('partials.image-placeholder') @endif
    </div>
    <div class="n-product-hero-info">
        <span class="n-kicker">{{ $product->category->name ?? '数字商品' }}</span>
        <h1>{{ $product->name }}</h1>
        <div class="n-product-facts">
            <span class="n-status {{ $buyable ? 'n-status-paid' : 'n-status-closed' }}"><span aria-hidden="true"></span>{{ $buyable ? '库存 ' . $stockCount . ' 件' : ($stockCount > 0 ? '库存不足 · 剩 ' . $stockCount . ' 件' : '已售罄') }}</span>
            <span>{{ $min }} 件起购</span>
            <span>支付后自动发货</span>
        </div>
    </div>
    <div class="n-product-hero-price">
        <span>商品单价</span>
        <strong>¥{{ number_format($product->price, 2) }}</strong>
        <small>/ 件</small>
    </div>
</header>

<div class="n-product-workspace">
    <div class="n-product-notes">
        @if($product->wholesalePrices->isNotEmpty())
        <section class="n-product-info-section">
            <div class="n-section-head"><h2>阶梯优惠</h2><span class="n-hint">按数量自动匹配单价</span></div>
            <table class="n-pricing-table wholesale-table">
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
        <section class="n-product-info-section">
            <div class="n-section-head"><h2>商品说明</h2></div>
            <div class="n-rich n-product-description">{!! $descriptionHtml !!}</div>
        </section>
        @endif

        <div class="n-delivery-strip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1" stroke-linecap="round"/></svg>
            <p>支付成功后系统自动发放卡密并发送至接收邮箱；后续也可随时凭邮箱和查询密码前往 <a href="/order/query">订单查询</a> 提取。</p>
        </div>
    </div>

    <aside class="n-checkout-column">
        <section class="n-panel n-checkout" id="n-checkout" aria-labelledby="n-checkout-title">
            <header>
                <h2 id="n-checkout-title">{{ $buyable ? '创建订单' : '暂时无法购买' }}</h2>
                <p class="n-hint">{{ $buyable ? '填写接收信息并选择支付方式。' : '库存补充后即可再次购买。' }}</p>
            </header>
            @if(!$buyable)
            <div class="n-empty n-stock-empty">
                @themeInclude('partials.image-placeholder')
                <h3>{{ $stockCount > 0 ? '未达到起购数量' : '商品已售罄' }}</h3>
                <p>剩余 {{ $stockCount }} 件，需至少购买 {{ $min }} 件。</p>
                <a href="{{ $product->category ? '/category/' . $product->category->slug : '/' }}" class="n-button n-button-secondary n-button-wide">看看其他商品 →</a>
            </div>
            @else
            <form action="/order/create" method="POST" class="n-form" data-guard>
                @csrf
                <input type="hidden" name="checkout_key" value="{{ app(\App\Services\CheckoutIntentService::class)->issue($product->id) }}" data-intent-product="{{ $product->id }}">
                <div class="n-sr" aria-hidden="true"><label for="n-hp-website">请勿填写</label><input type="text" id="n-hp-website" name="website" value="" tabindex="-1" autocomplete="off"></div>
                <input type="hidden" name="product_id" id="product_id" value="{{ $product->id }}">
                <input type="hidden" id="product-base-price" value="{{ $product->price }}">
                <input type="hidden" id="wholesale-prices-data" value="{{ $product->wholesalePrices->toJson() }}">

                <div class="n-field">
                    <label for="quantity">购买数量</label>
                    <div class="n-quantity">
                        <button type="button" data-qty-step="-1" aria-label="减少数量">−</button>
                        <input type="number" id="quantity" name="quantity" value="{{ old('quantity', $min) }}" min="{{ $min }}" max="{{ $max }}" step="1" required aria-describedby="n-quantity-hint" @error('quantity') aria-invalid="true" @enderror>
                        <button type="button" data-qty-step="1" aria-label="增加数量">+</button>
                    </div>
                    @error('quantity')<p class="n-field-error" role="alert">{{ $message }}</p>@enderror
                    <p class="n-hint" id="n-quantity-hint">{{ $min }} 件起购 · 单次最多 {{ $max }} 件</p>
                </div>

                <div class="n-field">
                    <label for="email">接收邮箱</label>
                    <input type="email" name="email" id="email" maxlength="200" class="n-input" value="{{ old('email') }}" placeholder="you@example.com" autocomplete="email" required aria-describedby="n-email-hint" @error('email') aria-invalid="true" @enderror>
                    @error('email')<p class="n-field-error" role="alert">{{ $message }}</p>@enderror
                    <p class="n-hint" id="n-email-hint">用于接收卡密与查询订单。</p>
                </div>

                <div class="n-field">
                    <label for="query_password">查询密码</label>
                    <input type="password" name="query_password" id="query_password" class="n-input" placeholder="至少 6 位，请妥善保存" autocomplete="new-password" minlength="6" maxlength="50" required aria-describedby="n-password-hint" @error('query_password') aria-invalid="true" @enderror>
                    @error('query_password')<p class="n-field-error" role="alert">{{ $message }}</p>@enderror
                    <p class="n-hint" id="n-password-hint">后续凭下单邮箱与该密码找回卡密。</p>
                </div>

                <details class="n-coupon-disclosure" @if(old('coupon_code') || $errors->has('coupon_code')) open @endif>
                    <summary class="n-coupon-summary">
                        <span>使用优惠码 <small>选填</small></span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                    </summary>
                    <div class="n-coupon-body">
                        <label class="n-sr" for="coupon_code">优惠码（选填）</label>
                        <div class="n-coupon-row">
                            <input type="text" name="coupon_code" id="coupon_code" maxlength="50" class="n-input" value="{{ old('coupon_code') }}" placeholder="输入优惠码" autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="n-coupon-hint" @error('coupon_code') aria-invalid="true" @enderror>
                            <button type="button" class="n-button n-button-secondary n-button-sm" data-checkout-quote>试算优惠</button>
                        </div>
                        @error('coupon_code')<p class="n-field-error" role="alert">{{ $message }}</p>@enderror
                        <p class="n-hint" id="n-coupon-hint" data-quote-status role="status" aria-live="polite">可试算优惠，不占用库存或优惠次数。</p>
                    </div>
                </details>

                <fieldset class="n-payment-options">
                    <legend>支付方式</legend>
                    <div>
                        @foreach($payMethods as $value => $label)
                        <label class="n-payment-choice">
                            <input type="radio" name="payment_method" value="{{ $value }}" @checked($selectedPay === $value) required>
                            <span>@themeInclude('partials.pay-icon', ['method' => $value])<strong>{{ $label }}</strong><i aria-hidden="true"></i></span>
                        </label>
                        @endforeach
                    </div>
                    @error('payment_method')<p class="n-field-error" role="alert">{{ $message }}</p>@enderror
                </fieldset>

                @if(turnstile_site_key())
                <div class="n-field">
                    <div class="cf-turnstile" data-sitekey="{{ turnstile_site_key() }}" data-size="compact"></div>
                    @error('turnstile')<p class="n-field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                @endif

                <div class="n-checkout-total" aria-live="polite" aria-atomic="true">
                    <div><span>当前单价</span><strong id="unit-price">¥{{ number_format($product->price, 2) }}</strong></div>
                    <div><span data-checkout-total-label>商品小计</span><strong id="total-price">¥{{ number_format($product->price * $min, 2) }}</strong></div>
                </div>
                <button type="submit" class="n-button n-button-primary n-button-wide">创建订单，继续付款 <span aria-hidden="true">→</span></button>
            </form>
            @endif
        </section>
    </aside>
</div>

@if($buyable)
<div class="n-mobile-purchase">
    <div><span>商品单价</span><strong>¥{{ number_format($product->price, 2) }}</strong></div>
    <a href="#n-checkout" class="n-button n-button-primary n-button-sm">填写购买信息 →</a>
</div>
@endif
@endsection
