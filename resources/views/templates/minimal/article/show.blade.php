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
<div class="n-reading-wrap">
    <nav class="n-breadcrumb" aria-label="面包屑">
        <a href="/">商品目录</a><span aria-hidden="true">/</span>
        <a href="/articles">帮助中心</a>
        @if($article->articleCategory)
        <span aria-hidden="true">/</span>
        <a href="/articles/category/{{ $article->articleCategory->slug }}">{{ $article->articleCategory->name }}</a>
        @endif
        <span aria-hidden="true">/</span>
        <span aria-current="page">{{ $article->title }}</span>
    </nav>

    <article class="n-panel n-reading-article">
        <header class="n-reading-header">
            @if($article->articleCategory)
            <a href="/articles/category/{{ $article->articleCategory->slug }}" class="n-kicker">{{ $article->articleCategory->name }}</a>
            @endif
            <h1>{{ $article->title }}</h1>
            <div class="n-reading-meta">
                <time datetime="{{ $article->created_at->toDateString() }}">{{ $article->created_at->format('Y-m-d') }}</time>
                <span aria-hidden="true">·</span>
                <span>{{ $article->views }} 次阅读</span>
            </div>
        </header>

        @php $safeCover = \App\Support\SafeUrl::asset($article->cover_image); @endphp
        @if($safeCover)
        <figure class="n-reading-cover">
            <img src="{{ $safeCover }}" alt="{{ $article->title }} 封面图" decoding="async" fetchpriority="high">
        </figure>
        @endif

        <div class="n-rich n-reading-body">{!! $contentHtml !!}</div>

        <footer class="n-reading-footer">
            <a href="/articles" class="n-button n-button-text n-button-sm">← 返回文章列表</a>
            <a href="/" class="n-button n-button-secondary n-button-sm">浏览商品 →</a>
        </footer>
    </article>

    @if($relatedArticles->isNotEmpty())
    <section class="n-related" aria-label="相关阅读">
        <h2>相关阅读</h2>
        <div class="n-related-list">
            @foreach($relatedArticles as $related)
            <a href="/articles/{{ $related->slug }}">
                <span>{{ $related->title }}</span>
                <time datetime="{{ $related->created_at->toDateString() }}">{{ $related->created_at->format('Y-m-d') }}</time>
                <i aria-hidden="true">→</i>
            </a>
            @endforeach
        </div>
    </section>
    @endif
</div>
@endsection
