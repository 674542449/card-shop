@extends('layouts.front')

@section('title', '订单查询 - ' . setting('site_name', 'CardShop'))

@section('content')
<div class="container py-4">
    <div class="query-form-card">
        <h2><i class="bi bi-search me-2"></i>订单查询</h2>

        <form action="/order/query" method="POST" data-guard>
            @csrf

            <div class="mb-3">
                <label for="email" class="form-label">邮箱地址</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror"
                       id="email" name="email" value="{{ old('email') }}"
                       required placeholder="请输入购买时使用的邮箱">
                @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="query_password" class="form-label">查询密码</label>
                <input type="password" class="form-control @error('query_password') is-invalid @enderror"
                       id="query_password" name="query_password"
                       required placeholder="请输入购买时设置的查询密码">
                @error('query_password')
                <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            {{-- Turnstile --}}
            @if(setting('turnstile_site_key'))
            <div class="mb-3">
                <div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}"></div>
            </div>
            @endif

            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">
                <i class="bi bi-search me-1"></i> 查询订单
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="/" class="text-secondary small">
                <i class="bi bi-arrow-left me-1"></i>返回首页
            </a>
        </div>
    </div>
</div>
@endsection
