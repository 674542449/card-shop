@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', $seoCanonical)

@section('structured_data')
@php
    $articleBreadcrumbs = [
        ['name' => '首页', 'url' => \App\Support\Seo::url('/')],
        ['name' => '公告与指南', 'url' => \App\Support\Seo::url('/articles')],
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
    $currentCategory ? (string) $currentCategory->name : '公告与指南',
    $seoCanonical,
    (string) $seoDescription,
    $articleBreadcrumbs,
    $articleSchemaItems,
)) }}
@endsection

@section('content')
<header class="m-article-index-heading">
    <div class="m-store-strip-title">
        <h1 class="m-page-heading">{{ $currentCategory ? $currentCategory->name : '公告与指南' }}</h1>
        <span class="m-pill m-pill-muted">{{ $articles->total() }} 篇</span>
    </div>
    <p class="m-store-strip-desc">商品使用说明、常见问题与店铺公告。</p>
</header>

<div class="m-article-layout">
    <section class="m-article-list" aria-label="文章列表">
        @forelse($articles as $article)
        @php
            $summaryText = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('/\[\[product:\d+\]\]/', '', (string) $article->summary))));
        @endphp
        <a href="/articles/{{ $article->slug }}" class="m-article-row">
            <time class="m-article-row-date" datetime="{{ $article->created_at->toDateString() }}">
                <span>{{ $article->created_at->format('m.d') }}</span>
                <small>{{ $article->created_at->format('Y') }}</small>
            </time>
            <div class="m-article-row-content">
                @if($article->articleCategory)
                <span class="m-article-category">{{ $article->articleCategory->name }}</span>
                @endif
                <h2 class="m-article-row-title">{{ $article->title }}</h2>
                @if($summaryText !== '')
                <p class="m-article-row-summary">{{ \Illuminate\Support\Str::limit($summaryText, 140) }}</p>
                @endif
            </div>
            <span class="m-article-row-arrow" aria-hidden="true">↗</span>
        </a>
        @empty
        <div class="m-empty m-article-empty">
            <h2 class="m-section-title">这里还没有文章</h2>
            <p class="m-muted">{{ $currentCategory ? '这个分类下暂时没有内容，可以看看其他分类。' : '公告和使用指南发布后，会出现在这里。' }}</p>
            <a href="/" class="m-button m-button-secondary m-button-sm">返回商品目录</a>
        </div>
        @endforelse

        @if($articles->hasPages())
        <div class="m-article-pagination">{{ $articles->links() }}</div>
        @endif
    </section>

    <aside class="m-article-sidebar">
        <section class="m-article-sidebar-section">
            <h2 class="m-eyebrow">文章分类</h2>
            <nav class="m-article-category-nav" aria-label="文章分类">
                <a href="/articles" @class(['is-active' => ! $currentCategory]) @if(! $currentCategory) aria-current="page" @endif>
                    <span>全部文章</span><span aria-hidden="true">↗</span>
                </a>
                @foreach($categories as $cat)
                <a href="/articles/category/{{ $cat->slug }}" @class(['is-active' => $currentCategory && $currentCategory->id === $cat->id]) @if($currentCategory && $currentCategory->id === $cat->id) aria-current="page" @endif>
                    <span>{{ $cat->name }}</span><span aria-hidden="true">↗</span>
                </a>
                @endforeach
            </nav>
        </section>
    </aside>
</div>
@endsection
