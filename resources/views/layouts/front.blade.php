<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', setting('seo_default_title', 'CardShop'))</title>
    <meta name="description" content="@yield('meta_description', setting('seo_default_description', ''))">
    <meta name="keywords" content="@yield('meta_keywords', setting('seo_default_keywords', ''))">

    @hasSection('canonical')
    <link rel="canonical" href="@yield('canonical')">
    @endif

    {{-- Open Graph --}}
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:title" content="@yield('title', setting('seo_default_title', 'CardShop'))">
    <meta property="og:description" content="@yield('meta_description', setting('seo_default_description', ''))">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ setting('site_name', 'CardShop') }}">

    @yield('structured_data')

    {{-- Bootstrap 5.3 CDN --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YcnS/fVhP6QAz09GixkEj+FpSMF9p2+wRvj" crossorigin="anonymous">

    {{-- Bootstrap Icons --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    {{-- Custom CSS --}}
    <link href="{{ asset('css/front.css') }}" rel="stylesheet">

    {{-- Turnstile --}}
    @if(setting('turnstile_site_key'))
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif

    @yield('head')
</head>
<body>

    {{-- Navbar --}}
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand navbar-brand-custom" href="/">
                <i class="bi bi-shop"></i>
                {{ setting('site_name', 'CardShop') }}
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse"
                    data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false"
                    aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('/') ? 'active' : '' }}" href="/">
                            <i class="bi bi-house-door"></i> 首页
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('articles*') ? 'active' : '' }}" href="/articles">
                            <i class="bi bi-journal-text"></i> 文章
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('order/query*') ? 'active' : '' }}" href="/order/query">
                            <i class="bi bi-search"></i> 订单查询
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    {{-- Announcement Bar --}}
    @php $announcement = setting('site_announcement', ''); @endphp
    @if($announcement)
    <div class="announcement-bar" id="announcement-bar">
        <div class="container">
            <i class="bi bi-megaphone-fill me-1"></i>
            {{ $announcement }}
        </div>
        <button type="button" class="btn-close" id="announcement-dismiss" aria-label="关闭"></button>
    </div>
    @endif

    {{-- Main Content --}}
    <main>
        @if($errors->any())
        <div class="container mt-3">
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
            </div>
        </div>
        @endif

        @yield('content')
    </main>

    {{-- Footer --}}
    <footer class="site-footer">
        <div class="container">
            <div class="footer-brand">{{ setting('site_name', 'CardShop') }}</div>
            <div>&copy; {{ date('Y') }} {{ setting('site_name', 'CardShop') }}. All rights reserved.</div>
        </div>
    </footer>

    {{-- Bootstrap 5.3 JS Bundle --}}
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    {{-- Custom JS --}}
    <script src="{{ asset('js/front.js') }}"></script>

    @yield('scripts')
</body>
</html>
