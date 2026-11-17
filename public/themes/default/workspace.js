(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        /* ------------------------------------------------------------------
           1. Keyboard Shortcut (Ctrl/Cmd+K) -> Focus Catalog Search Input
           ------------------------------------------------------------------ */
        const globalSearch = document.getElementById('global-product-search') || document.getElementById('category-product-search');
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k' && globalSearch) {
                e.preventDefault();
                globalSearch.focus();
                globalSearch.select();
            }
        });

        /* ------------------------------------------------------------------
           2. Marketplace View Switcher (Grid vs List) & In-Stock Toggle
           ------------------------------------------------------------------ */
        const catalogStage = document.getElementById('ws-catalog-stage');
        const viewButtons = Array.from(document.querySelectorAll('[data-ws-view]'));

        function setViewMode(mode, persist) {
            if (!catalogStage) return;
            const valid = mode === 'list' ? 'list' : 'grid';
            catalogStage.setAttribute('data-view-mode', valid);
            viewButtons.forEach((btn) => {
                const active = btn.getAttribute('data-ws-view') === valid;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-pressed', String(active));
            });
            if (persist) {
                try { localStorage.setItem('ws-view-mode', valid); } catch {}
            }
        }

        if (catalogStage && viewButtons.length) {
            let savedMode = 'grid';
            try { savedMode = localStorage.getItem('ws-view-mode') || 'grid'; } catch {}
            setViewMode(savedMode, false);
            viewButtons.forEach((btn) => {
                btn.addEventListener('click', () => setViewMode(btn.getAttribute('data-ws-view'), true));
            });
        }

        const items = Array.from(document.querySelectorAll('[data-ws-item]'));
        const stockToggle = document.getElementById('ws-filter-instock');
        const emptyFilter = document.getElementById('ws-filter-empty');
        const resetFilterBtn = document.getElementById('ws-filter-reset');
        const visibleCountNode = document.getElementById('ws-visible-count');
        let onlyInStock = false;

        function applyStockFilter() {
            if (!items.length) return;
            let shown = 0;
            items.forEach((el) => {
                const visible = !onlyInStock || el.getAttribute('data-buyable') === '1';
                el.hidden = !visible;
                if (visible) shown++;
            });
            if (emptyFilter) emptyFilter.hidden = shown > 0;
            if (visibleCountNode) visibleCountNode.textContent = String(shown);
        }

        if (stockToggle) {
            stockToggle.addEventListener('click', () => {
                onlyInStock = !onlyInStock;
                stockToggle.classList.toggle('is-active', onlyInStock);
                stockToggle.setAttribute('aria-pressed', String(onlyInStock));
                applyStockFilter();
            });
        }

        if (resetFilterBtn) {
            resetFilterBtn.addEventListener('click', () => {
                onlyInStock = false;
                if (stockToggle) {
                    stockToggle.classList.remove('is-active');
                    stockToggle.setAttribute('aria-pressed', 'false');
                }
                applyStockFilter();
            });
        }

        /* ------------------------------------------------------------------
           3. Product Detail Mobile Sticky Buy-Bar
           ------------------------------------------------------------------ */
        const mobileBuyBar = document.getElementById('ws-mobile-buybar');
        const checkoutDock = document.getElementById('ws-checkout-dock');
        if (mobileBuyBar && checkoutDock) {
            document.querySelectorAll('[data-ws-scroll-buy]').forEach((btn) => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    checkoutDock.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    const firstInput = checkoutDock.querySelector('input[name="email"]');
                    if (firstInput) {
                        setTimeout(() => firstInput.focus({ preventScroll: true }), 360);
                    }
                });
            });
            if ('IntersectionObserver' in window) {
                const dockObserver = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        mobileBuyBar.classList.toggle('is-dock-visible', entry.isIntersecting);
                    });
                }, { threshold: 0.15 });
                dockObserver.observe(checkoutDock);
            }
        }

        /* ------------------------------------------------------------------
           4. Quick-Buy Modal / Mobile Bottom Sheet (#ws-quick-drawer)
              (Coupon quote calculation is shared via public/js/checkout.js)
           ------------------------------------------------------------------ */
        const drawer = document.getElementById('ws-quick-drawer');
        if (!drawer) return;

        const drTitle = document.getElementById('qd-title');
        const drCat = document.getElementById('qd-category');
        const drDelivery = document.getElementById('qd-delivery');
        const drStock = document.getElementById('qd-stock');
        const drSummary = document.getElementById('qd-summary');
        const drDetailLink = document.getElementById('qd-detail-link');
        const drThumbBox = document.getElementById('qd-thumb');
        const drTiersWrap = document.getElementById('qd-tiers-wrap');
        const drTiersList = document.getElementById('qd-tiers-list');

        const drForm = document.getElementById('qd-form');
        const drProductId = document.getElementById('qd-product-id');
        const drCheckoutKey = document.getElementById('qd-checkout-key');
        const drQty = document.getElementById('qd-qty');
        const drQtyMinus = document.getElementById('qd-qty-minus');
        const drQtyPlus = document.getElementById('qd-qty-plus');
        const drQtyHint = document.getElementById('qd-qty-hint');
        const drCoupon = document.getElementById('qd-coupon');
        const drUnitPrice = document.getElementById('qd-unit-price');
        const drTotalPrice = document.getElementById('qd-total-price');

        let activePayload = null;
        let lastTrigger = null;
        const consumedIntentKeys = new Set();

        if (drForm) {
            drForm.addEventListener('submit', (e) => {
                if (!e.defaultPrevented && drCheckoutKey?.value) {
                    consumedIntentKeys.add(drCheckoutKey.value);
                }
            });
        }

        function computeDrawerUnitPrice(qty) {
            if (!activePayload) return 0;
            let unit = Number(activePayload.price);
            const tiers = Array.isArray(activePayload.tiers) ? activePayload.tiers : [];
            tiers.forEach((t) => {
                if (qty >= Number(t.min_quantity)) unit = Number(t.price);
            });
            return unit;
        }

        function refreshDrawerTotals(resetQuote) {
            if (!activePayload || !drQty) return;
            const minQ = Number(activePayload.minQty) || 1;
            const maxQ = Number(activePayload.maxQty) || 1;
            let q = parseInt(drQty.value, 10);
            if (Number.isNaN(q) || q < minQ) q = minQ;
            if (q > maxQ) q = maxQ;

            if (drQtyMinus) drQtyMinus.disabled = q <= minQ;
            if (drQtyPlus) drQtyPlus.disabled = q >= maxQ;

            const unit = computeDrawerUnitPrice(q);
            const total = unit * q;
            if (drUnitPrice) drUnitPrice.textContent = '¥' + unit.toFixed(2);
            if (drTotalPrice) drTotalPrice.textContent = '¥' + total.toFixed(2);

            if (drTiersList) {
                let bestMin = 0;
                Array.from(drTiersList.querySelectorAll('[data-tier-min]')).forEach((chip) => {
                    const m = Number(chip.getAttribute('data-tier-min'));
                    if (q >= m && m >= bestMin) bestMin = m;
                });
                Array.from(drTiersList.querySelectorAll('[data-tier-min]')).forEach((chip) => {
                    chip.classList.toggle('is-active', Number(chip.getAttribute('data-tier-min')) === bestMin && bestMin > 0);
                });
            }

            if (resetQuote && drForm) {
                drForm.dispatchEvent(new CustomEvent('checkout:reset'));
            }
        }

        function openDrawer(payload, triggerEl) {
            activePayload = payload;
            lastTrigger = triggerEl || null;

            drTitle.textContent = payload.name || '';
            drCat.textContent = payload.categoryName || '默认分类';
            drDelivery.textContent = '自动发货';
            drDelivery.className = 'ws-pill ws-pill-live';
            drStock.textContent = payload.isLowStock
                ? ('库存紧张 · 余 ' + payload.stock + ' 件')
                : ('库存 ' + payload.stock + ' 件');
            drStock.className = 'ws-pill ' + (payload.isLowStock ? 'ws-pill-warn' : 'ws-pill-neutral');
            drSummary.textContent = payload.summary || '支付核验完成后系统自动分配卡密凭据，支持在线复制与文件导出。';
            drDetailLink.setAttribute('href', payload.url || '#');

            drThumbBox.replaceChildren();
            if (payload.image) {
                const img = document.createElement('img');
                img.src = payload.image;
                img.alt = payload.name || '';
                drThumbBox.appendChild(img);
            } else {
                const mark = document.createElement('span');
                mark.className = 'ws-qd-thumb-fallback';
                mark.textContent = (payload.name || 'C').slice(0, 1);
                drThumbBox.appendChild(mark);
            }

            const minQ = Math.max(1, Number(payload.minQty) || 1);
            const maxQ = Math.max(minQ, Number(payload.maxQty) || 1);
            drProductId.value = String(payload.id);
            drCheckoutKey.value = String(payload.checkoutKey || '');
            const hpInput = drForm?.querySelector('input[name="website"]');
            if (hpInput) hpInput.value = '';
            const staleTsErr = drForm?.querySelector('.ws-turnstile-runtime-error');
            if (staleTsErr) staleTsErr.remove();

            if (!drCheckoutKey.value || consumedIntentKeys.has(drCheckoutKey.value)) {
                const csrf = drForm?.querySelector('input[name="_token"]')?.value;
                if (csrf && payload.id) {
                    fetch('/order/intent', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({ product_ids: [Number(payload.id)] }),
                    })
                        .then((r) => (r.ok ? r.json() : null))
                        .then((body) => {
                            const freshKey = body?.keys?.[String(payload.id)];
                            if (freshKey && activePayload?.id === payload.id) {
                                drCheckoutKey.value = freshKey;
                                payload.checkoutKey = freshKey;
                            }
                        })
                        .catch(() => {});
                }
            }
            drQty.min = String(minQ);
            drQty.max = String(maxQ);
            drQty.value = String(minQ);
            if (drQtyHint) drQtyHint.textContent = '限购 ' + minQ + '–' + maxQ + ' 件';
            if (drCoupon) drCoupon.value = '';

            drTiersList.replaceChildren();
            const tiers = Array.isArray(payload.tiers) ? payload.tiers : [];
            if (tiers.length > 0) {
                drTiersWrap.hidden = false;
                tiers.forEach((t) => {
                    const tierMin = Number(t.min_quantity);
                    const reachable = tierMin <= maxQ;
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'ws-tier-chip';
                    btn.setAttribute('data-tier-min', String(tierMin));
                    btn.textContent = '满 ' + tierMin + ' 件 · ¥' + Number(t.price).toFixed(2) + '/件';
                    if (!reachable) {
                        btn.disabled = true;
                        btn.title = '超出当前可购上限（' + maxQ + ' 件）';
                    } else {
                        btn.addEventListener('click', () => {
                            drQty.value = String(Math.max(minQ, Math.min(maxQ, tierMin)));
                            refreshDrawerTotals(true);
                        });
                    }
                    drTiersList.appendChild(btn);
                });
            } else {
                drTiersWrap.hidden = true;
            }

            refreshDrawerTotals(true);

            drawer.hidden = false;
            requestAnimationFrame(() => {
                drawer.classList.add('is-open');
                const tsNode = drForm?.querySelector('.cf-turnstile');
                if (tsNode && window.turnstile && typeof window.turnstile.reset === 'function') {
                    try { window.turnstile.reset(tsNode); } catch {}
                }
            });
            document.body.classList.add('ws-drawer-lock');

            if (window.matchMedia?.('(min-width: 901px) and (pointer: fine)').matches) {
                const emailInput = drForm?.querySelector('input[name="email"]');
                if (emailInput) setTimeout(() => emailInput.focus(), 80);
            }
        }

        function closeDrawer() {
            if (drawer.hidden) return;
            drawer.classList.remove('is-open');
            document.body.classList.remove('ws-drawer-lock');
            setTimeout(() => {
                drawer.hidden = true;
                if (lastTrigger && typeof lastTrigger.focus === 'function') lastTrigger.focus();
            }, 200);
        }

        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-ws-quickbuy]');
            if (trigger) {
                const card = trigger.closest('[data-product-payload]');
                if (!card) return;
                try {
                    const data = JSON.parse(card.getAttribute('data-product-payload'));
                    e.preventDefault();
                    openDrawer(data, trigger);
                } catch {}
                return;
            }
            if (e.target.closest('[data-ws-drawer-close]')) {
                e.preventDefault();
                closeDrawer();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !drawer.hidden) closeDrawer();
        });

        if (drQtyMinus) {
            drQtyMinus.addEventListener('click', () => {
                const minQ = Number(drQty.min) || 1;
                const cur = parseInt(drQty.value, 10) || minQ;
                drQty.value = String(Math.max(minQ, cur - 1));
                refreshDrawerTotals(true);
            });
        }
        if (drQtyPlus) {
            drQtyPlus.addEventListener('click', () => {
                const maxQ = Number(drQty.max) || 1;
                const cur = parseInt(drQty.value, 10) || 1;
                drQty.value = String(Math.min(maxQ, cur + 1));
                refreshDrawerTotals(true);
            });
        }
        if (drQty) {
            drQty.addEventListener('input', () => refreshDrawerTotals(true));
        }
    });
})();
