@extends(theme_view_path('layout'))

@section('title', '订单查询 - ' . setting('site_name', 'CardShop'))

@section('content')
<div class="oq-wrap">
    <section class="panel">
        <div class="panel-head">订单查询</div>
        <div class="panel-body">
            <p class="oq-intro">用下单时填写的邮箱和查询密码，取回订单和卡密。</p>

            {{-- 通用错误（邮箱或密码错、尝试次数过多）走 errors->first('error')，
                 它不属于任何一个字段，所以放在表单顶部而不是某个输入框下面。 --}}
            @if($errors->has('error'))
            <div class="alert alert-danger oq-alert" role="alert">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/><path d="M12 8v4.5"/><path d="M12 16h.01"/>
                </svg>
                <span>{{ $errors->first('error') }}</span>
            </div>
            @endif

            <form action="/order/query" method="POST" data-guard>
                @csrf

                <div class="field">
                    <label class="field-label" for="oq-email">邮箱<span class="req">*</span></label>
                    <input type="email" id="oq-email" name="email"
                           class="input @error('email') is-invalid @enderror"
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

                <div class="field">
                    <label class="field-label" for="oq-pass">查询密码<span class="req">*</span></label>
                    <input type="password" id="oq-pass" name="query_password"
                           class="input @error('query_password') is-invalid @enderror"
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
                <div class="field">
                    <div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}"></div>
                </div>
                @endif

                <button type="submit" class="btn btn-lg btn-block">查询订单</button>
            </form>
        </div>
    </section>

    <p class="oq-foot"><a href="/">返回首页</a></p>
</div>
@endsection
