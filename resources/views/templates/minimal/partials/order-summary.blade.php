<div class="n-receipt-product"><span class="n-kicker">ORDER SUMMARY</span><h2>{{ $order->displayName() ?? '商品已下架' }}</h2><p>{{ $order->quantity }} 件 · ¥{{ number_format($order->unit_price, 2) }}/件</p></div>
<dl class="n-receipt-lines">
    <div><dt>订单编号</dt><dd class="n-code">{{ $order->order_no }}</dd></div>
    @if($verified ?? false)<div><dt>下单邮箱</dt><dd>{{ $order->email }}</dd></div>@endif
    <div><dt>支付方式</dt><dd>{{ $methodLabel }}</dd></div>
    @if($order->discount_amount > 0)<div><dt>优惠金额</dt><dd>−¥{{ number_format($order->discount_amount, 2) }}</dd></div>@endif
    <div><dt>下单时间</dt><dd>{{ $order->created_at->format('Y-m-d H:i') }}</dd></div>
    @if($order->paid_at)<div><dt>支付时间</dt><dd>{{ $order->paid_at->format('Y-m-d H:i') }}</dd></div>@endif
    @if($showDeadline ?? false)<div><dt>支付截止</dt><dd>{{ $order->expires_at->format('Y-m-d H:i') }}</dd></div>@endif
</dl>
<div class="n-receipt-total"><span>{{ $order->isPaid() ? '实付金额' : '应付合计' }}</span><strong>¥{{ number_format($order->total_amount, 2) }}</strong></div>