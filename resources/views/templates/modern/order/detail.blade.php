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

    // 状态图标是内联 SVG。原来是 &#9989; / &#9200; 这类字符实体——同一个码位在
    // Windows、macOS、Android 上画出来是三个不同的东西，尺寸也不受 CSS 控制；
    // 读屏还会把它念成「白色重对勾」。四个状态的形状彼此不同，不只靠颜色区分。
    //
    // cls 里放的是样式表里已有的徽章类，不是新造的名字：
    // .badge-auto 是绿色成功胶囊；叠上 .badge-stock.out-of-stock（更高specificity）
    // 会把配色翻成红色，同时保留 .badge-auto 的 inline-flex + gap ——
    // 这一点是必须的，因为全局重置里 svg 是 display:block，
    // 放进一个普通 inline 元素里会自己另起一行。
    $states = [
        'paid' => [
            'title' => '支付成功',
            'note'  => '卡密已发放，同一份内容也发到了下单邮箱',
            'cls'   => 'badge-auto',
            'icon'  => '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
        ],
        'expired' => [
            'title' => '订单已过期',
            'note'  => '此订单已超过支付时限',
            'cls'   => 'badge-auto badge-stock out-of-stock',
            'icon'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        ],
        'closed' => [
            'title' => '订单已关闭',
            'note'  => '此订单已被关闭',
            'cls'   => 'badge-auto badge-stock out-of-stock',
            'icon'  => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
        ],
        'pending' => [
            'title' => '待支付',
            'note'  => '请尽快完成支付，超时订单会自动关闭',
            'cls'   => 'pd-guarantee-item',
            'icon'  => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4.5"/><path d="M12 16h.01"/>',
        ],
    ];

    $stateKey = $order->isPaid() ? 'paid'
        : (($order->isExpired() || $order->status === 'expired') ? 'expired'
        : ($order->status === 'closed' ? 'closed' : 'pending'));

    $state = $states[$stateKey];
@endphp

@section('content')
<div class="delivery-page-wrap">

    {{-- 状态排成一行，而不是模板 delivery 页那种「68px 圆标 + 1.85rem 大标题 + 副标题」
         的居中横幅：那一版要吃掉约 160px 首屏高度，只为重复一遍下面订单信息里
         还会再写一次的状态词，把卡密挤到折线以下。
         行内的两个分组用 .step-flow-item / .query-input-group ——
         样式表里没有通用的「一行控件」类，这两个是现成的零外边距 flex 行，
         比再造一个类名或者写 inline style 都稳。 --}}
    <div class="section-header">
        <div class="step-flow-item">
            <span class="{{ $state['cls'] }}">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true">{!! $state['icon'] !!}</svg>
                {{ $state['title'] }}
            </span>
            <span class="badge-stock">{{ $state['note'] }}</span>
        </div>
        <div class="query-input-group">
            @if($stateKey === 'pending')
            <a href="/order/pay/{{ $order->order_no }}" class="btn-buy">继续支付</a>
            @endif
            <a href="/order/query" class="btn-copy-sm">返回订单查询</a>
        </div>
    </div>

    {{-- 卡密排在订单信息之前。这是买家付钱换来的唯一东西，此前它排在一张订单信息
         表格之后，而且是全页最小的字。 --}}
    @if($order->isPaid() && $cards->count() > 0)
    <div class="card-keys-container">
        <div class="card-keys-header">
            <span class="card-keys-title">卡密信息 <span class="section-count">{{ $cards->count() }} 条</span></span>
            <span class="query-input-group">
                {{-- 真链接而不是 JS 生成的下载：卡密和这一页走同一道会话校验，
                     服务端能用同样的方式守住这个文件，而且 JS 关掉时它照样能用。 --}}
                <a href="/order/cards/{{ $order->order_no }}/download" class="btn-copy-sm">下载 TXT</a>
                {{-- .btn-copy 是 front.js 的委托监听认的类，data-target 指向取值元素。
                     按钮里不能放图标：复制成功后 front.js 直接改 textContent，
                     子元素会被一次性抹掉，再也回不来。
                     并挂 .btn-copy-sm 只为拿它的 flex-shrink:0，别的属性都被
                     后写的 .btn-copy 覆盖掉了。 --}}
                <button type="button" class="btn-copy-sm btn-copy" data-target="card-content-text">复制全部</button>
            </span>
        </div>

        @foreach($cards as $card)
        <div class="card-item-box">
            <span class="step-flow-item">
                {{-- 序号放在 .card-item-text 外面：那个类是 user-select:all，
                     点一下整块选中，序号混在里面会被一起复制走。 --}}
                <span class="step-flow-num">{{ $loop->iteration }}</span>
                <span class="card-item-text" id="card-content-{{ $loop->iteration }}">{{ $card->content }}</span>
            </span>
            <button type="button" class="btn-copy-sm btn-copy" data-target="card-content-{{ $loop->iteration }}">复制</button>
        </div>
        @endforeach
    </div>

    {{-- 「复制全部」的取值来源。移出屏幕的表单控件默认仍在 Tab 焦点序列里——键盘用户
         走到这里会掉进一个看不见的多行文本框，而且读屏会把整段卡密念一遍。
         aria-hidden + tabindex="-1" 把它同时移出这两条路径；readonly 防止误改。
         不能改成 hidden 属性：front.js 要读它的 .value。
         class="od-card-source" 是唯一把它挪出视口的东西，丢了整页卡密就明文铺在页面上。 --}}
    <textarea id="card-content-text" class="od-card-source" readonly tabindex="-1"
              aria-hidden="true">@foreach($cards as $card){{ $card->content }}
