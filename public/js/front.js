document.addEventListener('DOMContentLoaded', () => {
    'use strict';

    const root = document.documentElement;

    // 1. Unified Dark / Light Theme Switcher (shared across all storefront themes)
    const themeBtn = document.getElementById('ui-theme-toggle');
    const darkMedia = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function isDark() {
        const explicit = root.getAttribute('data-ui-theme');
        if (explicit === 'dark') return true;
        if (explicit === 'light') return false;
        return darkMedia ? darkMedia.matches : false;
    }

    function syncThemeButton() {
        if (!themeBtn) return;
        const dark = isDark();
        const label = dark ? '切换浅色模式' : '切换深色模式';
        themeBtn.setAttribute('aria-pressed', String(dark));
        themeBtn.setAttribute('aria-label', label);
        themeBtn.title = label;
    }

    syncThemeButton();
    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            const next = isDark() ? 'light' : 'dark';
            const systemScheme = darkMedia && darkMedia.matches ? 'dark' : 'light';
            if (next === systemScheme) {
                root.removeAttribute('data-ui-theme');
                try { localStorage.removeItem('ui-theme'); } catch {}
            } else {
                root.setAttribute('data-ui-theme', next);
                try { localStorage.setItem('ui-theme', next); } catch {}
            }
            syncThemeButton();
        });
    }
    if (darkMedia?.addEventListener) {
        darkMedia.addEventListener('change', syncThemeButton);
    }
    window.addEventListener('storage', (e) => {
        if (e.key !== 'ui-theme') return;
        if (e.newValue === 'dark' || e.newValue === 'light') {
            root.setAttribute('data-ui-theme', e.newValue);
        } else {
            root.removeAttribute('data-ui-theme');
        }
        syncThemeButton();
    });

    // 2. Mobile menu toggle (used by minimal & modern themes)
    const menuToggle = document.getElementById('menu-toggle');
    const mobileNav = document.getElementById('mobile-nav');
    if (menuToggle && mobileNav) {
        menuToggle.addEventListener('click', () => {
            const isOpen = mobileNav.classList.toggle('open');
            menuToggle.setAttribute('aria-expanded', String(isOpen));
        });
        document.addEventListener('click', (e) => {
            if (!mobileNav.contains(e.target) && !menuToggle.contains(e.target)) {
                mobileNav.classList.remove('open');
                menuToggle.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && mobileNav.classList.contains('open')) {
                mobileNav.classList.remove('open');
                menuToggle.setAttribute('aria-expanded', 'false');
                menuToggle.focus();
            }
        });
    }

    // 3. Back to top (IntersectionObserver sentinel instead of main-thread scroll listener)
    const backToTop = document.getElementById('back-to-top');
    if (backToTop) {
        backToTop.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        if ('IntersectionObserver' in window) {
            const sentinel = document.createElement('div');
            sentinel.setAttribute('aria-hidden', 'true');
            sentinel.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:300px;pointer-events:none;visibility:hidden';
            document.body.style.position = document.body.style.position || 'relative';
            document.body.prepend(sentinel);

            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    backToTop.classList.toggle('is-visible', !entry.isIntersecting);
                });
            });
            observer.observe(sentinel);
        }
    }

    // 4. Unified Dynamic Price Calculation + Quantity Stepper (shared across all themes)
    const quantityInput = document.getElementById('quantity');
    const totalPriceEl = document.getElementById('total-price');
    const basePrice = parseFloat((document.getElementById('product-base-price') || {}).value || 0);
    let wholesalePrices = [];

    const wholesaleDataEl = document.getElementById('wholesale-prices-data');
    if (wholesaleDataEl) {
        try { wholesalePrices = JSON.parse(wholesaleDataEl.value); } catch { wholesalePrices = []; }
    }

    const wholesaleRows = document.querySelectorAll('.wholesale-table tbody tr[data-min-qty]');
    const stepButtons = Array.from(document.querySelectorAll('[data-qty-step], [data-ws-qty-step]'));

    function stepDelta(btn) {
        return Number(btn.dataset.qtyStep || btn.dataset.wsQtyStep || 0);
    }

    function getEffectivePrice(qty) {
        let price = basePrice;
        let matchedIdx = -1;
        for (let i = wholesalePrices.length - 1; i >= 0; i--) {
            if (qty >= wholesalePrices[i].min_quantity) {
                price = parseFloat(wholesalePrices[i].price);
                matchedIdx = i;
                break;
            }
        }
        wholesaleRows.forEach((row, idx) => {
            row.classList.toggle('active-tier', idx === matchedIdx + 1);
        });
        if (matchedIdx === -1 && wholesaleRows.length > 0) {
            wholesaleRows[0].classList.add('active-tier');
        }
        return price;
    }

    function syncStepperAndMobilePrice() {
        if (!quantityInput) return;
        const min = Number(quantityInput.min) || 1;
        const max = Number(quantityInput.max) || 99999;
        const raw = Number(quantityInput.value);
        const cur = quantityInput.value !== '' && Number.isInteger(raw) ? raw : min;

        stepButtons.forEach((btn) => {
            const delta = stepDelta(btn);
            btn.disabled = delta < 0 ? cur <= min : cur >= max;
        });

        const mobileUnitNode = document.getElementById('ws-mobile-unit-price');
        const unitPriceEl = document.getElementById('unit-price');
        if (mobileUnitNode && unitPriceEl && unitPriceEl.textContent) {
            mobileUnitNode.replaceChildren(document.createTextNode(unitPriceEl.textContent + ' '));
            const sm = document.createElement('small');
            sm.textContent = '/ 件';
            mobileUnitNode.appendChild(sm);
        }
    }

    function updateTotalPrice() {
        if (!quantityInput || !totalPriceEl) return;
        const unitPriceEl = document.getElementById('unit-price');
        const qty = Number(quantityInput.value);
        if (quantityInput.value.trim() === '' || !Number.isInteger(qty) || qty < 1 || !quantityInput.validity.valid) {
            totalPriceEl.textContent = '—';
            if (unitPriceEl) unitPriceEl.textContent = '—';
            wholesaleRows.forEach((row) => row.classList.remove('active-tier'));
            syncStepperAndMobilePrice();
            return;
        }
        const unitPrice = getEffectivePrice(qty);
        const total = unitPrice * qty;
        if (unitPriceEl) unitPriceEl.textContent = '¥' + unitPrice.toFixed(2);
        totalPriceEl.textContent = '¥' + total.toFixed(2);
        syncStepperAndMobilePrice();
    }

    if (quantityInput) {
        const min = Number(quantityInput.min) || 1;
        const max = Number(quantityInput.max) || 99999;

        stepButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                const raw = Number(quantityInput.value);
                const cur = quantityInput.value !== '' && Number.isInteger(raw) ? raw : min;
                const next = Math.max(min, Math.min(max, cur + stepDelta(btn)));
                quantityInput.value = String(next);
                quantityInput.dispatchEvent(new Event('input', { bubbles: true }));
                quantityInput.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        wholesaleRows.forEach((row) => {
            if (row.getAttribute('data-tier-disabled') === '1') return;
            row.style.cursor = 'pointer';
            row.addEventListener('click', () => {
                const targetQty = Math.max(min, Math.min(max, Number(row.getAttribute('data-min-qty')) || min));
                quantityInput.value = String(targetQty);
                quantityInput.dispatchEvent(new Event('input', { bubbles: true }));
                quantityInput.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });

        quantityInput.addEventListener('input', updateTotalPrice);
        updateTotalPrice();
    }

    // 5. Copy to clipboard
    const copyTimers = new WeakMap();

    function legacyCopy(text) {
        const field = Object.assign(document.createElement('textarea'), { value: text });
        field.setAttribute('readonly', '');
        field.style.cssText = 'position:fixed;inset-block-start:0;opacity:0';
        document.body.append(field);
        field.select();
        try { return document.execCommand('copy'); } catch { return false; } finally { field.remove(); }
    }

    function flashLabel(btn, label, ms) {
        const existing = copyTimers.get(btn);
        if (existing) {
            clearTimeout(existing.timer);
        } else {
            copyTimers.set(btn, { original: btn.textContent, timer: 0 });
        }
        const entry = copyTimers.get(btn);
        btn.textContent = label;
        entry.timer = setTimeout(() => {
            btn.textContent = entry.original || '复制';
            btn.classList.remove('is-copy-failed');
            copyTimers.delete(btn);
        }, ms);
    }

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.btn-copy');
        if (!btn) return;
        const target = document.getElementById(btn.dataset.target);
        if (!target) return;
        const text = target.matches('input, textarea') ? target.value : target.textContent;
        if (text === '') return;

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
            } else if (!legacyCopy(text)) {
                throw new Error('copy-failed');
            }
            btn.classList.remove('is-copy-failed');
            flashLabel(btn, '已复制', 2000);
        } catch {
            if (legacyCopy(text)) {
                btn.classList.remove('is-copy-failed');
                flashLabel(btn, '已复制', 2000);
            } else {
                btn.classList.add('is-copy-failed');
                flashLabel(btn, '复制失败', 3500);
            }
        }
    });

    // 6. Payment status polling
    const paymentPollingEl = document.getElementById('payment-polling');
    let recheckPayment = null;
    if (paymentPollingEl) {
        const orderNo = paymentPollingEl.dataset.orderNo;
        const checkUrl = paymentPollingEl.dataset.pollUrl || ('/order/pay/' + orderNo);
        let paymentDeadline = new Date(paymentPollingEl.dataset.expires).getTime();
        const paymentStatus = paymentPollingEl.querySelector('[data-payment-status]');
        const paymentRetry = paymentPollingEl.querySelector('[data-payment-recheck]');
        const paymentVerify = paymentPollingEl.querySelector('[data-payment-verify]');
        let paymentTimer = null;
        let paymentBusy = false;
        let paymentStopped = false;
        let paymentFailures = 0;

        const paymentMessage = (msg) => { if (paymentStatus) paymentStatus.textContent = msg; };
        const paymentSchedule = () => {
            if (!paymentStopped && Date.now() < paymentDeadline) {
                paymentTimer = setTimeout(() => recheckPayment(false), Math.min(30000, 5000 * Math.pow(2, paymentFailures)));
            }
        };
        const paymentNeedsVerification = () => {
            paymentStopped = true;
            paymentMessage('查询会话已失效，请验证下单邮箱与查询密码后查看订单。');
            if (paymentVerify) paymentVerify.hidden = false;
        };

        recheckPayment = (manual) => {
            if (paymentBusy || (paymentStopped && !manual)) return;
            if (paymentTimer) clearTimeout(paymentTimer);
            if (manual) { paymentStopped = false; paymentFailures = 0; }
            paymentBusy = true;
            if (paymentRetry) paymentRetry.disabled = true;
            paymentMessage('正在检查付款结果…');

            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 10000);

            fetch(checkUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            })
                .then((response) => {
                    if ([401, 403, 419].includes(response.status) || response.redirected) {
                        paymentNeedsVerification();
                        return null;
                    }
                    if (!response.ok) throw new Error('payment-check-unavailable');
                    return response.json();
                })
                .then((data) => {
                    if (!data) return;
                    if (!['pending', 'paid', 'expired', 'closed'].includes(data.status)) throw new Error('payment-check-invalid');
                    if (data.verification_required) { paymentNeedsVerification(); return; }
                    if (data.status !== 'pending' || data.payment_review) {
                        paymentStopped = true;
                        window.location.reload();
                        return;
                    }
                    paymentFailures = 0;
                    if (data.expires_at) paymentDeadline = new Date(data.expires_at).getTime();
                    if (Date.now() >= paymentDeadline) {
                        paymentStopped = true;
                        paymentMessage('已到支付截止时间，请重新检查订单；若已付款，请勿重复付款。');
                    } else {
                        paymentMessage('等待付款确认，成功后自动显示卡密。也可点击重新检查。');
                    }
                })
                .catch(() => {
                    paymentFailures++;
                    paymentStopped = Date.now() >= paymentDeadline;
                    paymentMessage(paymentStopped
                        ? '已到支付截止时间，暂未能确认结果。请重新检查；若已付款，请勿重复付款。'
                        : '暂时无法检查付款结果，正在自动重试。请保持此页打开或重新检查。');
                })
                .finally(() => {
                    clearTimeout(timeout);
                    paymentBusy = false;
                    if (paymentRetry) paymentRetry.disabled = false;
                    paymentSchedule();
                });
        };
        if (paymentRetry) paymentRetry.addEventListener('click', () => recheckPayment(true));
        recheckPayment(false);
    }

    // 7. Countdown timer
    const countdownEl = document.getElementById('countdown-timer');
    if (countdownEl) {
        const expiresAt = new Date(countdownEl.dataset.expires).getTime();
        let countdownTimer = null;
        const updateCountdown = () => {
            const diff = expiresAt - Date.now();
            const timeEl = countdownEl.querySelector('.time');
            if (diff <= 0) {
                if (timeEl) timeEl.textContent = '00:00';
                if (countdownTimer) clearInterval(countdownTimer);
                if (recheckPayment) recheckPayment(false);
                else setTimeout(() => window.location.reload(), 2000);
                return;
            }
            const minutes = Math.floor(diff / 60000);
            const seconds = Math.floor((diff % 60000) / 1000);
            if (timeEl) timeEl.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        };
        updateCountdown();
        if (expiresAt > Date.now()) countdownTimer = setInterval(updateCountdown, 1000);
    }

    // 8. Form submission guard, Turnstile readiness check & bfcache restoration
    document.querySelectorAll('form[data-guard]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (e.defaultPrevented || !form.checkValidity()) return;

            const tsWrap = form.querySelector('.cf-turnstile');
            const tsInput = tsWrap ? tsWrap.querySelector('[name="cf-turnstile-response"]') : null;
            const prevHint = form.querySelector('.ws-turnstile-runtime-error');
            if (prevHint) prevHint.remove();

            if (tsInput && !tsInput.value.trim()) {
                e.preventDefault();
                const err = document.createElement('p');
                err.className = 'ws-field-error ws-turnstile-runtime-error';
                err.setAttribute('role', 'alert');
                err.textContent = '请等待人机安全校验完成后再提交';
                tsWrap.insertAdjacentElement('afterend', err);
                tsWrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                if (!btn.dataset.guardLabel) btn.dataset.guardLabel = btn.textContent;
                btn.disabled = true;
                btn.classList.add('is-loading');
                btn.textContent = '处理中...';
            }
        });
    });

    function refreshCheckoutIntents() {
        const csrf = document.querySelectorAll('input[name="_token"]')[0]?.value;
        if (!csrf) return;

        const ids = new Set();
        document.querySelectorAll('input[name="checkout_key"][data-intent-product]').forEach((el) => {
            const id = Number(el.getAttribute('data-intent-product'));
            if (id > 0) ids.add(id);
        });
        document.querySelectorAll('[data-product-payload]').forEach((card) => {
            try {
                const payload = JSON.parse(card.getAttribute('data-product-payload'));
                if (payload?.id > 0) ids.add(Number(payload.id));
            } catch {}
        });
        if (!ids.size) return;

        fetch('/order/intent', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({ product_ids: Array.from(ids).slice(0, 30) }),
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((body) => {
                const keys = body?.keys;
                if (!keys || typeof keys !== 'object') return;
                document.querySelectorAll('input[name="checkout_key"][data-intent-product]').forEach((el) => {
                    const key = keys[String(el.getAttribute('data-intent-product'))];
                    if (key) el.value = key;
                });
                document.querySelectorAll('[data-product-payload]').forEach((card) => {
                    try {
                        const payload = JSON.parse(card.getAttribute('data-product-payload'));
                        const key = keys[String(payload?.id)];
                        if (key) {
                            payload.checkoutKey = key;
                            card.setAttribute('data-product-payload', JSON.stringify(payload));
                        }
                    } catch {}
                });
            })
            .catch(() => {});
    }

    window.addEventListener('pageshow', (e) => {
        if (!e.persisted) return;
        document.querySelectorAll('form[data-guard] button[type="submit"]').forEach((btn) => {
            btn.disabled = false;
            btn.classList.remove('is-loading');
            if (btn.dataset.guardLabel) btn.textContent = btn.dataset.guardLabel;
        });
        document.querySelectorAll('input[data-clear-on-restore]').forEach((input) => {
            input.value = '';
            input.classList.add('is-secret-masked');
        });
        document.querySelectorAll('[data-secret-toggle]').forEach((btn) => {
            btn.setAttribute('aria-pressed', 'false');
            btn.setAttribute('aria-label', '显示查询密码');
            btn.textContent = '显示';
        });
        if (window.turnstile && typeof window.turnstile.reset === 'function') {
            document.querySelectorAll('.cf-turnstile').forEach((node) => {
                try { window.turnstile.reset(node); } catch {}
            });
        }
        refreshCheckoutIntents();
    });

    // Masked secret input visibility toggle (no-autofill password inputs)
    document.addEventListener('click', (e) => {
        const toggle = e.target.closest?.('[data-secret-toggle]');
        if (!toggle) return;
        const target = document.getElementById(toggle.getAttribute('data-secret-toggle') || '');
        if (!target) return;
        const unmask = target.classList.contains('is-secret-masked');
        target.classList.toggle('is-secret-masked', !unmask);
        toggle.setAttribute('aria-pressed', unmask ? 'true' : 'false');
        toggle.setAttribute('aria-label', unmask ? '隐藏查询密码' : '显示查询密码');
        toggle.textContent = unmask ? '隐藏' : '显示';
    });

    // 9. Sticky topbar auto-hide fallback when CSS scroll-state queries aren't supported
    const topBar = document.querySelectorAll('.fb-topbar')[0];
    if (topBar && (!window.CSS || !window.CSS.supports('container-type', 'scroll-state'))) {
        let lastScrollY = window.scrollY;
        window.addEventListener('scroll', () => {
            const maxScroll = Math.max(0, document.documentElement.scrollHeight - window.innerHeight);
            const y = Math.max(0, Math.min(window.scrollY, maxScroll));
            if (y === lastScrollY) return;
            topBar.classList.toggle('is-hidden', y > lastScrollY && y > topBar.offsetHeight);
            lastScrollY = y;
        }, { passive: true });
    }

    // 10. Announcement notice / dialog
    const annModal = document.getElementById('announcement-modal');
    if (annModal) {
        const annStore = 'cardshop_announcement_seen';
        const annSignature = annModal.getAttribute('data-signature') || '';
        const isNonModal = annModal.getAttribute('data-non-modal') === '1';
        let annHours = parseInt(annModal.getAttribute('data-hours'), 10);
        let annSeconds = parseInt(annModal.getAttribute('data-countdown'), 10);

        if (Number.isNaN(annHours)) annHours = 24;
        if (Number.isNaN(annSeconds) || annSeconds < 0) annSeconds = isNonModal ? 0 : 5;

        const annRead = () => {
            try { return JSON.parse(window.localStorage.getItem(annStore) || 'null'); } catch { return null; }
        };
        const annWrite = () => {
            try {
                window.localStorage.setItem(annStore, JSON.stringify({ signature: annSignature, seenAt: Date.now() }));
            } catch {}
        };
        const annSuppressed = () => {
            if (annHours === 0) return false;
            const seen = annRead();
            return !!seen && seen.signature === annSignature && (Date.now() - seen.seenAt) < annHours * 3600 * 1000;
        };

        if (!annSuppressed()) {
            const annCloseBtn = document.getElementById('ann-modal-close');
            if (isNonModal) {
                const dismissNotice = () => {
                    annModal.hidden = true;
                    annWrite();
                };
                annModal.hidden = false;
                annModal.querySelectorAll('[data-ann-dismiss]').forEach((el) => el.addEventListener('click', dismissNotice));
                document.addEventListener('keydown', (e) => {
                    if (!annModal.hidden && e.key === 'Escape') dismissNotice();
                });
            } else {
                const annCounter = document.getElementById('ann-modal-countdown');
                let annRemaining = annSeconds;
                let annTimer = null;
                const annPreviousOverflow = document.body.style.overflow;
                const annBox = annModal.querySelector('.ann-modal-box');
                const annOpener = document.activeElement;

                const annFocusable = () =>
                    Array.from(annBox.querySelectorAll('a[href], button, input, textarea, select, [tabindex]'))
                        .filter((el) => !el.disabled && el.tabIndex >= 0 && el.getClientRects().length > 0);

                const annFinish = () => {
                    if (annTimer) { clearInterval(annTimer); annTimer = null; }
                    annCloseBtn.disabled = false;
                    annCloseBtn.setAttribute('aria-disabled', 'false');
                    if (annCounter) annCounter.textContent = '';
                    annCloseBtn.focus();
                };

                const annClose = () => {
                    if (annCloseBtn.disabled) return;
                    annModal.hidden = true;
                    document.body.style.overflow = annPreviousOverflow;
                    annWrite();
                    if (annOpener && typeof annOpener.focus === 'function') annOpener.focus();
                };

                annWrite();
                annModal.hidden = false;
                document.body.style.overflow = 'hidden';
                annBox.focus();

                if (annRemaining > 0 && annCounter) {
                    annCounter.textContent = '(' + annRemaining + ')';
                    annTimer = setInterval(() => {
                        annRemaining -= 1;
                        if (annRemaining <= 0) annFinish();
                        else annCounter.textContent = '(' + annRemaining + ')';
                    }, 1000);
                } else {
                    annFinish();
                }

                annModal.querySelectorAll('[data-ann-dismiss]').forEach((el) => el.addEventListener('click', annClose));

                document.addEventListener('keydown', (e) => {
                    if (annModal.hidden) return;
                    if (e.key === 'Escape') { annClose(); return; }
                    if (e.key !== 'Tab') return;

                    const items = annFocusable();
                    if (!items.length) {
                        e.preventDefault();
                        annBox.focus();
                        return;
                    }
                    const first = items[0];
                    const last = items[items.length - 1];
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
    }

    // 11. Native <dialog> progressive-enhancement fallbacks (Invoker Commands + closedby="any")
    if (typeof HTMLButtonElement === 'undefined' || !('commandForElement' in HTMLButtonElement.prototype)) {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest?.('button[commandfor][command]');
            if (!btn || btn.disabled) return;
            const targetId = btn.getAttribute('commandfor');
            const command = btn.getAttribute('command');
            if (!targetId || !command) return;
            const target = document.getElementById(targetId);
            if (!target || target.tagName !== 'DIALOG') return;
            if (command === 'show-modal' && !target.open && typeof target.showModal === 'function') {
                target.showModal();
            } else if (command === 'close' && target.open && typeof target.close === 'function') {
                target.close();
            }
        });
    }

    if (typeof HTMLDialogElement === 'undefined' || !('closedBy' in HTMLDialogElement.prototype)) {
        const isOutsideDialog = (e, dialog) => {
            const r = dialog.getBoundingClientRect();
            return e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom;
        };
        document.querySelectorAll('dialog[closedby="any"]').forEach((dialog) => {
            let pressedOutside = false;
            dialog.addEventListener('pointerdown', (e) => {
                pressedOutside = isOutsideDialog(e, dialog);
            });
            dialog.addEventListener('pointerup', (e) => {
                if (pressedOutside && isOutsideDialog(e, dialog) && dialog.open) {
                    dialog.close();
                }
                pressedOutside = false;
            });
        });
    }
});
