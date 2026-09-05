<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @include('shared.head')

    {{-- 移动端地址栏着色，跟随页头 --header-bg：浅色 #38332D、深色 #26221D。
         手动开关（data-ui-theme）改变不了 meta，但覆盖了系统深浅色的默认场景。 --}}
    <meta name="theme-color" content="#38332D" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#26221D" media="(prefers-color-scheme: dark)">

    {{-- front.css 是三套模板共用的基座；default 自己的配色和深色模式叠在它之后，
         顺序不能反。theme_asset('style.css') 对 default 主题解析到 themes/default/style.css。 --}}
    <link href="{{ asset_versioned('css/front.css') }}" rel="stylesheet">
    <link href="{{ theme_asset('style.css') }}" rel="stylesheet">

    {{-- 深色初始化必须内联、在样式表之后、渲染之前执行，否则会先按浅色画一帧再跳深色
         （白闪）。try/catch 包住：无痕模式 / 禁用了站点数据的浏览器读 localStorage 会抛。 --}}
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
                {{-- Height is capped in CSS, so any upload lands at the header's scale. --}}
                <img src="{{ $siteLogo }}" alt="{{ setting('site_name', 'CardShop') }}" class="site-logo-img" decoding="async">
                @else
                <span class="logo-icon" aria-hidden="true">{{ mb_substr(setting('site_name', 'C'), 0, 1) }}</span>
                @endif
                {{ setting('site_name', 'CardShop') }}
            </a>

            {{-- 右侧一组：导航 + 深色开关 + 汉堡。包一层 .hdr-right 是为了让页头的
                 space-between 只在"logo | 右侧组"之间分配，加了开关也不会把导航挤到中间。 --}}
            <div class="hdr-right">
                <nav>
                    <ul class="site-nav">
                        <li><a href="/" class="{{ request()->is('/') ? 'active' : '' }}">购买商品</a></li>
                        <li><a href="/order/query" class="{{ request()->is('order/query*') ? 'active' : '' }}">查询订单</a></li>
                        <li><a href="/articles" class="{{ request()->is('articles*') ? 'active' : '' }}">相关文章</a></li>
                    </ul>
                </nav>

                {{-- 深色开关。aria-pressed 由页面末尾脚本同步（它表达"当前是否深色"，
                     状态在 <html> 上，不在按钮自己身上）。月亮/太阳两个图标由 CSS 按主题切显隐。 --}}
                <button type="button" class="theme-toggle" id="theme-toggle"
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

                <button class="menu-toggle" id="menu-toggle" aria-label="菜单" aria-expanded="false" aria-controls="mobile-nav">&#9776;</button>
            </div>
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
            <div class="alert alert-danger" role="alert">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
            @endif

            @if(session('success'))
            <div class="alert alert-success" role="status">{{ session('success') }}</div>
            @endif

            @if(session('error'))
            <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
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
        <a href="{{ setting('contact_url') }}" class="fab-btn fab-contact" title="联系客服" aria-label="联系客服" target="_blank" rel="noopener">&#128172;</a>
        @endif
        <button class="fab-btn fab-top" id="back-to-top" title="回到顶部" aria-label="回到顶部">&#8593;</button>
    </div>

    {{-- Announcement dialog, on the two pages a visitor actually arrives on. Not
         site-wide: interrupting someone on the payment or order-query page with a
         notice is the kind of thing that loses a sale. --}}
    @if(request()->is('/') || request()->is('product/*'))
    @themeInclude('partials.announcement-modal')
    @endif

    <script src="{{ asset_versioned('js/front.js') }}"></script>
    <script>
        // 深色开关。<head> 里的 init 脚本已按 localStorage 把 data-ui-theme 写到 <html>，
        // 这里只负责点击切换、记住选择、同步 aria-pressed。
        (function () {
            var btn = document.getElementById('theme-toggle');
            if (!btn) return;
            var root = document.documentElement;
            var isDark = function () {
                var a = root.getAttribute('data-ui-theme');
                // 没显式选过时，是深是浅由系统偏好决定——按钮状态要反映实际显示的样子。
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
