@if($verified ?? false)
@php
    $refundService = app(\App\Services\RefundService::class);
    $refundBalance = $refundService->balance($order);
    $refundEnabled = $refundService->enabled();
@endphp
<section class="{{ $panelClass }}" aria-label="退款与售后">
    @if($order->isPaid() && \App\Models\Order::paymentReview()->whereKey($order->id)->exists())
    <p role="status">购买已完成，另有付款回执等待店主核对。请联系客服处理额外付款，请勿重复付款。</p>
    @endif
    <h2>退款与售后</h2>
    @if($order->refunds->isNotEmpty())
    <ul>@foreach($order->refunds as $refund)
        <li>¥{{ $refund->amount }} · {{ ['requested'=>'待审核','approved'=>'待退款','completed'=>'已退款','rejected'=>'已拒绝'][$refund->status] ?? $refund->status }}
            · 申请时间 {{ $refund->created_at->format('Y-m-d H:i') }}
            @if($refund->completed_at)<p>退款完成时间：{{ $refund->completed_at->format('Y-m-d H:i') }}</p>@endif
            @if($refund->customer_note)<p>处理说明：{{ $refund->customer_note }}</p>@endif
        </li>
    @endforeach</ul>
    @endif
    @if($order->isPaid())
    <p>可申请余额 ¥{{ $refundBalance['available'] }} · 审核/退款处理中 ¥{{ $refundBalance['reserved'] }} · 已退款 ¥{{ $refundBalance['completed'] }}</p>
        @if(!$refundEnabled)
        <p>店主暂未开放退款申请；已有申请仍会继续处理。</p>
        @elseif(bccomp($refundBalance['available'], '0', 2) > 0)
        <details><summary>申请退款</summary><p>提交后由店主审核，进度变化将发送到下单邮箱。</p>
            <form method="POST" action="/order/refund/{{ $order->order_no }}" data-guard>
                @csrf
                <label for="refund-amount">退款金额（元）</label>
                <input id="refund-amount" type="number" name="amount" min="0.01" max="{{ $refundBalance['available'] }}" step="0.01" value="{{ old('amount', $refundBalance['available']) }}" required>
                <label for="refund-reason">申请原因</label>
                <textarea id="refund-reason" name="reason" maxlength="2000" required>{{ old('reason') }}</textarea>
                <button class="{{ $buttonClass }}" type="submit">提交申请</button>
            </form>
        </details>
        @else
        <p>当前没有可申请余额。已有申请和退款结果见上方记录。</p>
        @endif
    @else
    <p>异常付款请联系客服，并提供订单编号。</p>
    @endif
</section>
@endif
