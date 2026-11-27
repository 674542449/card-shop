@extends(theme_view_path('layout'))
@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', $seoCanonical)

@section('structured_data')
@php
    $articleBreadcrumbs = [
        ['name' => '商品目录', 'url' => \App\Support\Seo::url('/')],
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
<header class="n-store-bar">
    <div class="n-store-bar-copy">
        <div class="n-store-bar-title">
            <h1>{{ $currentCategory ? $currentCategory->name : '公告与指南' }}</h1>
            <span class="n-status">{{ $articles->total() }} 篇</span>
        </div>
        <p class="n-store-bar-desc">店铺消息、商品说明与使用指南。</p>
    </div>
</header>

<nav class="n-filter-bar" aria-label="文章分类">
    <a href="/articles" @class(['is-active' => !$currentCategory]) @if(!$currentCategory) aria-current="page" @endif>全部文章</a>
    @foreach($categories as $cat)
    <a href="/articles/category/{{ $cat->slug }}" @class(['is-active' => $currentCategory && $currentCategory->id === $cat->id]) @if($currentCategory && $currentCategory->id === $cat->id) aria-current="page" @endif>{{ $cat->name }}</a>
    @endforeach
</nav>

@if($articles->isNotEmpty())
<div class="n-resource-grid n-resource-grid-index">
    @foreach($articles as $article)
    @php
        $summaryText = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('/\[\[product:\d+\]\]/', '', (string) $article->summary))));
        $safeCover = \App\Support\SafeUrl::asset($article->cover_image);
    @endphp
    <a href="/articles/{{ $article->slug }}" class="n-resource-card">
        @if($safeCover)
        <div class="n-resource-cover"><img src="{{ $safeCover }}" alt="" loading="lazy" decoding="async"></div>
        @endif
        <div class="n-resource-meta">
            <span>{{ $article->articleCategory->name ?? '商店公告' }}</span>
            <time datetime="{{ $article->created_at->toDateString() }}">{{ $article->created_at->format('Y-m-d') }}</time>
        </div>
        <h2>{{ $article->title }}</h2>
        @if($summaryText !== '')
        <p>{{ \Illuminate\Support\Str::limit($summaryText, 120) }}</p>
        @endif
    </a>
    @endforeach
</div>
{{ $articles->links() }}
@else
<div class="n-empty">
    @themeInclude('partials.image-placeholder')
    <h2>还没有文章</h2>
    <p>{{ $currentCategory ? '这个分类暂时没有内容，可以查看其他分类。' : '公告与使用指南发布后会显示在这里。' }}</p>
    <a href="/" class="n-button n-button-secondary n-button-sm">返回商品目录</a>
</div>
@endif
@endsection
