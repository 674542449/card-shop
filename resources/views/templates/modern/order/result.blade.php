@extends(theme_view_path('layout'))

@section('title', '查询结果 - ' . setting('site_name', 'CardShop'))

@section('content')
<div class="or-wrap">
    <a href="/order/query" class="od-back">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M15 18l-6-6 6-6"/>
        </svg>
        返回查询
    </a>

    <section class="panel">
        <div class="panel-head">
            <span>查询结果</span>
            <span class="or-count">共 {{ $orders->count() }} 笔订单</span>
        </div>

        @if($orders->count() > 0)
        {{--
            表格标记原样保留，不要改。

            这张表是专门为「任何分辨率下一行一单」调过的：front.css 给了它
            table-layout:fixed 和每格 nowrap+省略号，内容撑不宽任何一列也不会折到
            第二行；宽度不够时按断点丢掉不再值那个宽度的列（先时间、再数量），
            订单号则切换成能区分彼此的尾号而不是整个消失。
            换成卡片流会让每单占三行，正是当初把五笔订单读不下去的原因。
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
                        <td class="oc-action"><a href="/order/detail/{{ $order->order_no }}" class="btn btn-sm">查看</a></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @else
        <div class="panel-body">
            <div class="state">
                <div class="state-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 4h14v16l-7-3-7 3z"/>
                    </svg>
                </div>
                <p class="state-title">这个邮箱下还没有订单</p>
                <p class="state-desc">确认一下是不是换了邮箱下单。如果刚下单，稍等片刻再查。</p>
                <a href="/" class="btn">去挑选商品</a>
            </div>
        </div>
        @endif
    </section>
</div>
@endsection
