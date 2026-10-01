@if(($verified ?? false) && $order->status === 'pending' && empty($order->payment_no))
<section class="n-refund-panel" aria-label="取消订单"><h2>还未付款？</h2><p>仅在尚未付款时取消。取消后释放库存与优惠次数；若付款已在处理中，到账后仍会按有效回执处理。</p><form method="POST" action="/order/cancel/{{ $order->order_no }}">@csrf<button class="n-button n-button-secondary" type="submit">取消未付款订单</button></form></section>
@endif
