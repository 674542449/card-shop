@if(($verified ?? false) && $order->status === 'pending' && empty($order->payment_no))
<section class="refund-panel" aria-label="取消订单"><h2>取消未支付订单</h2><p>仅限尚未发起实际支付的订单执行取消操作。取消后将立即释放预留库存与优惠码使用次数；若支付已在处理中，到账回执仍将按有效记录核对。</p><form method="POST" action="/order/cancel/{{ $order->order_no }}" data-guard>@csrf<button class="ws-btn ws-btn-secondary ws-btn-sm" type="submit">取消订单</button></form></section>
@endif
