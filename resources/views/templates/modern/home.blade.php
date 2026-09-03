@extends(theme_view_path('layout'))

@section('title', setting('seo_default_title', $siteName))
@section('meta_description', setting('seo_default_description', ''))
@section('meta_keywords', setting('seo_default_keywords', ''))

@section('structured_data')
@php
    // 数组构造再 json_encode，不写字面量 JSON：行首的 "@context" 会被当成真正的
    // @context 指令，编译出一个没闭合的 if()，整页 500。@php 块在指令编译前就被
    // 当作原始块存下来，放这里才安全。
    $structuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $siteName,
        'url' => url('/'),
        'description' => setting('seo_default_description', ''),
    ];
@endphp
<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@section('content')

    {{-- 首屏。目标模板的 hero 只有 44px/28px 的上下内距，比这套模板早先删掉的那个
         大 hero 矮一半以上，1080p 视口仍然能看到第一排商品，所以这次把它加回来。

         站名用 <p> 不用 <h1>：layout 里的 .seo-h1 已经是本页唯一的 h1，再写一个就
         成了两个。 --}}
    <section class="hero-simple">
        <div class="hero-badge">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
            </svg>
            付款后自动发货 · 卡密即时上屏
        </div>
        <p class="hero-title">{{ $siteName }}</p>
        @if($siteDescription)
        <p class="hero-desc">{{ $siteDescription }}</p>
        @endif
    </section>

    {{-- 三块说明写的都是这套后台真实存在的机制：支付回调后自动发卡、卡密同时上屏
         和发邮件、下单时自设查询密码后可凭邮箱+密码取回订单。不写质保、包换、手续费
         这类本平台根本没有对应逻辑的承诺。

         图标底色是逐个实例的强调色，样式表只给了 .trust-pillar-icon 的尺寸，没给
         配色，所以只能写在行内；用 token 而不是模板里那三个写死的十六进制值，
         否则深色模式下会留三块亮片。 --}}
    <div class="trust-pillars-bar">
        <div class="trust-pillar-card">
            <div class="trust-pillar-icon" style="background: var(--color-danger-bg); color: var(--color-danger);">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                </svg>
            </div>
            <div class="trust-pillar-info">
                <strong class="trust-pillar-title">付款后自动发货</strong>
                <p>支付成功即刻发卡，不用等人工处理，也不用留言催单</p>
            </div>
        </div>

        <div class="trust-pillar-card">
            <div class="trust-pillar-icon" style="background: var(--color-brand-subtle); color: var(--color-brand);">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                    <path d="m3 7.5 9 6 9-6"></path>
                </svg>
            </div>
            <div class="trust-pillar-info">
                <strong class="trust-pillar-title">卡密当场可见</strong>
                <p>订单页直接展示卡密内容，同一份也会发到你填写的邮箱</p>
            </div>
        </div>

        <div class="trust-pillar-card">
            <div class="trust-pillar-icon" style="background: var(--color-success-bg); color: var(--color-success);">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
            </div>
            <div class="trust-pillar-info">
                <strong class="trust-pillar-title">订单随时找回</strong>
                <p>下单时自己设一个查询密码，之后凭邮箱加密码就能取回订单</p>
            </div>
        </div>
    </div>

    {{-- 站点公告。目标模板那条通知条是单行截断的（.home-ann-content 带 nowrap +
         省略号），但这里的内容是运营写的富文本，截断等于把质保、售后、免责这些最该
         被读到的话吞掉一半，而且这套后台没有「展开看全文」的入口——弹窗读的是另一
         个设置项 popup_announcement，不是这一条。

         所以只借外层那只白卡片的壳，里面换成会换行的 .rich-text；标签也放进同一个
         子节点里，让这唯一的 flex 项从左边开始排（.home-ann-bar 是 space-between，
         两个子节点时短文案会被推到右边去）。 --}}
    @if($siteAnnouncement)
    <section class="home-ann-bar" aria-label="站点公告">
        <div class="rich-text">
            <span class="home-ann-tag">公告</span>
            {!! \App\Support\ContentRenderer::toHtml($siteAnnouncement) !!}
        </div>
    </section>
    @endif

    <section class="products-section">
        <div class="section-header">
            <div class="section-title-wrap">
                <h2 class="section-title">全部商品</h2>
                <span class="section-count">{{ $products->count() }} 件在售</span>
            </div>

            {{-- 目标模板这排按钮是纯前端假筛选（点一下在本地数组里过滤）。这里有真的
                 分类和真的路由，所以直接渲染成指向 /category/{slug} 的链接：不写死
                 数据、不留没有后端的 JS、还能被搜索引擎跟进。
                 首页本身就是「全部」，所以第一枚指向自己并且带 active。
                 products_count 是控制器 withCount 出来的在售数，为 0 的分类点进去
                 只会看到空状态，不如不列。 --}}
            @if($categories->isNotEmpty())
            <nav class="category-filter" aria-label="按分类浏览">
                <a href="/" class="cat-btn active" aria-current="page">全部</a>
                @foreach($categories as $cat)
                    @if(($cat->products_count ?? 0) > 0)
                    <a href="/category/{{ $cat->slug }}" class="cat-btn">{{ $cat->name }}</a>
                    @endif
                @endforeach
            </nav>
            @endif
        </div>

        @if($products->isEmpty())
        <div class="empty-state text-center">
            @themeInclude('partials.image-placeholder', ['class' => 'empty-state-glyph'])
            <p>还没有上架任何商品。</p>
        </div>
        @else
        <div class="product-grid">
            @foreach($products as $product)
            @themeInclude('partials.product-card', ['product' => $product])
            @endforeach
        </div>
        @endif
    </section>

    @if($latestArticles->isNotEmpty())
    <section class="home-article-section">
        <div class="section-header">
            <div class="section-title-wrap">
                <h2 class="section-title">最新文章</h2>
            </div>
            {{-- 模板里这个链接是行内写死样式的，.home-ann-link 是同一副长相
                 （品牌色 + 箭头）且已经在样式表里，直接用它。 --}}
            <a href="/articles" class="home-ann-link">
                <span>全部文章</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="9 18 15 12 9 6"></polyline>
                </svg>
            </a>
        </div>

        <div class="article-simple-grid">
            @foreach($latestArticles->take(3) as $article)
            @php
                // summary 是运营自己写的摘要，没写才退回正文去取；正文可能是富文本，
                // 得先去标签，否则会把半个 <p> 截出来。
                $excerpt = trim(strip_tags((string) ($article->summary ?: $article->content)));
            @endphp
            <article class="article-visual-card">
                {{-- 目标模板这块海报放的是文章分类名。这里的 $latestArticles 没有预载
                     articleCategory，取一次就是一次额外查询（首页三张卡三条），
                     不值得，放一个恒真的静态标签就够了。 --}}
                <div class="article-visual-poster"><strong>文章</strong></div>
                <div class="article-visual-body">
                    <a href="/articles/{{ $article->slug }}" class="article-visual-title">{{ $article->title }}</a>
                    @if($excerpt !== '')
                    <p class="article-card-excerpt">{{ \Illuminate\Support\Str::limit($excerpt, 60) }}</p>
                    @endif
                    <div class="article-visual-date">{{ $article->created_at?->format('Y-m-d') }} · 点击阅读</div>
                </div>
            </article>
            @endforeach
        </div>
    </section>
    @endif

    @if(setting('contact_qr_image') || setting('contact_text'))
    <section class="contact-section">
        @if(setting('contact_qr_image'))
        <img src="{{ setting('contact_qr_image') }}" alt="客服联系二维码" class="qr-img"
             width="96" height="96" loading="lazy" decoding="async">
        @endif
        <div class="contact-text">
            <div class="contact-title">联系我们</div>
            @if(setting('contact_text'))
            <div class="contact-sub">{{ setting('contact_text') }}</div>
            @endif
            <div class="contact-hint">扫描二维码或点击右下角按钮联系客服</div>
        </div>
    </section>
    @endif

@endsection
