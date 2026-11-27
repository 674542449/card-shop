@extends(theme_view_path('layout'))

@section('title', '订单支付 - ' . setting('site_name', 'CardShop'))

@php
    $methodLabel = \App\Support\PaymentMethods::label($order->payment_method);
@endphp

@section('content')
    <nav class="ml-breadcrumb" aria-label="面包屑">
        <ol>
            <li><a href="/">商城首页</a></li>
            <li><span aria-current="page">订单支付</span></li>
        </ol>
    </nav>

    <header class="ml-order-top">
        <div>
            <span class="ml-badge ml-badge-brand">安全收银台</span>
            <h1>{{ $expired ? ($deadTitle ?? '订单已结束') : '确认并完成支付' }}</h1>
            <p class="ml-hero-desc">{{ $expired ? ($deadReason ?? '该订单已超过支付时限，请重新下单。') : '核对清单后在倒计时内完成付款，付款成功立即自动发卡。' }}</p>
        </div>
        <a href="/order/query" class="ml-btn ml-btn-secondary ml-btn-sm">查询其他订单 →</a>
    </header>

    <div class="ml-order-grid">
        <section class="ml-card ml-receipt-card" aria-labelledby="ml-receipt-title">
            <div class="ml-receipt-head">
                <h2 id="ml-receipt-title">订单清单</h2>
                @themeInclude('partials.order-status', ['status' => $order->status])
            </div>

            <div class="ml-receipt-product">
                <span class="ml-order-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18m-13 5h4"/></svg>
                </span>
                <div>
                    <h3>{{ $order->displayName() ?? '商品已下架' }}</h3>
                    <p class="ml-muted">数字商品 · {{ $order->quantity }} 件</p>
                </div>
            </div>

            <dl class="ml-dl">
                <div><dt>订单编号</dt><dd><code>{{ $order->order_no }}</code></dd></div>
                <div><dt>商品单价</dt><dd>¥{{ number_format($order->unit_price, 2) }}</dd></div>
                <div><dt>购买数量</dt><dd>{{ $order->quantity }} 件</dd></div>
                @if($order->discount_amount > 0)
                <div><dt>优惠抵扣</dt><dd class="ml-discount">−¥{{ number_format($order->discount_amount, 2) }}</dd></div>
                @endif
                <div><dt>支付方式</dt><dd>{{ $methodLabel }}</dd></div>
                <div><dt>下单时间</dt><dd>{{ $order->created_at->format('Y-m-d H:i') }}</dd></div>
                @if(!$expired)
                <div><dt>支付截止</dt><dd>{{ $order->expires_at->format('Y-m-d H:i') }}</dd></div>
                @endif
            </dl>

            <div class="ml-receipt-total">
                <span>应付合计</span>
                <strong><small>¥</small>{{ number_format($order->total_amount, 2) }}</strong>
            </div>
            <p class="ml-hint">请妥善记录下单邮箱、查询密码与订单编号，方便随时查单提取。</p>
        </section>

        <aside class="ml-pay-side">
            @if($expired)
            <section class="ml-card ml-pay-card ml-pay-dead">
                <span class="ml-state-icon" aria-hidden="true">
                    <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.8">
                        <circle cx="16" cy="16" r="12"/>
                        @if($order->status === 'closed')
                        <path d="m12 12 8 8m0-8-8 8" stroke-linecap="round"/>
                        @else
                        <path d="M16 9v7l5 3" stroke-linecap="round"/>
                        @endif
                    </svg>
                </span>
                <h2>{{ $deadTitle ?? (($paymentReview ?? false) ? '等待人工核对' : '这笔订单已结束') }}</h2>
                <p class="ml-muted">{{ $deadReason ?? '订单已超过支付有效期，请重新选购商品下单。' }}</p>
                <div class="ml-pay-dead-actions">
                    <a href="/" class="ml-btn ml-btn-primary ml-btn-wide">{{ ($paymentReview ?? false) ? '返回商品目录' : '重新选购商品' }} →</a>
                    <a href="{{ $payUrl ?? \App\Support\OrderPayAccess::path($order) }}" class="ml-btn ml-btn-secondary ml-btn-wide">刷新订单状态</a>
                    <a href="/order/query?order_no={{ $order->order_no }}" class="ml-link">验证并查询订单</a>
                </div>
            </section>
            @else
            <section class="ml-card ml-pay-card">
                <span class="ml-badge ml-badge-neutral">等待付款 · {{ $methodLabel }}</span>
                <div class="ml-pay-amount">
                    <span>应付金额</span>
                    <strong><small>¥</small>{{ number_format($order->total_amount, 2) }}</strong>
                </div>

                <div class="ml-countdown" id="countdown-timer" data-expires="{{ $order->expires_at->toIso8601String() }}">
                    <span class="ml-inline-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                        <span>剩余支付时间</span>
                    </span>
                    <strong class="time">--:--</strong>
                </div>

                @if($paymentUrl)
                <a href="{{ $paymentUrl }}" class="ml-btn ml-btn-primary ml-btn-wide ml-btn-lg" data-payment-action target="_blank" rel="noopener noreferrer">前往收银台支付 ↗</a>
                <p class="ml-hint">收银台将在新窗口打开，付款成功后返回本页自动跳转展示卡密。</p>
                @elseif($paymentUnavailable ?? false)
                <div class="ml-alert ml-alert-warn" role="alert">
                    <strong>支付渠道暂时不可用</strong>
                    <p>暂时无法生成支付链接，请稍后刷新重试。若持续出现，请联系在线客服并提供订单编号。</p>
                </div>
                <a href="{{ $payUrl ?? \App\Support\OrderPayAccess::path($order) }}" class="ml-btn ml-btn-secondary ml-btn-wide">刷新支付页面</a>
                @else
                <div class="ml-alert ml-alert-info" role="status">
                    <strong>正在准备支付通道</strong>
                    <p>如支付按钮未立即出现，请点击下方按钮刷新本页。</p>
                </div>
                <a href="{{ $payUrl ?? \App\Support\OrderPayAccess::path($order) }}" class="ml-btn ml-btn-secondary ml-btn-wide">刷新支付页面</a>
                @endif

                <div class="ml-polling" id="payment-polling" data-order-no="{{ $order->order_no }}" data-poll-url="{{ $payUrl ?? \App\Support\OrderPayAccess::path($order) }}" data-expires="{{ $order->expires_at->toIso8601String() }}" aria-live="polite">
                    <div class="ml-polling-line">
                        <span class="ml-pulse-dot" aria-hidden="true"></span>
                        <span data-payment-status>正在自动监测付款状态，到账后自动显示卡密。</span>
                    </div>
                    <div class="ml-polling-actions">
                        <button type="button" class="ml-btn ml-btn-secondary ml-btn-sm" data-payment-recheck>重新检查付款</button>
                        <a href="/order/query?order_no={{ $order->order_no }}" class="ml-link" data-payment-verify hidden>验证并查询订单</a>
                    </div>
                </div>

                <noscript>
                    <p class="ml-alert ml-alert-warn">当前浏览器未启用 JavaScript，付款完成后请手动刷新本页查看结果。</p>
                </noscript>
            </section>
            @endif
        </aside>
    </div>
@endsection
