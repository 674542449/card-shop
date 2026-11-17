@php
    $drawerPayMethods = \App\Support\PaymentMethods::availableForCheckout();
@endphp
<div class="ws-drawer" id="ws-quick-drawer" hidden>
    <div class="ws-drawer-backdrop" data-ws-drawer-close></div>
    <aside class="ws-drawer-panel" role="dialog" aria-modal="true" aria-labelledby="qd-drawer-heading">
        <div class="ws-drawer-grabber" aria-hidden="true"></div>
        <div class="ws-drawer-head">
            <strong id="qd-drawer-heading">快捷下单</strong>
            <button type="button" class="ws-icon-btn" data-ws-drawer-close aria-label="关闭下单面板">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    <path d="M18 6L6 18M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <div class="ws-drawer-scroll">
            <div class="ws-qd-product">
                <div class="ws-qd-product-top">
                    <div class="ws-qd-thumb" id="qd-thumb"></div>
                    <div class="ws-qd-meta">
                        <div class="ws-item-badges">
                            <span class="ws-pill ws-pill-neutral" id="qd-category">商品分类</span>
                            <span class="ws-pill ws-pill-live" id="qd-delivery">自动发货</span>
                            <span class="ws-pill ws-pill-neutral" id="qd-stock">有货</span>
                        </div>
                        <h3 id="qd-title">商品名称</h3>
                    </div>
                </div>
                <p class="ws-qd-summary" id="qd-summary"></p>
                <a href="/" class="ws-qd-link" id="qd-detail-link">查看完整商品说明 &rarr;</a>
            </div>

            <div id="qd-tiers-wrap" hidden>
                <div class="ws-label" style="margin-bottom:6px;">
                    <span>阶梯批发单价</span>
                    <small>选择档位自动填入数量</small>
                </div>
                <div class="ws-tier-chips" id="qd-tiers-list"></div>
            </div>

            <form action="/order/create" method="POST" class="ws-form" id="qd-form" data-guard>
                @csrf
                <input type="hidden" name="product_id" id="qd-product-id" value="">
                <input type="hidden" name="checkout_key" id="qd-checkout-key" value="">
                <div class="ws-hp-field" aria-hidden="true">
                    <label for="qd-hp-website">请勿填写此项</label>
                    <input type="text" id="qd-hp-website" name="website" value="" tabindex="-1" autocomplete="off">
                </div>

                <div class="ws-field">
                    <label class="ws-label" for="qd-qty">
                        <span>购买数量</span>
                        <small><span id="qd-qty-hint">限购 1–1 件</span> &middot; 单价 <strong id="qd-unit-price" data-quote-unit>&yen;0.00</strong></small>
                    </label>
                    <div class="ws-stepper">
                        <button type="button" id="qd-qty-minus" aria-label="减少数量">&minus;</button>
                        <input type="number" id="qd-qty" name="quantity" value="1" min="1" max="1" step="1" inputmode="numeric" required>
                        <button type="button" id="qd-qty-plus" aria-label="增加数量">+</button>
                    </div>
                </div>

                <div class="ws-field">
                    <label class="ws-label" for="qd-email">
                        <span>接收邮箱</span>
                        <small>用于接收卡密通知及查询订单</small>
                    </label>
                    <input type="email" id="qd-email" name="email" class="ws-input"
                           placeholder="name@example.com" value="{{ old('email') }}" autocomplete="email" required>
                </div>

                <div class="ws-field">
                    <label class="ws-label" for="qd-password">
                        <span>查询密码</span>
                        <small>6–50 位字符，用于订单查询验证</small>
                    </label>
                    <input type="password" id="qd-password" name="query_password" class="ws-input"
                           placeholder="请设置 6–50 位查询密码" minlength="6" maxlength="50" autocomplete="new-password" required>
                </div>

                <div class="ws-field">
                    <label class="ws-label" for="qd-coupon">
                        <span>优惠码</span>
                        <small>选填</small>
                    </label>
                    <div class="ws-coupon-row">
                        <input type="text" id="qd-coupon" name="coupon_code" class="ws-input"
                               placeholder="请输入优惠码（选填）" maxlength="50" autocomplete="off">
                        <button type="button" class="ws-btn ws-btn-secondary" id="qd-quote-btn" data-checkout-quote>试算优惠</button>
                    </div>
                </div>
                <p class="ws-quote-note" id="qd-quote-msg" data-quote-status data-default-note="试算仅用于预览折扣金额，不占用商品库存与优惠码使用次数。" role="status" aria-live="polite">试算仅用于预览折扣金额，不占用商品库存与优惠码使用次数。</p>

                <div class="ws-field">
                    <span class="ws-label"><span>支付方式</span></span>
                    <div class="ws-pay-grid">
                        @foreach($drawerPayMethods as $method => $label)
                        <label class="ws-pay-tile">
                            <input type="radio" name="payment_method" value="{{ $method }}" {{ $loop->first ? 'checked' : '' }} required>
                            <span class="ws-pay-tile-body">
                                <span class="ws-pay-icon">@themeInclude('partials.pay-icon', ['method' => $method])</span>
                                <span>{{ $label }}</span>
                            </span>
                        </label>
                        @endforeach
                    </div>
                </div>

                @if(setting('turnstile_site_key'))
                <div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}"></div>
                @endif

                <div class="ws-checkout-bar ws-drawer-sticky-bar">
                    <div class="meta">
                        <span id="qd-total-label" data-checkout-total-label data-default-label="预计应付">预计应付</span>
                        <strong id="qd-total-price" data-quote-total>&yen;0.00</strong>
                    </div>
                    <button type="submit" class="ws-btn ws-btn-primary ws-btn-lg">提交订单 &rarr;</button>
                </div>
            </form>
        </div>
    </aside>
</div>
