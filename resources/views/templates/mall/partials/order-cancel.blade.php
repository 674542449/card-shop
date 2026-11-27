@if(($verified ?? false) && $order->status === 'pending' && empty($order->payment_no))
<section class="ml-card ml-refund-panel" aria-label="取消订单">
    <h2>尚未付款？可取消订单</h2>
    <p>仅支持取消尚未付款的订单；取消后将立即释放锁定库存与优惠券额度。如您已提交付款，到账后仍会按有效回执核对处理。</p>
    <form method="POST" action="/order/cancel/{{ $order->order_no }}">
        @csrf
        <button class="ml-btn ml-btn-secondary ml-btn-sm" type="submit">取消未付款订单</button>
    </form>
</section>
@endif
