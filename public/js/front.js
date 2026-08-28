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
        // Guard the re-entrant case. A second click inside the 2s window used to
        // capture "已复制" as `original`, and the button then said 已复制 forever —
        // on the card-delivery page, where 复制 is the one control that matters.
        if (btn.dataset.copyTimer) {
            clearTimeout(Number(btn.dataset.copyTimer));
        } else {
            btn.dataset.copyOriginal = btn.textContent;
        }

        btn.textContent = '已复制';
        btn.dataset.copyTimer = String(setTimeout(function () {
            btn.textContent = btn.dataset.copyOriginal || '复制';
            delete btn.dataset.copyTimer;
            delete btn.dataset.copyOriginal;
        }, 2000));
    }

    // Payment status polling
    var paymentPollingEl = document.getElementById('payment-polling');
    if (paymentPollingEl) {
        var orderNo = paymentPollingEl.dataset.orderNo;
        var checkUrl = '/order/pay/' + orderNo;
        var pollingInterval = setInterval(function () {
            fetch(checkUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (response) {
                    if (response.redirected) { window.location.reload(); return null; }
                    return response.json();
                })
                .then(function (data) {
                    // The controller answers JSON for this request. It used to answer
                    // HTML and this checked `indexOf('"paid"')` against the whole page —
                    // which the embedded order JSON and the status labels could both
                    // satisfy while the order was still pending, reloading in a loop.
                    if (data && data.status && data.status !== 'pending') {
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
                if (!btn.dataset.guardLabel) btn.dataset.guardLabel = btn.textContent;
                btn.disabled = true;
                btn.textContent = '处理中...';
            }
        });
    });

    // Restore those buttons when the page comes back from the back/forward cache.
    // Safari and Firefox restore the live DOM rather than re-running the script, so
    // a buyer who pressed 立即购买 and then hit Back landed on a dead 处理中... button
    // and had to reload to buy anything.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('form[data-guard] button[type="submit"]').forEach(function (btn) {
            btn.disabled = false;
            if (btn.dataset.guardLabel) btn.textContent = btn.dataset.guardLabel;
        });
    });

    // ----- Announcement dialog -----
    //
    // Shown on the home and product pages. Two things it must not do: pop up again
    // when the visitor moves from the home page to a product page, and pop up every
    // day for someone who has already read it. Both are the same rule — remember
    // that THIS browser has seen THIS announcement, and stay quiet for a while.
    //
    // The record is keyed on a signature of the rendered announcement, so editing
    // the notice reaches people who dismissed the old one instead of being hidden
    // behind their existing dismissal.
    var annModal = document.getElementById('announcement-modal');

    if (annModal) {
        var annStore = 'cardshop_announcement_seen';
        var annSignature = annModal.getAttribute('data-signature') || '';
        var annHours = parseInt(annModal.getAttribute('data-hours'), 10);
        var annSeconds = parseInt(annModal.getAttribute('data-countdown'), 10);

        if (isNaN(annHours)) { annHours = 24; }
        if (isNaN(annSeconds) || annSeconds < 0) { annSeconds = 5; }

        // Private browsing and "block site data" both make localStorage throw on
        // access, not just return null. Every use is guarded: the dialog still works
        // there, it simply cannot remember, which is the right way to fail.
        var annRead = function () {
            try {
                return JSON.parse(window.localStorage.getItem(annStore) || 'null');
            } catch (e) {
                return null;
            }
        };
        var annWrite = function () {
            try {
                window.localStorage.setItem(annStore, JSON.stringify({
                    signature: annSignature,
                    seenAt: Date.now()
                }));
            } catch (e) {
                /* Nothing to do — an unavailable store just means we ask again. */
            }
        };

        var annSuppressed = function () {
            if (annHours === 0) {
                return false;
            }

            var seen = annRead();

            return !!seen
                && seen.signature === annSignature
                && (Date.now() - seen.seenAt) < annHours * 3600 * 1000;
        };

        if (!annSuppressed()) {
            var annCloseBtn = document.getElementById('ann-modal-close');
            var annCounter = document.getElementById('ann-modal-countdown');
            var annRemaining = annSeconds;
            var annTimer = null;
            var annPreviousOverflow = document.body.style.overflow;

            var annFinish = function () {
                if (annTimer) {
                    clearInterval(annTimer);
                    annTimer = null;
                }
                annCloseBtn.disabled = false;
                annCloseBtn.setAttribute('aria-disabled', 'false');
                annCounter.textContent = '';
                annCloseBtn.focus();
            };

            var annClose = function () {
                // Only after the countdown: the backdrop and Escape are wired to the
                // same handler, so this one check covers every way out.
                if (annCloseBtn.disabled) {
                    return;
                }
                annModal.hidden = true;
                document.body.style.overflow = annPreviousOverflow;
                annWrite();
            };

            // Recorded on open, not on close. Someone who opens the home page and
            // immediately taps through to a product must not be shown it twice, and
            // that navigation happens before any dismissal.
            annWrite();

            annModal.hidden = false;
            document.body.style.overflow = 'hidden';

            if (annRemaining > 0) {
                annCounter.textContent = '(' + annRemaining + ')';
                annTimer = setInterval(function () {
                    annRemaining -= 1;
                    if (annRemaining <= 0) {
                        annFinish();
                    } else {
                        annCounter.textContent = '(' + annRemaining + ')';
                    }
                }, 1000);
            } else {
                annFinish();
            }

            annModal.querySelectorAll('[data-ann-dismiss]').forEach(function (el) {
                el.addEventListener('click', annClose);
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !annModal.hidden) {
                    annClose();
                }
            });
        }
    }

});
