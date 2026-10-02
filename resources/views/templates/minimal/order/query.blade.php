@extends(theme_view_path('layout'))
@section('title', '订单查询 - ' . setting('site_name', 'CardShop'))
@section('content')
<section class="n-query-wrap">
    <header class="n-query-title"><span class="n-kicker">ORDER LOOKUP</span><h1>找回你的订单</h1><p>查看进度，继续付款，领取已经购买的卡密。</p></header>
    <div class="n-query-flow" aria-label="订单查询流程"><span><b>1</b> 验证信息</span><i aria-hidden="true">→</i><span><b>2</b> 查看订单</span><i aria-hidden="true">→</i><span><b>3</b> 领取卡密</span></div>
    <div class="n-panel n-query-panel">
        <form action="/order/query" method="POST" class="n-form" data-guard>
            @csrf
            <div style="margin:12px 0"><label for="lookup-order-no">订单号（选填，可精确找回旧订单）</label><input class="n-input" id="lookup-order-no" name="order_no" value="{{ old('order_no', $lookupOrderNo ?? '') }}" maxlength="30" placeholder="留空查询历史订单"></div>
            <div class="n-field"><label for="oq-email">下单邮箱</label><input type="email" name="email" id="oq-email" class="n-input" value="{{ old('email') }}" placeholder="you@example.com" autocomplete="email" required @error('email') aria-invalid="true" @enderror>@error('email')<p class="n-field-error">{{ $message }}</p>@enderror</div>
            <div class="n-field"><label for="oq-pass">查询密码</label><input type="password" name="query_password" id="oq-pass" class="n-input" placeholder="购买时设置的密码" autocomplete="current-password" required aria-describedby="n-query-pass-hint" @error('query_password') aria-invalid="true" @enderror>@error('query_password')<p class="n-field-error">{{ $message }}</p>@enderror<p class="n-hint" id="n-query-pass-hint">使用你下单时自己设置的查询密码。</p></div>
            @if(setting('turnstile_site_key'))<div class="n-field"><div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}" data-size="compact"></div>@error('turnstile')<p class="n-field-error">{{ $message }}</p>@enderror</div>@endif
            <button type="submit" class="n-button n-button-primary n-button-wide">查询订单 <span aria-hidden="true">→</span></button>
        </form>
        <p class="n-query-security"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>验证通过后展示订单与卡密。</p>
    </div>
    <p class="n-query-bottom"><a href="/">← 返回商品目录</a>@if(\App\Support\SafeUrl::contact(setting('contact_url')))<a href="{{ \App\Support\SafeUrl::contact(setting('contact_url')) }}" target="_blank" rel="noopener">需要帮助？ ↗</a>@endif</p>
</section>
@endsection