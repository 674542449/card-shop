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
<div class="op-wrap">

    @if($expired)
        {{-- 订单可能是超时自动关闭，也可能是运营手动关闭。对后者说「已过期」会让买家
             带着错误的问题去找客服，所以标题和原因都用控制器给的。 --}}
        <section class="panel">
            <div class="panel-body">
                <div class="state">
                    <div class="state-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
                        </svg>
                    </div>
                    <p class="state-title">{{ $deadTitle ?? '订单已过期' }}</p>
                    <p class="state-desc">{{ $deadReason ?? '此订单已超过支付时限，请重新下单。' }}</p>
                    <a href="/" class="btn">返回首页</a>
                </div>
            </div>
        </section>
    @else
        {{-- 这一页的主语是「付这笔钱」。所以金额是全页最大的字，「前往支付」紧跟其后，
             倒计时降成金额旁边的一枚小胶囊——它是约束条件，不是主角。
             改之前正好相反：倒计时 32px 独占一张卡片排在最前，金额 18px 藏在订单信息
             表格的一行里，按钮还在倒计时后面。 --}}
        <section class="panel op-pay">
            <div class="panel-body op-pay-body">
                <p class="op-label">应付金额</p>
                <p class="op-amount">
                    <span class="cur">¥</span>{{ number_format($order->total_amount, 2) }}
                </p>

                <div class="op-deadline">
                    <span class="op-count" id="countdown-timer"
                          data-expires="{{ $order->expires_at->toIso8601String() }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
                        </svg>
                        {{-- front.js 只改这个元素的 textContent，其余结构它不碰。 --}}
                        <span class="time">--:--</span>
                    </span>
                    {{-- 绝对时刻是给没有 JS 的浏览器兜底的：倒计时靠脚本走字，脚本不跑时
                         它会一直停在 --:--，那样买家就完全不知道自己还有多久。 --}}
                    <span class="op-deadline-abs">
                        请在 {{ $order->expires_at->format('m-d H:i') }} 前完成支付，超时自动关闭
                    </span>
                </div>

                @if($paymentUrl)
                    <a href="{{ $paymentUrl }}" class="btn btn-lg btn-block"
                       target="_blank" rel="noopener">前往支付</a>
                    <p class="op-hint">支付页面在新标签页打开。付完回到这一页，状态会自动更新。</p>
                @elseif($paymentUnavailable ?? false)
                    {{-- 支付渠道拿不到链接（网关没配置，或者调用网关失败）。这里必须
                         说清楚，不能挂一个「正在准备支付渠道」的骨架屏——那个骨架屏
                         永远不会变成按钮，买家只会一直等。 --}}
                    <div class="alert alert-warning op-alert" role="alert">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"/><path d="M12 8v4.5"/><path d="M12 16h.01"/>
                        </svg>
                        <span>
                            当前支付渠道暂时不可用，无法生成支付链接。请稍后刷新本页重试；
                            若持续如此，请联系站点客服并提供上面的订单编号。此订单到期后会
                            自动关闭，不会扣款。
                        </span>
                    </div>
                @else
                    <div class="op-wait">
                        <div class="skel skel-line" style="width:70%"></div>
                        <div class="skel skel-line" style="width:45%"></div>
                        <p class="op-hint">正在准备支付渠道，请按支付页面的提示完成付款。</p>
                    </div>
                @endif

                {{-- aria-live 让读屏用户也能知道状态在变，而不是只有视觉上的一个转点。 --}}
                <p class="op-poll" id="payment-polling" data-order-no="{{ $order->order_no }}"
                   aria-live="polite">
                    <span class="op-dot" aria-hidden="true"></span>
                    正在等待支付结果，成功后自动跳转
                </p>

                {{-- 轮询和倒计时都依赖脚本。脚本被拦掉时这一页原本是完全静止的，
                     买家会以为页面卡住了。 --}}
                <noscript>
                    <p class="op-hint">
                        当前浏览器未启用 JavaScript，页面不会自动跳转。付款完成后请手动刷新本页。
                    </p>
                </noscript>
            </div>
        </section>
    @endif

    <section class="panel">
        <div class="panel-head">订单信息</div>
        <div class="panel-body">
            <dl class="op-info">
                <dt>订单编号</dt>
                <dd class="mono">{{ $order->order_no }}</dd>

                <dt>商品名称</dt>
                <dd>{{ $order->product->name ?? '—' }}</dd>

                <dt>购买数量</dt>
                <dd>{{ $order->quantity }} 件</dd>

                @if($order->discount_amount > 0)
                <dt>优惠金额</dt>
                <dd class="op-discount">-¥{{ number_format($order->discount_amount, 2) }}</dd>
                @endif

                <dt>支付方式</dt>
                <dd>{{ $methodLabel }}</dd>
            </dl>
        </div>
    </section>
</div>
@endsection
