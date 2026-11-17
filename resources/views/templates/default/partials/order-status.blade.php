{{--
    The order status pill. Four call sites had their own copy of this @switch and
    they had already started to drift, so it lives here now.

    $status is the raw column value.
--}}
@switch($status)
    @case('pending') <span class="order-status pending">待支付</span> @break
    @case('paid') <span class="order-status paid">已支付</span> @break
    @case('expired') <span class="order-status expired">已过期</span> @break
    @case('closed') <span class="order-status closed">已关闭</span> @break
    @default <span class="order-status">{{ $status }}</span>
@endswitch
