@extends('layouts.front')

@section('title', '订单支付 - ' . setting('site_name', 'CardShop'))

@section('content')
    <div class="page-card" style="margin-top:20px">
        <div class="page-card-header">订单信息</div>
        <div class="page-card-body">
            <table class="order-info-table">
                <tr><th>订单编号</th><td style="font-family:monospace;">{{ $order->order_no }}</td></tr>
                <tr><th>商品名称</th><td>{{ $order->product->name ?? '—' }}</td></tr>
                <tr><th>购买数量</th><td>{{ $order->quantity }} 件</td></tr>
                @if($order->discount_amount > 0)
                <tr><th>优惠金额</th><td style="color:var(--teal);">-¥{{ number_format($order->discount_amount, 2) }}</td></tr>
                @endif
                <tr><th>支付金额</th><td style="font-size:18px;font-weight:700;color:var(--price-color);">¥{{ number_format($order->total_amount, 2) }}</td></tr>
                <tr>
                    <th>支付方式</th>
                    <td>
                        @switch($order->payment_method)
                            @case('alipay') 支付宝 @break
                            @case('wechat') 微信支付 @break
                            @case('usdt_trc20') USDT(TRC20) @break
                            @case('usdt_bep20') USDT(BEP20) @break
                            @case('usdt_polygon') USDT(Polygon) @break
                            @default {{ $order->payment_method }}
                        @endswitch
                    </td>
                </tr>
            </table>
        </div>
    </div>

    @if($expired)
    <div class="page-card">
        <div class="page-card-body text-center" style="padding:40px 20px;">
            <div style="font-size:48px;margin-bottom:10px;" aria-hidden="true">&#9200;</div>
            <h3 style="font-size:18px;margin-bottom:8px;">订单已过期</h3>
            <p style="color:var(--text-light);margin-bottom:15px;">此订单已超过支付时限，请重新下单。</p>
            <a href="/" class="btn-submit" style="display:inline-block;width:auto;padding:8px 30px;">返回首页</a>
        </div>
    </div>
    @else
    <div class="page-card" style="margin-bottom:15px">
        <div class="page-card-body text-center">
            <div class="countdown-timer" id="countdown-timer"
                 data-expires="{{ $order->expires_at->toIso8601String() }}">
                <div style="color:var(--text-light);font-size:13px;margin-bottom:4px;">剩余支付时间</div>
                <div class="time" style="font-size:32px;font-weight:700;color:var(--price-color);">--:--</div>
                <div style="color:var(--text-light);font-size:12px;">超时订单将自动关闭</div>
            </div>
        </div>
    </div>

    @if($paymentUrl)
    <div class="text-center" style="margin-bottom:15px;">
        <a href="{{ $paymentUrl }}" class="btn-submit" target="_blank" rel="noopener"
           style="display:inline-block;width:auto;padding:10px 50px;font-size:16px;">前往支付</a>
        <p style="color:var(--text-light);font-size:13px;margin-top:10px;">点击上方按钮将跳转到支付页面</p>
    </div>
    @else
    <div class="page-card" style="margin-bottom:15px">
        <div class="page-card-body text-center" style="padding:30px;">
            <div style="font-size:36px;margin-bottom:10px;" aria-hidden="true">&#9203;</div>
            <h3 style="font-size:16px;margin-bottom:5px;">等待支付</h3>
            <p style="color:var(--text-light);font-size:13px;">请按照支付页面提示完成支付</p>
        </div>
    </div>
    @endif

    <div class="text-center" id="payment-polling" data-order-no="{{ $order->order_no }}"
         style="color:var(--text-light);font-size:13px;">
        &#8987; 正在等待支付结果，支付成功后将自动跳转...
    </div>
    @endif
@endsection
