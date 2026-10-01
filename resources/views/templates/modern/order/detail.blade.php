@extends(theme_view_path('layout'))

@section('title', '订单详情 - ' . setting('site_name', 'CardShop'))

@php
    $methodLabels = ['alipay' => '支付宝', 'wechat' => '微信支付', 'usdt_trc20' => 'USDT (TRC20)', 'usdt_bep20' => 'USDT (BEP20)', 'usdt_polygon' => 'USDT (Polygon)', 'manual' => '人工确认'];
    $methodLabel = $methodLabels[$order->payment_method] ?? ($order->payment_method ?: '—');
    $stateKey = $order->isPaid() ? 'paid' : (($order->isExpired() || $order->status === 'expired') ? 'expired' : ($order->status === 'closed' ? 'closed' : 'pending'));
    $canReadCards = ($verified ?? false) && $order->isPaid() && $cards->isNotEmpty();
    $stateTitles = ['paid' => $canReadCards ? '购买完成，卡密已就绪。' : '支付完成，查看这份购买。', 'pending' => '订单已创建，等待付款。', 'expired' => '这笔订单已过期。', 'closed' => '这笔订单已关闭。'];
    $stateNotes = ['paid' => $canReadCards ? '在这里保存卡密，也可以随时通过订单查询再次取回。' : '支付已经完成，订单信息见下方。', 'pending' => '请在支付有效期内完成付款，卡密将在支付成功后发放。', 'expired' => '已超过支付有效期。若已付款，请先查询订单或联系客服核对，请勿重复支付；尚未付款可重新下单。', 'closed' => '此订单已关闭。你可以重新挑选商品并下单。'];
    $paymentReview = !$order->isPaid() && !empty($order->payment_no);
    if ($paymentReview) {
        $stateTitles[$stateKey] = '付款待核对';
        $stateNotes[$stateKey] = '收到付款回执，订单暂未发货，请联系客服核对，请勿重复支付。';
    }
@endphp

@section('content')
<nav class="m-breadcrumb" aria-label="当前位置"><a href="/">首页</a><span aria-hidden="true">/</span><a href="/order/query">订单查询</a><span aria-hidden="true">/</span><span>订单详情</span></nav>
<div class="m-order-page-top">
    <div><p class="m-eyebrow">{{ $order->isPaid() ? 'YOUR DELIVERY · 卡密交付' : 'ORDER DETAILS · 订单详情' }}</p><h1 class="m-page-heading">{{ $stateTitles[$stateKey] }}</h1><p class="m-lead">{{ $stateNotes[$stateKey] }}</p></div>
    @themeInclude('partials.order-status', ['status' => $order->status])
