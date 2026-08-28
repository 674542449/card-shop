@extends('layouts.front')

@section('title', '订单详情 - ' . setting('site_name', 'CardShop'))

@section('content')
    <div style="margin-bottom:15px;">
        <a href="/order/query" style="color:var(--text-light);font-size:13px">&larr; 返回订单列表</a>
    </div>

    {{--
        Status header. It used to be a centred stack — a 48px glyph over a heading
        over a line of help text, inside 25px of padding — which spent about 160px
        of vertical space restating the one word already shown as a pill further
        down the page. Laid out as a row it says the same thing in ~60px, so the
        card secrets are on screen without scrolling.
    --}}
    @php
        // Glyph is decorative: the heading beside it states the status in words, so
        // it is hidden from assistive tech rather than read out as "check mark".
        $states = [
            'paid' => ['&#9989;', '支付成功', '订单已完成，卡密信息如下', 'is-paid'],
            'expired' => ['&#9200;', '订单已过期', '此订单已超过支付时限', 'is-expired'],
            'closed' => ['&#10060;', '订单已关闭', '此订单已被关闭', 'is-closed'],
            'pending' => ['&#9203;', '待支付', '请尽快完成支付', 'is-pending'],
        ];

        $stateKey = $order->isPaid() ? 'paid'
            : (($order->isExpired() || $order->status === 'expired') ? 'expired'
            : ($order->status === 'closed' ? 'closed' : 'pending'));

        [$glyph, $stateTitle, $stateNote, $stateClass] = $states[$stateKey];
    @endphp
    <div class="page-card order-state-card {{ $stateClass }}">
        <span class="os-glyph" aria-hidden="true">{!! $glyph !!}</span>
        <span class="os-text">
            <strong>{{ $stateTitle }}</strong>
            <small>{{ $stateNote }}</small>
        </span>
        @if($stateKey === 'pending')
        <a href="/order/pay/{{ $order->order_no }}" class="btn-buy-sm os-action">继续支付</a>
        @endif
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
                    <td>@include('front.partials.order-status', ['status' => $order->status])</td>
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
        <div class="page-card-header card-panel-header">
            <span>卡密信息</span>
            <span class="card-panel-actions">
                {{-- A real link, not a JS blob download. The cards live behind the
                     same session check as this page, so the server can gate the file
                     the same way it gates the page — and the download still works
                     with JS off, which for the one artefact the buyer paid for is
                     worth more than saving a round trip. --}}
                <a href="/order/cards/{{ $order->order_no }}/download" class="btn-buy-sm">下载 TXT</a>
                <button type="button" class="btn-buy-sm btn-copy" data-target="card-content-text">复制</button>
            </span>
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
