@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', \App\Support\Seo::url('/articles/' . $article->slug))
@section('og_image', (string) $article->cover_image)
@section('og_type', 'article')

@section('structured_data')
{{ \App\Support\Seo::jsonLdScript(\App\Support\Seo::articleSchema($article, (string) $seoDescription)) }}
@endsection

@section('content')
<nav class="m-breadcrumb m-article-breadcrumb" aria-label="面包屑">
    <a href="/">商品目录</a><span aria-hidden="true">/</span>
    <a href="/articles">帮助中心</a>
    @if($article->articleCategory)
    <span aria-hidden="true">/</span>
    <a href="/articles/category/{{ $article->articleCategory->slug }}">{{ $article->articleCategory->name }}</a>
    @endif
    <span aria-hidden="true">/</span>
    <span aria-current="page">{{ $article->title }}</span>
</nav>

<div class="m-article-layout m-article-reading-layout">
    <article class="m-article-paper">
        <header class="m-article-reading-header">
            @if($article->articleCategory)
            <a href="/articles/category/{{ $article->articleCategory->slug }}" class="m-article-category">{{ $article->articleCategory->name }}</a>
            @endif
            <h1 class="m-article-reading-title">{{ $article->title }}</h1>
            <div class="m-article-reading-meta">
                <time datetime="{{ $article->created_at->toDateString() }}">{{ $article->created_at->format('Y-m-d') }}</time>
                <span aria-hidden="true">·</span>
                <span>{{ $article->views }} 次阅读</span>
            </div>
        </header>

        @php $safeCover = \App\Support\SafeUrl::asset($article->cover_image); @endphp
        @if($safeCover)
        <figure class="m-article-cover">
            <img src="{{ $safeCover }}" alt="{{ $article->title }} 封面图" decoding="async" fetchpriority="high">
        </figure>
        @endif

        <div class="m-rich-text m-article-body">{!! $contentHtml !!}</div>

        <footer class="m-article-reading-footer">
            <a href="/articles" class="m-button m-button-quiet m-button-sm"><span aria-hidden="true">←</span> 返回文章列表</a>
            <a href="/" class="m-button m-button-secondary m-button-sm">前往商品目录 <span aria-hidden="true">↗</span></a>
        </footer>
    </article>

    <aside class="m-article-sidebar">
        @if($relatedArticles->isNotEmpty())
        <section class="m-article-sidebar-section">
            <h2 class="m-eyebrow">相关阅读</h2>
            <div class="m-article-related-list">
                @foreach($relatedArticles as $related)
                <a href="/articles/{{ $related->slug }}" class="m-article-related-link">
                    <span>{{ $related->title }}</span>
                    <time datetime="{{ $related->created_at->toDateString() }}">{{ $related->created_at->format('Y-m-d') }}</time>
                </a>
                @endforeach
            </div>
        </section>
        @endif

        <section class="m-article-quick-links">
            <a href="/" class="m-button m-button-primary m-button-wide m-button-sm">浏览全部商品 <span aria-hidden="true">→</span></a>
            <a href="/order/query" class="m-button m-button-secondary m-button-wide m-button-sm">查询已购订单</a>
        </section>
    </aside>
</div>
@endsection
