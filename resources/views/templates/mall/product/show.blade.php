@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', \App\Support\Seo::url('/product/' . $product->slug))
@section('og_image', (string) $product->image)
@section('og_type', 'product')

@php
    $min = max(1, (int) $product->min_quantity);
    $maxLimit = max($min, (int) $product->max_quantity);
    $buyable = $stockCount >= $min;
    $max = $buyable ? min($maxLimit, $stockCount) : $min;
    $lowThreshold = array_key_exists('low_stock_threshold', $product->getAttributes())
        ? $product->low_stock_threshold
        : 5;
    $lowStock = $buyable && $lowThreshold !== null && $stockCount <= (int) $lowThreshold;
    $payMethods = \App\Support\PaymentMethods::availableForCheckout();
    $selectedPay = old('payment_method', array_key_first($payMethods));
    $safeProductImg = \App\Support\SafeUrl::asset($product->image);
@endphp

@section('structured_data')
{{ \App\Support\Seo::jsonLdScript(\App\Support\Seo::productSchema($product, (string) $seoDescription, $buyable)) }}
@endsection

@section('content')
    <nav class="ml-breadcrumb" aria-label="面包屑">
        <ol>
            <li><a href="/">商城首页</a></li>
            @if($product->category)
            <li><a href="/category/{{ $product->category->slug }}">{{ $product->category->name }}</a></li>
            @endif
            <li><a href="/product/{{ $product->slug }}" aria-current="page">{{ $product->name }}</a></li>
        </ol>
    </nav>

    <div class="ml-pdp-grid">
        <div class="ml-pdp-main">
            <section class="ml-card ml-pdp-hero" aria-label="商品概览">
                <div class="ml-pdp-media">
                    @if($safeProductImg)
                    <img src="{{ $safeProductImg }}" alt="{{ $product->name }}" width="640" height="480" fetchpriority="high" decoding="async">
                    @else
                    @themeInclude('partials.image-placeholder')
                    @endif
                </div>
                <div class="ml-pdp-summary">
                    <div class="ml-pdp-badges">
                        @if($product->category)
                        <a href="/category/{{ $product->category->slug }}" class="ml-badge ml-badge-brand">{{ $product->category->name }}</a>
                        @endif
                        <span class="ml-badge ml-badge-live"><i aria-hidden="true"></i>自动发货</span>
                        @if(!$buyable)
                        <span class="ml-badge ml-badge-danger">{{ $stockCount > 0 ? '库存不足起购量' : '暂时缺货' }}</span>
                        @elseif($lowStock)
                        <span class="ml-badge ml-badge-warn">库存紧张 · 剩 {{ $stockCount }} 件</span>
                        @else
                        <span class="ml-badge ml-badge-neutral">现货 {{ $stockCount }} 件</span>
                        @endif
                    </div>

                    <h1>{{ $product->name }}</h1>

                    <div class="ml-pdp-price-bar">
                        <span class="ml-pdp-price-label">商城价</span>
                        <div class="ml-pdp-price-val">
                            <small>¥</small><strong>{{ number_format($product->price, 2) }}</strong><span>/ 件</span>
                        </div>
                        @if($product->wholesalePrices->isNotEmpty())
                        <span class="ml-badge ml-badge-warn">多件享阶梯优惠</span>
                        @endif
                    </div>

                    <dl class="ml-spec-grid">
                        <div><dt>可用库存</dt><dd>{{ $stockCount }} 件</dd></div>
                        <div><dt>起购 / 限购</dt><dd>{{ $min }}–{{ $maxLimit }} 件</dd></div>
                        <div><dt>交付方式</dt><dd>在线提取 + 邮件</dd></div>
                    </dl>
                </div>
            </section>

            @if($product->wholesalePrices->isNotEmpty())
            <section class="ml-card ml-pdp-section" aria-labelledby="ml-wholesale-title">
                <div class="ml-pdp-section-head">
                    <h2 id="ml-wholesale-title">阶梯批发价格</h2>
                    <span class="ml-muted">系统按购买数量自动核算最优单价</span>
                </div>
                <table class="ml-tier-table wholesale-table">
                    <thead><tr><th scope="col">购买数量</th><th scope="col">单件优惠价</th></tr></thead>
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
            <section class="ml-card ml-pdp-section" aria-labelledby="ml-desc-title">
                <div class="ml-pdp-section-head">
                    <h2 id="ml-desc-title">商品详情与使用说明</h2>
                </div>
                <div class="ml-rich">{!! $descriptionHtml !!}</div>
            </section>
            @endif

            <div class="ml-assurance-bar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1" stroke-linecap="round"/></svg>
                <p>付款成功后系统立即自动发卡；请妥善牢记下单邮箱与查询密码，关闭页面后可随时前往 <a href="/order/query">订单查询</a> 找回卡密。</p>
            </div>
        </div>

        <aside class="ml-pdp-aside" aria-labelledby="ml-checkout-title">
            <section class="ml-card ml-buybox" id="ml-checkout">
                <header class="ml-buybox-head">
                    <h2 id="ml-checkout-title">{{ $buyable ? '立即下单' : '商品暂不可售' }}</h2>
                    <p class="ml-muted">{{ $buyable ? '填写接收邮箱并选择支付方式即可自动发卡' : '库存补充后将自动恢复购买' }}</p>
                </header>

                @if(!$buyable)
                <div class="ml-empty ml-buybox-empty">
                    @themeInclude('partials.image-placeholder')
                    <h3>{{ $stockCount > 0 ? '库存低于起购数量' : '当前商品已售罄' }}</h3>
                    <p>当前可用库存 {{ $stockCount }} 件，本商品需至少购买 {{ $min }} 件。</p>
                    <a href="{{ $product->category ? '/category/' . $product->category->slug : '/' }}" class="ml-btn ml-btn-secondary ml-btn-wide">逛逛其他商品 →</a>
                </div>
                @else
                <form action="/order/create" method="POST" class="ml-form" data-guard>
                    @csrf
                    <input type="hidden" name="checkout_key" value="{{ app(\App\Services\CheckoutIntentService::class)->issue($product->id) }}" data-intent-product="{{ $product->id }}">
                    <div class="ml-sr-only" aria-hidden="true"><label for="ml-hp-website">请勿填写</label><input type="text" id="ml-hp-website" name="website" value="" tabindex="-1" autocomplete="off"></div>
                    <input type="hidden" name="product_id" id="product_id" value="{{ $product->id }}">
                    <input type="hidden" id="product-base-price" value="{{ $product->price }}">
                    <input type="hidden" id="wholesale-prices-data" value="{{ $product->wholesalePrices->toJson() }}">

                    <div class="ml-field">
                        <label class="ml-label" for="quantity">购买数量</label>
                        <div class="ml-qty">
                            <button type="button" data-qty-step="-1" aria-label="减少数量">−</button>
                            <input type="number" name="quantity" id="quantity" value="{{ old('quantity', $min) }}" min="{{ $min }}" max="{{ $max }}" step="1" required aria-describedby="ml-qty-hint" @error('quantity') aria-invalid="true" @enderror>
                            <button type="button" data-qty-step="1" aria-label="增加数量">+</button>
                        </div>
                        @error('quantity')<p class="ml-field-error" role="alert">{{ $message }}</p>@enderror
                        <p class="ml-hint" id="ml-qty-hint">{{ $min }} 件起购 · 单次最多 {{ $max }} 件</p>
                    </div>

                    <div class="ml-field">
                        <label class="ml-label" for="email">接收邮箱</label>
                        <input type="email" name="email" id="email" maxlength="200" class="ml-input" value="{{ old('email') }}" placeholder="you@example.com" autocomplete="email" required aria-describedby="ml-email-hint" @error('email') aria-invalid="true" @enderror>
                        @error('email')<p class="ml-field-error" role="alert">{{ $message }}</p>@enderror
                        <p class="ml-hint" id="ml-email-hint">卡密将自动发送至此邮箱，并作为查单凭证。</p>
                    </div>

                    <div class="ml-field">
                        <label class="ml-label" for="query_password">查询密码</label>
                        <input type="password" name="query_password" id="query_password" class="ml-input" placeholder="至少 6 位，用于查单提取" autocomplete="new-password" minlength="6" maxlength="50" required aria-describedby="ml-pass-hint" @error('query_password') aria-invalid="true" @enderror>
                        @error('query_password')<p class="ml-field-error" role="alert">{{ $message }}</p>@enderror
                        <p class="ml-hint" id="ml-pass-hint">自定义密码，后续凭邮箱与该密码找回卡密。</p>
                    </div>

                    <div class="ml-field">
                        <label class="ml-label" for="coupon_code">优惠码 <span class="ml-muted">选填</span></label>
                        <div class="ml-coupon-row">
                            <input type="text" name="coupon_code" id="coupon_code" maxlength="50" class="ml-input" value="{{ old('coupon_code') }}" placeholder="输入优惠码" aria-describedby="ml-coupon-hint" @error('coupon_code') aria-invalid="true" @enderror>
                            <button type="button" class="ml-btn ml-btn-secondary ml-btn-sm" data-checkout-quote>试算优惠</button>
                        </div>
                        @error('coupon_code')<p class="ml-field-error" role="alert">{{ $message }}</p>@enderror
                        <p class="ml-hint" id="ml-coupon-hint" data-quote-status data-default-note="试算不占用库存或优惠券次数。" role="status" aria-live="polite">试算不占用库存或优惠券次数。</p>
                    </div>

                    <fieldset class="ml-pay-fieldset">
                        <legend class="ml-label">选择支付方式</legend>
                        <div class="ml-pay-grid">
                            @foreach($payMethods as $value => $label)
                            <label class="ml-pay-choice">
                                <input type="radio" name="payment_method" value="{{ $value }}" @checked($selectedPay === $value) required>
                                <span class="ml-pay-face">
                                    @themeInclude('partials.pay-icon', ['method' => $value])
                                    <strong>{{ $label }}</strong>
                                    <svg class="ml-pay-check" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m3 8 3 3 7-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                            </label>
                            @endforeach
                        </div>
                        @error('payment_method')<p class="ml-field-error" role="alert">{{ $message }}</p>@enderror
                    </fieldset>

                    @if(turnstile_site_key())
                    <div class="ml-field">
                        <div class="cf-turnstile" data-sitekey="{{ turnstile_site_key() }}" data-size="compact"></div>
                        @error('turnstile')<p class="ml-field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    @endif

                    <div class="ml-total-box" aria-live="polite" aria-atomic="true">
                        <div><span>核算单价</span><strong id="unit-price">¥{{ number_format($product->price, 2) }}</strong></div>
                        <div class="ml-total-final"><span data-checkout-total-label data-default-label="预计应付">预计应付</span><strong id="total-price">¥{{ number_format($product->price * $min, 2) }}</strong></div>
                    </div>

                    <button type="submit" class="ml-btn ml-btn-primary ml-btn-wide ml-btn-lg">立即下单支付 →</button>
                </form>
                @endif
            </section>
        </aside>
    </div>

    @if($buyable)
    <div class="ml-mobile-buybar">
        <div><span>单价</span><strong>¥{{ number_format($product->price, 2) }}</strong></div>
        <a href="#ml-checkout" class="ml-btn ml-btn-primary ml-btn-sm">立即下单 →</a>
    </div>
    @endif
@endsection
