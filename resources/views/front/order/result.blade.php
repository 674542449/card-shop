@extends('layouts.front')

@section('title', '查询结果 - ' . setting('site_name', 'CardShop'))

@section('content')
    <div style="margin-bottom:15px;">
        <a href="/order/query" style="color:var(--text-light);font-size:13px">&larr; 返回查询</a>
    </div>

    <blockquote class="site-quote">
        共查询到 <strong>{{ $orders->count() }}</strong> 笔订单
    </blockquote>

    @if($orders->count() > 0)
    {{-- Desktop Table --}}
    <div class="desktop-only">
        <table class="product-table">
            <thead>
                <tr>
                    <th>订单编号</th>
                    <th>商品</th>
                    <th style="width:60px">数量</th>
                    <th style="width:100px">金额</th>
                    <th style="width:80px">状态</th>
                    <th style="width:130px">时间</th>
                    <th style="width:70px">操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($orders as $order)
                <tr>
                    <td style="font-family:monospace;font-size:13px;">{{ $order->order_no }}</td>
                    <td>{{ $order->product->name ?? '—' }}</td>
                    <td>{{ $order->quantity }}</td>
                    <td style="color:var(--price-color);font-weight:500;">¥{{ number_format($order->total_amount, 2) }}</td>
                    <td>
                        @switch($order->status)
                            @case('pending') <span class="order-status pending">待支付</span> @break
                            @case('paid') <span class="order-status paid">已支付</span> @break
                            @case('expired') <span class="order-status expired">已过期</span> @break
                            @case('closed') <span class="order-status closed">已关闭</span> @break
                            @default <span class="order-status">{{ $order->status }}</span>
                        @endswitch
                    </td>
                    <td style="color:var(--text-light);font-size:13px;">{{ $order->created_at->format('Y-m-d H:i') }}</td>
                    <td><a href="/order/detail/{{ $order->order_no }}" class="btn-buy-sm" style="padding:3px 12px;font-size:13px;">查看</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Mobile Cards --}}
    <div class="mobile-only">
        @foreach($orders as $order)
        <div class="page-card" style="margin-bottom:10px;">
            <div class="page-card-body" style="padding:12px 15px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <span style="font-family:monospace;font-size:12px;color:var(--text-light);">{{ $order->order_no }}</span>
                    @switch($order->status)
                        @case('pending') <span class="order-status pending">待支付</span> @break
                        @case('paid') <span class="order-status paid">已支付</span> @break
                        @case('expired') <span class="order-status expired">已过期</span> @break
                        @case('closed') <span class="order-status closed">已关闭</span> @break
                        @default <span class="order-status">{{ $order->status }}</span>
                    @endswitch
                </div>
                <div style="font-size:14px;margin-bottom:6px;">{{ $order->product->name ?? '—' }}</div>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <span style="color:var(--price-color);font-weight:700;">¥{{ number_format($order->total_amount, 2) }}</span>
                        <span style="color:var(--text-light);margin-left:6px;font-size:12px;">{{ $order->quantity }}件</span>
                    </div>
                    <a href="/order/detail/{{ $order->order_no }}" class="btn-buy-sm" style="padding:3px 14px;font-size:13px;">查看</a>
                </div>
            </div>
        </div>
        @endforeach
    </div>
    @else
    <div class="text-center" style="padding:60px 0;color:var(--text-light)">
        <div style="font-size:48px;margin-bottom:15px">&#128235;</div>
        <p>暂无订单记录</p>
        <a href="/" class="btn-buy-sm" style="margin-top:10px">去购买</a>
    </div>
    @endif
@endsection
