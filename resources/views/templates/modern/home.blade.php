@extends(theme_view_path('layout'))
@section('title', setting('seo_default_title', $siteName))
@section('meta_description', setting('seo_default_description', ''))
@section('meta_keywords', setting('seo_default_keywords', ''))
@section('structured_data')
@php
    $structuredData = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $siteName, 'url' => url('/'), 'description' => setting('seo_default_description', '')];
@endphp
<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
@section('content')
    <section class="m-home-intro">
        <div class="m-home-intro-copy">
            <span class="m-eyebrow"><span class="m-dot"></span> 为你的数字生活，准备就绪</span>
            <h1>好东西，<br>不必等待。</h1>
            <p class="m-lead">{{ $siteDescription ?: '挑选需要的数字商品，付款后自动领取卡密。简单几步，把时间留给更重要的事。' }}</p>
            <a class="m-button m-button-primary" href="#catalog">浏览商品 <span aria-hidden="true">↓</span></a>
            <a class="m-home-query" href="/order/query">已经购买？查询订单 <span aria-hidden="true">↗</span></a>
        </div>
        <div class="m-home-note">
            <svg class="m-home-art" viewBox="0 0 240 150" fill="none" aria-hidden="true">
                <rect x="50" y="35" width="130" height="82" rx="5" transform="rotate(-10 50 35)" fill="var(--m-clay-soft)" stroke="var(--m-clay)" stroke-width="1.3"/>
                <rect x="63" y="39" width="130" height="82" rx="5" transform="rotate(7 63 39)" fill="var(--m-surface)" stroke="var(--m-ink)" stroke-width="1.3"/>
                <path d="m78 65 99 12m-101 8 42 5m-44 10 23 3" stroke="var(--m-line-strong)" stroke-width="3" stroke-linecap="round"/>
                <path d="m151 101 7 9 18-15" stroke="var(--m-clay)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M205 31v12m-6-6h12M33 96v8m-4-4h8" stroke="var(--m-clay)" stroke-width="1.5"/>
            </svg>
            <div class="m-home-note-caption"><span class="m-eyebrow">一份数字商品的旅程</span><p>选好喜欢的，<br>剩下的交给我们。</p></div>
            <div class="m-home-note-steps"><span>01 挑选</span><span>02 支付</span><span>03 领取</span></div>
        </div>
    </section>
    @if($siteAnnouncement)
    <section class="m-home-announcement" aria-label="站点公告"><span class="m-eyebrow">店主的话</span><div class="m-rich-text">{!! \App\Support\ContentRenderer::toHtml($siteAnnouncement) !!}</div></section>
    @endif
    <section class="m-catalog-layout" id="catalog">
        <aside class="m-catalog-sidebar">
            <span class="m-eyebrow">商品分类</span>
            <nav class="m-category-nav" aria-label="按分类浏览">
                <a href="/" class="is-active" aria-current="page"><span>全部商品</span><span>{{ $catalogTotal }}</span></a>
                @foreach($categories as $cat)
                <a href="/category/{{ $cat->slug }}"><span>{{ $cat->name }}</span><span>{{ $cat->products_count ?? 0 }}</span></a>
                @endforeach
            </nav>
            <div class="m-sidebar-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6m-6 4h6"/></svg><h3>你的订单，一直在这里。</h3><p>凭下单邮箱和查询密码，可随时找回订单与卡密。</p><a href="/order/query">查询订单 <span aria-hidden="true">↗</span></a></div>
        </aside>
        <div class="m-catalog-main">
            <div class="m-catalog-toolbar"><div><h2 class="m-section-title">发现你的下一份好物</h2><p class="m-muted">{{ $products->total() }} 件商品 · 支付后自动发货</p></div>

            </div>
<form method="GET" action="{{ request()->url() }}" class="m-catalog-search-form" role="search">
 <label for="global-product-search">搜索全部商品</label><div class="m-catalog-search-controls"><input id="global-product-search" type="search" name="q" value="{{ request('q') }}" maxlength="200" placeholder="商品名称或分类"><button class="m-button m-button-primary" type="submit">搜索</button>@if(request()->filled('q'))<a href="{{ request()->url() }}">清空</a>@endif</div>
 </form>
            @if($products->isEmpty())
            <div class="m-empty">@themeInclude('partials.image-placeholder')<h3>{{ request()->filled("q") ? "没有找到匹配商品" : "好物正在准备中" }}</h3><p>试试其他关键词，或切换商品分类。</p></div>
            @else
            <div class="m-product-grid" id="catalog-grid">
                @foreach($products as $product)@themeInclude('partials.product-card', ['product' => $product])@endforeach
            </div>
            <div class="m-empty" id="catalog-empty" hidden><h3>没有找到匹配的商品</h3><p>试试其他关键词，或清空搜索看看全部商品。</p></div>
            <p class="m-sr-only" id="catalog-search-status" role="status" aria-live="polite"></p>
            @endif
        </div>
    </section>
    {{ $products->links() }}
    @if($latestArticles->isNotEmpty())
    <section class="m-home-journal"><div class="m-section-header"><div><span class="m-eyebrow">来自店铺</span><h2 class="m-section-title">一些消息，一点指南。</h2></div><a class="m-text-link" href="/articles">查看全部 <span aria-hidden="true">↗</span></a></div>
        <div class="m-journal-grid">
            @foreach($latestArticles->take(3) as $article)
            <a class="m-journal-item" href="/articles/{{ $article->slug }}"><time datetime="{{ $article->created_at?->toDateString() }}">{{ $article->created_at?->format('Y.m.d') }}</time><h3>{{ $article->title }}</h3><p>{{ \Illuminate\Support\Str::limit(trim(strip_tags((string) ($article->summary ?: $article->content))), 72) }}</p><span class="m-text-link">阅读文章 <span aria-hidden="true">↗</span></span></a>
            @endforeach
        </div>
    </section>
    @endif
    @if(\App\Support\SafeUrl::asset(setting('contact_qr_image')) || setting('contact_text'))
    <section class="m-home-contact">
        <div><span class="m-eyebrow">需要一点帮助？</span><h2 class="m-section-title">我们在这里。</h2>@if(setting('contact_text'))<p>{{ setting('contact_text') }}</p>@endif
            @if(\App\Support\SafeUrl::contact(setting('contact_url')))<a class="m-text-link" href="{{ \App\Support\SafeUrl::contact(setting('contact_url')) }}" target="_blank" rel="noopener">联系客服 ↗</a>@endif
        </div>
        @if(\App\Support\SafeUrl::asset(setting('contact_qr_image')))<img src="{{ \App\Support\SafeUrl::asset(setting('contact_qr_image')) }}" alt="客服联系二维码" width="100" height="100" loading="lazy" decoding="async">@endif
    </section>
    @endif
@endsection
