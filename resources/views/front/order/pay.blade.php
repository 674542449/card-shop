@extends('layouts.front')

@section('title', '订单支付 - ' . setting('site_name', 'CardShop'))

@section('content')
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-6">

            {{-- Order Summary --}}
            <div class="order-summary-card mb-3">
                <div class="card-header">
                    <i class="bi bi-receipt me-1"></i> 订单信息
                </div>
                <div class="card-body">
                    <div class="order-info-row">
                        <span class="label">订单编号</span>
                        <span class="value" style="font-family: monospace;">{{ $order->order_no }}</span>
                    </div>
                    <div class="order-info-row">
                        <span class="label">商品名称</span>
                        <span class="value">{{ $order->product->name ?? '—' }}</span>
                    </div>
                    <div class="order-info-row">
                        <span class="label">购买数量</span>
                        <span class="value">{{ $order->quantity }} 件</span>
                    </div>
                    @if($order->discount_amount > 0)
                    <div class="order-info-row">
                        <span class="label">优惠金额</span>
                        <span class="value text-success">-¥{{ number_format($order->discount_amount, 2) }}</span>
                    </div>
                    @endif
                    <div class="order-info-row">
                        <span class="label">支付金额</span>
                        <span class="value text-price" style="font-size: 1.25rem;">¥{{ number_format($order->total_amount, 2) }}</span>
                    </div>
                    <div class="order-info-row">
                        <span class="label">支付方式</span>
                        <span class="value">
                            @switch($order->payment_method)
                                @case('alipay') 支付宝 @break
                                @case('wechat') 微信支付 @break
                                @case('usdt_trc20') USDT(TRC20) @break
                                @case('usdt_bep20') USDT(BEP20) @break
                                @case('usdt_polygon') USDT(Polygon) @break
                                @default {{ $order->payment_method }}
                            @endswitch
                        </span>
                    </div>
                </div>
            </div>

            {{-- Payment Status --}}
            @if($expired)
                {{-- Expired --}}
                <div class="order-summary-card">
                    <div class="card-body">
                        <div class="payment-status expired">
                            <div class="icon"><i class="bi bi-clock-history"></i></div>
                            <h3 class="h5 fw-bold mb-2">订单已过期</h3>
                            <p class="text-secondary mb-3">此订单已超过支付时限，请重新下单。</p>
                            <a href="/" class="btn btn-primary">返回首页</a>
                        </div>
                    </div>
                </div>
            @else
                {{-- Pending Payment --}}
                <div class="order-summary-card mb-3">
                    <div class="card-body">
                        {{-- Countdown --}}
                        <div class="countdown-timer" id="countdown-timer"
                             data-expires="{{ $order->expires_at->toIso8601String() }}">
                            <div class="label mb-1">剩余支付时间</div>
                            <div class="time">--:--</div>
                            <div class="label">超时订单将自动关闭</div>
                        </div>
                    </div>
                </div>

                @if($paymentUrl)
                <div class="text-center mb-3">
                    <a href="{{ $paymentUrl }}" class="btn btn-primary btn-lg w-100" target="_blank" rel="noopener">
                        <i class="bi bi-credit-card me-1"></i> 前往支付
                    </a>
                    <p class="text-secondary mt-2 small">
                        <i class="bi bi-info-circle me-1"></i>点击上方按钮将跳转到支付页面
                    </p>
                </div>
                @else
                <div class="order-summary-card mb-3">
                    <div class="card-body">
                        <div class="payment-status pending">
                            <div class="icon"><i class="bi bi-hourglass-split"></i></div>
                            <h3 class="h5 fw-bold mb-2">等待支付</h3>
                            <p class="text-secondary">请按照支付页面提示完成支付</p>
                        </div>
                    </div>
                </div>
                @endif

                {{-- Polling indicator --}}
                <div class="text-center text-secondary small" id="payment-polling"
                     data-order-no="{{ $order->order_no }}">
                    <span class="loading-spinner me-1"></span>
                    正在等待支付结果，支付成功后将自动跳转...
                </div>
            @endif

        </div>
    </div>
</div>
@endsection
