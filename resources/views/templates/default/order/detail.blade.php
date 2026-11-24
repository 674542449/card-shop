@extends(theme_view_path('layout'))

@section('title', '订单详情与卡密交付 - ' . setting('site_name', 'CardShop'))

@section('content')
    @php
        $hasDeliveredCards = ($verified ?? false) && $order->isPaid() && $cards->count() > 0;
        $states = [
            'paid' => [
                '订单已完成 · ' . ($hasDeliveredCards ? '卡密已交付' : '支付已确认'),
                $hasDeliveredCards
                    ? '请妥善保存下方卡密凭据，支持单条复制、全部复制或导出 TXT 文件。'
                    : '订单已完成支付，当前暂无可展示的卡密凭据，请联系客服并提供订单编号核实处理。',
                'ws-pill-live',
            ],
            'expired' => ['订单已过期', '此订单已超过支付有效期；若已付款，请先核对订单或联系客服处理，请勿重复支付。', 'ws-pill-neutral'],
            'closed' => ['订单已关闭', '此订单当前处于已关闭状态。', 'ws-pill-neutral'],
            'pending' => ['等待支付', '订单尚未完成付款，系统已在支付有效期内为您预留对应库存。', 'ws-pill-warn'],
        ];

        $stateKey = $order->isPaid() ? 'paid'
            : (($order->isExpired() || $order->status === 'expired') ? 'expired'
            : ($order->status === 'closed' ? 'closed' : 'pending'));

        [$stateTitle, $stateNote, $pillClass] = $states[$stateKey];
        if (!$order->isPaid() && $order->payment_no) {
            $stateTitle = '付款待核对';
            $stateNote = '收到付款回执，订单暂未发货，请联系客服核对，请勿重复支付。';
            $pillClass = 'ws-pill-warn';
        }
    @endphp

    <div class="ws-console">
        <div class="ws-console-nav">
            <a href="/order/query" class="ws-btn ws-btn-secondary ws-btn-sm">&larr; 返回订单查询</a>
            <a href="/" class="ws-btn ws-btn-secondary ws-btn-sm">返回商品目录 &rarr;</a>
        </div>

        {{-- Status Summary Banner --}}
        <section class="ws-console-card">
            <div class="ws-panel-body ws-detail-state-head">
                <div>
                    <div class="ws-detail-state-badges">
                        <span class="ws-pill {{ $pillClass }}">{{ $order->order_no }}</span>
                        @themeInclude('partials.order-status', ['status' => $order->status])
                    </div>
                    <h1 style="margin:0 0 4px;font-size:20px;font-weight:800;">{{ $stateTitle }}</h1>
                    <p style="margin:0;font-size:13.5px;color:var(--ws-muted);">{{ $stateNote }}</p>
                </div>

                @if($stateKey === 'pending' && empty($order->payment_no))
                <a href="{{ \App\Support\OrderPayAccess::path($order) }}" class="ws-btn ws-btn-primary ws-detail-pay-btn">继续完成支付 &rarr;</a>
                @endif
            </div>
        </section>

        {{-- Card Secret Delivery Vault --}}
        @if(($verified ?? false) && $order->isPaid() && $cards->count() > 0)
        <section class="ws-vault" aria-label="卡密交付信息">
            <div class="ws-vault-head">
                <div class="ws-vault-title">
                    <span>已交付卡密凭据（共 {{ $cards->count() }} 组）</span>
                </div>
                <div class="ws-vault-actions">
                    <a href="/order/cards/{{ $order->order_no }}/download" class="ws-btn ws-btn-secondary ws-btn-sm">导出 TXT</a>
                    <button type="button" class="ws-btn ws-btn-primary ws-btn-sm btn-copy" data-target="card-content-text">复制全部卡密</button>
                </div>
            </div>

            <ul class="ws-vault-list">
                @foreach($cards as $card)
                <li>
                    <div class="ws-vault-item-head">
                        <span class="ws-vault-idx">凭证 #{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                        <button type="button" class="ws-vault-copy btn-copy" data-target="card-item-{{ $loop->index }}">复制</button>
                    </div>
                    <code class="ws-vault-code" id="card-item-{{ $loop->index }}">{{ $card->content }}</code>
                </li>
                @endforeach
            </ul>
            <textarea id="card-content-text" readonly tabindex="-1" aria-hidden="true" class="ws-sr-only">{{ $cards->pluck("content")->implode("\n") . "\n" }}</textarea>
        </section>
        @elseif(($verified ?? false) && $order->isPaid())
        <section class="ws-console-card" aria-label="卡密交付状态">
            <div class="ws-panel-body" style="font-size:13.5px;color:var(--ws-muted);">
                当前订单暂无关联的可展示卡密凭据，请联系在线客服并提供订单编号 <code class="ws-mono-no">{{ $order->order_no }}</code> 核实处理。
            </div>
        </section>
        @endif

        {{-- Receipt Breakdown --}}
        <section class="ws-console-card" aria-label="订单回执明细">
            <div class="ws-panel-head">
                <h2>订单结算回执</h2>
                <span>创建时间 {{ $order->created_at->format('Y-m-d H:i:s') }}</span>
            </div>
            <div class="ws-panel-body">
                <dl class="ws-kv-list">
                    <div class="ws-kv-row"><dt>订单编号</dt><dd class="ws-mono-no">{{ $order->order_no }}</dd></div>
                    <div class="ws-kv-row"><dt>商品名称</dt><dd>{{ $order->displayName() }}</dd></div>
                    <div class="ws-kv-row"><dt>购买数量</dt><dd>{{ $order->quantity }} 件</dd></div>
                    <div class="ws-kv-row"><dt>成交单价</dt><dd>&yen;{{ number_format($order->unit_price, 2) }}</dd></div>
                    @if($order->discount_amount > 0)
                    <div class="ws-kv-row">
                        <dt>优惠金额</dt>
                        <dd style="color:var(--ws-live);">
                            -&yen;{{ number_format($order->discount_amount, 2) }}
                            @if($order->coupon)
                            <span class="ws-pill ws-pill-neutral" style="margin-left:6px;">{{ $order->coupon->code }}</span>
                            @endif
                        </dd>
                    </div>
                    @endif
                    <div class="ws-kv-row"><dt>实付总额</dt><dd style="font-size:18px;font-weight:800;">&yen;{{ number_format($order->total_amount, 2) }}</dd></div>
                    <div class="ws-kv-row"><dt>支付方式</dt><dd>{{ \App\Support\PaymentMethods::label($order->payment_method) }}</dd></div>
                    @if($order->paid_at)
                    <div class="ws-kv-row"><dt>支付时间</dt><dd>{{ $order->paid_at->format('Y-m-d H:i:s') }}</dd></div>
                    @endif
                </dl>
            </div>
        </section>

        @include('shared.refund-panel', ['panelClass' => 'refund-panel', 'buttonClass' => 'ws-btn ws-btn-primary ws-btn-sm'])
        @themeInclude('partials.order-cancel')
    </div>
@endsection
