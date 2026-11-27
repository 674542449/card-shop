@extends(theme_view_path('layout'))

@section('title', '查询结果 - ' . setting('site_name', 'CardShop'))

@section('content')
    <nav class="ml-breadcrumb" aria-label="面包屑">
        <ol>
            <li><a href="/">商城首页</a></li>
            <li><a href="/order/query">订单查询</a></li>
            <li><a href="/order/query" aria-current="page">查询结果</a></li>
        </ol>
    </nav>

    <section class="ml-order-section">
        <header class="ml-order-top">
            <div>
                <div class="ml-hero-title-row">
                    <h1>订单查询结果</h1>
                    <span class="ml-badge ml-badge-neutral">共 {{ $orders->count() }} 笔</span>
                </div>
                <p class="ml-hero-desc">点击订单右侧按钮即可查看详情、继续支付或提取卡密。</p>
            </div>
            <a href="/order/query" class="ml-btn ml-btn-secondary ml-btn-sm">重新查询</a>
        </header>

        @if($orders->isNotEmpty())
        <div class="ml-order-list">
            @foreach($orders as $order)
            <article class="ml-card ml-order-row-card">
                <div class="ml-order-row-head">
                    <span class="ml-order-no">订单号 <code>{{ $order->order_no }}</code></span>
                    @themeInclude('partials.order-status', ['status' => $order->status])
                </div>
                <div class="ml-order-row-body">
                    <div class="ml-order-row-product">
                        <span class="ml-order-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18m-13 5h4"/></svg>
                        </span>
                        <div>
                            <h2>{{ $order->displayName() ?? '商品已下架' }}</h2>
                            <p>{{ $order->quantity }} 件 · <time datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->format('Y-m-d H:i') }}</time></p>
                        </div>
                    </div>
                    <div class="ml-order-row-amount">
                        <span>实付金额</span>
                        <strong>¥{{ number_format($order->total_amount, 2) }}</strong>
                    </div>
                    <a href="/order/detail/{{ $order->order_no }}" class="ml-btn {{ $order->isPaid() ? 'ml-btn-primary' : 'ml-btn-secondary' }} ml-btn-sm" aria-label="查看订单 {{ $order->order_no }}">
                        {{ $order->isPaid() ? '查看卡密' : '查看订单' }} →
                    </a>
                </div>
            </article>
            @endforeach
        </div>
        @themeInclude('partials.order-search-next')
        @else
        <div class="ml-card ml-empty ml-order-empty">
            @themeInclude('partials.image-placeholder')
            <h2>未找到匹配的订单</h2>
            <p>请确认下单邮箱与查询密码准确无误，或填写订单编号精确查找。</p>
            <div class="ml-order-actions">
                <a href="/order/query" class="ml-btn ml-btn-secondary ml-btn-sm">重新查询</a>
                <a href="/" class="ml-btn ml-btn-primary ml-btn-sm">浏览全部商品</a>
            </div>
        </div>
        @endif
    </section>
@endsection
