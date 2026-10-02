@extends(theme_view_path('layout'))
@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/product/' . $product->slug))
@php
    $min = max(1, (int) $product->min_quantity);
    $max = min((int) $product->max_quantity, $stockCount);
    $buyable = $stockCount >= $min;
    $payMethods = [];
    if (setting('epay_api_url') && setting('epay_merchant_id') && setting('epay_merchant_key')) { $payMethods['alipay'] = '支付宝'; $payMethods['wechat'] = '微信'; }
    if (setting('epusdt_api_url') && setting('epusdt_api_token')) { $payMethods['usdt_trc20'] = 'USDT · TRC20'; $payMethods['usdt_bep20'] = 'USDT · BEP20'; $payMethods['usdt_polygon'] = 'USDT · Polygon'; }
    if (empty($payMethods)) { $payMethods = ['alipay' => '支付宝', 'wechat' => '微信']; }
    foreach (array_keys($payMethods) as $method) {
        if (!in_array($method, \App\Support\PaymentMethods::supported(), true)) { unset($payMethods[$method]); }
    }
    if (isset($payMethods['usdt_trc20']) && setting('usdt_gateway', 'epusdt') !== 'bepusdt') { $payMethods['usdt_trc20'] = 'USDT · 网关默认网络'; }
    $selectedPay = old('payment_method', array_key_first($payMethods));
