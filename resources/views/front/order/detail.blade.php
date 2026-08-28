@extends('layouts.front')

@section('title', '订单详情 - ' . setting('site_name', 'CardShop'))

@section('content')
    <div style="margin-bottom:15px;">
        <a href="/order/query" style="color:var(--text-light);font-size:13px">&larr; 返回订单列表</a>
    </div>

    {{-- Status Header --}}
    <div class="page-card" style="margin-bottom:15px">
        <div class="page-card-body text-center" style="padding:25px;">
            @if($order->isPaid())
            {{-- Decorative: the heading underneath states the status in words, so the
                 glyph is hidden from assistive tech rather than read out as "check mark". --}}
            <div style="font-size:48px;color:var(--teal);" aria-hidden="true">&#9989;</div>
            <h3 style="font-size:18px;margin:8px 0 4px;">支付成功</h3>
            <p style="color:var(--text-light);font-size:13px;">订单已完成，卡密信息如下</p>
            @elseif($order->isExpired() || $order->status === 'expired')
            <div style="font-size:48px;" aria-hidden="true">&#9200;</div>
            <h3 style="font-size:18px;margin:8px 0 4px;">订单已过期</h3>
            <p style="color:var(--text-light);font-size:13px;">此订单已超过支付时限</p>
            @elseif($order->status === 'closed')
            <div style="font-size:48px;" aria-hidden="true">&#10060;</div>
            <h3 style="font-size:18px;margin:8px 0 4px;">订单已关闭</h3>
            <p style="color:var(--text-light);font-size:13px;">此订单已被关闭</p>
            @else
            <div style="font-size:48px;" aria-hidden="true">&#9203;</div>
            <h3 style="font-size:18px;margin:8px 0 4px;">待支付</h3>
            <p style="color:var(--text-light);font-size:13px;margin-bottom:12px;">请尽快完成支付</p>
            <a href="/order/pay/{{ $order->order_no }}" class="btn-submit" style="display:inline-block;width:auto;padding:8px 30px;">继续支付</a>
            @endif
        </div>
    </div>

    {{-- Order Info --}}
    <div class="page-card" style="margin-bottom:15px">
        <div class="page-card-header">订单信息</div>
        <div class="page-card-body">
            <table class="order-info-table">
                <tr><th>订单编号</th><td style="font-family:monospace;">{{ $order->order_no }}</td></tr>
                <tr><th>商品名称</th><td>{{ $order->product->name ?? '—' }}</td></tr>
                <tr><th>购买数量</th><td>{{ $order->quantity }} 件</td></tr>
                <tr><th>单价</th><td>¥{{ number_format($order->unit_price, 2) }}</td></tr>
                @if($order->discount_amount > 0)
                <tr><th>优惠金额</th><td style="color:var(--teal);">-¥{{ number_format($order->discount_amount, 2) }}</td></tr>
                @endif
                <tr><th>支付金额</th><td style="color:var(--price-color);font-weight:700;">¥{{ number_format($order->total_amount, 2) }}</td></tr>
                <tr>
                    <th>支付方式</th>
                    <td>
                        @switch($order->payment_method)
                            @case('alipay') 支付宝 @break
                            @case('wechat') 微信支付 @break
                            @case('usdt_trc20') USDT(TRC20) @break
                            @case('usdt_bep20') USDT(BEP20) @break
                            @case('usdt_polygon') USDT(Polygon) @break
                            @default {{ $order->payment_method ?? '—' }}
                        @endswitch
                    </td>
                </tr>
                <tr>
                    <th>订单状态</th>
                    <td>
                        @switch($order->status)
                            @case('pending') <span class="order-status pending">待支付</span> @break
                            @case('paid') <span class="order-status paid">已支付</span> @break
                            @case('expired') <span class="order-status expired">已过期</span> @break
                            @case('closed') <span class="order-status closed">已关闭</span> @break
                            @default <span class="order-status">{{ $order->status }}</span>
                        @endswitch
                    </td>
                </tr>
                @if($order->paid_at)
                <tr><th>支付时间</th><td>{{ $order->paid_at->format('Y-m-d H:i:s') }}</td></tr>
                @endif
                <tr><th>下单时间</th><td>{{ $order->created_at->format('Y-m-d H:i:s') }}</td></tr>
            </table>
        </div>
    </div>

    {{-- Card Contents --}}
    @if($order->isPaid() && $cards->count() > 0)
    <div class="page-card" style="margin-bottom:15px">
        <div class="page-card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span>卡密信息</span>
            <button type="button" class="btn-buy-sm btn-copy" data-target="card-content-text"
                    style="padding:3px 14px;font-size:13px;">复制</button>
        </div>
        <div class="page-card-body" style="padding:0;">
            <ul class="card-content-list">
                @foreach($cards as $card)
                <li>{{ $card->content }}</li>
                @endforeach
            </ul>
            <textarea id="card-content-text" style="position:absolute;left:-9999px;">@foreach($cards as $card){{ $card->content }}
@endforeach</textarea>
        </div>
    </div>
    @endif

    <div class="text-center mt-2">
        <a href="/order/query" style="color:var(--text-light);font-size:13px;margin-right:20px;">&larr; 返回订单列表</a>
        <a href="/" style="color:var(--text-light);font-size:13px;">返回首页</a>
    </div>
@endsection
