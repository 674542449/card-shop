<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @include('shared.head')
    <meta name="theme-color" content="#f8fafc" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0b1120" media="(prefers-color-scheme: dark)">
    <link href="{{ theme_asset('style.css') }}" rel="stylesheet">
    <script>
        try {
            var savedTheme = localStorage.getItem('ui-theme');
            if (savedTheme === 'dark' || savedTheme === 'light') {
                document.documentElement.dataset.uiTheme = savedTheme;
            }
        } catch (e) {}
    </script>
    @if(turnstile_site_key())
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
    @yield('head')
</head>
<body class="mall-store">
    @php
        $siteLogo = \App\Support\SafeUrl::asset(setting('site_logo'));
        $siteNameGlobal = setting('site_name', 'CardShop');
        $contactUrl = \App\Support\SafeUrl::contact(setting('contact_url'));
        $contactText = trim((string) setting('contact_text', ''));
        $contactQr = \App\Support\SafeUrl::asset(setting('contact_qr_image'));
        $isStoreActive = request()->is('/') || request()->is('category/*') || request()->is('product/*');
        $isOrderActive = request()->is('order/*');
        $isArticleActive = request()->is('articles*');
        $headerCategories = \App\Models\Category::active()
            ->ordered()
            ->withCount(['products' => fn ($q) => $q->active()])
            ->get();
        $headerTotalProducts = (int) $headerCategories->sum('products_count');
        $activeCategorySlug = request()->is('category/*') ? (string) request()->segment(2) : '';
    @endphp

    <a href="#main-content" class="ml-skip">跳至主要内容</a>

    <header class="ml-header">
        <div class="ml-container ml-header-top">
            <a class="ml-brand" href="/" aria-label="{{ $siteNameGlobal }} 首页">
                @if($siteLogo)
                <img src="{{ $siteLogo }}" alt="" class="ml-brand-logo" width="36" height="36" decoding="async">
                @else
                <span class="ml-brand-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/>
                        <path d="M3 6h18"/>
                        <path d="M16 10a4 4 0 0 1-8 0"/>
                    </svg>
                </span>
                @endif
                <span class="ml-brand-name">{{ $siteNameGlobal }}</span>
            </a>

            <search class="ml-header-search">
                <form method="GET" action="/" class="ml-search-form">
                    <label for="mall-global-search" class="ml-sr-only">搜索全部商品</label>
                    <div class="ml-search-field">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="7"/>
                            <path d="M20 20l-3.5-3.5"/>
                        </svg>
                        <input id="mall-global-search" type="search" name="q" value="{{ request()->is('/') ? request('q') : '' }}" maxlength="200" placeholder="搜索全部商品名称或关键词…">
                        @if(request()->is('/') && request()->filled('q'))
                        <a href="/" class="ml-search-clear" aria-label="清空搜索">清空</a>
                        @endif
                    </div>
                    <button type="submit" class="ml-btn ml-btn-primary ml-btn-sm">搜索</button>
                </form>
            </search>

            <nav class="ml-header-nav" aria-label="主导航">
                <a href="/" @class(['ml-nav-link', 'is-active' => $isStoreActive]) @if($isStoreActive) aria-current="page" @endif>商城首页</a>
                <a href="/order/query" @class(['ml-nav-link', 'is-active' => $isOrderActive]) @if($isOrderActive) aria-current="page" @endif>订单查询</a>
                <a href="/articles" @class(['ml-nav-link', 'is-active' => $isArticleActive]) @if($isArticleActive) aria-current="page" @endif>帮助中心</a>
            </nav>

            <div class="ml-header-tools">
                @if($contactUrl)
                <a href="{{ $contactUrl }}" class="ml-icon-btn ml-support-icon" title="联系客服" aria-label="联系客服" target="_blank" rel="noopener">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z"/>
                    </svg>
                </a>
                @endif

                <button type="button" class="ml-icon-btn" id="ui-theme-toggle" aria-label="切换深色模式" title="切换深色模式" aria-pressed="false">
                    <svg class="ml-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                    <svg class="ml-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                </button>

                <button type="button" class="ml-icon-btn ml-menu-toggle" id="menu-toggle" aria-label="展开菜单" aria-controls="mobile-nav" aria-expanded="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
            </div>
        </div>

        @if($headerCategories->isNotEmpty() && $isStoreActive)
        <div class="ml-cat-strip">
            <nav class="ml-container ml-cat-strip-inner" aria-label="商城频道分类">
                <a href="/" @class(['ml-cat-pill', 'is-active' => request()->is('/') && !request()->filled('q')]) @if(request()->is('/') && !request()->filled('q')) aria-current="page" @endif>
                    <span>全部商品</span>
                    <small>{{ $headerTotalProducts }}</small>
                </a>
                @foreach($headerCategories as $hCat)
                @php $isCurrentCat = $activeCategorySlug !== '' && $activeCategorySlug === $hCat->slug; @endphp
                <a href="/category/{{ $hCat->slug }}" @class(['ml-cat-pill', 'is-active' => $isCurrentCat]) @if($isCurrentCat) aria-current="page" @endif>
                    <span>{{ $hCat->name }}</span>
                    <small>{{ $hCat->products_count ?? 0 }}</small>
                </a>
                @endforeach
            </nav>
        </div>
        @endif

        <nav class="ml-mobile-nav" id="mobile-nav" aria-label="移动导航">
            <a href="/" @class(['is-active' => $isStoreActive]) @if($isStoreActive) aria-current="page" @endif>商城首页 <span aria-hidden="true">→</span></a>
            <a href="/order/query" @class(['is-active' => $isOrderActive]) @if($isOrderActive) aria-current="page" @endif>订单查询 <span aria-hidden="true">→</span></a>
            <a href="/articles" @class(['is-active' => $isArticleActive]) @if($isArticleActive) aria-current="page" @endif>帮助中心 <span aria-hidden="true">→</span></a>
            @if($contactUrl)
            <a href="{{ $contactUrl }}" target="_blank" rel="noopener">联系在线客服 <span aria-hidden="true">↗</span></a>
            @endif
        </nav>
    </header>

    <main id="main-content" class="ml-main ml-container" tabindex="-1">
        @if(request()->is('/') || request()->is('product/*'))
        @themeInclude('partials.announcement-modal')
        @endif
        @if($errors->any())
        <div class="ml-alert ml-alert-danger" role="alert">
            <strong>请检查以下信息</strong>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
        @endif
        @if(session('success'))<div class="ml-alert ml-alert-success" role="status">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="ml-alert ml-alert-danger" role="alert">{{ session('error') }}</div>@endif

        @yield('content')
    </main>

    <footer class="ml-footer ml-deferred-section">
        <div class="ml-container ml-footer-top">
            <div class="ml-footer-info">
                <a href="/" class="ml-footer-brand">{{ $siteNameGlobal }}</a>
                <p>{{ $contactText !== '' ? '客服支持：' . $contactText : '24 小时自动发货 · 安全加密交付数字商品' }}</p>
            </div>
            <nav class="ml-footer-nav" aria-label="页脚导航">
                <a href="/">商品目录</a>
                <a href="/order/query">订单查询</a>
                <a href="/articles">帮助中心</a>
                @if($contactUrl)<a href="{{ $contactUrl }}" target="_blank" rel="noopener">在线客服 ↗</a>@endif
                @if($contactQr)<a href="{{ $contactQr }}" target="_blank" rel="noopener">客服二维码 ↗</a>@endif
            </nav>
        </div>
        <div class="ml-container ml-footer-bottom">
            <span>&copy; {{ date('Y') }} {{ $siteNameGlobal }}</span>
            @include('shared.footer-brand')
            <span>即时发卡 · 自助提取</span>
        </div>
    </footer>

    <button type="button" class="ml-back-top" id="back-to-top" aria-label="返回顶部" title="返回顶部">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 15l-6-6-6 6"/></svg>
    </button>

    <script defer src="{{ asset_versioned('js/front.js') }}"></script>
    <script defer src="{{ asset_versioned('js/checkout.js') }}"></script>
    @yield('scripts')
</body>
</html>
