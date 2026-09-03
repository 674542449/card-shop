@extends(theme_view_path('layout'))

@section('title', '订单支付 - ' . setting('site_name', 'CardShop'))

@section('content')
    <div class="page-card" style="margin-top:20px">
        <div class="page-card-header">订单信息</div>
        <div class="page-card-body">
            <table class="order-info-table">
                <tr><th>订单编号</th><td class="op-mono">{{ $order->order_no }}</td></tr>
                <tr><th>商品名称</th><td>{{ $order->product->name ?? '—' }}</td></tr>
                <tr><th>购买数量</th><td>{{ $order->quantity }} 件</td></tr>
                @if($order->discount_amount > 0)
                <tr><th>优惠金额</th><td class="op-discount">-¥{{ number_format($order->discount_amount, 2) }}</td></tr>
                @endif
                <tr><th>支付金额</th><td class="op-total-amount">¥{{ number_format($order->total_amount, 2) }}</td></tr>
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
            <div class="op-status-glyph" aria-hidden="true">&#9200;</div>
            <h3 class="op-status-title">{{ $deadTitle ?? '订单已过期' }}</h3>
            <p class="op-status-desc">{{ $deadReason ?? '此订单已超过支付时限，请重新下单。' }}</p>
            <a href="/" class="btn-submit" style="display:inline-block;width:auto;padding:8px 30px;">返回首页</a>
        </div>
    </div>
    @else
    <div class="page-card" style="margin-bottom:15px">
        <div class="page-card-body text-center">
            <div class="countdown-timer op-countdown" id="countdown-timer"
                 data-expires="{{ $order->expires_at->toIso8601String() }}">
                <div class="op-countdown-hint" style="margin-bottom:4px;">剩余支付时间</div>
                <div class="time">--:--</div>
                <div class="op-countdown-sub">超时订单将自动关闭</div>
            </div>
        </div>
    </div>

    @if($paymentUrl)
    <div class="text-center" style="margin-bottom:15px;">
        <a href="{{ $paymentUrl }}" class="btn-submit op-pay-btn" target="_blank" rel="noopener">前往支付 ↗</a>
        <p class="op-pay-hint">点击上方按钮将在新窗口打开支付页面</p>
    </div>
    @elseif($paymentUnavailable ?? false)
    <div class="page-card" style="margin-bottom:15px">
        <div class="page-card-body" style="padding:25px;">
            <h3 class="op-unavailable-title">暂时无法发起支付</h3>
            <p class="op-unavailable-desc">
                当前支付渠道不可用，无法生成支付链接。请稍后刷新本页重试；若持续如此，
                请联系站点客服并提供上方的订单编号。此订单到期后会自动关闭，不会扣款。
            </p>
        </div>
    </div>
    @else
    <div class="page-card" style="margin-bottom:15px">
        <div class="page-card-body text-center" style="padding:30px;">
            <div class="op-status-glyph" style="font-size:36px;" aria-hidden="true">&#9203;</div>
            <h3 class="op-unavailable-title">等待支付</h3>
            <p class="op-pay-hint">请按照支付页面提示完成支付</p>
        </div>
    </div>
    @endif

    <div class="text-center op-polling" id="payment-polling" data-order-no="{{ $order->order_no }}">
        &#8987; 正在等待支付结果，支付成功后将自动跳转...
    </div>
    @endif
@endsection
