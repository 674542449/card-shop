<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @include('shared.head')

    <meta name="theme-color" content="#F4F6F9" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1B212C" media="(prefers-color-scheme: dark)">

    <link href="{{ theme_asset('style.css') }}" rel="stylesheet">
    <link href="{{ asset_versioned('css/front.css') }}" rel="stylesheet">

    <script>
        (function () {
            try {
                var saved = localStorage.getItem('ui-theme');
                if (saved === 'dark' || saved === 'light') {
                    document.documentElement.setAttribute('data-ui-theme', saved);
                }
            } catch (e) {}
        })();
    </script>

    @if(setting('turnstile_site_key'))
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif

    @yield('head')
</head>
<body class="ws-app fb-app">
    @php
        $siteLogo = \App\Support\SafeUrl::asset(setting('site_logo'));
        $siteNameGlobal = setting('site_name', 'CardShop');
        $contactUrl = \App\Support\SafeUrl::contact(setting('contact_url'));
        $contactText = trim((string) setting('contact_text', ''));
        $contactQr = \App\Support\SafeUrl::asset(setting('contact_qr_image'));
        $supportHref = $contactUrl ?: $contactQr;
        $isStoreActive = request()->is('/') || request()->is('category/*') || request()->is('product/*');
        $isOrderActive = request()->is('order/*');
        $isArticleActive = request()->is('articles*');
    @endphp

    <a class="ws-skip-link" href="#main-content">跳至主要内容</a>

    {{-- Facebook 56px Top App Bar: Left Logo+Search · Center 3-Tab Bar · Right Circular Action Pills --}}
    <header class="ws-topbar fb-topbar">
        <div class="fb-topbar-inner">
            {{-- Left Zone: Circular #0866FF Brand Badge + Wordmark --}}
            <div class="fb-topbar-left">
                <a class="ws-brand fb-brand" href="/">
                    @if($siteLogo)
                    <img src="{{ $siteLogo }}" alt="{{ $siteNameGlobal }}" class="fb-brand-logo" width="40" height="40" decoding="async">
                    @else
                    <span class="fb-brand-circle" aria-hidden="true">{{ mb_substr($siteNameGlobal, 0, 1) }}</span>
                    @endif
                    <span class="fb-brand-text">{{ $siteNameGlobal }}</span>
                </a>
            </div>

            {{-- Center Zone: Iconic Facebook Full-Height Center Tabs with #0866FF Bottom Indicator --}}
            <nav class="fb-center-tabs" aria-label="主导航">
                <a href="/" class="fb-center-tab {{ $isStoreActive ? 'is-active' : '' }}" title="商品目录" @if($isStoreActive) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 9l1.5-5h15L21 9"></path>
                        <path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"></path>
                        <path d="M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8"></path>
                    </svg>
                    <span>商品商城</span>
                </a>
                <a href="/order/query" class="fb-center-tab {{ $isOrderActive ? 'is-active' : '' }}" title="订单查询与提取" @if($isOrderActive) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M6 2h12a2 2 0 0 1 2 2v17l-4-2.5L12 21l-4-2.5L4 21V4a2 2 0 0 1 2-2z"></path>
                        <path d="M9 8h6M9 12h6"></path>
                    </svg>
                    <span>订单查询</span>
                </a>
                <a href="/articles" class="fb-center-tab {{ $isArticleActive ? 'is-active' : '' }}" title="公告与帮助中心" @if($isArticleActive) aria-current="page" @endif>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    </svg>
                    <span>帮助中心</span>
                </a>
            </nav>

            {{-- Right Zone: Facebook #E4E6EB 40x40 Circular Action Buttons --}}
            <div class="fb-topbar-right">
                @if($supportHref)
                <a href="{{ $supportHref }}" class="fb-circle-btn" title="联系客服" aria-label="联系客服" target="_blank" rel="noopener">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z"></path>
                    </svg>
                </a>
                @endif

                <button type="button" class="fb-circle-btn" id="ui-theme-toggle"
                        aria-label="切换深色模式" title="切换深色模式" aria-pressed="false">
                    <svg class="ws-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"></path>
                    </svg>
                    <svg class="ws-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"></circle>
                        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Facebook Mobile Sub-Header 3-Tab Bar (<= 760px: keeps bottom of screen 100% unobstructed) --}}
        <nav class="fb-mobile-tabbar" aria-label="移动端主导航">
            <a href="/" class="fb-mobile-tab {{ $isStoreActive ? 'is-active' : '' }}" @if($isStoreActive) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M3 9l1.5-5h15L21 9"></path>
                    <path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"></path>
                    <path d="M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8"></path>
                </svg>
                <span>商品商城</span>
            </a>
            <a href="/order/query" class="fb-mobile-tab {{ $isOrderActive ? 'is-active' : '' }}" @if($isOrderActive) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M6 2h12a2 2 0 0 1 2 2v17l-4-2.5L12 21l-4-2.5L4 21V4a2 2 0 0 1 2-2z"></path>
                    <path d="M9 8h6M9 12h6"></path>
                </svg>
                <span>订单查询</span>
            </a>
            <a href="/articles" class="fb-mobile-tab {{ $isArticleActive ? 'is-active' : '' }}" @if($isArticleActive) aria-current="page" @endif>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                </svg>
                <span>帮助中心</span>
            </a>
        </nav>
    </header>

    <main class="ws-main" id="main-content" tabindex="-1">
        <div class="ws-shell">
            @if($errors->any())
            <div class="ws-alert ws-alert-danger" role="alert">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
            @endif

            @if(session('success'))
            <div class="ws-alert ws-alert-success" role="status">{{ session('success') }}</div>
            @endif

            @if(session('error'))
            <div class="ws-alert ws-alert-danger" role="alert">{{ session('error') }}</div>
            @endif

            @yield('content')
        </div>
    </main>

    <footer class="ws-footer">
        <div class="ws-shell ws-footer-inner">
            <div>
                <div>&copy; {{ date('Y') }} {{ $siteNameGlobal }} · 数字商品在线交易与自动交付系统</div>
                @if($contactText !== '')
                <div class="ws-footer-contact">客服支持：{{ $contactText }}</div>
                @endif
                @include('shared.footer-brand')
            </div>
            <div class="ws-footer-links">
                <a href="/">商品目录</a>
                <a href="/order/query">订单查询</a>
                <a href="/articles">帮助中心</a>
                @if($contactUrl)
                <a href="{{ $contactUrl }}" target="_blank" rel="noopener">在线客服 &nearr;</a>
                @endif
                @if($contactQr)
                <a href="{{ $contactQr }}" target="_blank" rel="noopener">客服二维码 &nearr;</a>
                @endif
            </div>
        </div>
    </footer>

    <div class="ws-fab-stack">
        <button type="button" class="ws-fab" id="back-to-top" title="返回顶部" aria-label="返回顶部">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 15l-6-6-6 6"></path>
            </svg>
        </button>
    </div>

    @if(request()->is('/') || request()->is('product/*'))
    @themeInclude('partials.announcement-modal')
    @endif

    <script defer src="{{ asset_versioned('js/front.js') }}"></script>
    <script defer src="{{ theme_asset('workspace.js') }}"></script>
    <script defer src="{{ asset_versioned('js/checkout.js') }}"></script>
    @yield('scripts')
</body>
</html>
