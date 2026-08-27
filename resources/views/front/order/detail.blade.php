@extends('layouts.front')

@section('title', '订单详情 - ' . setting('site_name', 'CardShop'))

@section('content')
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/">首页</a></li>
            <li class="breadcrumb-item"><a href="/order/query">订单查询</a></li>
            <li class="breadcrumb-item active" aria-current="page">订单详情</li>
        </ol>
    </nav>

    <div class="row justify-content-center">
        <div class="col-lg-8">

            {{-- Status Header --}}
            <div class="order-summary-card mb-3">
                <div class="card-body">
                    @if($order->isPaid())
                    <div class="payment-status success">
                        <div class="icon"><i class="bi bi-check-circle-fill"></i></div>
                        <h3 class="h5 fw-bold mb-1">支付成功</h3>
                        <p class="text-secondary mb-0">订单已完成，卡密信息如下</p>
                    </div>
                    @elseif($order->isExpired() || $order->status === 'expired')
                    <div class="payment-status expired">
                        <div class="icon"><i class="bi bi-clock-history"></i></div>
                        <h3 class="h5 fw-bold mb-1">订单已过期</h3>
                        <p class="text-secondary mb-0">此订单已超过支付时限</p>
                    </div>
                    @elseif($order->status === 'closed')
                    <div class="payment-status expired">
                        <div class="icon"><i class="bi bi-x-circle"></i></div>
                        <h3 class="h5 fw-bold mb-1">订单已关闭</h3>
                        <p class="text-secondary mb-0">此订单已被关闭</p>
                    </div>
                    @else
                    <div class="payment-status pending">
                        <div class="icon"><i class="bi bi-hourglass-split"></i></div>
                        <h3 class="h5 fw-bold mb-1">待支付</h3>
                        <p class="text-secondary mb-2">请尽快完成支付</p>
                        <a href="/order/pay/{{ $order->order_no }}" class="btn btn-primary">
                            <i class="bi bi-credit-card me-1"></i> 继续支付
                        </a>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Order Info --}}
            <div class="order-summary-card mb-3">
                <div class="card-header">
                    <i class="bi bi-info-circle me-1"></i> 订单信息
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
                    <div class="order-info-row">
                        <span class="label">单价</span>
                        <span class="value">¥{{ number_format($order->unit_price, 2) }}</span>
                    </div>
                    @if($order->discount_amount > 0)
                    <div class="order-info-row">
                        <span class="label">优惠金额</span>
                        <span class="value text-success">-¥{{ number_format($order->discount_amount, 2) }}</span>
                    </div>
                    @endif
                    <div class="order-info-row">
                        <span class="label">支付金额</span>
                        <span class="value text-price fw-bold">¥{{ number_format($order->total_amount, 2) }}</span>
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
                                @default {{ $order->payment_method ?? '—' }}
                            @endswitch
                        </span>
                    </div>
                    <div class="order-info-row">
                        <span class="label">订单状态</span>
                        <span class="value">
                            @switch($order->status)
                                @case('pending')
                                    <span class="status-badge pending">待支付</span>
                                    @break
                                @case('paid')
                                    <span class="status-badge paid">已支付</span>
                                    @break
                                @case('expired')
                                    <span class="status-badge expired">已过期</span>
                                    @break
                                @case('closed')
                                    <span class="status-badge closed">已关闭</span>
                                    @break
                                @default
                                    <span class="status-badge">{{ $order->status }}</span>
                            @endswitch
                        </span>
                    </div>
                    @if($order->paid_at)
                    <div class="order-info-row">
                        <span class="label">支付时间</span>
                        <span class="value">{{ $order->paid_at->format('Y-m-d H:i:s') }}</span>
                    </div>
                    @endif
                    <div class="order-info-row">
                        <span class="label">下单时间</span>
                        <span class="value">{{ $order->created_at->format('Y-m-d H:i:s') }}</span>
                    </div>
                </div>
            </div>

            {{-- Card Contents --}}
            @if($order->isPaid() && $cards->count() > 0)
            <div class="order-summary-card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-key me-1"></i> 卡密信息</span>
                    <button type="button" class="btn btn-copy btn-sm" data-target="card-content-text">
                        <i class="bi bi-clipboard me-1"></i> 复制
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="card-contents" id="card-content-text">@foreach($cards as $card){{ $card->content }}
@endforeach</div>
                </div>
            </div>
            @endif

            {{-- Back --}}
            <div class="text-center mt-3">
                <a href="/order/query" class="btn btn-outline-secondary me-2">
                    <i class="bi bi-arrow-left me-1"></i> 返回订单列表
                </a>
                <a href="/" class="btn btn-outline-primary">
                    <i class="bi bi-house me-1"></i> 返回首页
                </a>
            </div>

        </div>
    </div>
</div>
@endsection
