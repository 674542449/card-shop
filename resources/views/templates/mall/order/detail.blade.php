@extends(theme_view_path('layout'))

@section('title', '订单详情 - ' . setting('site_name', 'CardShop'))

@php
    $methodLabel = \App\Support\PaymentMethods::label($order->payment_method);
    $stateKey = $order->isPaid() ? 'paid' : (($order->isExpired() || $order->status === 'expired') ? 'expired' : ($order->status === 'closed' ? 'closed' : 'pending'));
    $canReadCards = ($verified ?? false) && $order->isPaid() && $cards->isNotEmpty();
    $stateTitles = [
        'paid' => $canReadCards ? '支付成功，卡密已交付' : '支付已完成，查看订单详情',
        'pending' => '订单待付款',
        'expired' => '订单已过期',
        'closed' => '订单已关闭',
    ];
    $stateNotes = [
        'paid' => $canReadCards ? '请及时复制或下载 TXT 备份卡密，后续也可凭下单邮箱与查询密码随时找回。' : '订单已付款成功，订单清单详见右侧。',
        'pending' => '请在支付截止时间前完成付款，支付成功后系统将自动发放卡密。',
        'expired' => '此订单已超时未付款。若已完成扣款请联系客服核对，切勿重复支付；尚未付款可重新下单。',
        'closed' => '此订单已关闭，如需购买请返回商城重新选购下单。',
    ];
    $paymentReview = !$order->isPaid() && !empty($order->payment_no);
    if ($paymentReview) {
        $stateTitles[$stateKey] = '付款待核对';
        $stateNotes[$stateKey] = '已收到付款回执但暂未完成发货，请联系客服提供订单号核对，请勿重复支付。';
    }
@endphp

