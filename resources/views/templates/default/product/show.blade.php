@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', \App\Support\Seo::url('/product/' . $product->slug))
@section('og_image', (string) $product->image)
@section('og_type', 'product')

@php
    $minQty = max(1, (int) $product->min_quantity);
    $maxLimit = max($minQty, (int) $product->max_quantity);
    $buyable = $stockCount >= $minQty;
    $maxBuyable = $buyable ? min($maxLimit, $stockCount) : $minQty;
    $lowThreshold = array_key_exists('low_stock_threshold', $product->getAttributes())
        ? $product->low_stock_threshold
        : 5;
    $isLowStock = $buyable && $lowThreshold !== null && $stockCount <= (int) $lowThreshold;
    $qtyOld = old('quantity');
    $qtyDefault = is_numeric($qtyOld)
        ? max($minQty, min($maxBuyable, (int) $qtyOld))
        : $minQty;
    $initialUnitPrice = (float) $product->getEffectivePrice($qtyDefault);
    $payMethods = \App\Support\PaymentMethods::availableForCheckout();
    $selectedPay = old('payment_method', array_key_first($payMethods));
    $checkoutKey = app(\App\Services\CheckoutIntentService::class)->issue((int) $product->id);
    $prodImage = \App\Support\SafeUrl::asset($product->image);
@endphp

@section('structured_data')
{{ \App\Support\Seo::jsonLdScript(\App\Support\Seo::productSchema($product, (string) $seoDescription, $buyable)) }}
@endsection

