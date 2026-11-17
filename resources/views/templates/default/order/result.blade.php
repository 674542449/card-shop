@extends(theme_view_path('layout'))

@section('title', '订单查询结果 - ' . setting('site_name', 'CardShop'))

@section('content')
    <div class="ws-console">
        <div class="ws-console-topbar">
            <div>
                <h1 style="margin:0;font-size:20px;font-weight:800;">订单查询结果</h1>
                <span style="font-size:13px;color:var(--ws-muted);">请选择对应订单查看详情、获取卡密凭据或申请售后服务</span>
            </div>
            <a href="/order/query" class="ws-btn ws-btn-secondary ws-btn-sm">&larr; 返回重新查询</a>
        </div>

        @themeInclude('partials.order-search-next')

        @if($orders->count() > 0)
        <div class="ws-order-list">
            @foreach($orders as $order)
            <article class="ws-order-card">
                <div class="ws-order-card-main">
                    <div class="ws-order-card-top">
                        @themeInclude('partials.order-status', ['status' => $order->status])
                        <span class="ws-mono-no">{{ $order->order_no }}</span>
                    </div>
                    <h3>{{ $order->displayName() }}</h3>
                    <div class="ws-order-card-meta">
                        <span>数量 {{ $order->quantity }} 件</span>
                        <span>&middot;</span>
                        <span>下单时间 {{ $order->created_at->format('Y-m-d H:i') }}</span>
                    </div>
                </div>

                <div class="ws-order-card-right">
                    <span class="ws-order-amount">&yen;{{ number_format($order->total_amount, 2) }}</span>
                    <a href="/order/detail/{{ $order->order_no }}" class="ws-btn ws-btn-primary ws-btn-sm">
                        {{ $order->isPaid() ? '查看卡密' : '查看详情' }} &rarr;
                    </a>
                </div>
            </article>
            @endforeach
        </div>
        @else
        <div class="ws-empty">
            @themeInclude('partials.image-placeholder', ['class' => 'empty-state-glyph'])
            <h3>未查询到符合条件的订单</h3>
            <p>请核对输入的下单邮箱、查询密码及订单编号是否准确。</p>
            <a href="/order/query" class="ws-btn ws-btn-primary ws-btn-sm">返回重新查询</a>
        </div>
        @endif
    </div>
@endsection