@section('content')
    <nav class="ml-breadcrumb" aria-label="面包屑">
        <ol>
            <li><a href="/">商城首页</a></li>
            <li><a href="/order/query">订单查询</a></li>
            <li><a href="/order/detail/{{ $order->order_no }}" aria-current="page">订单详情</a></li>
        </ol>
    </nav>

    <header class="ml-order-top">
        <div>
            <span class="ml-badge {{ $order->isPaid() ? 'ml-badge-live' : 'ml-badge-brand' }}">
                {{ $order->isPaid() ? '卡密交付凭证' : '订单状态跟踪' }}
            </span>
            <h1>{{ $stateTitles[$stateKey] }}</h1>
            <p class="ml-hero-desc">{{ $stateNotes[$stateKey] }}</p>
        </div>
        @themeInclude('partials.order-status', ['status' => $order->status])
    </header>

    <div class="ml-order-grid ml-delivery-grid">
        <div class="ml-delivery-main">
            @if($canReadCards)
            <section class="ml-card ml-delivery-card" aria-labelledby="ml-delivery-title">
                <div class="ml-delivery-head">
                    <div>
                        <h2 id="ml-delivery-title">已发放卡密 <span class="ml-badge ml-badge-brand">{{ $cards->count() }} 条</span></h2>
                        <p class="ml-muted">支持单条一键复制或打包导出 TXT 文本备份</p>
                    </div>
                    <div class="ml-order-actions">
                        <button type="button" class="ml-btn ml-btn-secondary ml-btn-sm btn-copy" data-target="card-content-text">复制全部</button>
                        <a href="/order/cards/{{ $order->order_no }}/download" class="ml-btn ml-btn-primary ml-btn-sm">下载 TXT ↓</a>
                    </div>
                </div>

                <ol class="ml-card-keys">
                    @foreach($cards as $card)
                    <li class="ml-card-key">
                        <div class="ml-card-key-head">
                            <span>卡密 #{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <button type="button" class="ml-btn ml-btn-secondary ml-btn-xs btn-copy" data-target="card-content-{{ $loop->iteration }}">复制</button>
                        </div>
                        <code id="card-content-{{ $loop->iteration }}">{{ $card->content }}</code>
                    </li>
                    @endforeach
                </ol>

                <textarea id="card-content-text" class="ml-sr-only" readonly tabindex="-1" aria-hidden="true" hidden>{{ $cards->pluck("content")->implode("\n") . "\n" }}</textarea>
            </section>

            <div class="ml-assurance-bar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1" stroke-linecap="round"/></svg>
                <p>建议立即下载 TXT 或复制留存。后续凭下单邮箱与查询密码可随时回到本页重新查看。</p>
            </div>
            @else
            <section class="ml-card ml-empty ml-order-empty">
                @themeInclude('partials.image-placeholder')
                <h2>{{ $stateKey === 'pending' ? '付款完成后立即自动发卡' : ($stateKey === 'paid' ? '暂未加载到卡密记录' : '本订单未发放卡密') }}</h2>
                <p>{{ $paymentReview ? $stateNotes[$stateKey] : ($stateKey === 'pending' ? '该订单正等待支付，完成付款后将在此页展示全部卡密。' : ($stateKey === 'paid' ? '请稍后刷新查看；如持续异常请联系客服并提供订单编号。' : '你可以返回商城目录重新挑选商品并下单。')) }}</p>
                <div class="ml-order-actions">
                    @if($stateKey === 'pending' && empty($order->payment_no))
                    <a href="{{ \App\Support\OrderPayAccess::path($order) }}" class="ml-btn ml-btn-primary ml-btn-sm">前往继续支付 →</a>
                    @else
                    <a href="/" class="ml-btn ml-btn-primary ml-btn-sm">逛逛全部商品 →</a>
                    @endif
                    <a href="/order/query" class="ml-btn ml-btn-secondary ml-btn-sm">返回订单查询</a>
                </div>
            </section>
            @endif

            <div class="ml-delivery-links">
                <a href="/" class="ml-link">← 继续浏览商品</a>
                <a href="/order/query" class="ml-link">查询其他订单 →</a>
            </div>
        </div>

        <aside class="ml-card ml-receipt-card" aria-labelledby="ml-detail-receipt-title">
            <div class="ml-receipt-head">
                <h2 id="ml-detail-receipt-title">订单摘要</h2>
            </div>

            <div class="ml-receipt-product">
                <span class="ml-order-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18m-13 5h4"/></svg>
                </span>
                <div>
                    <h3>{{ $order->displayName() ?? '商品已下架' }}</h3>
                    <p class="ml-muted">{{ $order->quantity }} 件 · ¥{{ number_format($order->unit_price, 2) }}/件</p>
                </div>
            </div>

            <dl class="ml-dl">
                <div><dt>订单编号</dt><dd><code>{{ $order->order_no }}</code></dd></div>
                @if($verified ?? false)
                <div><dt>接收邮箱</dt><dd>{{ $order->email }}</dd></div>
                @endif
                <div><dt>支付方式</dt><dd>{{ $methodLabel }}</dd></div>
                @if($order->discount_amount > 0)
                <div><dt>优惠抵扣</dt><dd class="ml-discount">−¥{{ number_format($order->discount_amount, 2) }}</dd></div>
                @endif
                <div><dt>下单时间</dt><dd>{{ $order->created_at->format('Y-m-d H:i') }}</dd></div>
                @if($order->paid_at)
                <div><dt>支付时间</dt><dd>{{ $order->paid_at->format('Y-m-d H:i') }}</dd></div>
                @endif
                @if($stateKey === 'pending' && empty($order->payment_no))
                <div><dt>支付截止</dt><dd>{{ $order->expires_at->format('Y-m-d H:i') }}</dd></div>
                @endif
            </dl>

            <div class="ml-receipt-total">
                <span>{{ $order->isPaid() ? '实付金额' : '应付金额' }}</span>
                <strong><small>¥</small>{{ number_format($order->total_amount, 2) }}</strong>
            </div>
        </aside>
    </div>

    @include('shared.refund-panel', ['panelClass' => 'ml-card ml-refund-panel', 'buttonClass' => 'ml-btn ml-btn-primary ml-btn-sm'])
    @themeInclude('partials.order-cancel')
@endsection
