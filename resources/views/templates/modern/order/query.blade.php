@extends(theme_view_path('layout'))

@section('title', '订单查询 - ' . setting('site_name', 'CardShop'))

@section('content')
<nav class="m-breadcrumb" aria-label="当前位置"><a href="/">首页</a><span aria-hidden="true">/</span><span>订单查询</span></nav>
<div class="m-query-layout">
    <section class="m-query-intro">
        <p class="m-eyebrow">YOUR ORDERS · 订单查询</p>
        <h1 class="m-page-heading">每一份购买，<br>都能在这里找回。</h1>
        <p class="m-lead">使用下单时填写的邮箱与查询密码，查看订单进度、继续付款，或取回已经购买的卡密。</p>
        <ol class="m-query-steps">
            <li><span>01</span><div><h2>找到你的订单</h2><p>填写购买时使用的邮箱和自己设置的查询密码。</p></div></li>
            <li><span>02</span><div><h2>查看支付进度</h2><p>未完成付款的订单，可在有效期内继续支付。</p></div></li>
            <li><span>03</span><div><h2>保存卡密内容</h2><p>已支付订单支持再次查看、复制和下载 TXT。</p></div></li>
        </ol>
        <a href="/" class="m-query-back">继续浏览商品 <span aria-hidden="true">↗</span></a>
    </section>
    <section class="m-panel m-query-card" aria-labelledby="m-query-form-heading">
        <div class="m-query-card-heading">
            <svg width="32" height="32" viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M8 5h16v22l-8-4-8 4V5Z"/><path d="M12 11h8m-8 5h5"/></svg>
            <h2 class="m-section-title" id="m-query-form-heading">查询订单</h2>
            <p class="m-muted">两项信息，找回属于你的购买记录。</p>
        </div>
        <form action="/order/query" method="POST" class="m-form" data-guard>
            @csrf
            <div style="margin:12px 0"><label for="lookup-order-no">订单号（选填，可精确找回旧订单）</label><input class="m-input" id="lookup-order-no" name="order_no" value="{{ old('order_no', $lookupOrderNo ?? '') }}" maxlength="30" placeholder="留空查询历史订单"></div>
            <div class="m-field">
                <label class="m-label" for="oq-email">下单邮箱</label>
                <input type="email" id="oq-email" name="email" class="m-input @error('email') is-invalid @enderror" value="{{ old('email') }}" required autocomplete="email" placeholder="购买时使用的邮箱">
                @error('email')<p class="m-field-error">{{ $message }}</p>@enderror
            </div>
            <div class="m-field">
                <label class="m-label" for="oq-pass">查询密码</label>
                <input type="password" id="oq-pass" name="query_password" class="m-input @error('query_password') is-invalid @enderror" required autocomplete="current-password" placeholder="购买时设置的查询密码" aria-describedby="m-query-password-hint">
                @error('query_password')<p class="m-field-error">{{ $message }}</p>@enderror
                <p class="m-hint" id="m-query-password-hint">这是下单时你自己设置的查询密码。</p>
            </div>
            @if(setting('turnstile_site_key'))
            <div class="m-field"><div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}" data-size="compact"></div></div>
            @error('turnstile')<p class="m-field-error">{{ $message }}</p>@enderror
            @endif
            <button type="submit" class="m-button m-button-primary m-button-wide">查询订单</button>
        </form>
        <p class="m-query-privacy"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg> 订单与卡密仅在验证信息后展示。</p>
    </section>
</div>
@endsection
