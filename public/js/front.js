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

        // 滚过一屏才出现。之前它是常驻的，于是在页面顶部也挂着一个点了什么都不会发生
        // 的按钮，还会压住右下角的内容——实测手机上 modern 首页的「查看全部」链接就
        // 被它盖住（elementFromPoint 命中的是这个按钮）。
        // 用 class 切换而不是直接改 style：显示与否是状态，具体怎么隐藏交给样式表。
        var toggleBackToTop = function () {
            backToTop.classList.toggle('is-visible', window.scrollY > 300);
        };
        toggleBackToTop();
        window.addEventListener('scroll', toggleBackToTop, { passive: true });
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
        // 失败必须说出来。原来是 catch (e) {} —— 复制不成功时按钮毫无反应，买家会
        // 以为已经拷到剪贴板，然后粘出一片空白，而卡密正是他唯一买到的东西。
        try {
            if (document.execCommand('copy')) { showCopied(btn); }
            else { showCopyFailed(btn); }
        } catch (e) {
            showCopyFailed(btn);
        }
        document.body.removeChild(ta);
    }

    // Swap the button's label for a while, then put the original back.
    //
    // Guard the re-entrant case. A second click inside the window used to capture
    // "已复制" as `original`, and the button then said 已复制 forever — on the
    // card-delivery page, where 复制 is the one control that matters.
    function flashLabel(btn, label, ms) {
        if (btn.dataset.copyTimer) {
            clearTimeout(Number(btn.dataset.copyTimer));
        } else {
            btn.dataset.copyOriginal = btn.textContent;
        }

        btn.textContent = label;
        btn.dataset.copyTimer = String(setTimeout(function () {
            btn.textContent = btn.dataset.copyOriginal || '复制';
            btn.classList.remove('is-copy-failed');
            delete btn.dataset.copyTimer;
            delete btn.dataset.copyOriginal;
        }, ms));
    }

    function showCopied(btn) {
        btn.classList.remove('is-copy-failed');
        flashLabel(btn, '已复制', 2000);
    }

    // Failure has to say so. This used to be `catch (e) {}` — when the copy did not
    // go through the button did nothing at all, so the buyer assumed the cards were
    // on the clipboard, pasted nothing, and the one thing they paid for was gone.
    // Held longer than the success label because it asks for a second action.
    function showCopyFailed(btn) {
        btn.classList.add('is-copy-failed');
        flashLabel(btn, '复制失败', 3500);
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
                // is-loading 是样式钩子：主题可以在按钮上画一个转环。禁用态本身
                // 只是让按钮变灰，看不出「正在处理」和「不能点」的区别。
                btn.classList.add('is-loading');
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
            btn.classList.remove('is-loading');
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
            var annBox = annModal.querySelector('.ann-modal-box');
            // 打开前记住焦点在哪，关闭时还回去。不还的话，关掉弹窗后焦点回到
            // <body>，键盘用户得从页头重新 Tab 一遍才能回到原来的位置。
            var annOpener = document.activeElement;

            // 对话框里当前可聚焦的元素。每次 Tab 都重新查：关闭按钮在倒计时结束前
            // 是禁用的，可聚焦集合会变。
            var annFocusable = function () {
                return Array.prototype.filter.call(
                    annBox.querySelectorAll('a[href], button, input, textarea, select, [tabindex]'),
                    function (el) {
                        return !el.disabled
                            && el.tabIndex >= 0
                            && el.getClientRects().length > 0;
                    }
                );
            };

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
                if (annOpener && typeof annOpener.focus === 'function') {
                    annOpener.focus();
                }
            };

            // Recorded on open, not on close. Someone who opens the home page and
            // immediately taps through to a product must not be shown it twice, and
            // that navigation happens before any dismissal.
            annWrite();

            annModal.hidden = false;
            document.body.style.overflow = 'hidden';
            annBox.focus();

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
                if (annModal.hidden) {
                    return;
                }

                if (e.key === 'Escape') {
                    annClose();
                    return;
                }

                // 焦点约束。aria-modal="true" 只告诉读屏「底下的内容不算数」，
                // 它不拦 Tab —— 没有这段，键盘用户会 Tab 出对话框，走到一个被
                // 遮罩盖住、点不到也看不清的页面里。
                if (e.key !== 'Tab') {
                    return;
                }

                var items = annFocusable();
                if (!items.length) {
                    // 倒计时还没结束，对话框里没有可聚焦的东西：焦点留在框上。
                    e.preventDefault();
                    annBox.focus();
                    return;
                }

                var first = items[0];
                var last = items[items.length - 1];

                if (e.shiftKey && (document.activeElement === first || document.activeElement === annBox)) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            });
        }
    }

});
