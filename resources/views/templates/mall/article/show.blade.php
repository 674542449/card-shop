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
    <nav class="ml-breadcrumb" aria-label="面包屑">
        <ol>
            <li><a href="/">商城首页</a></li>
            <li><a href="/articles">帮助中心</a></li>
            @if($article->articleCategory)
            <li><a href="/articles/category/{{ $article->articleCategory->slug }}">{{ $article->articleCategory->name }}</a></li>
            @endif
            <li><a href="/articles/{{ $article->slug }}" aria-current="page">{{ $article->title }}</a></li>
        </ol>
    </nav>

    <div class="ml-article-grid-layout ml-article-detail-layout">
        <article class="ml-card ml-article-paper">
            <header class="ml-article-head">
                <div class="ml-article-meta">
                    @if($article->articleCategory)
                    <a href="/articles/category/{{ $article->articleCategory->slug }}" class="ml-badge ml-badge-brand">{{ $article->articleCategory->name }}</a>
                    @endif
                    <time datetime="{{ $article->created_at->toDateString() }}">发布于 {{ $article->created_at->format('Y-m-d') }}</time>
                    <span aria-hidden="true">·</span>
                    <span>{{ $article->views }} 次阅读</span>
                </div>
                <h1>{{ $article->title }}</h1>
            </header>

            @php $safeCover = \App\Support\SafeUrl::asset($article->cover_image); @endphp
            @if($safeCover)
            <figure class="ml-article-cover">
                <img src="{{ $safeCover }}" alt="{{ $article->title }} 封面图" width="960" height="540" fetchpriority="high" decoding="async">
            </figure>
            @endif

            <div class="ml-rich ml-article-body">{!! $contentHtml !!}</div>

            <footer class="ml-article-foot">
                <a href="/articles" class="ml-btn ml-btn-secondary ml-btn-sm">← 返回帮助中心</a>
                <a href="/" class="ml-btn ml-btn-primary ml-btn-sm">前往商品目录 →</a>
            </footer>
        </article>

        <aside class="ml-article-aside" aria-label="相关内容">
            @if($relatedArticles->isNotEmpty())
            <section class="ml-card ml-aside-card">
                <h2 class="ml-aside-title">相关指南</h2>
                <div class="ml-related-list">
                    @foreach($relatedArticles as $related)
                    <a href="/articles/{{ $related->slug }}" class="ml-related-item">
                        <strong>{{ $related->title }}</strong>
                        <time datetime="{{ $related->created_at->toDateString() }}">{{ $related->created_at->format('Y-m-d') }}</time>
                    </a>
                    @endforeach
                </div>
            </section>
            @endif

            <section class="ml-card ml-aside-card ml-aside-cta">
                <h2 class="ml-aside-title">快速直达</h2>
                <div class="ml-aside-actions">
                    <a href="/" class="ml-btn ml-btn-primary ml-btn-wide ml-btn-sm">浏览全部商品 →</a>
                    <a href="/order/query" class="ml-btn ml-btn-secondary ml-btn-wide ml-btn-sm">查询已购订单</a>
                </div>
            </section>
        </aside>
    </div>
@endsection