</div>
<div class="m-order-layout m-order-delivery-layout">
    <div class="m-order-delivery-main">
        @if($canReadCards)
        <section class="m-panel m-order-delivery-panel" aria-labelledby="m-delivery-heading">
            <div class="m-order-delivery-heading"><div><p class="m-eyebrow">READY TO USE</p><h2 class="m-section-title" id="m-delivery-heading">你的卡密 <span class="m-order-key-count">{{ $cards->count() }} 条</span></h2></div><div class="m-order-actions"><button type="button" class="m-button m-button-secondary btn-copy" data-target="card-content-text">复制全部</button><a href="/order/cards/{{ $order->order_no }}/download" class="m-button m-button-primary">下载 TXT <span aria-hidden="true">↓</span></a></div></div>
            <ol class="m-order-keys">
                @foreach($cards as $card)
                <li class="m-order-key"><div class="m-order-key-top"><span>卡密 {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><button type="button" class="m-button m-button-quiet btn-copy" data-target="card-content-{{ $loop->iteration }}">复制</button></div><code id="card-content-{{ $loop->iteration }}">{{ $card->content }}</code></li>
                @endforeach
            </ol>
            <textarea id="card-content-text" class="m-order-copy-source" readonly tabindex="-1" aria-hidden="true" hidden>{{ $cards->pluck("content")->implode("\n") . "\n" }}</textarea>
        </section>
        <aside class="m-order-note"><span class="m-order-note-mark" aria-hidden="true">i</span><div><h2>给这份购买留个备份</h2><p>建议复制或下载卡密并妥善保存。下次使用下单邮箱和查询密码，还能回到这里查看。</p></div></aside>
        @else
        <section class="m-panel m-order-awaiting">
            <span class="m-order-state-symbol" aria-hidden="true"><svg width="36" height="36" viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="7" y="9" width="26" height="22" rx="3"/><path d="M7 16h26m-20 8h6"/></svg></span>
            <h2 class="m-section-title">{{ $stateKey === 'pending' ? '完成付款，即可获取卡密' : ($stateKey === 'paid' ? '暂无可展示的卡密' : '这笔订单没有发放卡密') }}</h2>
            <p class="m-muted">{{ $paymentReview ? $stateNotes[$stateKey] : ($stateKey === 'pending' ? '这份订单还在等待付款。支付成功后，卡密会显示在这里。' : ($stateKey === 'paid' ? '请稍后重新查看；如果仍未显示，请联系站点客服并提供订单编号。' : '你可以重新挑选商品，创建一份新的订单。')) }}</p>
            <div class="m-order-actions">@if($stateKey === 'pending' && empty($order->payment_no))<a href="/order/pay/{{ $order->order_no }}" class="m-button m-button-primary">继续支付 <span aria-hidden="true">↗</span></a>@else<a href="/" class="m-button m-button-primary">浏览商品 <span aria-hidden="true">↗</span></a>@endif<a href="/order/query" class="m-button m-button-secondary">返回订单查询</a></div>
        </section>
        @endif
        <div class="m-order-bottom-links"><a href="/" class="m-order-text-link">继续浏览商品 <span aria-hidden="true">↗</span></a><a href="/order/query" class="m-order-text-link">查询其他订单</a></div>
    </div>
    <aside class="m-panel m-order-receipt m-order-delivery-receipt" aria-labelledby="m-detail-receipt-heading">
        <div class="m-order-receipt-heading"><div><p class="m-eyebrow">ORDER RECEIPT</p><h2 class="m-section-title" id="m-detail-receipt-heading">这份购买</h2></div></div>
        <div class="m-order-receipt-product"><span class="m-order-product-icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18m-13 5h4"/></svg></span><div><h3>{{ $order->displayName() ?? '商品已下架' }}</h3><p class="m-muted">{{ $order->quantity }} 件 <span aria-hidden="true">·</span> ¥{{ number_format($order->unit_price, 2) }}/件</p></div></div>
        <dl class="m-details">
            <div><dt>订单编号</dt><dd class="m-order-code">{{ $order->order_no }}</dd></div>
            @if($verified ?? false)<div><dt>下单邮箱</dt><dd>{{ $order->email }}</dd></div>@endif
            <div><dt>支付方式</dt><dd>{{ $methodLabel }}</dd></div>
            @if($order->discount_amount > 0)<div><dt>优惠金额</dt><dd class="m-order-discount">−¥{{ number_format($order->discount_amount, 2) }}</dd></div>@endif
            <div><dt>下单时间</dt><dd>{{ $order->created_at->format('Y-m-d H:i') }}</dd></div>
            @if($order->paid_at)<div><dt>支付时间</dt><dd>{{ $order->paid_at->format('Y-m-d H:i') }}</dd></div>@endif
            @if($stateKey === 'pending' && empty($order->payment_no))<div><dt>支付截止</dt><dd>{{ $order->expires_at->format('Y-m-d H:i') }}</dd></div>@endif
        </dl>
        <div class="m-order-total"><span>{{ $order->isPaid() ? '实付金额' : '订单金额' }}</span><strong><small>¥</small>{{ number_format($order->total_amount, 2) }}</strong></div>
    </aside>
</div>
@if($verified ?? false)
 <section class="m-refund-panel" aria-label="退款与售后">@if($order->isPaid() && \App\Models\Order::paymentReview()->whereKey($order->id)->exists())<p role="status">购买已完成，另有付款回执等待店主核对。请联系客服处理额外付款，请勿重复付款。</p>@endif<h2>退款与售后</h2>
 @if($order->refunds->isNotEmpty())<ul>@foreach($order->refunds as $refund)<li>¥{{ $refund->amount }} · {{ ['requested'=>'待审核','approved'=>'待退款','completed'=>'已退款','rejected'=>'已拒绝'][$refund->status] ?? $refund->status }} · {{ $refund->created_at->format('Y-m-d H:i') }}</li>@endforeach</ul>@endif
 @if($order->isPaid())<details><summary>申请退款</summary><p>提交后由店主审核，实际退款完成后将更新记录。</p><form method="POST" action="/order/refund/{{ $order->order_no }}">@csrf<label>退款金额（元）<input type="number" name="amount" min="0.01" max="{{ $order->total_amount }}" step="0.01" value="{{ $order->total_amount }}" required></label><label>申请原因<textarea name="reason" maxlength="2000" required></textarea></label><button class="m-button m-button-primary" type="submit">提交申请</button></form></details>@else<p>异常付款请联系客服，并提供订单编号。</p>@endif
 </section>@endif
@themeInclude('partials.order-cancel')
@endsection
