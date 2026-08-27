document.addEventListener('DOMContentLoaded', function () {

    // Mobile menu toggle
    var menuToggle = document.getElementById('menu-toggle');
    var mobileNav = document.getElementById('mobile-nav');
    if (menuToggle && mobileNav) {
        menuToggle.addEventListener('click', function () {
            mobileNav.classList.toggle('open');
        });
        document.addEventListener('click', function (e) {
            if (!mobileNav.contains(e.target) && e.target !== menuToggle) {
                mobileNav.classList.remove('open');
            }
        });
    }

    // Back to top
    var backToTop = document.getElementById('back-to-top');
    if (backToTop) {
        backToTop.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // Dynamic price calculation (product page)
    var quantityInput = document.getElementById('quantity');
    var totalPriceEl = document.getElementById('total-price');

    var basePrice = parseFloat((document.getElementById('product-base-price') || {}).value || 0);
    var wholesalePrices = [];
    var appliedDiscount = 0;

    var wholesaleDataEl = document.getElementById('wholesale-prices-data');
    if (wholesaleDataEl) {
        try { wholesalePrices = JSON.parse(wholesaleDataEl.value); } catch (e) { wholesalePrices = []; }
    }

    function getEffectivePrice(qty) {
        var price = basePrice;
        for (var i = wholesalePrices.length - 1; i >= 0; i--) {
            if (qty >= wholesalePrices[i].min_quantity) {
                price = parseFloat(wholesalePrices[i].price);
                break;
            }
        }
        return price;
    }

    function updateTotalPrice() {
        if (!quantityInput || !totalPriceEl) return;
        var qty = parseInt(quantityInput.value) || 1;
        var unitPrice = getEffectivePrice(qty);
        var total = unitPrice * qty;
        if (appliedDiscount > 0) {
            total = Math.max(0.01, total - appliedDiscount);
        }
        totalPriceEl.textContent = '¥' + total.toFixed(2);
    }

    if (quantityInput) {
        quantityInput.addEventListener('input', updateTotalPrice);
        updateTotalPrice();
    }

    // Copy to clipboard
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.btn-copy');
        if (!btn) return;
        var targetId = btn.dataset.target;
        var target = document.getElementById(targetId);
        if (!target) return;
        var text = target.value || target.textContent;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { showCopied(btn); })
                .catch(function () { fallbackCopy(text, btn); });
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
        try { document.execCommand('copy'); showCopied(btn); } catch (e) {}
        document.body.removeChild(ta);
    }

    function showCopied(btn) {
        var original = btn.textContent;
        btn.textContent = '已复制';
        setTimeout(function () { btn.textContent = original; }, 2000);
    }

    // Payment status polling
    var paymentPollingEl = document.getElementById('payment-polling');
    if (paymentPollingEl) {
        var orderNo = paymentPollingEl.dataset.orderNo;
        var checkUrl = '/order/pay/' + orderNo;
        var pollingInterval = setInterval(function () {
            fetch(checkUrl, { headers: { 'Accept': 'application/json' } })
                .then(function (response) {
                    if (response.redirected) { window.location.reload(); return; }
                    return response.text();
                })
                .then(function (html) {
                    if (html && html.indexOf('"paid"') !== -1) {
                        clearInterval(pollingInterval);
                        window.location.reload();
                    }
                })
                .catch(function () {});
        }, 5000);
        setTimeout(function () { clearInterval(pollingInterval); }, 30 * 60 * 1000);
    }

    // Countdown timer
    var countdownEl = document.getElementById('countdown-timer');
    if (countdownEl) {
        var expiresAt = new Date(countdownEl.dataset.expires).getTime();
        function updateCountdown() {
            var diff = expiresAt - Date.now();
            var timeEl = countdownEl.querySelector('.time');
            if (diff <= 0) {
                if (timeEl) timeEl.textContent = '00:00';
                setTimeout(function () { window.location.reload(); }, 2000);
                return;
            }
            var minutes = Math.floor(diff / 60000);
            var seconds = Math.floor((diff % 60000) / 1000);
            if (timeEl) timeEl.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
            requestAnimationFrame(updateCountdown);
        }
        updateCountdown();
    }

    // Form submission guard
    document.querySelectorAll('form[data-guard]').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.textContent = '处理中...';
            }
        });
    });

});
