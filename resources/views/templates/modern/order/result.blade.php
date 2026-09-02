@extends(theme_view_path('layout'))

@section('title', '查询结果 - ' . setting('site_name', 'CardShop'))

@section('content')
<div class="orders-page-wrap">

    <div class="section-header">
        <div class="section-title-wrap">
            <h2 class="section-title">查询结果</h2>
            <span class="section-count">共 {{ $orders->count() }} 笔订单</span>
        </div>
        {{-- 这一页是 POST /order/query 的响应，刷新会重新提交表单。想换个邮箱再查，
             得回到查询页，所以这个入口一直留着，不只在空结果时出现。 --}}
        <a href="/order/query" class="btn-query">返回查询</a>
    </div>

    @if($orders->count() > 0)
    {{--
        表格标记原样保留，不要改。

        这张表是专门为「任何分辨率下一行一单」调过的：front.css 给了它
        table-layout:fixed 和每格 nowrap+省略号，内容撑不宽任何一列也不会折到
        第二行；宽度不够时按断点丢掉不再值那个宽度的列（先时间、再数量），
        订单号则切换成能区分彼此的尾号而不是整个消失。
        换成卡片流会让每单占三行，正是当初把五笔订单读不下去的原因。

        操作列的按钮用 .btn-buy-sm：front.css 有 .order-table .oc-action .btn-buy-sm
        这条 (0,3,0) 的规则，按断点把内边距从 3px 12px 一路收到 3px 6px，
        让按钮跟着 46px 宽的操作列一起缩。换成别的按钮类就会在手机上被裁掉。
    --}}
    <div class="order-table-wrap">
        <table class="order-table">
            <thead>
                <tr>
                    <th class="oc-no">订单编号</th>
                    <th class="oc-name">商品</th>
                    <th class="oc-qty">数量</th>
                    <th class="oc-amount">金额</th>
                    <th class="oc-status">状态</th>
                    <th class="oc-time">时间</th>
                    <th class="oc-action">操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($orders as $order)
                <tr>
                    <td class="oc-no">
                        <span class="oc-no-full">{{ $order->order_no }}</span>
                        <span class="oc-no-tail">…{{ substr($order->order_no, -6) }}</span>
                    </td>
                    <td class="oc-name" title="{{ $order->product->name ?? '' }}">{{ $order->product->name ?? '—' }}</td>
                    <td class="oc-qty">{{ $order->quantity }}</td>
                    <td class="oc-amount">¥{{ number_format($order->total_amount, 2) }}</td>
                    <td class="oc-status">@themeInclude('partials.order-status', ['status' => $order->status])</td>
                    <td class="oc-time">{{ $order->created_at->format('Y-m-d H:i') }}</td>
                    <td class="oc-action">
                        <a href="/order/detail/{{ $order->order_no }}" class="btn-buy-sm"
                           aria-label="查看订单 {{ $order->order_no }}">查看</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="notice-callout">
        <div class="notice-callout-header">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
            怎么取回卡密
        </div>
        <ul>
            <li>点某一行的「查看」打开订单详情，已支付的订单会在那里显示完整卡密。</li>
            <li>同一份卡密在支付成功时也发送到了这个邮箱。</li>
            <li>还没支付的订单在详情页里可以继续付款，超时会自动关闭。</li>
        </ul>
    </div>
    @else
    <div class="empty-state">
        {{-- .success-banner 的作用只是「居中的图标+标题+副标题」并留出 28px 下边距，
             按钮跟着它排就不用另外加间距；类名带 success 是模板的命名，
             这里的情绪由灰色图标和文案决定。 --}}
        <div class="success-banner">
            <svg class="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M5 4h14v16l-7-3-7 3z"></path>
            </svg>
            {{-- 上面 .section-title 已经是本页的标题了，这里再放一级标题会让层级倒挂
                 （1.85rem 压过 1.4rem），所以只借样式不当标题用。 --}}
            <p class="success-title">这个邮箱下还没有订单</p>
            <p class="success-subtitle">确认一下是不是换了邮箱下单。如果刚下完单，稍等片刻再查。</p>
        </div>
        <a href="/" class="btn-buy">去挑选商品</a>
    </div>
    @endif
</div>
@endsection