@section('content')
    <nav class="ws-breadcrumb" aria-label="面包屑导航">
        <a href="/">商品目录</a>
        @if($product->category)
        <span class="sep">/</span>
        <a href="/category/{{ $product->category->slug }}">{{ $product->category->name }}</a>
        @endif
        <span class="sep">/</span>
        <span class="current">{{ $product->name }}</span>
    </nav>

    @php $announcement = setting('site_announcement', ''); @endphp
    @if($announcement)
    <section class="ws-notice" aria-label="站点公告">
        <span class="ws-notice-badge">公告</span>
        <div class="ws-notice-body rich-text">{!! \App\Support\ContentRenderer::toHtml($announcement) !!}</div>
    </section>
    @endif

    <div class="ws-detail-grid {{ $buyable ? 'has-mobile-buybar' : '' }}">
        {{-- Left Column: Facebook Marketplace Listing Overview, Tiered Wholesale & Documentation --}}
        <div class="ws-detail-main">
            <section class="ws-showcase-card fb-detail-card" aria-label="商品信息概览">
                <div class="ws-showcase-media">
                    @if($prodImage)
                    <img src="{{ $prodImage }}" alt="{{ $product->name }}" width="640" height="400" fetchpriority="high" decoding="async">
                    @else
                    @themeInclude('partials.image-placeholder', ['class' => 'pd-image-ph'])
                    @endif
                </div>

                <div class="ws-showcase-info">
                    <div class="ws-item-badges">
                        @if($product->category)
                        <a href="/category/{{ $product->category->slug }}" class="ws-pill ws-pill-accent">{{ $product->category->name }}</a>
                        @endif
                        <span class="ws-pill ws-pill-live">自动发货</span>
                        @if($isLowStock)
                        <span class="ws-pill ws-pill-warn">库存紧张</span>
                        @elseif($buyable)
                        <span class="ws-pill ws-pill-live">正常销售</span>
                        @else
                        <span class="ws-pill ws-pill-danger">暂时缺货</span>
                        @endif
                    </div>

                    <h1>{{ $product->name }}</h1>

                    <div class="ws-spec-grid">
                        <div class="ws-spec-box">
                            <span>可用库存</span>
                            <strong>{{ $stockCount }} 件</strong>
                        </div>
                        <div class="ws-spec-box">
                            <span>单笔限购</span>
                            <strong>{{ $minQty }}–{{ $maxLimit }} 件</strong>
                        </div>
                        <div class="ws-spec-box">
                            <span>交付方式</span>
                            <strong>在线提取 / 邮件</strong>
                        </div>
                    </div>

                    @if($buyable)
                    <div class="fb-detail-mobile-jump">
                        <a href="#ws-checkout-dock" class="ws-btn ws-btn-primary ws-btn-lg" data-ws-scroll-buy style="width:100%;">
                            立即购买（&yen;{{ number_format($initialUnitPrice, 2) }} / 件）
                        </a>
                    </div>
                    @endif
                </div>
            </section>

            @if($product->wholesalePrices->count() > 0)
            <section class="ws-panel-card" aria-label="阶梯批发价格">
                <div class="ws-panel-head">
                    <h2>阶梯批发价格</h2>
                    <span>点击对应档位可自动填入购买数量</span>
                </div>
                <table class="wholesale-table">
                    <thead>
                        <tr>
                            <th>单笔购买数量</th>
                            <th>核算单价</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr data-min-qty="{{ $minQty }}">
                            <td>{{ $minQty }} 件起（基础单价）</td>
                            <td>&yen;{{ number_format($product->price, 2) }} / 件</td>
                        </tr>
                        @foreach($product->wholesalePrices as $tier)
                        @php $tierReachable = (int) $tier->min_quantity <= $maxBuyable; @endphp
                        <tr data-min-qty="{{ $tier->min_quantity }}" @if(!$tierReachable) data-tier-disabled="1" class="is-unavailable" @endif>
                            <td>
                                满 {{ $tier->min_quantity }} 件及以上
                                @if(!$tierReachable)
                                <small class="ws-tier-note">（超出当前可购上限 {{ $maxBuyable }} 件）</small>
                                @endif
                            </td>
                            <td>&yen;{{ number_format($tier->price, 2) }} / 件</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
            @endif

            <section class="ws-panel-card" aria-label="商品详细说明">
                <div class="ws-panel-head">
                    <h2>商品详细说明</h2>
                    <span>下单前请仔细阅读商品规格与使用须知</span>
                </div>
                <div class="ws-panel-body rich-text">
                    @if(trim($descriptionHtml) !== '')
                        {!! $descriptionHtml !!}
                    @else
                        <p>暂无补充图文说明。支付核验完成后，系统将自动发放对应卡密凭据，支持在线复制与导出 TXT 文件。</p>
                    @endif
                </div>
            </section>
        </div>

        {{-- Right Column: Facebook Marketplace Checkout Console --}}
        <aside class="ws-checkout-dock" id="ws-checkout-dock" aria-label="订单结算面板">
            @if($buyable)
            <form action="/order/create" method="POST" class="ws-form" data-guard>
                @csrf
                <input type="hidden" name="product_id" value="{{ $product->id }}">
                <input type="hidden" name="checkout_key" value="{{ $checkoutKey }}" data-intent-product="{{ $product->id }}">
                <input type="hidden" id="product-base-price" value="{{ $product->price }}">
                <input type="hidden" id="wholesale-prices-data" value="{{ $product->wholesalePrices->toJson() }}">
                <div class="ws-hp-field" aria-hidden="true">
                    <label for="checkout-hp-website">请勿填写此项</label>
                    <input type="text" id="checkout-hp-website" name="website" value="" tabindex="-1" autocomplete="off">
                </div>

                <div class="ws-dock-price-head">
                    <div>
                        <span class="ws-dock-price-caption">当前单价</span>
                        <span class="unit" id="unit-price">&yen;{{ number_format($initialUnitPrice, 2) }}</span>
                    </div>
                    <span class="ws-pill {{ $isLowStock ? 'ws-pill-warn' : 'ws-pill-live' }}">{{ $isLowStock ? '库存紧张 · 余 ' . $stockCount . ' 件' : '库存 ' . $stockCount . ' 件' }}</span>
                </div>

                <div class="ws-field">
                    <label class="ws-label" for="quantity">
                        <span>购买数量</span>
                        <small>限购 {{ $minQty }}–{{ $maxBuyable }} 件</small>
                    </label>
                    <div class="ws-stepper">
                        <button type="button" data-ws-qty-step="-1" aria-label="减少数量">&minus;</button>
                        <input type="number" id="quantity" name="quantity"
                               value="{{ $qtyDefault }}"
                               min="{{ $minQty }}" max="{{ $maxBuyable }}" step="1"
                               inputmode="numeric" required>
                        <button type="button" data-ws-qty-step="1" aria-label="增加数量">+</button>
                    </div>
                    @error('quantity')<span class="ws-field-error" role="alert">{{ $message }}</span>@enderror
                </div>

                <div class="ws-field">
                    <label class="ws-label" for="checkout-email">
                        <span>接收邮箱</span>
                        <small>必填，用于接收通知与查询订单</small>
                    </label>
                    <input type="email" id="checkout-email" name="email"
                           class="ws-input @error('email') is-invalid @enderror"
                           placeholder="name@example.com" value="{{ old('email') }}" autocomplete="email" required>
                    @error('email')<span class="ws-field-error" role="alert">{{ $message }}</span>@enderror
                </div>

                <div class="ws-field">
                    <label class="ws-label" for="checkout-password">
                        <span>查询密码</span>
                        <small>6–50 位字符，用于查询订单</small>
                    </label>
                    <input type="password" id="checkout-password" name="query_password"
                           class="ws-input @error('query_password') is-invalid @enderror"
                           placeholder="请设置 6–50 位查询密码" minlength="6" maxlength="50" autocomplete="new-password" required>
                    @error('query_password')<span class="ws-field-error" role="alert">{{ $message }}</span>@enderror
                </div>

                <div class="ws-field">
                    <label class="ws-label" for="checkout-coupon">
                        <span>优惠码</span>
                        <small>选填</small>
                    </label>
                    <div class="ws-coupon-row">
                        <input type="text" id="checkout-coupon" name="coupon_code"
                               class="ws-input @error('coupon_code') is-invalid @enderror"
                               placeholder="请输入优惠码（选填）" value="{{ old('coupon_code') }}" maxlength="50" autocomplete="off">
                        <button type="button" class="ws-btn ws-btn-secondary" data-checkout-quote>试算优惠</button>
                    </div>
                    @error('coupon_code')<span class="ws-field-error" role="alert">{{ $message }}</span>@enderror
                </div>
                <p class="ws-quote-note" data-quote-status role="status" aria-live="polite">可试算优惠，试算不占用库存或优惠次数。</p>

                <div class="ws-field">
                    <span class="ws-label"><span>支付方式</span></span>
                    <div class="ws-pay-grid">
                        @foreach($payMethods as $method => $label)
                        <label class="ws-pay-tile">
                            <input type="radio" name="payment_method" value="{{ $method }}"
                                   @checked($selectedPay === $method) required>
                            <span class="ws-pay-tile-body">
                                <span class="ws-pay-icon">@themeInclude('partials.pay-icon', ['method' => $method])</span>
                                <span>{{ $label }}</span>
                            </span>
                        </label>
                        @endforeach
                    </div>
                    @error('payment_method')<span class="ws-field-error" role="alert">{{ $message }}</span>@enderror
                </div>

                @if(setting('turnstile_site_key'))
                <div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}"></div>
                @error('turnstile')<span class="ws-field-error" role="alert">{{ $message }}</span>@enderror
                @endif

                <div class="ws-checkout-bar">
                    <div class="meta">
                        <span data-checkout-total-label>商品小计</span>
                        <strong id="total-price">&yen;{{ number_format($initialUnitPrice * $qtyDefault, 2) }}</strong>
                    </div>
                    <button type="submit" class="ws-btn ws-btn-primary ws-btn-lg">提交订单 &rarr;</button>
                </div>
            </form>
            @else
            <div style="text-align:center;padding:18px 8px;">
                <span class="ws-pill ws-pill-danger" style="margin-bottom:10px;">暂时缺货</span>
                <h2 style="margin:0 0 8px;font-size:18px;">当前商品库存不足</h2>
                <p style="margin:0 0 18px;font-size:13.5px;color:var(--ws-muted);">
                    当前可售库存少于起购数量（{{ $minQty }} 件），暂无法提交新订单。
                </p>
                <a href="/" class="ws-btn ws-btn-primary" style="width:100%;">返回商品目录 &rarr;</a>
            </div>
            @endif
        </aside>
    </div>

    @if($buyable)
    <div class="ws-mobile-buybar" id="ws-mobile-buybar">
        <div class="ws-mobile-buybar-meta">
            <span>商品单价 · 库存 {{ $stockCount }} 件</span>
            <strong id="ws-mobile-unit-price">&yen;{{ number_format($initialUnitPrice, 2) }} <small>/ 件</small></strong>
        </div>
        <a href="#ws-checkout-dock" class="ws-btn ws-btn-primary" data-ws-scroll-buy>立即购买</a>
    </div>
    @endif
@endsection