@endforeach</textarea>

    <div class="notice-callout">
        <div class="notice-callout-header">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>
            </svg>
            卡密保存提示
        </div>
        <ul>
            <li>支付成功后卡密由系统自动发放，同一份内容也发送到了你下单时填写的邮箱。</li>
            <li>建议现在就用「复制全部」或「下载 TXT」保存一份。</li>
            <li>之后随时可以用下单邮箱和查询密码回到这一页重新查看。</li>
        </ul>
    </div>
    @endif

    <div class="order-result-card">
        <div class="order-result-header">
            <div class="order-sn-text">订单号：<strong>{{ $order->order_no }}</strong></div>
            @themeInclude('partials.order-status', ['status' => $order->status])
        </div>

        <div class="pay-detail-item">
            <span class="pay-detail-label">商品名称</span>
            <span class="pay-detail-val">{{ $order->product->name ?? '—' }}</span>
        </div>

        <div class="pay-detail-item">
            <span class="pay-detail-label">购买数量</span>
            <span class="pay-detail-val">{{ $order->quantity }} 件</span>
        </div>

        <div class="pay-detail-item">
            <span class="pay-detail-label">单价</span>
            <span class="pay-detail-val">¥{{ number_format($order->unit_price, 2) }}</span>
        </div>

        @if($order->discount_amount > 0)
        <div class="pay-detail-item">
            <span class="pay-detail-label">优惠金额</span>
            <span class="pay-detail-val">-¥{{ number_format($order->discount_amount, 2) }}</span>
        </div>
        @endif

        <div class="pay-detail-item">
            <span class="pay-detail-label">支付金额</span>
            <span class="pay-detail-val">
                <span class="summary-total-price">¥{{ number_format($order->total_amount, 2) }}</span>
            </span>
        </div>

        <div class="pay-detail-item">
            <span class="pay-detail-label">支付方式</span>
            <span class="pay-detail-val">{{ $methodLabel }}</span>
        </div>

        @if($order->paid_at)
        <div class="pay-detail-item">
            <span class="pay-detail-label">支付时间</span>
            <span class="pay-detail-val">{{ $order->paid_at->format('Y-m-d H:i:s') }}</span>
        </div>
        @endif

        <div class="pay-detail-item">
            <span class="pay-detail-label">下单时间</span>
            <span class="pay-detail-val">{{ $order->created_at->format('Y-m-d H:i:s') }}</span>
        </div>
    </div>

    <div class="query-input-group">
        <a href="/" class="btn-buy">返回首页</a>
        <a href="/order/query" class="btn-copy-sm">返回订单查询</a>
    </div>
</div>
@endsection
