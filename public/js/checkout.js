(function () {
    'use strict';
    var button = document.querySelector('[data-checkout-quote]');
    if (!button) return;
    var form = button.closest('form');
    var quantity = form.querySelector('[name="quantity"]');
    var coupon = form.querySelector('[name="coupon_code"]');
    var result = form.querySelector('[data-quote-status]');
    var totalLabel = form.querySelector('[data-checkout-total-label]');
    var sequence = 0;
    var pending;
    function reset() {
        sequence++;
        if (pending) pending.abort();
        pending = null;
        button.disabled = false;
        result.textContent = '可试算优惠，试算不占用库存或优惠次数。';
        result.removeAttribute('data-error');
        if (totalLabel) totalLabel.textContent = '商品小计';
    }
    quantity.addEventListener('input', reset);
    coupon.addEventListener('input', function () {
        // Revert a previously discounted total to the existing local tier estimate.
        quantity.dispatchEvent(new Event('input', { bubbles: true }));
    });
    button.addEventListener('click', async function () {
        if (!quantity.reportValidity()) return;
        if (pending) pending.abort();
        var version = ++sequence;
        var controller = new AbortController();
        pending = controller;
        button.disabled = true;
        result.textContent = '正在校验数量和优惠码…';
        result.removeAttribute('data-error');
        try {
            var response = await fetch('/order/quote', {
                method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value },
                body: JSON.stringify({ product_id: Number(form.querySelector('[name="product_id"]').value), quantity: Number(quantity.value), coupon_code: coupon.value.trim() })
            });
            var body = await response.json();
            if (version !== sequence) return;
            if (!response.ok) {
                var validation = Object.values(body.errors || {}).flat()[0];
                throw new Error(validation || body.message || '试算失败，请稍后重试。');
            }
            var unitPrice = form.querySelector('#unit-price');
            if (unitPrice) unitPrice.textContent = '¥' + body.data.unit_price;
            form.querySelector('#total-price').textContent = '¥' + body.data.total_amount;
            if (totalLabel) totalLabel.textContent = '预计实付';
            result.textContent = '已校验：优惠 ¥' + body.data.discount_amount + '，预计实付 ¥' + body.data.total_amount + '。最终金额以提交订单时为准。';
        } catch (error) {
            if (version !== sequence || error.name === 'AbortError') return;
            quantity.dispatchEvent(new Event('input', { bubbles: true }));
            result.textContent = error instanceof TypeError ? '网络异常，暂时无法试算，请重试。' : error.message;
            result.setAttribute('data-error', 'true');
        } finally {
            if (pending === controller) {
                pending = null;
                button.disabled = false;
            }
        }
    });
})();
