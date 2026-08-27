@extends('layouts.front')

@section('title', '查询结果 - ' . setting('site_name', 'CardShop'))

@section('content')
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/">首页</a></li>
            <li class="breadcrumb-item"><a href="/order/query">订单查询</a></li>
            <li class="breadcrumb-item active" aria-current="page">查询结果</li>
        </ol>
    </nav>

    <h1 class="h4 fw-bold mb-4">
        <i class="bi bi-list-check me-1"></i>
        订单列表
        <small class="text-secondary fw-normal ms-2">(共 {{ $orders->count() }} 笔订单)</small>
    </h1>

    @if($orders->count() > 0)
        {{-- Desktop Table --}}
        <div class="order-list-table d-none d-md-block">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>订单编号</th>
                            <th>商品</th>
                            <th>数量</th>
                            <th>金额</th>
                            <th>状态</th>
                            <th>时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($orders as $order)
                        <tr>
                            <td style="font-family: monospace; font-size: 0.8125rem;">{{ $order->order_no }}</td>
                            <td>{{ $order->product->name ?? '—' }}</td>
                            <td>{{ $order->quantity }}</td>
                            <td class="text-price">¥{{ number_format($order->total_amount, 2) }}</td>
                            <td>
                                @switch($order->status)
                                    @case('pending')
                                        <span class="status-badge pending">待支付</span>
                                        @break
                                    @case('paid')
                                        <span class="status-badge paid">已支付</span>
                                        @break
                                    @case('expired')
                                        <span class="status-badge expired">已过期</span>
                                        @break
                                    @case('closed')
                                        <span class="status-badge closed">已关闭</span>
                                        @break
                                    @default
                                        <span class="status-badge">{{ $order->status }}</span>
                                @endswitch
                            </td>
                            <td class="text-secondary small">{{ $order->created_at->format('Y-m-d H:i') }}</td>
                            <td>
                                <a href="/order/detail/{{ $order->order_no }}" class="btn btn-sm btn-outline-primary">
                                    查看
                                </a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Mobile Cards --}}
        <div class="d-md-none">
            @foreach($orders as $order)
            <div class="order-card-mobile">
                <div class="order-no">{{ $order->order_no }}</div>
                <div class="product-name">{{ $order->product->name ?? '—' }}</div>
                <div class="order-meta">
                    <div>
                        <span class="text-price fw-bold">¥{{ number_format($order->total_amount, 2) }}</span>
                        <span class="text-secondary mx-1">&middot;</span>
                        <span class="text-secondary">{{ $order->quantity }}件</span>
                    </div>
                    @switch($order->status)
                        @case('pending')
                            <span class="status-badge pending">待支付</span>
                            @break
                        @case('paid')
                            <span class="status-badge paid">已支付</span>
                            @break
                        @case('expired')
                            <span class="status-badge expired">已过期</span>
                            @break
                        @case('closed')
                            <span class="status-badge closed">已关闭</span>
                            @break
                        @default
                            <span class="status-badge">{{ $order->status }}</span>
                    @endswitch
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <span class="text-secondary small">{{ $order->created_at->format('Y-m-d H:i') }}</span>
                    <a href="/order/detail/{{ $order->order_no }}" class="btn btn-sm btn-outline-primary">
                        查看详情
                    </a>
                </div>
            </div>
            @endforeach
        </div>
    @else
        <div class="empty-state">
            <div class="icon"><i class="bi bi-inbox"></i></div>
            <p>暂无订单记录</p>
            <a href="/" class="btn btn-primary">去购买</a>
        </div>
    @endif
</div>
@endsection
