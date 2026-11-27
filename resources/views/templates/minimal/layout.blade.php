<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @include('shared.head')
    <meta name="theme-color" content="#f7f7f8" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#18191d" media="(prefers-color-scheme: dark)">
    <link href="{{ theme_asset('style.css') }}" rel="stylesheet">
    <script>try { var savedTheme = localStorage.getItem('ui-theme'); if (savedTheme === 'light' || savedTheme === 'dark') document.documentElement.dataset.uiTheme = savedTheme; } catch (e) {}</script>
    @if(turnstile_site_key())<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>@endif
    @yield('head')
</head>
<body class="minimal-store">
    @php
        $siteLogo = \App\Support\SafeUrl::asset(setting('site_logo'));
        $siteName = setting('site_name', 'CardShop');
        $contactUrl = \App\Support\SafeUrl::contact(setting('contact_url'));
        $contactText = trim((string) setting('contact_text', ''));
        $contactQr = \App\Support\SafeUrl::asset(setting('contact_qr_image'));
        $navItems = [
            ['url' => '/', 'label' => '商品目录', 'active' => request()->is('/') || request()->is('category/*') || request()->is('product/*')],
            ['url' => '/order/query', 'label' => '订单查询', 'active' => request()->is('order/*')],
            ['url' => '/articles', 'label' => '帮助中心', 'active' => request()->is('articles*')],
        ];
    @endphp
    <a class="n-skip" href="#main-content">跳到主要内容</a>
    <header class="n-header">
        <div class="n-shell n-header-inner">
            <a class="n-brand" href="/" aria-label="{{ $siteName }} 首页">
                @if($siteLogo)<img src="{{ $siteLogo }}" alt="" decoding="async">@else<span class="n-brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="5" y="5" width="14" height="14" rx="2"/><path d="M9 9h6m-6 4h4" stroke-linecap="round"/></svg></span>@endif
                <span>{{ $siteName }}</span>
            </a>
            <nav class="n-desktop-nav" aria-label="主导航">
                @foreach($navItems as $item)
                <a href="{{ $item['url'] }}" @class(['is-active' => $item['active']]) @if($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>
            <div class="n-header-tools">
                @if($contactUrl)
                <a class="n-contact-link" href="{{ $contactUrl }}" target="_blank" rel="noopener">客服 ↗</a>
                @endif
                <button type="button" class="n-icon-button" id="ui-theme-toggle" aria-label="切换深色模式" title="切换深色模式" aria-pressed="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="7"/><path d="M12 5a7 7 0 0 1 0 14Z" fill="currentColor" stroke="none"/></svg>
                </button>
                <button type="button" class="n-icon-button n-menu-toggle" id="menu-toggle" aria-label="打开导航菜单" aria-controls="mobile-nav" aria-expanded="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M5 7h14M5 12h14M5 17h14" stroke-linecap="round"/></svg>
                </button>
            </div>
        </div>
        <nav class="n-mobile-nav" id="mobile-nav" aria-label="移动导航">
            @foreach($navItems as $item)
            <a href="{{ $item['url'] }}" @class(['is-active' => $item['active']]) @if($item['active']) aria-current="page" @endif>{{ $item['label'] }} <span aria-hidden="true">→</span></a>
            @endforeach
            @if($contactUrl)
            <a href="{{ $contactUrl }}" target="_blank" rel="noopener">联系客服 <span aria-hidden="true">↗</span></a>
            @endif
        </nav>
    </header>
    <main class="n-shell n-main" id="main-content" tabindex="-1">
        @if($errors->any())<div class="n-message n-message-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if(session('success'))<div class="n-message n-message-success" role="status">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="n-message n-message-error" role="alert">{{ session('error') }}</div>@endif
        @yield('content')
    </main>
    <footer class="n-footer">
        <div class="n-shell n-footer-inner">
            <div>
                <strong>{{ $siteName }}</strong>
                <p>{{ $contactText !== '' ? '客服支持：' . $contactText : '数字商品，简单购买，自动交付。' }}</p>
            </div>
            <nav aria-label="页脚导航">
                @foreach($navItems as $item)<a href="{{ $item['url'] }}">{{ $item['label'] }}</a>@endforeach
                @if($contactUrl)<a href="{{ $contactUrl }}" target="_blank" rel="noopener">联系客服 ↗</a>@endif
                @if($contactQr)<a href="{{ $contactQr }}" target="_blank" rel="noopener">客服二维码 ↗</a>@endif
            </nav>
        </div>
        <div class="n-shell n-footer-bottom">
            <small>&copy; {{ date('Y') }} {{ $siteName }}</small>
            @include('shared.footer-brand')
        </div>
    </footer>
    <button type="button" class="n-back-top" id="back-to-top" aria-label="回到顶部" title="回到顶部"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 19V5m-7 7 7-7 7 7" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
    @if(request()->is('/') || request()->is('product/*'))@themeInclude('partials.announcement-modal')@endif
    <script defer src="{{ asset_versioned('js/front.js') }}"></script>
    <script defer src="{{ asset_versioned('js/checkout.js') }}"></script>
    @yield('scripts')
</body>
</html>
