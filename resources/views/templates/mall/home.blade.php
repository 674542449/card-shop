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
    <section class="ml-hero-bar">
        <div class="ml-hero-copy">
            <div class="ml-hero-title-row">
                <h1>{{ request()->filled('q') ? '搜索「' . request('q') . '」' : $siteName }}</h1>
                <span class="ml-badge ml-badge-live"><i aria-hidden="true"></i>24/7 自动发货</span>
            </div>
            @if($siteDescription)
            <p class="ml-hero-desc">{{ $siteDescription }}</p>
            @endif
        </div>
        <ul class="ml-trust-list" aria-label="商城服务保障">
            <li>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m13 3-8 11h6l-1 7 9-12h-6l1-6Z" stroke-linejoin="round"/></svg>
                <span>付款即发卡</span>
            </li>
            <li>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>密码自助查单</span>
            </li>
            <li>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <span>多通道核验</span>
            </li>
        </ul>
    </section>

    @if($siteAnnouncement)
    <section class="ml-announcement" aria-label="站点公告">
        <span class="ml-announcement-tag">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <span>店铺公告</span>
        </span>
        <div class="ml-rich">{!! \App\Support\ContentRenderer::toHtml($siteAnnouncement) !!}</div>
    </section>
    @endif

    @if($categories->isNotEmpty() && !request()->filled('q'))
    <section class="ml-cat-shelf" aria-labelledby="ml-cat-shelf-title">
        <div class="ml-section-head">
            <h2 id="ml-cat-shelf-title" class="ml-heading-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
                <span>精选分类</span>
            </h2>
            <span class="ml-muted">共 {{ $categories->count() }} 个品类 · {{ $catalogTotal }} 款商品</span>
        </div>
        <div class="ml-cat-grid">
            @foreach($categories as $category)
            @php $catImg = \App\Support\SafeUrl::asset($category->image); @endphp
            <a href="/category/{{ $category->slug }}" class="ml-cat-tile">
                <span class="ml-cat-tile-icon" aria-hidden="true">
                    @if($catImg)
                    <img src="{{ $catImg }}" alt="" width="40" height="40" decoding="async">
                    @else
                    <span>{{ mb_substr($category->name, 0, 1) }}</span>
                    @endif
                </span>
                <div class="ml-cat-tile-meta">
                    <strong>{{ $category->name }}</strong>
                    <span>{{ $category->products_count ?? 0 }} 款在售</span>
                </div>
            </a>
            @endforeach
        </div>
    </section>
    @endif

    <section class="ml-catalog-section" id="catalog" aria-labelledby="ml-catalog-title">
        <div class="ml-section-head">
            <h2 id="ml-catalog-title" class="ml-heading-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                <span>{{ request()->filled('q') ? '匹配商品' : '全部在售商品' }}</span>
            </h2>
            <span class="ml-muted">共 {{ $products->total() }} 款 · 支持在线提取与邮件送达</span>
        </div>

        @if($products->isNotEmpty())
        <div class="ml-product-grid" id="catalog-grid">
            @foreach($products as $product)
                @themeInclude('partials.product-card', ['product' => $product, 'lcp' => $loop->index < 2, 'eager' => $loop->index < 4])
            @endforeach
        </div>
        {{ $products->links() }}
        @else
        <div class="ml-empty">
            @themeInclude('partials.image-placeholder')
            <h3>{{ request()->filled('q') ? '未找到符合条件的商品' : '暂无上架商品' }}</h3>
            <p>{{ request()->filled('q') ? '请尝试更换关键词，或清空搜索查看全部商品。' : '商品正在筹备上架中，请稍后再来。' }}</p>
            @if(request()->filled('q'))
            <a href="/" class="ml-btn ml-btn-primary ml-btn-sm">查看全部商品</a>
            @endif
        </div>
        @endif
    </section>

    @if($latestArticles->isNotEmpty())
    <section class="ml-guides-section ml-deferred-section" aria-labelledby="ml-guides-title">
        <div class="ml-section-head">
            <h2 id="ml-guides-title" class="ml-heading-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                <span>公告与使用指南</span>
            </h2>
            <a href="/articles" class="ml-link">查看全部帮助文档 →</a>
        </div>
        <div class="ml-guide-grid">
            @foreach($latestArticles->take(3) as $article)
            @php
                $guideRaw = (string) preg_replace('/\[\[product:\d+\]\]/', ' ', (string) ($article->summary ?: $article->content));
                $guideRaw = (string) preg_replace('/<\/(?:h[1-6]|p|div|li|tr|blockquote)>|<br\s*\/?>/i', ' ', $guideRaw);
                $guideExcerpt = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($guideRaw), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            @endphp
            <article class="ml-guide-card">
                <div class="ml-guide-meta">
                    @if($article->articleCategory)
                    <span class="ml-badge ml-badge-neutral">{{ $article->articleCategory->name }}</span>
                    @endif
                    <time datetime="{{ $article->created_at?->toDateString() }}">{{ $article->created_at?->format('Y-m-d') }}</time>
                </div>
                <h3><a class="ml-card-link" href="/articles/{{ $article->slug }}">{{ $article->title }}</a></h3>
                <p>{{ \Illuminate\Support\Str::limit($guideExcerpt ?: '点击查看完整说明与使用教程。', 76) }}</p>
            </article>
            @endforeach
        </div>
    </section>
    @endif

    @if(\App\Support\SafeUrl::asset(setting('contact_qr_image')) || setting('contact_text') || \App\Support\SafeUrl::contact(setting('contact_url')))
    <section class="ml-support-section ml-deferred-section" aria-label="客户服务支持">
        <div class="ml-support-left">
            @if(\App\Support\SafeUrl::asset(setting('contact_qr_image')))
            <button type="button" class="cs-qr-trigger" command="show-modal" commandfor="contact-qr-dialog" aria-label="点击放大查看客服二维码" title="点击放大查看客服二维码">
                <img src="{{ \App\Support\SafeUrl::asset(setting('contact_qr_image')) }}" alt="客服联系二维码" width="76" height="76" loading="lazy" decoding="async" class="ml-support-qr">
                <span class="cs-qr-zoom-badge" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35M11 8v6M8 11h6"/></svg>
                </span>
            </button>
            @else
            <span class="ml-support-badge" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5z"/></svg>
            </span>
            @endif
            <div>
                <h2>需要购物协助或售后支持？</h2>
                <p>{{ setting('contact_text') ?: '如遇支付核对或卡密使用问题，请联系在线客服并提供订单编号。' }}</p>
            </div>
        </div>
        <div class="ml-support-actions">
            <a href="/order/query" class="ml-btn ml-btn-secondary ml-btn-sm">自助查询订单</a>
            @if(\App\Support\SafeUrl::contact(setting('contact_url')))
            <a href="{{ \App\Support\SafeUrl::contact(setting('contact_url')) }}" class="ml-btn ml-btn-primary ml-btn-sm" target="_blank" rel="noopener">联系在线客服 ↗</a>
            @endif
        </div>
    </section>
    @endif
@endsection
