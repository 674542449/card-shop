@extends(theme_view_path('layout'))
@section('title', '订单详情 - ' . setting('site_name', 'CardShop'))
@php
    $methodLabels = ['alipay' => '支付宝', 'wechat' => '微信支付', 'usdt_trc20' => 'USDT (TRC20)', 'usdt_bep20' => 'USDT (BEP20)', 'usdt_polygon' => 'USDT (Polygon)', 'manual' => '人工确认'];
    $methodLabel = $methodLabels[$order->payment_method] ?? ($order->payment_method ?: '—');
    $stateKey = $order->isPaid() ? 'paid' : (($order->isExpired() || $order->status === 'expired') ? 'expired' : ($order->status === 'closed' ? 'closed' : 'pending'));
    $canReadCards = ($verified ?? false) && $order->isPaid() && $cards->isNotEmpty();
    $stateTitles = ['paid' => '购买已完成', 'pending' => '等待付款', 'expired' => '订单已过期', 'closed' => '订单已关闭'];
    $stateNotes = ['paid' => '复制或下载卡密，随时通过订单查询再次取回。', 'pending' => '请在有效期内完成付款，支付成功后自动发放卡密。', 'expired' => '已经超过支付有效期。若已付款，请先查询订单或联系客服核对，请勿重复支付；尚未付款可重新下单。', 'closed' => '这笔订单已关闭，可以重新选择商品下单。'];
    $paymentReview = !$order->isPaid() && !empty($order->payment_no);
    if ($paymentReview) {
        $stateTitles[$stateKey] = '付款待核对';
        $stateNotes[$stateKey] = '收到付款回执，订单暂未发货，请联系客服核对，请勿重复支付。';
    }
@endphp
@section('content')
<nav class="n-breadcrumb" aria-label="面包屑"><a href="/">商店</a><span aria-hidden="true">/</span><a href="/order/query">订单查询</a><span aria-hidden="true">/</span><span aria-current="page">订单详情</span></nav>
<header class="n-page-header"><div><span class="n-kicker">{{ $order->isPaid() ? 'DELIVERY' : 'ORDER DETAILS' }}</span><h1>{{ $stateTitles[$stateKey] }}</h1><p>{{ $stateNotes[$stateKey] }}</p></div>@themeInclude('partials.order-status', ['status' => $order->status])</header>
<div class="n-order-reference-bar"><span>订单编号</span><code>{{ $order->order_no }}</code><span>{{ $order->quantity }} 件商品</span></div>
<div class="n-delivery-layout">
    <section class="n-delivery-main">
        @if($canReadCards)
        <div class="n-delivery-toolbar"><div><h2>卡密内容</h2><p class="n-hint">共 {{ $cards->count() }} 条，请妥善保存。</p></div><div class="n-actions"><button type="button" class="n-button n-button-secondary btn-copy" data-target="card-content-text">复制全部</button><a href="/order/cards/{{ $order->order_no }}/download" class="n-button n-button-primary">下载 TXT <span aria-hidden="true">↓</span></a></div></div>
        <ol class="n-key-list">@foreach($cards as $card)<li class="n-key"><div class="n-key-head"><span>卡密 {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><button type="button" class="n-button n-button-text btn-copy" data-target="card-content-{{ $loop->iteration }}">复制</button></div><code id="card-content-{{ $loop->iteration }}">{{ $card->content }}</code></li>@endforeach</ol>
        <textarea id="card-content-text" readonly tabindex="-1" aria-hidden="true" hidden>{{ $cards->pluck("content")->implode("\n") . "\n" }}</textarea>
        <p class="n-delivery-note">建议保存一份备份。使用下单邮箱与查询密码，可以再次查看已购卡密。</p>
        @else
        <div class="n-empty n-delivery-empty">@themeInclude('partials.image-placeholder')<h2>{{ $stateKey === 'pending' ? '完成支付后，卡密会出现在这里' : ($stateKey === 'paid' ? '暂无可展示的卡密' : '这笔订单没有发放卡密') }}</h2><p>{{ $paymentReview ? $stateNotes[$stateKey] : ($stateKey === 'pending' ? '这笔购买还在等待付款。' : ($stateKey === 'paid' ? '请稍后重新查看。若仍未显示，请联系客服并提供订单编号。' : '返回商品目录，创建一份新的订单。')) }}</p><div class="n-actions">@if($stateKey === 'pending' && empty($order->payment_no))<a href="/order/pay/{{ $order->order_no }}" class="n-button n-button-primary">继续支付 →</a>@else<a href="/" class="n-button n-button-primary">浏览商品 →</a>@endif<a href="/order/query" class="n-button n-button-secondary">返回订单查询</a></div></div>
        @endif
    </section>
    <aside class="n-delivery-receipt">@themeInclude('partials.order-summary', ['showDeadline' => $stateKey === 'pending'])</aside>
</div>
<div class="n-order-links"><a href="/" class="n-button n-button-text">← 继续浏览商品</a><a href="/order/query" class="n-button n-button-text">查询其他订单 →</a></div>
@include('shared.refund-panel', ['panelClass' => 'n-refund-panel', 'buttonClass' => 'n-button n-button-primary'])
@themeInclude('partials.order-cancel')
@endsection
