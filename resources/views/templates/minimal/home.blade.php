@extends(theme_view_path('layout'))
@section('title', $seoTitle)
@section('meta_description', setting('seo_default_description', '') ?: $siteDescription)
@section('meta_keywords', setting('seo_default_keywords', ''))
@section('canonical', $seoCanonical)

@section('structured_data')
@php
    $homeSchemaItems = $products->getCollection()
        ->map(fn ($p) => ['name' => (string) $p->name, 'url' => \App\Support\Seo::url('/product/' . $p->slug)])
        ->all();
@endphp
{{ \App\Support\Seo::jsonLdScript(\App\Support\Seo::homeSchema((string) $siteName, (string) (setting('seo_default_description') ?: $siteDescription), $homeSchemaItems)) }}
@endsection

@section('content')
<section class="n-store-bar">
    <div class="n-store-bar-copy">
        <div class="n-store-bar-title">
            <h1>{{ $siteName }}</h1>
            <span class="n-status n-status-paid"><span aria-hidden="true"></span>自动交付</span>
        </div>
        @if($siteDescription)
        <p class="n-store-bar-desc">{{ $siteDescription }}</p>
        @endif
    </div>
    <search class="n-store-bar-search">
        <form method="GET" action="{{ request()->url() }}" class="n-inline-search">
            <label for="global-product-search" class="n-sr">搜索全部商品</label>
            <div class="n-search-box">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5" stroke-linecap="round"/></svg>
                <input id="global-product-search" type="search" name="q" value="{{ request('q') }}" maxlength="200" placeholder="搜索全部商品…">
                @if(request()->filled('q'))
                <a href="{{ request()->url() }}" class="n-search-clear" aria-label="清空搜索">清空</a>
                @endif
            </div>
            <button class="n-button n-button-primary n-button-sm" type="submit">搜索</button>
        </form>
    </search>
</section>

@if($siteAnnouncement)
<section class="n-announcement" aria-label="站点公告">
    <span class="n-kicker">公告</span>
    <div class="n-rich">{!! \App\Support\ContentRenderer::toHtml($siteAnnouncement) !!}</div>
</section>
@endif

<section class="n-catalog" id="catalog">
    <div class="n-catalog-bar">
        <nav class="n-filter-bar" aria-label="商品分类">
            <a href="/#catalog" @class(['is-active' => !request()->filled('q')]) @if(!request()->filled('q')) aria-current="page" @endif>全部商品 <small>{{ $catalogTotal }}</small></a>
            @foreach($categories as $cat)
            <a href="/category/{{ $cat->slug }}">{{ $cat->name }} <small>{{ $cat->products_count ?? 0 }}</small></a>
            @endforeach
        </nav>
        <span class="n-list-meta">{{ request()->filled('q') ? '匹配 ' : '共 ' }}{{ $products->total() }} 件</span>
    </div>

    @if($products->isNotEmpty())
    <div class="n-catalog-grid" id="catalog-grid">
        @foreach($products as $product)
            @themeInclude('partials.product-card', ['product' => $product, 'lcp' => $loop->first])
        @endforeach
    </div>
    @else
    <div class="n-empty">
        @themeInclude('partials.image-placeholder')
        <h2>{{ request()->filled('q') ? '没有找到匹配商品' : '暂无上架商品' }}</h2>
        <p>{{ request()->filled('q') ? '试试更换其他关键词，或切换商品分类。' : '当前目录暂无可售商品。' }}</p>
        @if(request()->filled('q'))
        <a href="{{ request()->url() }}" class="n-button n-button-secondary n-button-sm">查看全部商品</a>
        @endif
    </div>
    @endif
</section>

{{ $products->links() }}

@if($latestArticles->isNotEmpty())
<section class="n-home-resources" aria-label="公告与使用指南">
    <div class="n-section-head">
        <h2>公告与指南</h2>
        <a href="/articles" class="n-button n-button-text n-button-sm">全部文章 →</a>
    </div>
    <div class="n-resource-grid">
        @foreach($latestArticles->take(3) as $article)
        @php
            $guideRaw = (string) preg_replace('/\[\[product:\d+\]\]/', ' ', (string) ($article->summary ?: $article->content));
            $guideRaw = (string) preg_replace('/<\/(?:h[1-6]|p|div|li|tr|blockquote)>|<br\s*\/?>/i', ' ', $guideRaw);
            $guideExcerpt = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($guideRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        @endphp
        <a href="/articles/{{ $article->slug }}" class="n-resource-card">
            <time datetime="{{ $article->created_at?->toDateString() }}">{{ $article->created_at?->format('Y.m.d') }}</time>
            <h3>{{ $article->title }}</h3>
            <p>{{ \Illuminate\Support\Str::limit($guideExcerpt ?: '点击查看完整说明文档。', 76) }}</p>
        </a>
        @endforeach
    </div>
</section>
@endif

@if(\App\Support\SafeUrl::asset(setting('contact_qr_image')) || setting('contact_text') || \App\Support\SafeUrl::contact(setting('contact_url')))
<section class="n-contact" aria-label="客户服务支持">
    <div>
        <h2>客户服务</h2>
        <p>{{ setting('contact_text') ?: '如遇下单、查单或售后问题，点击右侧二维码放大扫码联系客服。' }}</p>
        @if(\App\Support\SafeUrl::contact(setting('contact_url')))
        <a href="{{ \App\Support\SafeUrl::contact(setting('contact_url')) }}" class="n-button n-button-secondary n-button-sm" target="_blank" rel="noopener">联系在线客服 ↗</a>
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
