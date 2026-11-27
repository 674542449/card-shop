@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', $seoCanonical)

@section('structured_data')
@php
    $articleBreadcrumbs = [
        ['name' => '商品目录', 'url' => \App\Support\Seo::url('/')],
        ['name' => '帮助中心', 'url' => \App\Support\Seo::url('/articles')],
    ];
    if ($currentCategory) {
        $articleBreadcrumbs[] = [
            'name' => (string) $currentCategory->name,
            'url' => \App\Support\Seo::url('/articles/category/' . $currentCategory->slug),
        ];
    }
    $articleSchemaItems = $articles->getCollection()
        ->map(fn ($a) => ['name' => (string) $a->title, 'url' => \App\Support\Seo::url('/articles/' . $a->slug)])
        ->all();
@endphp
{{ \App\Support\Seo::jsonLdScript(\App\Support\Seo::collectionSchema(
    $currentCategory ? (string) $currentCategory->name : '公告与帮助文档',
    $seoCanonical,
    (string) $seoDescription,
    $articleBreadcrumbs,
    $articleSchemaItems,
)) }}
@endsection

@section('content')
    <nav class="ws-breadcrumb" aria-label="面包屑导航">
        <a href="/">商品目录</a>
        <span class="sep">/</span>
        @if($currentCategory)
        <a href="/articles">帮助中心</a>
        <span class="sep">/</span>
        <span class="current">{{ $currentCategory->name }}</span>
        @else
        <span class="current">帮助中心</span>
        @endif
    </nav>

    <div class="ws-editorial-layout">
        <aside class="ws-rail">
            <div class="ws-rail-box">
                <div class="ws-rail-heading">文档分类</div>
                <nav class="ws-cat-nav" aria-label="文档分类">
                    <a href="/articles" class="ws-cat-link {{ !$currentCategory ? 'is-active' : '' }}" @if(!$currentCategory) aria-current="page" @endif>
                        <span class="ws-cat-link-left"><span>全部文档</span></span>
                        <span class="ws-cat-count">{{ $totalPublishedArticles ?? $articles->total() }}</span>
                    </a>
                    @foreach($categories as $cat)
                    <a href="/articles/category/{{ $cat->slug }}"
                       class="ws-cat-link {{ $currentCategory && $currentCategory->id === $cat->id ? 'is-active' : '' }}"
                       @if($currentCategory && $currentCategory->id === $cat->id) aria-current="page" @endif>
                        <span class="ws-cat-link-left"><span>{{ $cat->name }}</span></span>
                        <span class="ws-cat-count">{{ $cat->articles_count ?? 0 }}</span>
                    </a>
                    @endforeach
                </nav>
            </div>
        </aside>

        <div class="ws-stage">
            <div class="ws-cat-banner" style="padding:18px 22px;">
                <div class="ws-cat-banner-top" style="align-items:center;">
                    <div>
                        <h1 style="margin:0;font-size:20px;">{{ $currentCategory ? $currentCategory->name : '公告与帮助文档' }}</h1>
                    </div>
                    <span class="ws-pill ws-pill-neutral">共 {{ $articles->total() }} 篇</span>
                </div>
            </div>

            @if($articles->count() > 0)
            <div class="ws-article-stack">
                @foreach($articles as $article)
                @php
                    $rawExcerpt = (string) ($article->summary ?: $article->content);
                    $cleanExcerpt = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('/\[\[product:\d+\]\]/', '', $rawExcerpt))));
                    $coverImg = \App\Support\SafeUrl::asset($article->cover_image);
                @endphp
                <article class="ws-article-card {{ $coverImg ? 'has-cover' : '' }}">
                    @if($coverImg)
                    <a href="/articles/{{ $article->slug }}" class="ws-article-thumb" tabindex="-1" aria-hidden="true">
                        <img src="{{ $coverImg }}" alt="" width="640" height="360" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async">
                    </a>
                    @endif
                    <div class="ws-article-card-body">
                        <div class="ws-article-card-top">
                            @if($article->articleCategory)
                            <span class="ws-pill ws-pill-accent">{{ $article->articleCategory->name }}</span>
                            @endif
                            <span class="ws-inline-icon"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg><time datetime="{{ $article->created_at?->toDateString() }}">{{ $article->created_at?->format('Y-m-d') }}</time></span>
                            <span class="ws-inline-icon"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>阅读量 {{ (int) $article->views }}</span>
                        </div>
                        <h2 class="ws-article-heading"><a href="/articles/{{ $article->slug }}" class="ws-article-card-title">{{ $article->title }}</a></h2>
                        @if($cleanExcerpt !== '')
                        <p>{{ \Illuminate\Support\Str::limit($cleanExcerpt, 120) }}</p>
                        @endif
                    </div>
                </article>
                @endforeach
            </div>

            <div class="pagination-wrap">{{ $articles->links() }}</div>
            @else
            <div class="ws-empty">
                @themeInclude('partials.image-placeholder', ['class' => 'empty-state-glyph'])
                <h3>暂无已发布的文档</h3>
                <p>当前分类下暂无相关说明文档。</p>
                <a href="/" class="ws-btn ws-btn-primary ws-btn-sm">返回商品目录</a>
            </div>
            @endif
        </div>
    </div>
@endsection
