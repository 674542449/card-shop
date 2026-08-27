@extends('layouts.admin')

@section('breadcrumb', '仪表盘')

@section('content')
{{-- 统计卡片 --}}
<div class="row">
    <div class="col-xl-3 col-md-6 col-12 mb-4">
        <div class="card border-start border-primary border-4 h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">今日收入</div>
                <div class="h4 fw-bold text-primary mt-2">¥{{ number_format($todayRevenue, 2) }}</div>
                <div class="text-muted small">{{ $todayOrders }} 笔订单</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 col-12 mb-4">
        <div class="card border-start border-success border-4 h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">本周收入</div>
                <div class="h4 fw-bold text-success mt-2">¥{{ number_format($weekRevenue, 2) }}</div>
                <div class="text-muted small">{{ $weekOrders }} 笔订单</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 col-12 mb-4">
        <div class="card border-start border-warning border-4 h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">本月收入</div>
                <div class="h4 fw-bold text-warning mt-2">¥{{ number_format($monthRevenue, 2) }}</div>
                <div class="text-muted small">{{ $monthOrders }} 笔订单</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 col-12 mb-4">
        <div class="card border-start border-danger border-4 h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-bold">总收入</div>
                <div class="h4 fw-bold text-danger mt-2">¥{{ number_format($totalRevenue, 2) }}</div>
                <div class="text-muted small">{{ $totalOrders }} 笔订单</div>
            </div>
        </div>
    </div>
</div>

{{-- 收入趋势 & 订单状态分布 --}}
<div class="row">
    <div class="col-lg-8 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-bold">近7天收入趋势</h6>
            </div>
            <div class="card-body">
                <canvas id="revenueChart" height="300"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-bold">订单状态分布</h6>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    @foreach($orderStatusCounts as $status => $count)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            @switch($status)
                                @case('pending')
                                    待支付
                                    @break
                                @case('paid')
                                    已支付
                                    @break
                                @case('expired')
                                    已过期
                                    @break
                                @case('closed')
                                    已关闭
                                    @break
                                @default
                                    {{ $status }}
                            @endswitch
                            <span class="badge bg-primary rounded-pill">{{ $count }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>

{{-- 最近订单 & 库存预警 --}}
<div class="row">
    <div class="col-lg-8 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h6 class="mb-0 fw-bold">最近订单</h6>
            </div>
            <div class="card-body">
                @if($recentOrders->count())
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>订单号</th>
                                    <th>商品</th>
                                    <th>金额</th>
                                    <th>状态</th>
                                    <th>时间</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentOrders as $order)
                                    <tr>
                                        <td>
                                            <a href="{{ route('admin.orders.show', $order) }}">{{ $order->order_no }}</a>
                                        </td>
                                        <td>{{ $order->product_name }}</td>
                                        <td>¥{{ number_format($order->amount, 2) }}</td>
                                        <td>
                                            @switch($order->status)
                                                @case('pending')
                                                    <span class="badge bg-warning text-dark">待支付</span>
                                                    @break
                                                @case('paid')
                                                    <span class="badge bg-success">已支付</span>
                                                    @break
                                                @case('expired')
                                                    <span class="badge bg-secondary">已过期</span>
                                                    @break
                                                @case('closed')
                                                    <span class="badge bg-dark">已关闭</span>
                                                    @break
                                            @endswitch
                                        </td>
                                        <td>{{ $order->created_at->format('m-d H:i') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-center text-muted py-4">暂无订单</div>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">库存预警</h6>
                @if($lowStockProducts->count())
                    <span class="badge bg-danger">{{ $lowStockProducts->count() }}</span>
                @endif
            </div>
            <div class="card-body">
                @if($lowStockProducts->count())
                    <ul class="list-group list-group-flush">
                        @foreach($lowStockProducts as $product)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <a href="{{ route('admin.cards.index') }}">{{ $product->name }}</a>
                                <span class="badge bg-danger rounded-pill">{{ $product->stock }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="text-center text-muted py-4">所有商品库存充足</div>
                @endif
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const labels = @json($chartLabels);
        const data = @json($chartData);
        renderRevenueChart('revenueChart', labels, data);
    });
</script>
@endpush
@endsection
