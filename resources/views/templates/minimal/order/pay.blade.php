@extends(theme_view_path('layout'))
@section('title', '订单支付 - ' . setting('site_name', 'CardShop'))
@php
    $methodLabels = ['alipay' => '支付宝', 'wechat' => '微信支付', 'usdt_trc20' => 'USDT (TRC20)', 'usdt_bep20' => 'USDT (BEP20)', 'usdt_polygon' => 'USDT (Polygon)', 'manual' => '人工确认'];
    $methodLabel = $methodLabels[$order->payment_method] ?? ($order->payment_method ?: '—');
@endphp
@section('content')
<nav class="n-breadcrumb" aria-label="面包屑"><a href="/">商店</a><span aria-hidden="true">/</span><span aria-current="page">订单支付</span></nav>
<header class="n-page-header"><div><span class="n-kicker">CHECKOUT</span><h1>{{ $expired ? ($deadTitle ?? '订单已过期') : '完成这次购买' }}</h1><p>{{ $expired ? ($deadReason ?? '此订单已超过支付时限，请重新下单。') : '确认金额，完成付款，自动领取卡密。' }}</p></div>@themeInclude('partials.order-status', ['status' => $order->status])</header>
<div class="n-payment-sheet">
    <section class="n-payment-main" aria-labelledby="n-payment-heading">
        @if($expired)
        <span class="n-state-icon" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="16" cy="16" r="12"/><path d="m12 12 8 8m0-8-8 8"/></svg></span><h2 id="n-payment-heading">{{ $deadTitle ?? (($paymentReview ?? false) ? '等待人工核对' : '这笔订单已经结束') }}</h2><p>{{ $deadReason ?? '已超过支付有效期，请重新选择商品并下单。' }}</p><div class="n-payment-actions"><a href="/" class="n-button n-button-primary n-button-wide">{{ ($paymentReview ?? false) ? '返回商品目录' : '重新挑选商品' }} →</a><a href="/order/pay/{{ $order->order_no }}" class="n-button n-button-secondary n-button-wide">刷新订单状态</a><a href="/order/query?order_no={{ $order->order_no }}" class="n-button n-button-text n-button-wide">验证并查询订单</a></div>
        @else
        <div class="n-payment-channel">@themeInclude('partials.pay-icon', ['method' => $order->payment_method])<span>{{ $methodLabel }}</span></div><h2 id="n-payment-heading">确认支付金额</h2><div class="n-payment-amount"><small>¥</small><strong>{{ number_format($order->total_amount, 2) }}</strong></div><p class="n-payment-explainer">支付成功后，卡密会显示在订单页面。</p>
        <div class="n-timer" id="countdown-timer" data-expires="{{ $order->expires_at->toIso8601String() }}"><span>剩余支付时间</span><strong class="time">--:--</strong></div>
        <div class="n-payment-actions">
            @if($paymentUrl)<a href="{{ $paymentUrl }}" class="n-button n-button-primary n-button-wide" target="_blank" rel="noopener">前往支付 <span aria-hidden="true">↗</span></a><p class="n-hint">收银台在新标签页打开。付款后回到这里查看结果。</p>
            @elseif($paymentUnavailable ?? false)<div class="n-message n-message-error" role="alert"><strong>支付渠道暂时不可用</strong><p>无法生成支付链接，请刷新重试。若持续如此，请联系客服并提供订单编号。</p></div><a href="/order/pay/{{ $order->order_no }}" class="n-button n-button-secondary n-button-wide">刷新支付页面</a>
            @else<div class="n-message" role="status"><strong>正在准备支付渠道</strong><p>若支付按钮未出现，请刷新本页重试。</p></div><a href="/order/pay/{{ $order->order_no }}" class="n-button n-button-secondary n-button-wide">刷新支付页面</a>@endif
        </div>
        <div class="n-payment-polling" id="payment-polling" data-order-no="{{ $order->order_no }}" data-expires="{{ $order->expires_at->toIso8601String() }}" aria-live="polite"><span class="n-dot" aria-hidden="true"></span><span data-payment-status>自动确认付款，成功后展示卡密。</span><button type="button" class="n-button n-button-secondary" data-payment-recheck>重新检查付款</button> <a href="/order/query?order_no={{ $order->order_no }}" data-payment-verify hidden>验证并查询订单</a></div>
        <noscript><p class="n-message">浏览器未启用 JavaScript，付款后请手动刷新本页。支付截止时间见右侧订单信息。</p></noscript>
        @endif
    </section>
    <aside class="n-payment-receipt">@themeInclude('partials.order-summary', ['showDeadline' => !$expired])<p class="n-hint">保留下单邮箱、查询密码与订单编号，之后仍可找回这份购买。</p><a href="/order/query" class="n-button n-button-text">查询其他订单 →</a></aside>
</div>
@endsection
