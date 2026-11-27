@extends(theme_view_path('layout'))

@section('title', '订单查询 - ' . setting('site_name', 'CardShop'))

@section('content')
<div class="m-query-shell">
    <header class="m-query-header">
        <span class="m-eyebrow">订单查询与卡密提取</span>
        <h1 class="m-page-heading">查询你的订单</h1>
        <p class="m-store-strip-desc">输入下单时填写的邮箱与查询密码，查看进度、继续付款或提取卡密。</p>
    </header>

    <section class="m-panel m-query-card" aria-labelledby="m-query-form-heading">
        <h2 class="m-sr-only" id="m-query-form-heading">订单验证表单</h2>
        <form action="/order/query" method="POST" class="m-form" autocomplete="off" data-guard>
            @csrf
            <div class="m-sr-only" aria-hidden="true"><label for="m-oq-hp">请勿填写</label><input type="text" id="m-oq-hp" name="website" value="" tabindex="-1" autocomplete="off"></div>

            <div class="m-field">
                <label class="m-label" for="oq-email">下单邮箱</label>
                <input type="email" id="oq-email" name="email" class="m-input @error('email') is-invalid @enderror" value="{{ old('email') }}" required autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" placeholder="购买时使用的邮箱">
                @error('email')<p class="m-field-error" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="m-field">
                <label class="m-label" for="oq-pass">查询密码</label>
                <div class="m-secret-wrap">
                    <input type="text" id="oq-pass" name="query_password" class="m-input is-secret-masked @error('query_password') is-invalid @enderror" maxlength="50" required autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" data-clear-on-restore placeholder="购买时设置的查询密码" aria-describedby="m-query-password-hint">
                    <button type="button" class="m-secret-toggle" data-secret-toggle="oq-pass" aria-pressed="false" aria-label="显示查询密码">显示</button>
                </div>
                @error('query_password')<p class="m-field-error" role="alert">{{ $message }}</p>@enderror
                <p class="m-hint" id="m-query-password-hint">下单时自行设置的查询密码。</p>
            </div>

            <div class="m-field">
                <label class="m-label" for="lookup-order-no">订单编号 <span class="m-muted">· 选填，精确检索指定订单</span></label>
                <input type="text" class="m-input" id="lookup-order-no" name="order_no" value="{{ old('order_no', $lookupOrderNo ?? '') }}" maxlength="30" placeholder="留空则查询历史订单" autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
            </div>

            @if(turnstile_site_key())
            <div class="m-field">
                <div class="cf-turnstile" data-sitekey="{{ turnstile_site_key() }}" data-size="compact"></div>
                @error('turnstile')<p class="m-field-error" role="alert">{{ $message }}</p>@enderror
            </div>
            @endif

            <button type="submit" class="m-button m-button-primary m-button-wide">验证并查询订单</button>
        </form>
        <p class="m-query-privacy">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            订单与卡密仅在校验邮箱和密码一致后展示。
        </p>
    </section>

    <div class="m-query-foot">
        <a href="/" class="m-text-link"><span aria-hidden="true">←</span> 返回商品目录</a>
        @if(\App\Support\SafeUrl::contact(setting('contact_url')))
        <a href="{{ \App\Support\SafeUrl::contact(setting('contact_url')) }}" class="m-order-text-link" target="_blank" rel="noopener">需要帮助？ ↗</a>
        @endif
    </div>
</div>
@endsection
