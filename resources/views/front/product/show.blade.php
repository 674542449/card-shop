@extends('layouts.front')

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/product/' . $product->slug))
@section('og_type', 'product')

@section('structured_data')
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Product",
    "name": "{{ e($product->name) }}",
    "description": "{{ e($product->seo_description ?: Str::limit(strip_tags($descriptionHtml), 200)) }}",
    "url": "{{ url('/product/' . $product->slug) }}",
    "offers": {
        "@type": "Offer",
        "priceCurrency": "CNY",
        "price": "{{ $product->price }}",
        "availability": "{{ $stockCount > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock' }}"
    }
}
</script>
@endsection

@section('content')
    @php $announcement = setting('site_announcement', ''); @endphp
    @if($announcement)
    <blockquote class="site-quote">{!! nl2br(e($announcement)) !!}</blockquote>
    @endif

    <div class="pd-layout">
        {{-- Left: Product Info + Order Form --}}
        <div class="pd-main">
            <div class="pd-info">
                <div class="pd-meta">
                    <span class="badge-auto">自动发货</span>
                    <span class="pd-stock">库存 <strong>{{ $stockCount }}</strong> 件</span>
                </div>
                <h2 class="pd-title">{{ $product->name }}</h2>
                <div class="pd-price-row">
                    <span class="pd-price">¥{{ number_format($product->price, 2) }}</span>
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

                @if($stockCount > 0)
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
                               max="{{ min($product->max_quantity, $stockCount) }}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">邮箱</label>
                        <input type="email" name="email" id="email" class="form-input"
                               value="{{ old('email') }}" required placeholder="接收卡密信息">
                    </div>

                    <div class="form-group">
                        <label class="form-label">查询密码</label>
                        <div class="form-input-wrap">
                            <input type="password" name="query_password" id="query_password" class="form-input"
                                   minlength="6" required placeholder="至少6位，用于查询订单">
                        </div>
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
                            $payMethods['wechat'] = '微信支付';
                        }
                        if (setting('epusdt_api_url') && setting('epusdt_api_token')) {
                            $payMethods['usdt_trc20'] = 'USDT (TRC20)';
                            $payMethods['usdt_bep20'] = 'USDT (BEP20)';
                            $payMethods['usdt_polygon'] = 'USDT (Polygon)';
                        }
                        // Nothing configured yet: still offer the default gateways so the form
                        // stays usable and the operator sees a payment error rather than a
                        // validation error they cannot act on.
                        if (empty($payMethods)) {
                            $payMethods = ['alipay' => '支付宝', 'wechat' => '微信支付'];
                        }
                    @endphp

                    <div class="form-group">
                        <label class="form-label">支付方式</label>
                        <select name="payment_method" id="payment_method" class="form-input" required>
                            @foreach($payMethods as $value => $label)
                            <option value="{{ $value }}" @selected(old('payment_method') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if(setting('turnstile_site_key'))
                    <div style="margin: 12px 0;">
                        <div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}"></div>
                    </div>
                    @endif

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
                <div class="pd-tab-content">
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
