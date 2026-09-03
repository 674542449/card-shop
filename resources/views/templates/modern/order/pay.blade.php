@extends(theme_view_path('layout'))

@section('title', '订单支付 - ' . setting('site_name', 'CardShop'))

@php
    $methodLabels = [
        'alipay'        => '支付宝',
        'wechat'        => '微信支付',
        'usdt_trc20'    => 'USDT(TRC20)',
        'usdt_bep20'    => 'USDT(BEP20)',
        'usdt_polygon'  => 'USDT(Polygon)',
    ];
    $methodLabel = $methodLabels[$order->payment_method] ?? $order->payment_method;
@endphp

@section('content')
<div class="pay-page-wrap">

    @if($expired)
        {{-- 订单可能是超时自动关闭，也可能是运营手动关闭。对后者说「已过期」会让买家
             带着错误的问题去找客服，所以标题和原因都用控制器给的。
             ?? 的默认值不能删：这两个变量只在「死单」那份 payload 里，另一份没有。 --}}
        <section class="pay-status-card">
            {{-- .success-banner / .success-title / .success-subtitle 是模板里唯一一组
                 「居中图标 + 大标题 + 副标题」，类名带 success 只是模板的命名，
                 情绪由图标和文案决定：这里用灰色的 .empty-state-icon，不用绿色圆牌。 --}}
            <div class="success-banner">
                <svg class="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"></circle>
                    <path d="M12 7v5l3 2"></path>
                </svg>
                <h2 class="success-title">{{ $deadTitle ?? '订单已过期' }}</h2>
                <p class="success-subtitle">{{ $deadReason ?? '此订单已超过支付时限，请重新下单。' }}</p>
            </div>

            {{-- 死单也要留下订单编号：买家来问客服时，这是唯一能对上号的东西。 --}}
            <dl class="pay-details-list">
                <div class="pay-detail-item">
                    <dt class="pay-detail-label">订单编号</dt>
                    <dd class="pay-detail-val"><span class="card-item-text">{{ $order->order_no }}</span></dd>
                </div>
                <div class="pay-detail-item">
                    <dt class="pay-detail-label">商品名称</dt>
                    <dd class="pay-detail-val">{{ $order->product->name ?? '—' }}</dd>
                </div>
                <div class="pay-detail-item">
                    <dt class="pay-detail-label">购买数量</dt>
                    <dd class="pay-detail-val">{{ $order->quantity }} 件</dd>
                </div>
                <div class="pay-detail-item">
                    <dt class="pay-detail-label">支付方式</dt>
                    <dd class="pay-detail-val">{{ $methodLabel }}</dd>
                </div>
            </dl>

            <a href="/" class="btn-checkout-submit">
                <span>返回首页重新下单</span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" aria-hidden="true">
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                    <polyline points="12 5 19 12 12 19"></polyline>
                </svg>
            </a>
        </section>
    @else
        {{-- 倒计时和自动跳转都靠脚本。脚本被拦掉时这一页是完全静止的，买家会以为
             页面卡住了，所以把话说在最前面，而不是藏在页面底部。 --}}
        <noscript>
            <div class="alert alert-warning">
                当前浏览器未启用 JavaScript：倒计时不会走动，支付成功后也不会自动跳转。
                付款完成后请手动刷新本页。
            </div>
        </noscript>

        {{-- 这一页的主语是「付这笔钱」。金额是全页最大的字，「前往支付」紧跟其后，
             倒计时降成金额上方的一枚小胶囊——它是约束条件，不是主角。 --}}
        <section class="pay-status-card">
            {{-- id 和 data-expires 必须在同一个元素上：front.js 先读 dataset.expires，
                 再在这个元素内部 querySelector('.time') 写字。.time 那个 span 丢了的话，
                 倒计时永远停在 --:--，但到点仍然会刷新页面。 --}}
            <div class="pay-timer-box" id="countdown-timer"
                 data-expires="{{ $order->expires_at->toIso8601String() }}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 14 14"></polyline>
                </svg>
                <span>剩余支付时间：<span class="time countdown-digits">--:--</span></span>
            </div>

            <p class="success-subtitle">等待付款 · 订单应付金额</p>
            <p class="pay-amount-main">¥{{ number_format($order->total_amount, 2) }}</p>

            @if($paymentUrl)
                <a href="{{ $paymentUrl }}" class="btn-checkout-submit" target="_blank" rel="noopener">
                    <span>前往支付</span>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.5" aria-hidden="true">
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                        <polyline points="12 5 19 12 12 19"></polyline>
                    </svg>
                </a>
            @elseif($paymentUnavailable ?? false)
                {{-- 支付渠道拿不到链接（网关没配置，或者调用网关失败）。这里必须说清楚，
                     不能挂一个「正在准备支付渠道」的占位——那个占位永远不会变成按钮，
                     买家只会一直等。 --}}
                <div class="alert alert-warning" role="alert">
                    当前支付渠道暂时不可用，无法生成支付链接。请稍后刷新本页重试；若持续如此，
                    请联系站点客服并提供下面的订单编号。此订单到期后会自动关闭，不会扣款。
                </div>
            @else
                <div class="alert alert-info" role="status">
                    正在准备支付渠道。若这段提示一直没有变成支付按钮，请刷新本页重试。
                </div>
            @endif

            <dl class="pay-details-list">
                {{-- 轮询挂在这一行上：front.js 用 id 找到它、读 data-order-no 拼出
                     /order/pay/{order_no} 每 5 秒问一次状态。id 或属性名一旦改动，
                     付过款的订单就再也不会自动跳转，买家会一直盯着这一页。
                     aria-live 让读屏用户也能被告知这里的状态是活的。 --}}
                <div class="pay-detail-item" id="payment-polling"
                     data-order-no="{{ $order->order_no }}" aria-live="polite">
                    <dt class="pay-detail-label">支付状态</dt>
                    <dd class="pay-detail-val">
                        <span class="badge-auto">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2.5" aria-hidden="true">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            等待付款 · 成功后自动跳转
                        </span>
                    </dd>
                </div>
                <div class="pay-detail-item">
                    <dt class="pay-detail-label">订单编号</dt>
                    <dd class="pay-detail-val"><span class="card-item-text">{{ $order->order_no }}</span></dd>
                </div>
                <div class="pay-detail-item">
                    <dt class="pay-detail-label">商品名称</dt>
                    <dd class="pay-detail-val">{{ $order->product->name ?? '—' }}</dd>
                </div>
                <div class="pay-detail-item">
                    <dt class="pay-detail-label">购买数量</dt>
                    <dd class="pay-detail-val">{{ $order->quantity }} 件</dd>
                </div>
                @if($order->discount_amount > 0)
                <div class="pay-detail-item">
                    <dt class="pay-detail-label">优惠金额</dt>
                    <dd class="pay-detail-val">-¥{{ number_format($order->discount_amount, 2) }}</dd>
                </div>
                @endif
                <div class="pay-detail-item">
                    <dt class="pay-detail-label">支付方式</dt>
                    <dd class="pay-detail-val">{{ $methodLabel }}</dd>
                </div>
                {{-- 绝对时刻是给没有 JS 的浏览器兜底的：倒计时不走字时，
                     它至少还能告诉买家自己有多久。 --}}
                <div class="pay-detail-item">
                    <dt class="pay-detail-label">支付截止</dt>
                    <dd class="pay-detail-val">{{ $order->expires_at->format('m-d H:i') }}</dd>
                </div>
            </dl>
        </section>

        <div class="notice-callout">
            <div class="notice-callout-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="16" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
                付款之后会发生什么
            </div>
            <ul>
                @if($paymentUrl)
                <li>「前往支付」会在新标签页打开收银台，付完之后回到这一页即可。</li>
                @endif
                <li>支付成功后本页会自动跳转到订单详情，卡密直接显示在那里，不需要手动刷新。</li>
                <li>同一份卡密也会发送到你下单时填写的邮箱。</li>
                <li>之后凭这个邮箱和你设置的查询密码，可以在「查询订单」里随时取回。</li>
                <li>超过上面的支付截止时间，订单会自动关闭，不会扣款。</li>
            </ul>
        </div>
    @endif
</div>
@endsection
