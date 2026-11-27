@extends(theme_view_path('layout'))
@section('title', '查询结果 - ' . setting('site_name', 'CardShop'))

@section('content')
<nav class="n-breadcrumb" aria-label="面包屑">
    <a href="/">商品目录</a><span aria-hidden="true">/</span>
    <a href="/order/query">订单查询</a><span aria-hidden="true">/</span>
    <span aria-current="page">查询结果</span>
</nav>

<header class="n-page-header">
    <div>
        <span class="n-kicker">购买记录</span>
        <h1>你的订单</h1>
        <p>本次展示 {{ $orders->count() }} 笔匹配订单，点击查看详情与卡密。</p>
    </div>
    <a href="/order/query" class="n-button n-button-secondary n-button-sm">重新查询</a>
</header>

@if($orders->isNotEmpty())
<div class="n-history-list">
    @foreach($orders as $order)
    <article class="n-history-item">
        <div class="n-history-top">
            <span class="n-history-number">{{ $order->order_no }}</span>
            @themeInclude('partials.order-status', ['status' => $order->status])
        </div>
        <div class="n-history-body">
            <div class="n-history-product">
                <span class="n-history-icon" aria-hidden="true">@themeInclude('partials.image-placeholder')</span>
                <div>
                    <h2>{{ $order->displayName() ?? '商品已下架' }}</h2>
                    <p>{{ $order->quantity }} 件 · <time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->format('Y-m-d H:i') }}</time></p>
                </div>
            </div>
            <strong class="n-history-amount">¥{{ number_format($order->total_amount, 2) }}</strong>
            <a href="/order/detail/{{ $order->order_no }}" class="n-button n-button-secondary n-button-sm" aria-label="查看订单 {{ $order->order_no }}">{{ $order->isPaid() ? '查看卡密' : '查看订单' }} →</a>
        </div>
    </article>
    @endforeach
</div>
@else
<div class="n-panel n-empty">
    @themeInclude('partials.image-placeholder')
    <h2>{{ !empty($hasMore) ? '当前批次暂无匹配订单' : '没有找到购买记录' }}</h2>
    <p>{{ !empty($hasMore) ? '该邮箱下较新的记录未匹配当前密码，点击下方按钮可继续查找更早的订单。' : '请确认下单邮箱与查询密码是否正确，或填写订单号精确查找。' }}</p>
    <div class="n-actions">
        <a href="/order/query" class="n-button n-button-secondary">重新查询</a>
        <a href="/" class="n-button n-button-primary">浏览商品</a>
    </div>
</div>
@endif
@themeInclude('partials.order-search-next')
@endsection
