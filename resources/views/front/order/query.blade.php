@extends('layouts.front')

@section('title', '订单查询 - ' . setting('site_name', 'CardShop'))

@section('content')
    <div class="page-card" style="margin-top:30px">
        <div class="page-card-header">订单查询</div>
        <div class="page-card-body">
            <form action="/order/query" method="POST" data-guard>
                @csrf

                <div class="pd-form">
                    <div class="form-group">
                        <label class="form-label">邮箱</label>
                        <input type="email" name="email" class="form-input @error('email') is-invalid @enderror"
                               value="{{ old('email') }}" required placeholder="购买时使用的邮箱">
                    </div>
                    @error('email')
                    <div class="form-error">{{ $message }}</div>
                    @enderror

                    <div class="form-group">
                        <label class="form-label">查询密码</label>
                        <input type="password" name="query_password" class="form-input @error('query_password') is-invalid @enderror"
                               required placeholder="购买时设置的查询密码">
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
