<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @include('shared.head')

    {{--
        先 front.css 再 style.css，顺序不能反。

        front.css 从头到尾是变量驱动的（:root 里几十个 token），所以这套模板只需要
        覆盖 token 就能把整站重新配色 —— 包括它没有覆盖的页面（订单查询、订单详情、
        文章页，那些由 theme_view_path() 回落到 default 提供）。
        反过来把 style.css 放前面，覆盖就全被 front.css 的原值盖回去了。
    --}}
    <link href="{{ asset_versioned('css/front.css') }}" rel="stylesheet">
    <link href="{{ theme_asset('style.css') }}" rel="stylesheet">

    @if(setting('turnstile_site_key'))
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif

    @yield('head')
</head>
<body>

    <h1 class="seo-h1">{{ setting('site_name', 'CardShop') }} - {{ setting('site_description', '自动发卡平台') }}</h1>

    {{-- 页头是一条发丝线而不是一个色块：极简版式里，页头该几乎不存在，
         只负责把内容和屏幕顶端分开。 --}}
    <header class="site-header">
        <div class="container">
            @php $siteLogo = setting('site_logo'); @endphp
            <a class="site-logo" href="/">
                @if($siteLogo)
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

            {{-- id 沿用 default：front.js 绑的是 #menu-toggle / #mobile-nav / #back-to-top，
                 换名字会让这套模板的移动端菜单和回到顶部静默失效。 --}}
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

    {{-- 用内联 SVG 而不是 emoji（&#128172; / &#8593;）：emoji 的字形、大小和基线由
         系统字体决定，在 Windows、macOS、Android 上三个样子，还会被当成文字选中。
         这两个是控件，不是内容。 --}}
    <div class="fab-group">
        @if(setting('contact_url'))
        <a href="{{ setting('contact_url') }}" class="fab-btn fab-contact" title="联系客服" target="_blank" rel="noopener" aria-label="联系客服">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true">
                <path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-3.8-.9L3 21l1.9-5a8.4 8.4 0 0 1-.9-3.8 8.4 8.4 0 0 1 8.4-9 8.4 8.4 0 0 1 8.6 8.3z"></path>
            </svg>
        </a>
        @endif
        <button class="fab-btn fab-top" id="back-to-top" title="回到顶部" aria-label="回到顶部">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" width="18" height="18" aria-hidden="true">
                <path d="M12 19V5M5 12l7-7 7 7"></path>
            </svg>
        </button>
    </div>

    @if(request()->is('/') || request()->is('product/*'))
    @themeInclude('partials.announcement-modal')
    @endif

    <script src="{{ asset_versioned('js/front.js') }}"></script>
    @yield('scripts')
</body>
</html>
