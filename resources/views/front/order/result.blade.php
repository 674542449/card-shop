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
    {{--
        ONE order per line at every width — that is the whole design constraint here.
        There used to be a desktop table and a separate stack of phone cards; the
        phone copy spread each order over three lines, which is what made a list of
        five orders unreadable.

        The single table now stays a table on a phone. front.css gives it
        table-layout:fixed and nowrap+ellipsis on every cell, so content can never
        push a column wider or wrap to a second line; columns that stop earning
        their width (time, then quantity) are dropped by media query instead, and
        the order number switches to its distinguishing tail rather than vanishing.
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
                    {{-- Full number on a wide screen, last 6 digits on a narrow one.
                         Two spans rather than two rows: the buyer still has something
                         to tell two orders of the same product apart, and an
                         ellipsised 18-digit number would have told them nothing. --}}
                    <td class="oc-no">
                        <span class="oc-no-full">{{ $order->order_no }}</span>
                        <span class="oc-no-tail">…{{ substr($order->order_no, -6) }}</span>
                    </td>
                    <td class="oc-name" title="{{ $order->product->name ?? '' }}">{{ $order->product->name ?? '—' }}</td>
                    <td class="oc-qty">{{ $order->quantity }}</td>
                    <td class="oc-amount">¥{{ number_format($order->total_amount, 2) }}</td>
                    <td class="oc-status">@include('front.partials.order-status', ['status' => $order->status])</td>
                    <td class="oc-time">{{ $order->created_at->format('Y-m-d H:i') }}</td>
                    <td class="oc-action"><a href="/order/detail/{{ $order->order_no }}" class="btn-buy-sm">查看</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @else
    {{-- Same .empty-state block as the product and article lists. --}}
    <div class="empty-state">
        @include('front.partials.image-placeholder', ['class' => 'empty-state-glyph'])
        <p>暂无订单记录</p>
        <a href="/" class="btn-buy-sm">去购买</a>
    </div>
    @endif
@endsection
