<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @include('shared.head')

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
            @include('shared.footer-brand')
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
    @themeInclude('partials.announcement-modal')
    @endif

    <script src="{{ asset_versioned('js/front.js') }}"></script>
    @yield('scripts')
</body>
</html>
