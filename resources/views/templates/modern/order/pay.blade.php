@extends(theme_view_path('layout'))

@section('title', '订单支付 - ' . setting('site_name', 'CardShop'))

@php
    $methodLabels = ['alipay' => '支付宝', 'wechat' => '微信支付', 'usdt_trc20' => 'USDT (TRC20)', 'usdt_bep20' => 'USDT (BEP20)', 'usdt_polygon' => 'USDT (Polygon)', 'manual' => '人工确认'];
    $methodLabel = $methodLabels[$order->payment_method] ?? ($order->payment_method ?: '—');
@endphp

@section('content')
<nav class="m-breadcrumb" aria-label="当前位置"><a href="/">首页</a><span aria-hidden="true">/</span><span>订单支付</span></nav>
<div class="m-order-page-top">
    <div><p class="m-eyebrow">CHECKOUT · 订单支付</p><h1 class="m-page-heading">{{ $expired ? ($deadTitle ?? '订单已过期') : '最后一步，完成付款。' }}</h1><p class="m-lead">{{ $expired ? ($deadReason ?? '此订单已超过支付时限，请重新下单。') : '确认这份订单，支付成功后即可获取卡密。' }}</p></div>
    <a href="/order/query" class="m-button m-button-quiet">查询其他订单 <span aria-hidden="true">↗</span></a>
</div>
<div class="m-order-layout m-order-payment-layout">
    <section class="m-panel m-order-receipt" aria-labelledby="m-receipt-heading">
        <div class="m-order-receipt-heading"><div><p class="m-eyebrow">ORDER RECEIPT</p><h2 class="m-section-title" id="m-receipt-heading">订单清单</h2></div>@themeInclude('partials.order-status', ['status' => $order->status])</div>
        <div class="m-order-receipt-product"><span class="m-order-product-icon" aria-hidden="true"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18m-13 5h4"/></svg></span><div><h3>{{ $order->displayName() ?? '商品已下架' }}</h3><p class="m-muted">数字商品 <span aria-hidden="true">·</span> {{ $order->quantity }} 件</p></div></div>
        <dl class="m-details">
            <div><dt>订单编号</dt><dd class="m-order-code">{{ $order->order_no }}</dd></div>
            <div><dt>商品单价</dt><dd>¥{{ number_format($order->unit_price, 2) }}</dd></div>
            <div><dt>购买数量</dt><dd>{{ $order->quantity }} 件</dd></div>
            @if($order->discount_amount > 0)<div><dt>优惠金额</dt><dd class="m-order-discount">−¥{{ number_format($order->discount_amount, 2) }}</dd></div>@endif
            <div><dt>支付方式</dt><dd>{{ $methodLabel }}</dd></div>
            <div><dt>下单时间</dt><dd>{{ $order->created_at->format('Y-m-d H:i') }}</dd></div>
            @if(!$expired)<div><dt>支付截止</dt><dd>{{ $order->expires_at->format('Y-m-d H:i') }}</dd></div>@endif
        </dl>
        <div class="m-order-total"><span>应付合计</span><strong><small>¥</small>{{ number_format($order->total_amount, 2) }}</strong></div>
        <p class="m-hint m-order-receipt-foot">需要查询这笔购买时，请保留下单邮箱、查询密码和订单编号。</p>
    </section>
    <aside class="m-order-payment-side">
        @if($expired)
        <section class="m-order-payment-card m-order-dead">
            <span class="m-order-state-symbol" aria-hidden="true"><svg width="30" height="30" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="16" cy="16" r="12"/>@if($order->status === 'closed')<path d="m12 12 8 8m0-8-8 8"/>@else<path d="M16 9v7l5 3"/>@endif</svg></span>
            <h2 class="m-section-title">{{ $deadTitle ?? (($paymentReview ?? false) ? '等待人工核对' : '这笔订单已结束') }}</h2>
            <p class="m-muted">{{ $deadReason ?? '订单已经超过支付时限，请重新下单。' }}</p>
            <a href="/" class="m-button m-button-primary m-button-wide">{{ ($paymentReview ?? false) ? '返回商品目录' : '重新挑选商品' }} <span aria-hidden="true">↗</span></a>
            <a href="/order/pay/{{ $order->order_no }}" class="m-button m-button-secondary m-button-wide">刷新订单状态</a>
            <a href="/order/query?order_no={{ $order->order_no }}" class="m-order-text-link">验证并查询订单</a>
        </section>
        @else
        <section class="m-order-payment-card">
            <p class="m-eyebrow">PAYMENT · 等待付款</p>
            <h2 class="m-section-title">使用{{ $methodLabel }}支付</h2>
            <p class="m-muted">请在有效期内完成付款。</p>
            <div class="m-order-payment-amount"><span>应付金额</span><strong><small>¥</small>{{ number_format($order->total_amount, 2) }}</strong></div>
            <div class="m-order-timer" id="countdown-timer" data-expires="{{ $order->expires_at->toIso8601String() }}"><span>剩余支付时间</span><strong class="time">--:--</strong></div>
            @if($paymentUrl)
            <a href="{{ $paymentUrl }}" class="m-button m-button-primary m-button-wide" target="_blank" rel="noopener">前往支付 <span aria-hidden="true">↗</span></a>
            <p class="m-hint m-order-payment-hint">收银台将在新标签页打开，付款完成后回到这里。</p>
            @elseif($paymentUnavailable ?? false)
            <div class="m-order-message m-order-message-warning" role="alert"><strong>支付渠道暂时不可用</strong><p>无法生成支付链接，请稍后刷新重试。若持续如此，请联系站点客服并提供订单编号。</p></div>
            <a href="/order/pay/{{ $order->order_no }}" class="m-button m-button-secondary m-button-wide">刷新支付页面</a>
            @else
            <div class="m-order-message" role="status"><strong>正在准备支付渠道</strong><p>如果支付按钮迟迟未出现，请刷新本页重试。</p></div>
            <a href="/order/pay/{{ $order->order_no }}" class="m-button m-button-secondary m-button-wide">刷新支付页面</a>
            @endif
            <div class="m-order-polling" id="payment-polling" data-order-no="{{ $order->order_no }}" data-expires="{{ $order->expires_at->toIso8601String() }}" aria-live="polite"><span class="m-order-status-dot" aria-hidden="true"></span><span data-payment-status>正在确认支付状态，成功后自动显示卡密。</span><button type="button" class="m-button m-button-secondary" data-payment-recheck>重新检查付款</button> <a href="/order/query?order_no={{ $order->order_no }}" data-payment-verify hidden>验证并查询订单</a></div>
            <noscript><p class="m-order-message m-order-message-warning">当前浏览器未启用 JavaScript，付款后请手动刷新本页；支付截止时间见订单清单。</p></noscript>
        </section>
        @endif
        <section class="m-order-next"><h2>付款之后</h2><ol><li><span>01</span><p>系统确认支付，自动发放卡密。</p></li><li><span>02</span><p>在订单详情里查看、复制或下载。</p></li><li><span>03</span><p>之后可使用邮箱与查询密码再次取回。</p></li></ol></section>
    </aside>
</div>
@endsection
