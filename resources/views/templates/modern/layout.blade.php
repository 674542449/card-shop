<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @include('shared.head')
    <meta name="theme-color" content="#faf9f5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#25241f" media="(prefers-color-scheme: dark)">
    <link href="{{ theme_asset('style.css') }}" rel="stylesheet">
    <script>
        try {
            var savedTheme = localStorage.getItem('ui-theme');
            if (savedTheme === 'dark' || savedTheme === 'light') document.documentElement.dataset.uiTheme = savedTheme;
        } catch (e) {}
    </script>
    @if(turnstile_site_key())
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
    @yield('head')
</head>
<body class="modern-store">
    @php
        $siteLogo = \App\Support\SafeUrl::asset(setting('site_logo'));
        $siteName = setting('site_name', 'CardShop');
        $contactUrl = \App\Support\SafeUrl::contact(setting('contact_url'));
        $contactText = trim((string) setting('contact_text', ''));
        $contactQr = \App\Support\SafeUrl::asset(setting('contact_qr_image'));
        $navItems = [
            ['url' => '/', 'label' => '商品目录', 'icon' => 'store', 'active' => request()->is('/') || request()->is('category/*') || request()->is('product/*')],
            ['url' => '/order/query', 'label' => '订单查询', 'icon' => 'order', 'active' => request()->is('order/*')],
            ['url' => '/articles', 'label' => '帮助中心', 'icon' => 'guide', 'active' => request()->is('articles*')],
        ];
    @endphp
    <a href="#main-content" class="m-skip-link">跳到主要内容</a>
    <header class="m-header">
        <div class="m-container m-header-inner">
            <a class="m-brand" href="/" aria-label="{{ $siteName }} 首页">
                @if($siteLogo)
                <img class="m-brand-logo" src="{{ $siteLogo }}" alt="" decoding="async">
                @else
                <svg class="m-brand-mark" viewBox="0 0 32 32" fill="none" aria-hidden="true">
                    <path d="M7 5h14l5 5v17H7z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                    <path d="M21 5v6h5M11 16h11M11 21h7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    <path d="M3 10v17a4 4 0 0 0 4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                @endif
                <span>{{ $siteName }}</span>
            </a>
            <nav class="m-desktop-nav" aria-label="主导航">
                @foreach($navItems as $item)
                <a href="{{ $item['url'] }}" @class(['m-nav-link', 'is-active' => $item['active']]) @if($item['active']) aria-current="page" @endif>
                    @if($item['icon'] === 'store')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l1.5-5h15L21 9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8"/></svg>
                    @elseif($item['icon'] === 'order')<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2h12a2 2 0 0 1 2 2v17l-4-2.5L12 21l-4-2.5L4 21V4a2 2 0 0 1 2-2z"/><path d="M9 8h6M9 12h6"/></svg>
                    @else<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>@endif
                    <span>{{ $item['label'] }}</span>
                </a>
                @endforeach
            </nav>
            <div class="m-header-actions">
                @if($contactUrl)
                <a class="m-contact-link" href="{{ $contactUrl }}" target="_blank" rel="noopener">联系客服 <span aria-hidden="true">↗</span></a>
                @elseif($contactQr)
                <button type="button" class="m-contact-link cs-qr-link-btn" command="show-modal" commandfor="contact-qr-dialog">联系客服</button>
                @endif
                <button type="button" class="m-icon-button" id="ui-theme-toggle" aria-label="切换深色模式" title="切换深色模式" aria-pressed="false">
                    <svg class="m-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20.8 13a8.8 8.8 0 1 1-9.8-9.8A7.2 7.2 0 0 0 20.8 13z"/></svg>
                    <svg class="m-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg>
                </button>
                <button type="button" class="m-icon-button m-menu-toggle" id="menu-toggle" aria-label="菜单" aria-controls="mobile-nav" aria-expanded="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            </div>
        </div>
        <nav class="m-mobile-nav" id="mobile-nav" aria-label="移动导航">
            @foreach($navItems as $item)
            <a href="{{ $item['url'] }}" @class(['m-nav-link', 'is-active' => $item['active']]) @if($item['active']) aria-current="page" @endif>{{ $item['label'] }} <span aria-hidden="true">↗</span></a>
            @endforeach
            @if($contactUrl)
            <a class="m-nav-link" href="{{ $contactUrl }}" target="_blank" rel="noopener">联系客服 <span aria-hidden="true">↗</span></a>
            @elseif($contactQr)
            <button type="button" class="m-nav-link cs-qr-link-btn" command="show-modal" commandfor="contact-qr-dialog">客服二维码</button>
            @endif
        </nav>
    </header>
    <main id="main-content" class="m-main m-container" tabindex="-1">
        @if($errors->any())
        <div class="m-alert m-alert-error" role="alert">
            <strong>请检查以下内容</strong>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
        @endif
        @if(session('success'))<div class="m-alert m-alert-success" role="status">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="m-alert m-alert-error" role="alert">{{ session('error') }}</div>@endif
        @yield('content')
    </main>
    <footer class="m-footer">
        <div class="m-container m-footer-top">
            <div>
                <a href="/" class="m-footer-brand">{{ $siteName }}</a>
                <p>{{ $contactText !== '' ? '客服支持：' . $contactText : '数字商品在线交易与自动交付' }}</p>
            </div>
            <div class="m-footer-links">
                @foreach($navItems as $item)<a href="{{ $item['url'] }}">{{ $item['label'] }}</a>@endforeach
                @if($contactUrl)<a href="{{ $contactUrl }}" target="_blank" rel="noopener">联系客服 ↗</a>@endif
                @if($contactQr)<button type="button" class="cs-qr-link-btn" command="show-modal" commandfor="contact-qr-dialog">客服二维码</button>@endif
            </div>
        </div>
        <div class="m-container m-footer-bottom">
            <span>&copy; {{ date('Y') }} {{ $siteName }}</span>
            @include('shared.footer-brand')
            <span>24/7 自动交付</span>
        </div>
    </footer>
    <button type="button" class="m-back-top" id="back-to-top" aria-label="回到顶部" title="回到顶部"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 20V4M5 11l7-7 7 7"/></svg></button>
    @if(request()->is('/') || request()->is('product/*'))
    @themeInclude('partials.announcement-modal')
    @endif
    @include('shared.contact-qr-modal')
    <script defer src="{{ asset_versioned('js/front.js') }}"></script>
    <script defer src="{{ asset_versioned('js/checkout.js') }}"></script>
    @yield('scripts')
</body>
</html>
