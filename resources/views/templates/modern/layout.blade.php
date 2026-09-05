<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @include('shared.head')

    {{-- 移动端地址栏着色，跟随页头 --bg-surface：浅色 #FFFFFF、深色 #0F172A。
         meta theme-color 只能按系统偏好（prefers-color-scheme）切换，跟不了本主题
         右上角那个手动深色开关（data-ui-theme），但覆盖了绝大多数默认场景已经够用。 --}}
    <meta name="theme-color" content="#FFFFFF" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0F172A" media="(prefers-color-scheme: dark)">

    {{-- 顺序不能反：front.css 在前，本主题在后。front.css 通体变量驱动，本主题重指
         它的 token，那些没有单独实现的共享组件（alert、分页、订单状态、回顶按钮、
         公告弹窗）就一起换了皮。反过来加载的话，front.css 会盖掉整套外观。 --}}
    <link href="{{ asset_versioned('css/front.css') }}" rel="stylesheet">
    <link href="{{ theme_asset('style.css') }}" rel="stylesheet">

    {{-- 深色模式的初始化必须**内联且在样式表之后、渲染之前**执行。
         放到 front.js 里（在 body 末尾）的话，页面会先按浅色画一帧再跳成深色，
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

    @php
        // 顶部导航和移动端抽屉是同一份数据的两种排版。以前两处各写一遍 request()->is()，
        // 改一个链接得记得改两处；合并成一个数组之后，两边不可能再对不上。
        $siteLogo = setting('site_logo');
        $siteName = setting('site_name', 'CardShop');
        $navItems = [
            ['url' => '/', 'label' => '首页', 'active' => request()->is('/')],
            ['url' => '/order/query', 'label' => '查询订单', 'active' => request()->is('order/query*')],
            ['url' => '/articles', 'label' => '公告', 'active' => request()->is('articles*')],
        ];
    @endphp

    <header class="site-header">
        <div class="container">
            <a class="site-brand" href="/">
                @if($siteLogo)
                {{-- 外面这层 .site-logo 只为拿 front.css 的 `.site-logo img`
                     （高 30px、宽自适应、最大 180px）。运营上传的 logo 尺寸不可控，
                     本主题的样式表里没有任何规则管它，不套这一层的话一张 1600px 的
                     横幅会把导航整条挤出屏幕。
                     .site-logo 不能加在 <a> 上：front.css 还带一条
                     `.site-logo:hover { color: #fff }`，那是给深色页头写的，在本主题
                     的白色页头上会让品牌名一 hover 就消失。套在图片外层就避开了。 --}}
                <span class="site-logo"><img src="{{ $siteLogo }}" alt="{{ $siteName }}" decoding="async"></span>
                @else
                {{-- 首字牌是装饰：紧跟其后的 <span> 已经把站名读出来了，
                     读屏再读一遍单个字没有意义。 --}}
                <span class="brand-icon" aria-hidden="true">{{ mb_substr($siteName, 0, 1) }}</span>
                @endif
                <span>{{ $siteName }}</span>
            </a>

            <nav aria-label="主导航">
                <ul class="site-nav">
                    @foreach($navItems as $item)
                    <li>
                        <a href="{{ $item['url'] }}" class="nav-link{{ $item['active'] ? ' active' : '' }}"
                           @if($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                    </li>
                    @endforeach
                </ul>
            </nav>

            <div class="header-actions">
                {{-- 深色开关。aria-pressed 由页面末尾的脚本同步，因为它表达的是
                     「当前是否深色」，而那个状态在 <html> 上，不在按钮自己身上。 --}}
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
                {{-- 只留图标不留「客服」二字：窄屏上导航被折进抽屉后，页头右边同时站着
                     深色开关、客服、汉堡三个控件，再加两个汉字实测会把站名挤到换行，
                     而页头是定高的，换行就被切掉。title + aria-label 已经把名字说清楚。 --}}
                <a href="{{ setting('contact_url') }}" class="hdr-action" target="_blank" rel="noopener"
                   title="联系客服" aria-label="联系客服">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-3.8-.9L3 21l1.9-5a8.4 8.4 0 0 1-.9-3.8 8.4 8.4 0 0 1 8.4-9 8.4 8.4 0 0 1 8.6 8.3z"></path>
                    </svg>
                </a>
                @endif

                {{-- id 必须是 menu-toggle：front.js 按 id 取它。
                     里面的 svg 不能吃点击，否则汉堡键会「点了没反应」——原因和对应的
                     pointer-events 规则写在样式表的 .menu-toggle-btn svg 那一条。 --}}
                <button type="button" class="menu-toggle-btn" id="menu-toggle"
                        aria-label="菜单" aria-controls="mobile-nav" aria-expanded="false">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <line x1="3" y1="12" x2="21" y2="12"></line>
                        <line x1="3" y1="18" x2="21" y2="18"></line>
                    </svg>
                </button>
            </div>
        </div>
    </header>

    {{-- id 是 front.js 的接口（它按 id 取元素并切 .open），类名用本主题的 .mobile-drawer。
         **不能**再挂 front.css 的 .mobile-nav：那套规则自带一份定位和深色背景，
         两套定位叠在一起，抽屉要么悬在页头下方，要么整个钻进吸顶页头背后。 --}}
    <div class="mobile-drawer" id="mobile-nav">
        <div class="mobile-drawer-content">
            @foreach($navItems as $item)
            <a href="{{ $item['url'] }}" class="nav-link{{ $item['active'] ? ' active' : '' }}"
               @if($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </div>
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
            <div class="footer-inner">
                <div class="footer-left">
                    {{-- .site-brand 在这里只借它的字重和字号；页脚没有第二个能把站名
                         排成品牌行的类。 --}}
                    <div class="site-brand">{{ $siteName }}</div>
                    <div>付款后自动发货，卡密直接显示在页面上，并发送到你填写的邮箱。</div>
                    {{-- 版权和 Powered by 放进 .footer-left 这一列，而不是另起一条带分隔线的
                         底栏——模板那条底栏是纯行内样式堆出来的，样式表里没有对应的类，
                         照抄就得写一串 style=。 --}}
                    <span class="footer-copyright">&copy; {{ date('Y') }} {{ $siteName }}. All rights reserved.</span>
                    @include('shared.footer-brand')
                </div>
                <div class="footer-links">
                    @foreach($navItems as $item)
                    <a href="{{ $item['url'] }}">{{ $item['label'] }}</a>
                    @endforeach
                    @if(setting('contact_url'))
                    <a href="{{ setting('contact_url') }}" target="_blank" rel="noopener">联系客服</a>
                    @endif
                </div>
            </div>
        </div>
    </footer>

    {{-- .fab-group 和 .fab-top 都是必需的：front.js 只切 .is-visible，而唯一让按钮可见的
         规则是 `.fab-top.is-visible`；.fab-group 那层则负责 pointer-events，少了它，
         两个按钮之间的空隙照样拦截点击。 --}}
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

        // 抽屉的两处补丁。front.js 负责开合本身（它切 .open），这里只补它管不到的两件事：
        //
        // 1) 点遮罩关闭。本主题的抽屉是整屏遮罩，而 front.js 的「点到外面就收起」判断是
        //    `!drawer.contains(e.target)` —— 点在遮罩上时 e.target 就是抽屉元素自己，
        //    contains 对自身返回 true，于是遮罩怎么点都不关，用户只能回头去找汉堡键。
        //    （旧版抽屉是页头下的一小块面板，点页面任何地方都在它外面，所以没这个问题。）
        // 2) aria-expanded。汉堡键是个展开控件，读屏用户需要知道当前是开是合。
        //
        // 包一层 DOMContentLoaded 是为了**监听器的注册顺序**：front.js 的 click 监听是在它
        // 自己的 DOMContentLoaded 回调里注册的，而本脚本是内联执行的，直接注册会排在它
        // 前面，读到的还是切换前的旧状态。同样等 DOMContentLoaded，本回调排在 front.js
        // 之后，读到的就是切换后的结果。
        document.addEventListener('DOMContentLoaded', function () {
            var drawer = document.getElementById('mobile-nav');
            var toggle = document.getElementById('menu-toggle');
            if (!drawer) return;

            var syncExpanded = function () {
                if (toggle) {
                    toggle.setAttribute('aria-expanded', drawer.classList.contains('open') ? 'true' : 'false');
                }
            };

            if (toggle) { toggle.addEventListener('click', syncExpanded); }

            drawer.addEventListener('click', function (e) {
                if (e.target === drawer) {
                    drawer.classList.remove('open');
                    syncExpanded();
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && drawer.classList.contains('open')) {
                    drawer.classList.remove('open');
                    syncExpanded();
                    if (toggle) { toggle.focus(); }
                }
            });
        });
    </script>
    @yield('scripts')
</body>
</html>
