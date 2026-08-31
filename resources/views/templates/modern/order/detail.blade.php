@extends(theme_view_path('layout'))

@section('title', '订单详情 - ' . setting('site_name', 'CardShop'))

@php
    $methodLabels = [
        'alipay'       => '支付宝',
        'wechat'       => '微信支付',
        'usdt_trc20'   => 'USDT(TRC20)',
        'usdt_bep20'   => 'USDT(BEP20)',
        'usdt_polygon' => 'USDT(Polygon)',
    ];
    $methodLabel = $methodLabels[$order->payment_method] ?? ($order->payment_method ?: '—');

    // 状态图标改用内联 SVG。原来是 &#9989; / &#9200; 这类字符实体——同一个码位在
    // Windows、macOS、Android 上画出来是三个不同的东西，尺寸也不受 CSS 控制；
    // 读屏还会把它念成「白色重对勾」。四个状态的形状彼此不同，不只靠颜色区分。
    $states = [
        'paid' => [
            'title' => '支付成功',
            'note'  => '订单已完成，卡密信息如下',
            'cls'   => 'ost-paid',
            'icon'  => '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
        ],
        'expired' => [
            'title' => '订单已过期',
            'note'  => '此订单已超过支付时限',
            'cls'   => 'ost-expired',
            'icon'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        ],
        'closed' => [
            'title' => '订单已关闭',
            'note'  => '此订单已被关闭',
            'cls'   => 'ost-closed',
            'icon'  => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
        ],
        'pending' => [
            'title' => '待支付',
            'note'  => '请尽快完成支付，超时订单会自动关闭',
            'cls'   => 'ost-pending',
            'icon'  => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4.5"/><path d="M12 16h.01"/>',
        ],
    ];

    $stateKey = $order->isPaid() ? 'paid'
        : (($order->isExpired() || $order->status === 'expired') ? 'expired'
        : ($order->status === 'closed' ? 'closed' : 'pending'));

    $state = $states[$stateKey];
@endphp

@section('content')
<div class="od-wrap">

    <a href="/order/query" class="od-back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M15 18l-6-6 6-6"/>
        </svg>
        返回订单列表
    </a>

    {{-- 状态条排成一行而不是居中堆叠：堆叠版本要用掉约 160px 的竖直空间去重复一个
         下面还会再说一遍的词，把卡密挤到首屏之外。 --}}
    <div class="od-state {{ $state['cls'] }}">
        <span class="od-state-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">{!! $state['icon'] !!}</svg>
        </span>
        <span class="od-state-text">
            <strong>{{ $state['title'] }}</strong>
            <small>{{ $state['note'] }}</small>
        </span>
        @if($stateKey === 'pending')
            <a href="/order/pay/{{ $order->order_no }}" class="btn btn-sm od-state-action">继续支付</a>
        @endif
    </div>

    {{-- 卡密排在订单信息之前。这是买家付钱换来的唯一东西，此前它排在一张订单信息
         表格之后，而且是全页最小的字（13px 列表项）。 --}}
    @if($order->isPaid() && $cards->count() > 0)
    <section class="panel od-cards">
        <div class="panel-head">
            <span>卡密信息 <span class="od-cards-count">{{ $cards->count() }} 条</span></span>
            <span class="od-cards-actions">
                {{-- 真链接而不是 JS 生成的下载：卡密和这一页走同一道会话校验，
                     服务端能用同样的方式守住这个文件，而且 JS 关掉时它照样能用。 --}}
                <a href="/order/cards/{{ $order->order_no }}/download" class="btn btn-secondary btn-sm">下载 TXT</a>
                <button type="button" class="btn btn-sm btn-copy" data-target="card-content-text">复制</button>
            </span>
        </div>
        <div class="panel-body od-cards-body">
            <ol class="od-card-list">
                @foreach($cards as $card)
                <li><span class="od-card-idx">{{ $loop->iteration }}</span><code>{{ $card->content }}</code></li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- 复制按钮的取值来源。移出屏幕的表单控件默认仍在 Tab 焦点序列里——键盘用户
         走到这里会掉进一个看不见的多行文本框，而且读屏会把整段卡密念一遍。
         aria-hidden + tabindex="-1" 把它同时移出这两条路径；readonly 防止误改。 --}}
    <textarea id="card-content-text" class="od-card-source" readonly tabindex="-1"
              aria-hidden="true">@foreach($cards as $card){{ $card->content }}
@endforeach</textarea>
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

                <dt>单价</dt>
                <dd>¥{{ number_format($order->unit_price, 2) }}</dd>

                @if($order->discount_amount > 0)
                <dt>优惠金额</dt>
                <dd class="op-discount">-¥{{ number_format($order->discount_amount, 2) }}</dd>
                @endif

                <dt>支付金额</dt>
                <dd class="od-total">¥{{ number_format($order->total_amount, 2) }}</dd>

                <dt>支付方式</dt>
                <dd>{{ $methodLabel }}</dd>

                <dt>订单状态</dt>
                <dd>@themeInclude('partials.order-status', ['status' => $order->status])</dd>

                @if($order->paid_at)
                <dt>支付时间</dt>
                <dd>{{ $order->paid_at->format('Y-m-d H:i:s') }}</dd>
                @endif

                <dt>下单时间</dt>
                <dd>{{ $order->created_at->format('Y-m-d H:i:s') }}</dd>
            </dl>
        </div>
    </section>

    <p class="od-foot">
        <a href="/order/query">返回订单列表</a>
        <a href="/">返回首页</a>
    </p>
</div>
@endsection
