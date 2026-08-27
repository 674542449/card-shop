/**
 * Card Shop - Frontend JavaScript
 */
document.addEventListener('DOMContentLoaded', function () {

    // ============================================================
    // Announcement Dismiss
    // ============================================================
    const announcementBar = document.getElementById('announcement-bar');
    const announcementDismiss = document.getElementById('announcement-dismiss');

    if (announcementBar && announcementDismiss) {
        // Check if already dismissed in this session
        if (sessionStorage.getItem('announcement_dismissed') === '1') {
            announcementBar.style.display = 'none';
        }

        announcementDismiss.addEventListener('click', function () {
            announcementBar.style.display = 'none';
            sessionStorage.setItem('announcement_dismissed', '1');
        });
    }

    // ============================================================
    // Dynamic Price Calculation (Product Page)
    // ============================================================
    const quantityInput = document.getElementById('quantity');
    const totalPriceEl = document.getElementById('total-price');
    const unitPriceEl = document.getElementById('unit-price-display');
    const couponInput = document.getElementById('coupon_code');
    const couponApplyBtn = document.getElementById('coupon-apply-btn');
    const couponMessage = document.getElementById('coupon-message');

    let basePrice = parseFloat(document.getElementById('product-base-price')?.value || 0);
    let wholesalePrices = [];
    let appliedDiscount = 0;

    // Parse wholesale prices from data attribute
    const wholesaleDataEl = document.getElementById('wholesale-prices-data');
    if (wholesaleDataEl) {
        try {
            wholesalePrices = JSON.parse(wholesaleDataEl.value);
        } catch (e) {
            wholesalePrices = [];
        }
    }

    function getEffectivePrice(qty) {
        let price = basePrice;
        for (let i = wholesalePrices.length - 1; i >= 0; i--) {
            if (qty >= wholesalePrices[i].min_quantity) {
                price = parseFloat(wholesalePrices[i].price);
                break;
            }
        }
        return price;
    }

    function updateTotalPrice() {
        if (!quantityInput || !totalPriceEl) return;

        const qty = parseInt(quantityInput.value) || 1;
        const unitPrice = getEffectivePrice(qty);
        let total = unitPrice * qty;

        if (unitPriceEl) {
            unitPriceEl.textContent = '¥' + unitPrice.toFixed(2);
        }

        if (appliedDiscount > 0) {
            total = Math.max(0.01, total - appliedDiscount);
        }

        totalPriceEl.textContent = '¥' + total.toFixed(2);
    }

    if (quantityInput) {
        quantityInput.addEventListener('input', function () {
            appliedDiscount = 0;
            if (couponMessage) {
                couponMessage.textContent = '';
                couponMessage.className = '';
            }
            updateTotalPrice();
        });
        updateTotalPrice();
    }

    // ============================================================
    // AJAX Coupon Validation
    // ============================================================
    if (couponApplyBtn) {
        couponApplyBtn.addEventListener('click', function () {
            const code = couponInput ? couponInput.value.trim() : '';
            if (!code) {
                showCouponMessage('请输入优惠码', 'danger');
                return;
            }

            const qty = parseInt(quantityInput?.value) || 1;
            const unitPrice = getEffectivePrice(qty);
            const total = unitPrice * qty;
            const productId = document.getElementById('product_id')?.value;

            couponApplyBtn.disabled = true;
            couponApplyBtn.textContent = '验证中...';

            // Use the CSRF token
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

            fetch('/order/verify-coupon', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    coupon_code: code,
                    product_id: productId,
                    amount: total,
                }),
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    appliedDiscount = parseFloat(data.discount || 0);
                    showCouponMessage('优惠码已应用，优惠 ¥' + appliedDiscount.toFixed(2), 'success');
                    updateTotalPrice();
                } else {
                    appliedDiscount = 0;
                    showCouponMessage(data.message || '优惠码无效', 'danger');
                    updateTotalPrice();
                }
            })
            .catch(function () {
                // If no coupon verify endpoint, calculate locally
                appliedDiscount = 0;
                showCouponMessage('优惠码将在提交订单时验证', 'info');
            })
            .finally(function () {
                couponApplyBtn.disabled = false;
                couponApplyBtn.textContent = '应用';
            });
        });
    }

    function showCouponMessage(msg, type) {
        if (!couponMessage) return;
        couponMessage.textContent = msg;
        couponMessage.className = 'form-text text-' + type;
    }

    // ============================================================
    // Copy to Clipboard
    // ============================================================
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.btn-copy');
        if (!btn) return;

        const targetId = btn.dataset.target;
        const target = document.getElementById(targetId);
        if (!target) return;

        const text = target.textContent || target.value;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showCopied(btn);
            }).catch(function () {
                fallbackCopy(text, btn);
            });
        } else {
            fallbackCopy(text, btn);
        }
    });

    function fallbackCopy(text, btn) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            showCopied(btn);
        } catch (e) {
            // ignore
        }
        document.body.removeChild(ta);
    }

    function showCopied(btn) {
        var original = btn.textContent;
        btn.textContent = '已复制';
        btn.classList.add('copied');
        setTimeout(function () {
            btn.textContent = original;
            btn.classList.remove('copied');
        }, 2000);
    }

    // ============================================================
    // Payment Status Polling
    // ============================================================
    const paymentPollingEl = document.getElementById('payment-polling');

    if (paymentPollingEl) {
        const orderNo = paymentPollingEl.dataset.orderNo;
        const checkUrl = '/order/pay/' + orderNo;

        var pollingInterval = setInterval(function () {
            fetch(checkUrl, {
                headers: { 'Accept': 'application/json' },
            })
            .then(function (response) {
                if (response.redirected) {
                    // If server redirects, payment might be complete
                    window.location.reload();
                    return;
                }
                return response.text();
            })
            .then(function (html) {
                // Simple check - if the page now contains paid status
                if (html && html.indexOf('"paid"') !== -1) {
                    clearInterval(pollingInterval);
                    window.location.reload();
                }
            })
            .catch(function () {
                // Silently fail, will retry
            });
        }, 5000);

        // Stop polling after 30 minutes
        setTimeout(function () {
            clearInterval(pollingInterval);
        }, 30 * 60 * 1000);
    }

    // ============================================================
    // Countdown Timer
    // ============================================================
    const countdownEl = document.getElementById('countdown-timer');

    if (countdownEl) {
        const expiresAt = new Date(countdownEl.dataset.expires).getTime();

        function updateCountdown() {
            const now = Date.now();
            const diff = expiresAt - now;

            if (diff <= 0) {
                countdownEl.querySelector('.time').textContent = '00:00';
                countdownEl.querySelector('.label').textContent = '订单已过期';
                setTimeout(function () {
                    window.location.reload();
                }, 2000);
                return;
            }

            var minutes = Math.floor(diff / 60000);
            var seconds = Math.floor((diff % 60000) / 1000);
            countdownEl.querySelector('.time').textContent =
                String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');

            requestAnimationFrame(updateCountdown);
        }

        updateCountdown();
    }

    // ============================================================
    // Form submission guard (prevent double submit)
    // ============================================================
    document.querySelectorAll('form[data-guard]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="loading-spinner"></span> 处理中...';
            }
        });
    });

});
