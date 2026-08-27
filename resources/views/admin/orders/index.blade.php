@extends('layouts.admin')

@section('breadcrumb', '订单管理')

@section('content')
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ url('/admin/orders') }}">
            <div class="row g-3 mb-3">
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">全部状态</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>待支付</option>
                        <option value="paid" {{ request('status') === 'paid' ? 'selected' : '' }}>已支付</option>
                        <option value="expired" {{ request('status') === 'expired' ? 'selected' : '' }}>已过期</option>
                        <option value="closed" {{ request('status') === 'closed' ? 'selected' : '' }}>已关闭</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="payment_method" class="form-select">
                        <option value="">全部方式</option>
                        <option value="alipay" {{ request('payment_method') === 'alipay' ? 'selected' : '' }}>支付宝</option>
                        <option value="wechat" {{ request('payment_method') === 'wechat' ? 'selected' : '' }}>微信</option>
                        <option value="usdt" {{ request('payment_method') === 'usdt' ? 'selected' : '' }}>USDT</option>
                        <option value="manual" {{ request('payment_method') === 'manual' ? 'selected' : '' }}>手动</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <input type="text" name="email" class="form-control" placeholder="搜索邮箱" value="{{ request('email') }}">
                </div>
            </div>
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}">
                </div>
                <div class="col-md-3">
                    <input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}">
                </div>
                <div class="col-md-6 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">筛选</button>
                    <a href="{{ url('/admin/orders') }}" class="btn btn-secondary">重置</a>
                    <a href="{{ url('/admin/orders/export') . '?' . http_build_query(request()->query()) }}" class="btn btn-outline-success">导出</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        @if($orders->count())
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>订单号</th>
                            <th>商品</th>
                            <th>邮箱</th>
                            <th>数量</th>
                            <th>
                                @php
                                    $amountDir = request('sort') === 'total_amount' && request('dir') === 'asc' ? 'desc' : 'asc';
                                @endphp
                                <a href="{{ url('/admin/orders') . '?' . http_build_query(array_merge(request()->query(), ['sort' => 'total_amount', 'dir' => $amountDir])) }}" class="text-decoration-none text-dark">
                                    金额(¥)
                                    @if(request('sort') === 'total_amount')
                                        <i class="bi bi-arrow-{{ request('dir') === 'asc' ? 'up' : 'down' }}"></i>
                                    @endif
                                </a>
                            </th>
                            <th>支付方式</th>
                            <th>状态</th>
                            <th>
                                @php
                                    $timeDir = request('sort') === 'created_at' && request('dir') === 'asc' ? 'desc' : 'asc';
                                @endphp
                                <a href="{{ url('/admin/orders') . '?' . http_build_query(array_merge(request()->query(), ['sort' => 'created_at', 'dir' => $timeDir])) }}" class="text-decoration-none text-dark">
                                    时间
                                    @if(request('sort') === 'created_at')
                                        <i class="bi bi-arrow-{{ request('dir') === 'asc' ? 'up' : 'down' }}"></i>
                                    @endif
                                </a>
                            </th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($orders as $order)
                            <tr>
                                <td>{{ $order->order_no }}</td>
                                <td>{{ $order->product->name ?? '-' }}</td>
                                <td>{{ $order->email }}</td>
                                <td>{{ $order->quantity }}</td>
                                <td>{{ $order->total_amount }}</td>
                                <td>
                                    @switch($order->payment_method)
                                        @case('alipay') 支付宝 @break
                                        @case('wechat') 微信 @break
                                        @case('usdt') USDT @break
                                        @case('manual') 手动 @break
                                        @default -
                                    @endswitch
                                </td>
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
                                <td>{{ $order->created_at->format('Y-m-d H:i') }}</td>
                                <td>
                                    <a href="{{ url('/admin/orders/' . $order->id) }}" class="btn btn-sm btn-outline-primary">详情</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-center text-muted mb-0">暂无订单</p>
        @endif
    </div>
    @if($orders->hasPages())
        <div class="card-footer">
            {{ $orders->links() }}
        </div>
    @endif
</div>
@endsection
