@extends(theme_view_path('layout'))

@section('title', '订单查询 - ' . setting('site_name', 'CardShop'))

@section('content')
    <div class="page-card" style="margin-top:30px">
        <div class="page-card-header">订单查询</div>
        <div class="page-card-body">
            <form action="/order/query" method="POST" data-guard>
                @csrf
            <div style="margin:12px 0"><label for="lookup-order-no">订单号（选填，可精确找回旧订单）</label><input class="form-input" id="lookup-order-no" name="order_no" value="{{ old('order_no', $lookupOrderNo ?? '') }}" maxlength="30" placeholder="留空查询历史订单"></div>

                <div class="pd-form">
                    <div class="form-group">
                        <label class="form-label" for="email">邮箱</label>
                        <input type="email" name="email" id="email" class="form-input @error('email') is-invalid @enderror"
                               value="{{ old('email') }}" maxlength="200" autocomplete="email" required placeholder="购买时使用的邮箱">
                    </div>
                    @error('email')
                    <div class="form-error">{{ $message }}</div>
                    @enderror

                    <div class="form-group">
                        <label class="form-label" for="query_password">查询密码</label>
                        <input type="password" name="query_password" id="query_password" class="form-input @error('query_password') is-invalid @enderror"
                               maxlength="50" autocomplete="current-password" required placeholder="购买时设置的查询密码">
                    </div>
                    @error('query_password')
                    <div class="form-error">{{ $message }}</div>
                    @enderror

                    {{-- Same .form-turnstile wrapper as the buy form on the product page:
                         the indent to the label column is a stylesheet rule, not two
                         hand-tuned inline margins that can drift apart. --}}
                    @if(setting('turnstile_site_key'))
                    <div class="form-turnstile">
                        <div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}"></div>
                    </div>
                    @endif
                </div>

                <button type="submit" class="btn-submit" style="margin-top:10px">查询订单</button>
            </form>

            <div class="text-center mt-2">
                <a href="/" style="font-size:13px;color:var(--text-light);">&larr; 返回首页</a>
            </div>
        </div>
    </div>
@endsection
