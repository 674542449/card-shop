@extends(theme_view_path('layout'))

@section('title', '查询结果 - ' . setting('site_name', 'CardShop'))

@section('content')
<nav class="m-breadcrumb" aria-label="当前位置">
    <a href="/">首页</a><span aria-hidden="true">/</span>
    <a href="/order/query">订单查询</a><span aria-hidden="true">/</span>
    <span aria-current="page">查询结果</span>
</nav>
<section class="m-order-results">
    <div class="m-order-page-top">
        <div>
            <p class="m-eyebrow">购买记录</p>
            <h1 class="m-page-heading">你的订单</h1>
            <p class="m-lead">本次展示 {{ $orders->count() }} 笔匹配订单。</p>
        </div>
        <a href="/order/query" class="m-button m-button-secondary m-button-sm">重新查询</a>
    </div>
    @if($orders->isNotEmpty())
    <div class="m-order-list">
        @foreach($orders as $order)
        <article class="m-panel m-order-list-card">
            <div class="m-order-list-top">
                <p class="m-order-reference">订单号 <span>{{ $order->order_no }}</span></p>
                @themeInclude('partials.order-status', ['status' => $order->status])
            </div>
            <div class="m-order-list-body">
                <div class="m-order-list-product">
                    <span class="m-order-product-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18m-13 5h4"/></svg></span>
                    <div>
                        <h2>{{ $order->displayName() ?? '商品已下架' }}</h2>
                        <p>{{ $order->quantity }} 件 <span aria-hidden="true">·</span> <time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->format('Y-m-d H:i') }}</time></p>
                    </div>
                </div>
                <div class="m-order-list-amount">
                    <span>订单金额</span>
                    <strong>¥{{ number_format($order->total_amount, 2) }}</strong>
                </div>
                <a href="/order/detail/{{ $order->order_no }}" class="m-button m-button-secondary m-button-sm" aria-label="查看订单 {{ $order->order_no }}">{{ $order->isPaid() ? '查看卡密' : '查看订单' }} <span aria-hidden="true">↗</span></a>
            </div>
        </article>
        @endforeach
    </div>
    @themeInclude('partials.order-search-next')
    @else
    <div class="m-panel m-empty m-order-empty">
        <svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M13 7h22v34l-11-6-11 6V7Z"/><path d="M19 17h10m-10 7h6"/></svg>
        <h2 class="m-section-title">暂时没有找到订单</h2>
        <p class="m-muted">请确认下单邮箱与查询密码是否准确，或填写订单号精确查找。</p>
        <div class="m-order-actions">
            <a href="/order/query" class="m-button m-button-secondary">重新查询</a>
            <a href="/" class="m-button m-button-primary">浏览商品</a>
        </div>
    </div>
    @endif
</section>
@endsection
