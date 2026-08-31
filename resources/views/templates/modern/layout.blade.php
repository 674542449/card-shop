<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @include('shared.head')

    {{-- 顺序不能反：front.css 在前，本模板在后。front.css 通体变量驱动，本模板覆盖
         它的 token 就能把没有单独实现的页面（订单查询、订单详情、文章页）一起带过来。 --}}
    <link href="{{ asset_versioned('css/front.css') }}" rel="stylesheet">
    <link href="{{ theme_asset('style.css') }}" rel="stylesheet">

    {{-- 深色模式的初始化必须**内联且在样式表之后、渲染之前**执行。
         放到 front.js 里（defer、在 body 末尾）的话，页面会先按浅色画一帧再跳成深色，
         也就是所谓的白闪；对一个默认深色的用户来说这一下非常刺眼。
         try/catch 包住是因为无痕模式和禁用了站点数据的浏览器读 localStorage 会抛。 --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('ui-theme');
                if (t === 'dark' || t === 'light') {
                    document.documentElement.setAttribute('data-ui-theme', t);
                }
            } catch (e) {}
        })();
    </script>

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
                <img src="{{ $siteLogo }}" alt="{{ setting('site_name', 'CardShop') }}" class="site-logo-img">
                @else
                <span class="logo-icon">{{ mb_substr(setting('site_name', 'C'), 0, 1) }}</span>
                @endif
                {{ setting('site_name', 'CardShop') }}
            </a>

            <nav>
                <ul class="site-nav">
                    <li>
                        <a href="/" class="{{ request()->is('/') ? 'active' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M3 10.5 12 3l9 7.5"></path><path d="M5 9.5V21h14V9.5"></path>
                            </svg>
                            首页
                        </a>
                    </li>
                    <li>
                        <a href="/order/query" class="{{ request()->is('order/query*') ? 'active' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="4" y="3" width="16" height="18" rx="2"></rect>
                                <path d="M8 8h8M8 12h8M8 16h5"></path>
                            </svg>
                            查询订单
                        </a>
                    </li>
                    <li>
                        <a href="/articles" class="{{ request()->is('articles*') ? 'active' : '' }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 3a6 6 0 0 0-6 6v4l-2 3h16l-2-3V9a6 6 0 0 0-6-6z"></path>
                                <path d="M10 19a2 2 0 0 0 4 0"></path>
                            </svg>
                            公告
                        </a>
                    </li>
                </ul>
            </nav>

            <div class="hdr-actions">
                {{-- 深色开关。aria-pressed 由脚本同步，因为它表达的是「当前是否深色」，
                     而那个状态在 <html> 上，不在按钮自己身上。 --}}
                <button type="button" class="hdr-action" id="ui-theme-toggle"
                        aria-label="切换深色模式" title="切换深色模式" aria-pressed="false">
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"></path>
                    </svg>
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"></circle>
                        <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
                    </svg>
                </button>

                @if(setting('contact_url'))
                <a href="{{ setting('contact_url') }}" class="hdr-action" target="_blank" rel="noopener" title="联系客服">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-3.8-.9L3 21l1.9-5a8.4 8.4 0 0 1-.9-3.8 8.4 8.4 0 0 1 8.4-9 8.4 8.4 0 0 1 8.6 8.3z"></path>
                    </svg>
                    <span>客服</span>
                </a>
                @endif
            </div>

            <button class="menu-toggle" id="menu-toggle" aria-label="菜单">&#9776;</button>
        </div>
    </header>

    <div class="mobile-nav" id="mobile-nav">
        <ul>
            <li><a href="/" class="{{ request()->is('/') ? 'active' : '' }}">首页</a></li>
            <li><a href="/order/query" class="{{ request()->is('order/query*') ? 'active' : '' }}">查询订单</a></li>
            <li><a href="/articles" class="{{ request()->is('articles*') ? 'active' : '' }}">公告</a></li>
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
    <script>
        (function () {
            var btn = document.getElementById('ui-theme-toggle');
            if (!btn) return;
            var root = document.documentElement;
            var isDark = function () {
                var a = root.getAttribute('data-ui-theme');
                // 没有显式选择时，当前是深是浅由系统偏好决定——按钮的状态必须反映
                // 实际显示的样子，而不是「存过什么」。
                if (a === 'dark') return true;
                if (a === 'light') return false;
                return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            };
            var sync = function () { btn.setAttribute('aria-pressed', isDark() ? 'true' : 'false'); };
            sync();
            btn.addEventListener('click', function () {
                var next = isDark() ? 'light' : 'dark';
                root.setAttribute('data-ui-theme', next);
                try { localStorage.setItem('ui-theme', next); } catch (e) {}
                sync();
            });
        })();
    </script>
    @yield('scripts')
</body>
</html>
