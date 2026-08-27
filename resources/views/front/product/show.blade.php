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
<div class="container py-4 product-detail">
    {{-- Breadcrumb --}}
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/">首页</a></li>
            @if($product->category)
            <li class="breadcrumb-item">
                <a href="/category/{{ $product->category->slug }}">{{ $product->category->name }}</a>
            </li>
            @endif
            <li class="breadcrumb-item active" aria-current="page">{{ $product->name }}</li>
        </ol>
    </nav>

    <div class="row g-4">
        {{-- Left: Product Info --}}
        <div class="col-lg-7">
            <h1 class="h3 fw-bold mb-3">{{ $product->name }}</h1>

            {{-- Price Box --}}
            <div class="price-box">
                <div class="d-flex align-items-end gap-3 mb-2">
                    <span class="price-main">¥{{ number_format($product->price, 2) }}</span>
                    <span class="stock-badge {{ $stockCount > 0 ? 'in-stock' : 'out-of-stock' }}">
                        <span class="dot"></span>
                        {{ $stockCount > 0 ? '有货 (' . $stockCount . '件)' : '缺货' }}
                    </span>
                </div>

                @if($product->wholesalePrices->count() > 0)
                <div class="mt-2">
                    <small class="text-secondary fw-semibold">批量优惠价：</small>
                    <table class="table table-sm wholesale-table mt-1 mb-0">
                        <thead>
                            <tr>
                                <th>数量</th>
                                <th>单价</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1 件起</td>
                                <td class="text-price">¥{{ number_format($product->price, 2) }}</td>
                            </tr>
                            @foreach($product->wholesalePrices as $wp)
                            <tr>
                                <td>{{ $wp->min_quantity }} 件起</td>
                                <td class="text-price">¥{{ number_format($wp->price, 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>

            {{-- Description --}}
            @if($descriptionHtml)
            <div class="mt-4">
                <h2 class="h5 fw-bold mb-3">商品详情</h2>
                <div class="product-description">
                    {!! $descriptionHtml !!}
                </div>
            </div>
            @endif
        </div>

        {{-- Right: Purchase Form --}}
        <div class="col-lg-5">
            <div class="purchase-form" id="purchase-form">
                <h2 class="h5 fw-bold mb-3">购买商品</h2>

                @if($stockCount <= 0)
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    当前商品暂时缺货，请稍后再来。
                </div>
                @else
                <form action="/order/create" method="POST" data-guard>
                    @csrf

                    <input type="hidden" name="product_id" id="product_id" value="{{ $product->id }}">
                    <input type="hidden" id="product-base-price" value="{{ $product->price }}">
                    <input type="hidden" id="wholesale-prices-data"
                           value="{{ $product->wholesalePrices->toJson() }}">

                    {{-- Email --}}
                    <div class="mb-3">
                        <label for="email" class="form-label">邮箱地址</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="{{ old('email') }}" required placeholder="用于接收卡密信息">
                    </div>

                    {{-- Query Password --}}
                    <div class="mb-3">
                        <label for="query_password" class="form-label">查询密码</label>
                        <input type="password" class="form-control" id="query_password" name="query_password"
                               minlength="6" required placeholder="至少6位字符">
                        <div class="form-text">
                            <i class="bi bi-info-circle me-1"></i>用于查询订单，请牢记
                        </div>
                    </div>

                    {{-- Quantity --}}
                    <div class="mb-3">
                        <label for="quantity" class="form-label">购买数量</label>
                        <input type="number" class="form-control" id="quantity" name="quantity"
                               value="{{ old('quantity', $product->min_quantity) }}"
                               min="{{ $product->min_quantity }}"
                               max="{{ min($product->max_quantity, $stockCount) }}"
                               required>
                        <div class="form-text">
                            可购买 {{ $product->min_quantity }} - {{ min($product->max_quantity, $stockCount) }} 件
                        </div>
                    </div>

                    {{-- Coupon --}}
                    <div class="mb-3">
                        <label for="coupon_code" class="form-label">优惠码 <small class="text-secondary">（可选）</small></label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="coupon_code" name="coupon_code"
                                   value="{{ old('coupon_code') }}" placeholder="输入优惠码">
                            <button type="button" class="btn btn-outline-secondary" id="coupon-apply-btn">应用</button>
                        </div>
                        <div id="coupon-message" class="form-text"></div>
                    </div>

                    {{-- Payment Method --}}
                    <div class="mb-3">
                        <label class="form-label">支付方式</label>
                        <div class="payment-methods">
                            <div class="payment-method-item">
                                <input type="radio" name="payment_method" value="alipay" id="pay-alipay"
                                       {{ old('payment_method', 'alipay') === 'alipay' ? 'checked' : '' }}>
                                <label for="pay-alipay">
                                    <i class="bi bi-alipay"></i> 支付宝
                                </label>
                            </div>
                            <div class="payment-method-item">
                                <input type="radio" name="payment_method" value="wechat" id="pay-wechat"
                                       {{ old('payment_method') === 'wechat' ? 'checked' : '' }}>
                                <label for="pay-wechat">
                                    <i class="bi bi-wechat"></i> 微信
                                </label>
                            </div>
                            <div class="payment-method-item">
                                <input type="radio" name="payment_method" value="usdt_trc20" id="pay-usdt-trc20"
                                       {{ old('payment_method') === 'usdt_trc20' ? 'checked' : '' }}>
                                <label for="pay-usdt-trc20">
                                    <i class="bi bi-currency-exchange"></i> USDT(TRC20)
                                </label>
                            </div>
                            <div class="payment-method-item">
                                <input type="radio" name="payment_method" value="usdt_bep20" id="pay-usdt-bep20"
                                       {{ old('payment_method') === 'usdt_bep20' ? 'checked' : '' }}>
                                <label for="pay-usdt-bep20">
                                    <i class="bi bi-currency-exchange"></i> USDT(BEP20)
                                </label>
                            </div>
                            <div class="payment-method-item">
                                <input type="radio" name="payment_method" value="usdt_polygon" id="pay-usdt-polygon"
                                       {{ old('payment_method') === 'usdt_polygon' ? 'checked' : '' }}>
                                <label for="pay-usdt-polygon">
                                    <i class="bi bi-currency-exchange"></i> USDT(Polygon)
                                </label>
                            </div>
                        </div>
                    </div>

                    {{-- Turnstile --}}
                    @if(setting('turnstile_site_key'))
                    <div class="mb-3">
                        <div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}"></div>
                    </div>
                    @endif

                    {{-- Total Price --}}
                    <div class="total-price-display">
                        <span class="label">应付金额</span>
                        <span class="amount" id="total-price">¥{{ number_format($product->price * $product->min_quantity, 2) }}</span>
                    </div>

                    {{-- Submit --}}
                    <button type="submit" class="btn btn-submit-order">
                        <i class="bi bi-cart-check me-1"></i> 立即购买
                    </button>
                </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
