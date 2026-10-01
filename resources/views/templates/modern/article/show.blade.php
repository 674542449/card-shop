@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/articles/' . $article->slug))
@section('og_type', 'article')

@section('structured_data')
@php
    $structuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $article->title,
        'description' => $article->seo_description
            ?: $article->summary
            ?: \Illuminate\Support\Str::limit(strip_tags($contentHtml), 200),
        'url' => url('/articles/' . $article->slug),
        'datePublished' => $article->created_at?->toIso8601String(),
        'dateModified' => $article->updated_at?->toIso8601String(),
        'publisher' => [
            '@type' => 'Organization',
            'name' => setting('site_name', 'CardShop'),
        ],
    ];
    if ($article->cover_image) {
        $structuredData['image'] = url($article->cover_image);
    }
@endphp
<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@section('content')
<nav class="m-breadcrumb m-article-breadcrumb" aria-label="面包屑">
    <a href="/">商店</a><span aria-hidden="true">/</span><a href="/articles">公告与指南</a>
</nav>

<div class="m-article-layout m-article-reading-layout">
    <article class="m-article-paper">
        <header class="m-article-reading-header">
            @if($article->articleCategory)
            <a href="/articles/category/{{ $article->articleCategory->slug }}" class="m-article-category">{{ $article->articleCategory->name }}</a>
            @else
            <span class="m-eyebrow">商店笔记</span>
            @endif
            <h1 class="m-article-reading-title">{{ $article->title }}</h1>
            <div class="m-article-reading-meta">
                <time datetime="{{ $article->created_at->toDateString() }}">{{ $article->created_at->format('Y 年 m 月 d 日') }}</time>
                <span aria-hidden="true">·</span>
                <span>{{ $article->views }} 次阅读</span>
            </div>
        </header>

        @if($article->cover_image)
        <figure class="m-article-cover">
            <img src="{{ $article->cover_image }}" alt="{{ $article->title }} 封面图" decoding="async" fetchpriority="high">
        </figure>
        @endif

        <div class="m-rich-text m-article-body">{!! $contentHtml !!}</div>

        <footer class="m-article-reading-footer">
            <a href="/articles" class="m-button m-button-quiet"><span aria-hidden="true">←</span> 返回文章列表</a>
            <a href="/" class="m-button m-button-secondary">去挑选商品 <span aria-hidden="true">↗</span></a>
        </footer>
    </article>

    <aside class="m-article-sidebar">
        @if($relatedArticles->isNotEmpty())
        <section class="m-article-sidebar-section">
            <h2 class="m-eyebrow">继续阅读</h2>
            <div class="m-article-related-list">
                @foreach($relatedArticles as $related)
                <a href="/articles/{{ $related->slug }}" class="m-article-related-link">
                    <span>{{ $related->title }}</span>
                    <time datetime="{{ $related->created_at->toDateString() }}">{{ $related->created_at->format('Y.m.d') }}</time>
                </a>
                @endforeach
            </div>
        </section>
        @endif

        <section class="m-article-note m-panel m-panel-pad">
            <span class="m-article-note-mark" aria-hidden="true">✳</span>
            <h2 class="m-section-title">回到商店</h2>
            <p class="m-muted">找到需要的商品，或者查看已有订单。</p>
            <div class="m-article-note-links">
                <a href="/" class="m-button m-button-quiet">挑选商品 <span aria-hidden="true">→</span></a>
                <a href="/order/query" class="m-button m-button-quiet">查询订单 <span aria-hidden="true">→</span></a>
            </div>
        </section>
    </aside>
</div>
@endsection