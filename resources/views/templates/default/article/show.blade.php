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
    <nav class="ws-breadcrumb" aria-label="面包屑导航">
        <a href="/">商品目录</a>
        <span class="sep">/</span>
        <a href="/articles">帮助中心</a>
        @if($article->articleCategory)
        <span class="sep">/</span>
        <a href="/articles/category/{{ $article->articleCategory->slug }}">{{ $article->articleCategory->name }}</a>
        @endif
        <span class="sep">/</span>
        <span class="current">{{ $article->title }}</span>
    </nav>

    <div class="ws-reader-layout">
        <article class="ws-reader-card">
            <h1>{{ $article->title }}</h1>

            <div class="ws-reader-meta">
                @if($article->articleCategory)
                <a href="/articles/category/{{ $article->articleCategory->slug }}" class="ws-pill ws-pill-accent">
                    {{ $article->articleCategory->name }}
                </a>
                @endif
                <time datetime="{{ $article->created_at?->toDateString() }}">发布日期 {{ $article->created_at?->format('Y-m-d') }}</time>
                <span>&middot;</span>
                <span>阅读量 {{ $article->views }}</span>
            </div>

            @php $safeArticleCover = \App\Support\SafeUrl::asset($article->cover_image); @endphp
            @if($safeArticleCover)
            <div class="ws-reader-cover">
                <img src="{{ $safeArticleCover }}" alt="{{ $article->title }} 封面图"
                     width="640" height="360" decoding="async" fetchpriority="high">
            </div>
            @endif

            <div class="rich-text">
                {!! $contentHtml !!}
            </div>
        </article>

        <aside style="display:flex;flex-direction:column;gap:14px;">
            @if($relatedArticles->count() > 0)
            <div class="ws-panel-card">
                <div class="ws-panel-head">
                    <h2>相关文档</h2>
                </div>
                <ul class="ws-related-list">
                    @foreach($relatedArticles as $related)
                    <li>
                        <a href="/articles/{{ $related->slug }}">{{ $related->title }}</a>
                        <time datetime="{{ $related->created_at?->toDateString() }}">{{ $related->created_at?->format('Y-m-d') }}</time>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            <a href="/articles" class="ws-btn ws-btn-secondary" style="width:100%;">&larr; 返回文档列表</a>
            <a href="/" class="ws-btn ws-btn-primary" style="width:100%;">前往商品目录 &rarr;</a>
        </aside>
    </div>
@endsection
