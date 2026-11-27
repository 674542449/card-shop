@extends(theme_view_path('layout'))

@section('title', '订单查询 - ' . setting('site_name', 'CardShop'))

@section('content')
    <nav class="ml-breadcrumb" aria-label="面包屑">
        <ol>
            <li><a href="/">商城首页</a></li>
            <li><a href="/order/query" aria-current="page">订单查询</a></li>
        </ol>
    </nav>

    <div class="ml-query-shell">
        <header class="ml-query-head">
            <span class="ml-badge ml-badge-brand">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                <span>自助查单与提取</span>
            </span>
            <h1>订单查询与卡密提取</h1>
            <p class="ml-hero-desc">输入下单邮箱与查询密码，即可随时查看进度、继续支付或提取卡密。</p>
        </header>

        <section class="ml-card ml-query-card" aria-labelledby="ml-query-form-title">
            <h2 class="ml-sr-only" id="ml-query-form-title">订单身份验证表单</h2>
            <form action="/order/query" method="POST" class="ml-form" autocomplete="off" data-guard>
                @csrf
                <div class="ml-sr-only" aria-hidden="true">
                    <label for="ml-oq-hp">请勿填写</label>
                    <input type="text" id="ml-oq-hp" name="website" value="" tabindex="-1" autocomplete="off">
                </div>

                <div class="ml-field">
                    <label class="ml-label" for="oq-email">下单邮箱</label>
                    <input type="email" id="oq-email" name="email" class="ml-input @error('email') is-invalid @enderror" value="{{ old('email') }}" required autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" placeholder="购买时填写的接收邮箱">
                    @error('email')<p class="ml-field-error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="ml-field">
                    <label class="ml-label" for="oq-pass">查询密码</label>
                    <div class="ml-secret-wrap">
                        <input type="text" id="oq-pass" name="query_password" class="ml-input is-secret-masked @error('query_password') is-invalid @enderror" maxlength="50" required autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" data-clear-on-restore placeholder="购买时设置的查询密码" aria-describedby="ml-query-pass-hint">
                        <button type="button" class="ml-secret-toggle" data-secret-toggle="oq-pass" aria-pressed="false" aria-label="显示查询密码">显示</button>
                    </div>
                    @error('query_password')<p class="ml-field-error" role="alert">{{ $message }}</p>@enderror
                    <p class="ml-hint" id="ml-query-pass-hint">为保障卡密隐私，本页面已禁用浏览器自动填充。</p>
                </div>

                <div class="ml-field">
                    <label class="ml-label" for="lookup-order-no">订单编号 <span class="ml-muted">选填 · 精确检索单笔订单</span></label>
                    <input type="text" id="lookup-order-no" name="order_no" class="ml-input" value="{{ old('order_no', $lookupOrderNo ?? '') }}" maxlength="30" placeholder="留空则查询该邮箱全部匹配订单" autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
                </div>

                @if(turnstile_site_key())
                <div class="ml-field">
                    <div class="cf-turnstile" data-sitekey="{{ turnstile_site_key() }}" data-size="compact"></div>
                    @error('turnstile')<p class="ml-field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                @endif

                <button type="submit" class="ml-btn ml-btn-primary ml-btn-wide ml-btn-lg">验证并查询订单 →</button>
            </form>

            <p class="ml-query-privacy">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                <span>订单记录与卡密仅在校验邮箱和密码完全匹配后展示。</span>
            </p>
        </section>

        <div class="ml-query-foot">
            <a href="/" class="ml-link">← 返回商品目录</a>
            @if(\App\Support\SafeUrl::contact(setting('contact_url')))
            <a href="{{ \App\Support\SafeUrl::contact(setting('contact_url')) }}" class="ml-link" target="_blank" rel="noopener"><span>忘记密码或需要帮助？</span><span aria-hidden="true">↗</span></a>
            @endif
        </div>
    </div>
@endsection
