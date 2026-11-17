(function () {
    'use strict';

    document.querySelectorAll('[data-checkout-quote]').forEach((button) => {
        const form = button.closest('form');
        if (!form) return;

        const quantity = form.querySelector('[name="quantity"]');
        const coupon = form.querySelector('[name="coupon_code"]');
        const result = form.querySelector('[data-quote-status]');
        const totalLabel = form.querySelector('[data-checkout-total-label]');
        const unitNode = form.querySelector('[data-quote-unit], #unit-price');
        const totalNode = form.querySelector('[data-quote-total], #total-price');
        const defaultNote = result?.dataset.defaultNote || '可试算优惠，试算不占用库存或优惠次数。';
        const defaultLabel = totalLabel?.dataset.defaultLabel || '商品小计';

        let sequence = 0;
        let pending = null;

        function reset() {
            sequence++;
            if (pending) pending.abort();
            pending = null;
            button.disabled = false;
            if (result) {
                result.textContent = defaultNote;
                result.removeAttribute('data-error');
            }
            if (totalLabel) totalLabel.textContent = defaultLabel;
        }

        form.addEventListener('checkout:reset', reset);
        if (quantity) quantity.addEventListener('input', reset);
        if (coupon) {
            coupon.addEventListener('input', () => {
                if (quantity) quantity.dispatchEvent(new Event('input', { bubbles: true }));
                else reset();
            });
        }

        button.addEventListener('click', async () => {
            if (quantity && !quantity.reportValidity()) return;
            if (pending) pending.abort();
            const version = ++sequence;
            const controller = new AbortController();
            pending = controller;
            button.disabled = true;
            if (result) {
                result.textContent = '正在校验数量和优惠码…';
                result.removeAttribute('data-error');
            }

            try {
                const response = await fetch('/order/quote', {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': form.querySelector('[name="_token"]')?.value || '',
                    },
                    body: JSON.stringify({
                        product_id: Number(form.querySelector('[name="product_id"]')?.value || 0),
                        quantity: Number(quantity?.value || 1),
                        coupon_code: coupon ? coupon.value.trim() : '',
                    }),
                });
                const body = await response.json();
                if (version !== sequence) return;
                if (!response.ok) {
                    const validation = Object.values(body.errors || {}).flat()[0];
                    throw new Error(validation || body.message || '试算失败，请稍后重试。');
                }
                if (unitNode) unitNode.textContent = '¥' + body.data.unit_price;
                if (totalNode) totalNode.textContent = '¥' + body.data.total_amount;
                if (totalLabel) totalLabel.textContent = '预计实付';
                if (result) {
                    result.textContent = '已校验：优惠 ¥' + body.data.discount_amount + '，预计实付 ¥' + body.data.total_amount + '。最终金额以提交订单时为准。';
                }
            } catch (error) {
                if (version !== sequence || error.name === 'AbortError') return;
                if (quantity) quantity.dispatchEvent(new Event('input', { bubbles: true }));
                if (result) {
                    result.textContent = error instanceof TypeError ? '网络异常，暂时无法试算，请重试。' : error.message;
                    result.setAttribute('data-error', 'true');
                }
            } finally {
                if (pending === controller) {
                    pending = null;
                    button.disabled = false;
                }
            }
        });
    });
})();
