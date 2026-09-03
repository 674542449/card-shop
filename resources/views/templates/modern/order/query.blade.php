@extends(theme_view_path('layout'))

@section('title', '订单查询 - ' . setting('site_name', 'CardShop'))

@section('content')
{{-- 模板里这一页有「按订单号 / 按邮箱」两个 tab。本站没有按订单号查这条路
     （QueryOrderRequest 要的是 email + query_password 两个字段一起校验），
     所以 tab 切换整块不要——摆出一个后端接不住的查询方式只会让人白填一次。 --}}
<div class="orders-page-wrap">

    {{-- 全页唯一的 h1 是 layout 里的 .seo-h1，这里只能降一级。 --}}
    <div class="section-header">
        <div class="section-title-wrap">
            <h2 class="section-title">订单查询</h2>
            <span class="section-count">邮箱 + 查询密码</span>
        </div>
    </div>

    {{-- 「邮箱或查询密码错误」「尝试次数过多」走 $errors 的 error 键，由 layout 的
         $errors->all() 统一渲染。这里以前又单独画了一遍 $errors->first('error')，
         同一句话在页面上会连着出现两次——删掉这块，让 layout 独占。 --}}

    <div class="query-card">
        <form action="/order/query" method="POST" data-guard>
            @csrf

            <div class="form-group">
                <label class="form-label" for="oq-email">下单邮箱 <span aria-hidden="true">*</span></label>
                <input type="email" id="oq-email" name="email"
                       class="form-input @error('email') is-invalid @enderror"
                       value="{{ old('email') }}" required autocomplete="email"
                       placeholder="购买时使用的邮箱">
                @error('email')
                <p class="field-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"/><path d="M12 8v4.5"/><path d="M12 16h.01"/>
                    </svg>
                    {{ $message }}
                </p>
                @enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="oq-pass">查询密码 <span aria-hidden="true">*</span></label>
                {{-- 故意不回填 value：密码框回填了，浏览器后退回到这一页时屏幕上会留着明文。 --}}
                <input type="password" id="oq-pass" name="query_password"
                       class="form-input @error('query_password') is-invalid @enderror"
                       required autocomplete="current-password"
                       placeholder="购买时设置的查询密码">
                @error('query_password')
                <p class="field-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"/><path d="M12 8v4.5"/><path d="M12 16h.01"/>
                    </svg>
                    {{ $message }}
                </p>
                @enderror
                <p class="field-hint">查询密码是下单时你自己设置的，和邮箱密码无关。</p>
            </div>

            @if(setting('turnstile_site_key'))
            <div class="form-group">
                <div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}"></div>
            </div>
            @error('turnstile')
            <p class="field-error">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/><path d="M12 8v4.5"/><path d="M12 16h.01"/>
                </svg>
                {{ $message }}
            </p>
            @enderror
            @endif

            {{-- 必须是 button[type=submit]：front.js 的防重复提交靠
                 form.querySelector('button[type="submit"]') 找它，换成 input 就找不到了。 --}}
            <button type="submit" class="btn-query">查询订单</button>
        </form>
    </div>

    {{-- 模板首页那条三步流程的结构留着，文案换成本站真实的取件方式。 --}}
    <div class="purchase-steps-flow">
        <div class="steps-flow-title">查询与提卡流程</div>
        <div class="steps-flow-grid">
            <div class="step-flow-item">
                <span class="step-flow-num">1</span>
                <span class="step-flow-text">填下单邮箱和查询密码</span>
            </div>
            <div class="step-flow-item">
                <span class="step-flow-num">2</span>
                <span class="step-flow-text">列出这个邮箱下的全部订单</span>
            </div>
            <div class="step-flow-item">
                <span class="step-flow-num">3</span>
                <span class="step-flow-text">已支付的订单直接看卡密、下载 TXT</span>
            </div>
        </div>
    </div>

    <a href="/" class="btn-buy">返回首页</a>
</div>
@endsection
