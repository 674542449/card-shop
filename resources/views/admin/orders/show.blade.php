@extends('layouts.admin')

@section('breadcrumb', '订单详情')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">订单详情</h4>
    <a href="{{ url('/admin/orders') }}" class="btn btn-secondary">返回</a>
</div>

<div class="card mb-4">
    <div class="card-header">订单信息</div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <dl class="row mb-0">
                    <dt class="col-sm-4">订单号</dt>
                    <dd class="col-sm-8">{{ $order->order_no }}</dd>

                    <dt class="col-sm-4">商品</dt>
                    <dd class="col-sm-8">
                        <a href="{{ url('/admin/products/' . $order->product_id . '/edit') }}">{{ $order->product->name ?? '-' }}</a>
                    </dd>

                    <dt class="col-sm-4">邮箱</dt>
                    <dd class="col-sm-8">{{ $order->email }}</dd>

                    <dt class="col-sm-4">数量</dt>
                    <dd class="col-sm-8">{{ $order->quantity }}</dd>

                    <dt class="col-sm-4">单价</dt>
                    <dd class="col-sm-8">&yen;{{ $order->unit_price }}</dd>

                    <dt class="col-sm-4">总金额</dt>
                    <dd class="col-sm-8">&yen;{{ $order->total_amount }}</dd>

                    <dt class="col-sm-4">优惠码</dt>
                    <dd class="col-sm-8">{{ $order->coupon->code ?? '-' }}</dd>
                </dl>
            </div>
            <div class="col-md-6">
                <dl class="row mb-0">
                    <dt class="col-sm-4">优惠金额</dt>
                    <dd class="col-sm-8">&yen;{{ $order->discount_amount ?? '0.00' }}</dd>

                    <dt class="col-sm-4">支付方式</dt>
                    <dd class="col-sm-8">
                        @switch($order->payment_method)
                            @case('alipay') 支付宝 @break
                            @case('wechat') 微信 @break
                            @case('usdt') USDT @break
                            @case('manual') 手动 @break
                            @default -
                        @endswitch
                    </dd>

                    <dt class="col-sm-4">支付流水号</dt>
                    <dd class="col-sm-8">{{ $order->payment_no ?? '-' }}</dd>

                    <dt class="col-sm-4">状态</dt>
                    <dd class="col-sm-8">
                        @switch($order->status)
                            @case('pending')
                                <span class="badge bg-warning text-dark">待支付</span>
                                @break
                            @case('paid')
                                <span class="badge bg-success">已支付</span>
                                @break
                            @case('expired')
                                <span class="badge bg-secondary">已过期</span>
                                @break
                            @case('closed')
                                <span class="badge bg-dark">已关闭</span>
                                @break
                        @endswitch
                    </dd>

                    <dt class="col-sm-4">IP</dt>
                    <dd class="col-sm-8">{{ $order->ip }}</dd>

                    <dt class="col-sm-4">创建时间</dt>
                    <dd class="col-sm-8">{{ $order->created_at->format('Y-m-d H:i:s') }}</dd>

                    <dt class="col-sm-4">支付时间</dt>
                    <dd class="col-sm-8">{{ $order->paid_at ? $order->paid_at->format('Y-m-d H:i:s') : '-' }}</dd>

                    <dt class="col-sm-4">过期时间</dt>
                    <dd class="col-sm-8">{{ $order->expired_at ? $order->expired_at->format('Y-m-d H:i:s') : '-' }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>

@if($order->status === 'paid' || $order->status === 'pending')
    <div class="card mb-4">
        <div class="card-header">操作</div>
        <div class="card-body d-flex gap-2">
            @if($order->status === 'paid')
                <form action="{{ url('/admin/orders/' . $order->id . '/resend') }}" method="POST" onsubmit="return confirm('确定要补发卡密吗？')">
                    @csrf
                    <button type="submit" class="btn btn-primary">补发卡密</button>
                </form>
            @endif

            @if($order->status === 'pending')
                <form action="{{ url('/admin/orders/' . $order->id . '/paid') }}" method="POST" class="mark-paid-form" onsubmit="return confirm('确定要手动确认支付吗？')">
                    @csrf
                    <button type="submit" class="btn btn-success">手动确认支付</button>
                </form>
                <form action="{{ url('/admin/orders/' . $order->id . '/close') }}" method="POST" class="close-order-form" onsubmit="return confirm('确定要关闭此订单吗？')">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger">关闭订单</button>
                </form>
            @endif
        </div>
    </div>
@endif

@if($order->status === 'paid' && $order->cards && $order->cards->count())
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>卡密内容</span>
            <span class="badge bg-primary">{{ $order->cards->count() }}</span>
        </div>
        <div class="card-body">
            <ol class="list-group list-group-numbered">
                @foreach($order->cards as $index => $card)
                    <li class="list-group-item">
                        <code class="text-break">{{ $card->content }}</code>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>
@endif
@endsection
