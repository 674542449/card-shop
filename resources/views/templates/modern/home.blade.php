@extends(theme_view_path('layout'))
@section('title', $seoTitle)
@section('meta_description', setting('seo_default_description', '') ?: $siteDescription)
@section('canonical', $seoCanonical)
@section('meta_keywords', setting('seo_default_keywords', ''))

@section('structured_data')
@php
    $homeSchemaItems = $products->getCollection()
        ->map(fn ($p) => ['name' => (string) $p->name, 'url' => \App\Support\Seo::url('/product/' . $p->slug)])
        ->all();
@endphp
{{ \App\Support\Seo::jsonLdScript(\App\Support\Seo::homeSchema((string) $siteName, (string) (setting('seo_default_description') ?: $siteDescription), $homeSchemaItems)) }}
@endsection

@section('content')
    <section class="m-store-strip">
        <div class="m-store-strip-copy">
            <div class="m-store-strip-title">
                <h1>{{ $siteName }}</h1>
                <span class="m-pill m-pill-success"><span class="m-dot" aria-hidden="true"></span>自动交付</span>
            </div>
            @if($siteDescription)
            <p class="m-store-strip-desc">{{ $siteDescription }}</p>
            @endif
        </div>
        <search class="m-store-strip-search">
            <form method="GET" action="{{ request()->url() }}" class="m-inline-search">
                <label for="global-product-search" class="m-sr-only">搜索全部商品</label>
                <div class="m-search-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5" stroke-linecap="round"/></svg>
                    <input id="global-product-search" type="search" name="q" value="{{ request('q') }}" maxlength="200" placeholder="搜索全部商品…">
                    @if(request()->filled('q'))
                    <a href="{{ request()->url() }}" class="m-search-clear" aria-label="清空搜索">清空</a>
                    @endif
                </div>
                <button class="m-button m-button-primary m-button-sm" type="submit">搜索</button>
            </form>
        </search>
    </section>

    @if($siteAnnouncement)
    <section class="m-home-announcement" aria-label="站点公告">
        <span class="m-eyebrow">公告</span>
        <div class="m-rich-text">{!! \App\Support\ContentRenderer::toHtml($siteAnnouncement) !!}</div>
    </section>
    @endif

    <section class="m-catalog-layout" id="catalog">
        <aside class="m-catalog-sidebar" aria-label="商品分类导航">
            <span class="m-eyebrow">商品分类</span>
            <nav class="m-category-nav" aria-label="按分类浏览">
                <a href="/" @class(['is-active' => !request()->filled('q')]) @if(!request()->filled('q')) aria-current="page" @endif>
                    <span>全部商品</span>
                    <span>{{ $catalogTotal }}</span>
                </a>
                @foreach($categories as $cat)
                <a href="/category/{{ $cat->slug }}">
                    <span>{{ $cat->name }}</span>
                    <span>{{ $cat->products_count ?? 0 }}</span>
                </a>
                @endforeach
            </nav>
        </aside>

        <div class="m-catalog-main">
            <div class="m-catalog-toolbar">
                <div>
                    <h2 class="m-section-title">{{ request()->filled('q') ? '「' . request('q') . '」的检索结果' : '在售商品' }}</h2>
                </div>
                <span class="m-muted">共 {{ $products->total() }} 件 · 付款后自动发货</span>
            </div>

            @if($products->isEmpty())
            <div class="m-empty">
                @themeInclude('partials.image-placeholder')
                <h3>{{ request()->filled('q') ? '没有找到匹配商品' : '暂无上架商品' }}</h3>
                <p>{{ request()->filled('q') ? '试试更换其他关键词，或浏览全部分类。' : '当前目录暂无可售商品。' }}</p>
                @if(request()->filled('q'))
                <a href="{{ request()->url() }}" class="m-button m-button-secondary m-button-sm">查看全部商品</a>
                @endif
            </div>
            @else
            <div class="m-product-grid" id="catalog-grid">
                @foreach($products as $product)
                    @themeInclude('partials.product-card', ['product' => $product, 'lcp' => $loop->first])
                @endforeach
            </div>
            @endif

            {{ $products->links() }}
        </div>
    </section>

    @if($latestArticles->isNotEmpty())
    <section class="m-home-journal" aria-label="公告与帮助指南">
        <div class="m-section-header">
            <h2 class="m-section-title">公告与指南</h2>
            <a class="m-text-link" href="/articles">查看全部 <span aria-hidden="true">↗</span></a>
        </div>
        <div class="m-journal-grid">
            @foreach($latestArticles->take(3) as $article)
            @php
                $guideRaw = (string) preg_replace('/\[\[product:\d+\]\]/', ' ', (string) ($article->summary ?: $article->content));
                $guideRaw = (string) preg_replace('/<\/(?:h[1-6]|p|div|li|tr|blockquote)>|<br\s*\/?>/i', ' ', $guideRaw);
                $guideExcerpt = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($guideRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            @endphp
            <a class="m-journal-item" href="/articles/{{ $article->slug }}">
                <time datetime="{{ $article->created_at?->toDateString() }}">{{ $article->created_at?->format('Y.m.d') }}</time>
                <h3>{{ $article->title }}</h3>
                <p>{{ \Illuminate\Support\Str::limit($guideExcerpt ?: '点击查看完整说明文档。', 68) }}</p>
            </a>
            @endforeach
        </div>
    </section>
    @endif

    @if(\App\Support\SafeUrl::asset(setting('contact_qr_image')) || setting('contact_text') || \App\Support\SafeUrl::contact(setting('contact_url')))
    <section class="m-home-contact" aria-label="客户服务支持">
        <div>
            <h2 class="m-section-title">需要协助？</h2>
            <p>{{ setting('contact_text') ?: '如遇下单、查单或售后问题，点击右侧二维码放大扫码联系客服。' }}</p>
            @if(\App\Support\SafeUrl::contact(setting('contact_url')))
            <a class="m-text-link" href="{{ \App\Support\SafeUrl::contact(setting('contact_url')) }}" target="_blank" rel="noopener">联系在线客服 <span aria-hidden="true">↗</span></a>
            @endif
        </div>
        @if(\App\Support\SafeUrl::asset(setting('contact_qr_image')))
        <button type="button" class="cs-qr-trigger" command="show-modal" commandfor="contact-qr-dialog" aria-label="点击放大查看客服二维码" title="点击放大查看客服二维码">
            <img src="{{ \App\Support\SafeUrl::asset(setting('contact_qr_image')) }}" alt="客服联系二维码" width="84" height="84" loading="lazy" decoding="async">
            <span class="cs-qr-zoom-badge" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35M11 8v6M8 11h6"/></svg>
            </span>
        </button>
        @endif
    </section>
    @endif
@endsection
