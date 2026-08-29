<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- yieldContent() inside {{ }} instead of @yield, because @yield prints the
         section RAW. These sections carry product names and SEO text typed by the
         operator; a single " in a name closes the content attribute and everything
         after it becomes markup in <head>. @yield stays below for the sections that
         are meant to be HTML (structured_data, head, content, scripts). --}}
    {{-- The fallback is applied with ?: rather than passed to yieldContent().
         yieldContent() escapes its $default itself (ManagesLayouts::yieldContent, the
         `e($default)` on the first line), so handing it the raw setting and wrapping
         the call in {{ }} escaped it twice — a shop whose SEO description contains
         "A & B" served "A &amp;amp; B" to search engines on every page with no
         section of its own. Defined sections come back unescaped and are escaped
         exactly once, here, which is the point of the {{ }}. --}}
    <title>{{ $__env->yieldContent('title') ?: setting('seo_default_title', 'CardShop') }}</title>
    <meta name="description" content="{{ $__env->yieldContent('meta_description') ?: setting('seo_default_description', '') }}">
    <meta name="keywords" content="{{ $__env->yieldContent('meta_keywords') ?: setting('seo_default_keywords', '') }}">

    @hasSection('canonical')
    <link rel="canonical" href="{{ $__env->yieldContent('canonical') }}">
    @endif

    <meta property="og:type" content="{{ $__env->yieldContent('og_type', 'website') }}">
    <meta property="og:title" content="{{ $__env->yieldContent('title') ?: setting('seo_default_title', 'CardShop') }}">
    <meta property="og:description" content="{{ $__env->yieldContent('meta_description') ?: setting('seo_default_description', '') }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="{{ setting('site_name', 'CardShop') }}">

    @yield('structured_data')

    @php $siteFavicon = setting('site_favicon'); @endphp
    @if($siteFavicon)
    {{-- Operator-supplied icon. type is omitted deliberately: the uploader accepts
         .ico and .png and the browser sniffs it correctly either way. --}}
    <link rel="icon" href="{{ $siteFavicon }}">
    <link rel="apple-touch-icon" href="{{ $siteFavicon }}">
    @endif

    <link href="{{ asset_versioned('css/front.css') }}" rel="stylesheet">

    @if(setting('turnstile_site_key'))
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif

    @yield('head')
</head>
<body>

    <h1 class="seo-h1">{{ setting('site_name', 'CardShop') }} - {{ setting('site_description', '自动发卡平台') }}</h1>

    <header class="site-header">
        <div class="container">
            @php $siteLogo = setting('site_logo'); @endphp
            <a class="site-logo" href="/">
                @if($siteLogo)
                {{-- Height is capped in CSS, so any upload lands at the header's scale. --}}
                <img src="{{ $siteLogo }}" alt="{{ setting('site_name', 'CardShop') }}" class="site-logo-img">
                @else
                <span class="logo-icon">{{ mb_substr(setting('site_name', 'C'), 0, 1) }}</span>
                @endif
                {{ setting('site_name', 'CardShop') }}
            </a>

            <nav>
                <ul class="site-nav">
                    <li><a href="/" class="{{ request()->is('/') ? 'active' : '' }}">购买商品</a></li>
                    <li><a href="/order/query" class="{{ request()->is('order/query*') ? 'active' : '' }}">查询订单</a></li>
                    <li><a href="/articles" class="{{ request()->is('articles*') ? 'active' : '' }}">相关文章</a></li>
                </ul>
            </nav>

            <button class="menu-toggle" id="menu-toggle" aria-label="菜单">&#9776;</button>
        </div>
    </header>

    <div class="mobile-nav" id="mobile-nav">
        <ul>
            <li><a href="/" class="{{ request()->is('/') ? 'active' : '' }}">购买商品</a></li>
            <li><a href="/order/query" class="{{ request()->is('order/query*') ? 'active' : '' }}">查询订单</a></li>
            <li><a href="/articles" class="{{ request()->is('articles*') ? 'active' : '' }}">相关文章</a></li>
        </ul>
    </div>

    <main class="main-container">
        <div class="container">
            @if($errors->any())
            <div class="alert alert-danger">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
            @endif

            @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            @yield('content')
        </div>
    </main>

    <footer class="site-footer">
        <div class="container">
            &copy; {{ date('Y') }} {{ setting('site_name', 'CardShop') }}. All rights reserved.
        </div>
    </footer>

    <div class="fab-group">
        @if(setting('contact_url'))
        <a href="{{ setting('contact_url') }}" class="fab-btn fab-contact" title="联系客服" target="_blank" rel="noopener">&#128172;</a>
        @endif
        <button class="fab-btn fab-top" id="back-to-top" title="回到顶部">&#8593;</button>
    </div>

    {{-- Announcement dialog, on the two pages a visitor actually arrives on. Not
         site-wide: interrupting someone on the payment or order-query page with a
         notice is the kind of thing that loses a sale. --}}
    @if(request()->is('/') || request()->is('product/*'))
    @include('front.partials.announcement-modal')
    @endif

    <script src="{{ asset_versioned('js/front.js') }}"></script>
    @yield('scripts')
</body>
</html>