@endphp
@section('structured_data')
@php
    $productData = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $product->name, 'description' => $seoDescription, 'sku' => (string) $product->id, 'offers' => ['@type' => 'Offer', 'price' => number_format($product->price, 2, '.', ''), 'priceCurrency' => 'CNY', 'availability' => $buyable ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock', 'url' => url('/product/' . $product->slug)]];
    if ($product->image) { $productData['image'] = url($product->image); }
@endphp
<script type="application/ld+json">{!! json_encode($productData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
@section('content')
<nav class="n-breadcrumb" aria-label="面包屑"><a href="/">商品目录</a><span aria-hidden="true">/</span>@if($product->category)<a href="/category/{{ $product->category->slug }}">{{ $product->category->name }}</a><span aria-hidden="true">/</span>@endif<span aria-current="page">商品详情</span></nav>
<header class="n-product-hero">
    <div class="n-product-hero-image">@if($product->image)<img src="{{ $product->image }}" alt="{{ $product->name }} 商品图" decoding="async" fetchpriority="high">@else @themeInclude('partials.image-placeholder') @endif</div>
    <div class="n-product-hero-info"><span class="n-kicker">{{ $product->category->name ?? '数字商品' }}</span><h1>{{ $product->name }}</h1><div class="n-product-facts"><span class="n-status {{ $buyable ? 'n-status-paid' : 'n-status-expired' }}"><span aria-hidden="true"></span>{{ $buyable ? '库存 ' . $stockCount . ' 件' : ($stockCount > 0 ? '库存不足 · 剩 ' . $stockCount . ' 件' : '已售罄') }}</span><span>{{ $min }} 件起购</span><span>支付后自动发货</span></div></div>
    <div class="n-product-hero-price"><span>商品单价</span><strong>¥{{ number_format($product->price, 2) }}</strong><small>/ 件</small></div>
</header>
<div class="n-product-workspace">
    <div class="n-product-notes">
        @if(trim($descriptionHtml) !== '')<section class="n-product-info-section"><div class="n-section-head"><h2>商品说明</h2><span class="n-kicker">DETAILS</span></div><div class="n-rich n-product-description">{!! $descriptionHtml !!}</div></section>@endif
        @if($product->wholesalePrices->isNotEmpty())<section class="n-product-info-section"><div class="n-section-head"><h2>数量优惠</h2><span class="n-hint">自动匹配对应单价</span></div><table class="n-pricing-table wholesale-table"><thead><tr><th scope="col">购买数量</th><th scope="col">每件单价</th></tr></thead><tbody><tr data-min-qty="{{ $min }}"><td>{{ $min }} 件起</td><td>¥{{ number_format($product->price, 2) }}</td></tr>@foreach($product->wholesalePrices as $wp)<tr data-min-qty="{{ $wp->min_quantity }}"><td>{{ $wp->min_quantity }} 件起</td><td>¥{{ number_format($wp->price, 2) }}</td></tr>@endforeach</tbody></table></section>@endif
        <section class="n-product-info-section"><h2>如何领取</h2><ol class="n-product-how"><li><span>01</span><div><strong>设置接收信息</strong><p>填写接收邮箱，设置至少 6 位查询密码。</p></div></li><li><span>02</span><div><strong>确认并支付订单</strong><p>检查数量、金额与支付方式，在有效期内完成付款。</p></div></li><li><span>03</span><div><strong>查看、复制与下载</strong><p>支付成功后，在订单页领取卡密；之后仍可通过<a href="/order/query">订单查询</a>取回。</p></div></li></ol></section>
    </div>
    <aside class="n-checkout-column">
        <section class="n-panel n-checkout" id="n-checkout" aria-labelledby="n-checkout-title"><header><span class="n-kicker">PURCHASE</span><h2 id="n-checkout-title">{{ $buyable ? '创建订单' : '暂时无法购买' }}</h2><p class="n-hint">{{ $buyable ? '填写信息，下一步确认金额并付款。' : '库存补充后即可再次购买。' }}</p></header>
        @if(!$buyable)<div class="n-empty n-stock-empty">@themeInclude('partials.image-placeholder')<h3>{{ $stockCount > 0 ? '未达到起购数量' : '商品已售罄' }}</h3><p>剩余 {{ $stockCount }} 件，需至少购买 {{ $min }} 件。</p><a href="{{ $product->category ? '/category/' . $product->category->slug : '/' }}" class="n-button n-button-secondary n-button-wide">看看其他商品 →</a></div>
        @else
        <form action="/order/create" method="POST" class="n-form" data-guard>
            @csrf
                <input type="hidden" name="checkout_key" value="{{ old('checkout_key', app(\App\Services\CheckoutIntentService::class)->issue($product->id)) }}">
            <input type="hidden" name="product_id" id="product_id" value="{{ $product->id }}"><input type="hidden" id="product-base-price" value="{{ $product->price }}"><input type="hidden" id="wholesale-prices-data" value="{{ $product->wholesalePrices->toJson() }}">
            <div class="n-field"><label for="quantity">购买数量</label><div class="n-quantity"><button type="button" data-qty-step="-1" aria-label="减少数量">−</button><input type="number" id="quantity" name="quantity" value="{{ old('quantity', $min) }}" min="{{ $min }}" max="{{ $max }}" step="1" required aria-describedby="n-quantity-hint" @error('quantity') aria-invalid="true" @enderror><button type="button" data-qty-step="1" aria-label="增加数量">+</button></div>@error('quantity')<p class="n-field-error">{{ $message }}</p>@enderror<p class="n-hint" id="n-quantity-hint">{{ $min }} 件起购 · 单次最多 {{ $max }} 件</p></div>
            <div class="n-field"><label for="email">接收邮箱</label><input type="email" name="email" id="email" maxlength="200" class="n-input" value="{{ old('email') }}" placeholder="you@example.com" autocomplete="email" required aria-describedby="n-email-hint" @error('email') aria-invalid="true" @enderror>@error('email')<p class="n-field-error">{{ $message }}</p>@enderror<p class="n-hint" id="n-email-hint">用于接收卡密与查询订单。</p></div>
            <div class="n-field"><label for="query_password">查询密码</label><input type="password" name="query_password" id="query_password" class="n-input" placeholder="至少 6 位，请妥善保存" autocomplete="new-password" minlength="6" maxlength="50" required aria-describedby="n-password-hint" @error('query_password') aria-invalid="true" @enderror>@error('query_password')<p class="n-field-error">{{ $message }}</p>@enderror<p class="n-hint" id="n-password-hint">之后凭下单邮箱与该密码找回卡密。</p></div>
            <div class="n-field"><label for="coupon_code">优惠码 <span>选填</span></label><input type="text" name="coupon_code" id="coupon_code" maxlength="50" class="n-input" value="{{ old('coupon_code') }}" placeholder="输入优惠码" aria-describedby="n-coupon-hint" @error('coupon_code') aria-invalid="true" @enderror>@error('coupon_code')<p class="n-field-error">{{ $message }}</p>@enderror<p class="n-hint" id="n-coupon-hint">点击试算查看预计实付；提交订单时会再次校验。</p></div>
<div style="margin:12px 0"><button type="button" class="n-button n-button-secondary" data-checkout-quote>校验优惠码并试算</button><p class="n-hint" data-quote-status role="status" aria-live="polite">可试算优惠，试算不占用库存或优惠次数。</p></div>
            <fieldset class="n-payment-options"><legend>支付方式</legend><div>@foreach($payMethods as $value => $label)<label class="n-payment-choice"><input type="radio" name="payment_method" value="{{ $value }}" @checked($selectedPay === $value) required><span>@themeInclude('partials.pay-icon', ['method' => $value])<strong>{{ $label }}</strong><i aria-hidden="true"></i></span></label>@endforeach</div>@error('payment_method')<p class="n-field-error">{{ $message }}</p>@enderror</fieldset>
            @if(setting('turnstile_site_key'))<div class="n-field"><div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}" data-size="compact"></div>@error('turnstile')<p class="n-field-error">{{ $message }}</p>@enderror</div>@endif
            <div class="n-checkout-total" aria-live="polite" aria-atomic="true"><div><span>当前单价</span><strong id="unit-price">¥{{ number_format($product->price, 2) }}</strong></div><div><span data-checkout-total-label>商品小计</span><strong id="total-price">¥{{ number_format($product->price * $min, 2) }}</strong></div></div>
            <button type="submit" class="n-button n-button-primary n-button-wide">创建订单，继续付款 <span aria-hidden="true">→</span></button>
        </form>
        @endif
        </section>
    </aside>
</div>
@if($buyable)<div class="n-mobile-purchase"><div><span>商品单价</span><strong>¥{{ number_format($product->price, 2) }}</strong></div><a href="#n-checkout" class="n-button n-button-primary">填写购买信息 →</a></div>@endif
@endsection