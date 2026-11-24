@extends(theme_view_path('layout'))

@section('title', '订单支付 - ' . setting('site_name', 'CardShop'))

@section('content')
    <div class="ws-console">
        <div class="ws-console-nav">
            <a href="/" class="ws-btn ws-btn-secondary ws-btn-sm">&larr; 返回商品目录</a>
            <a href="/order/query?order_no={{ $order->order_no }}" class="ws-btn ws-btn-secondary ws-btn-sm">订单查询 &rarr;</a>
        </div>

        <div class="ws-steps" aria-label="订单进度">
            <div class="ws-step is-done"><span class="ws-step-num">01</span><span>提交订单</span></div>
            <div class="ws-step {{ !$expired ? 'is-current' : '' }}"><span class="ws-step-num">02</span><span>在线支付</span></div>
            <div class="ws-step"><span class="ws-step-num">03</span><span>自动交付</span></div>
        </div>

        @if(!$expired)
        <div class="ws-console-card">
            <div class="ws-cashier-hero">
                <div id="countdown-timer" data-expires="{{ $order->expires_at->toIso8601String() }}">
                    <span class="ws-cashier-caption">请在支付有效期内完成付款</span>
                    <span class="time">--:--</span>
                    <span class="ws-cashier-subcaption">超时未支付将自动关闭订单并释放预留库存</span>
                </div>

                <div class="ws-cashier-cta-wrap">
                    @if($paymentUrl)
                    <a href="{{ $paymentUrl }}" class="ws-btn ws-btn-primary ws-btn-lg ws-cashier-cta" target="_blank" rel="noopener noreferrer">前往支付平台付款 &nearr;</a>
                    <span class="ws-cashier-hint">支付完成后请保持当前页面开启，系统确认回执后将自动跳转至交付页面</span>
                    @elseif($paymentUnavailable ?? false)
                    <div class="ws-alert ws-alert-danger" role="alert" style="margin:0;max-width:520px;text-align:left;">
                        <strong>支付渠道暂时不可用</strong>：当前未能生成支付链接，请稍后点击
                        <a href="{{ $payUrl ?? \App\Support\OrderPayAccess::path($order) }}" style="text-decoration:underline;font-weight:700;">刷新本页重试</a>；如持续异常请联系在线客服并提供下方订单编号。
                    </div>
                    @else
                    <span class="ws-cashier-hint">请按页面提示完成支付，系统确认回执后将自动发放卡密凭据</span>
                    @endif
                </div>
            </div>

            <div class="ws-panel-body">
                <h1 style="margin:0 0 14px;font-size:16px;font-weight:750;">订单结算明细</h1>
                <dl class="ws-kv-list">
                    <div class="ws-kv-row"><dt>订单编号</dt><dd class="ws-mono-no">{{ $order->order_no }}</dd></div>
                    <div class="ws-kv-row"><dt>商品名称</dt><dd>{{ $order->displayName() }}</dd></div>
                    <div class="ws-kv-row"><dt>购买数量</dt><dd>{{ $order->quantity }} 件</dd></div>
                    @if($order->discount_amount > 0)
                    <div class="ws-kv-row"><dt>优惠减免</dt><dd style="color:var(--ws-live);">-&yen;{{ number_format($order->discount_amount, 2) }}</dd></div>
                    @endif
                    <div class="ws-kv-row"><dt>支付方式</dt><dd>{{ \App\Support\PaymentMethods::label($order->payment_method) }}</dd></div>
                    <div class="ws-kv-row"><dt>应付总额</dt><dd style="font-size:19px;font-weight:850;color:var(--ws-accent);">&yen;{{ number_format($order->total_amount, 2) }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="ws-polling-bar" id="payment-polling" aria-live="polite"
             data-order-no="{{ $order->order_no }}"
             data-poll-url="{{ $payUrl ?? \App\Support\OrderPayAccess::path($order) }}"
             data-expires="{{ $order->expires_at->toIso8601String() }}">
            <span data-payment-status>正在同步支付状态，确认到账后将自动展示交付信息…</span>
            <div class="ws-polling-actions">
                <button type="button" class="ws-btn ws-btn-primary ws-btn-sm" data-payment-recheck>重新检查付款</button>
                <a href="/order/query?order_no={{ $order->order_no }}" class="ws-btn ws-btn-secondary ws-btn-sm" data-payment-verify hidden>验证并查询订单</a>
            </div>
        </div>
        @else
        <div class="ws-console-card">
            <div class="ws-panel-body ws-dead-state">
                <span class="ws-pill {{ ($paymentReview ?? false) ? 'ws-pill-warn' : 'ws-pill-neutral' }}" style="margin-bottom:10px;">
                    {{ ($paymentReview ?? false) ? '付款待核对' : '订单状态提示' }}
                </span>
                <h1 style="margin:0 0 8px;font-size:21px;font-weight:800;">{{ $deadTitle ?? '订单已过期' }}</h1>
                <p style="margin:0 auto 22px;max-width:480px;font-size:14px;color:var(--ws-muted);">
                    {{ $deadReason ?? '此订单已超过支付有效期，请重新提交订单。' }}
                </p>
                <div class="ws-dead-actions">
                    <a href="{{ $payUrl ?? \App\Support\OrderPayAccess::path($order) }}" class="ws-btn ws-btn-primary">刷新订单状态</a>
                    <a href="/order/query?order_no={{ $order->order_no }}" class="ws-btn ws-btn-secondary">验证并查询订单</a>
                    <a href="/" class="ws-btn ws-btn-secondary">返回商品目录</a>
                </div>
            </div>

            <div class="ws-panel-body" style="border-top:1px solid var(--ws-border);">
                <dl class="ws-kv-list">
                    <div class="ws-kv-row"><dt>订单编号</dt><dd class="ws-mono-no">{{ $order->order_no }}</dd></div>
                    <div class="ws-kv-row"><dt>商品名称</dt><dd>{{ $order->displayName() }}</dd></div>
                    <div class="ws-kv-row"><dt>订单金额</dt><dd>&yen;{{ number_format($order->total_amount, 2) }}</dd></div>
                </dl>
            </div>
        </div>
        @endif
    </div>
@endsection
