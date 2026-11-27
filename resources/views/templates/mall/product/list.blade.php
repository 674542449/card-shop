@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', $seoCanonical)

@section('structured_data')
@php
    $categorySchemaItems = $products->getCollection()
        ->map(fn ($p) => ['name' => (string) $p->name, 'url' => \App\Support\Seo::url('/product/' . $p->slug)])
        ->all();
@endphp
{{ \App\Support\Seo::jsonLdScript(\App\Support\Seo::collectionSchema(
    (string) $category->name,
    $seoCanonical,
    (string) $seoDescription,
    [
        ['name' => '商品目录', 'url' => \App\Support\Seo::url('/')],
        ['name' => (string) $category->name, 'url' => \App\Support\Seo::url('/category/' . $category->slug)],
    ],
    $categorySchemaItems,
)) }}
@endsection

@section('content')
    <nav class="ml-breadcrumb" aria-label="面包屑">
        <ol>
            <li><a href="/">商城首页</a></li>
            <li><a href="/category/{{ $category->slug }}" aria-current="page">{{ $category->name }}</a></li>
        </ol>
    </nav>

    <section class="ml-dept-header">
        <div class="ml-dept-copy">
            <div class="ml-hero-title-row">
                <h1>{{ $category->name }}</h1>
                <span class="ml-badge ml-badge-neutral">共 {{ $products->total() }} 款</span>
            </div>
            @if($category->description)
            <p class="ml-hero-desc">{{ $category->description }}</p>
            @endif
        </div>

        <div class="ml-dept-search">
            <search>
                <form method="GET" action="{{ request()->url() }}" class="ml-search-form">
                    <label for="global-product-search" class="ml-sr-only">搜索当前分类</label>
                    <div class="ml-search-field">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="11" cy="11" r="7"/>
                            <path d="M20 20l-3.5-3.5"/>
                        </svg>
                        <input id="global-product-search" type="search" name="q" value="{{ request('q') }}" maxlength="200" placeholder="在「{{ $category->name }}」中搜索…">
                        @if(request()->filled('q'))
                        <a href="{{ request()->url() }}" class="ml-search-clear" aria-label="清空搜索">清空</a>
                        @endif
                    </div>
                    <button type="submit" class="ml-btn ml-btn-primary ml-btn-sm">搜索</button>
                </form>
            </search>
            <a class="ml-link ml-dept-all-link" href="{{ url('/') . (request()->filled('q') ? '?' . http_build_query(['q' => request('q')]) : '') }}">在全部商品中搜索 →</a>
        </div>
    </section>

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
        <h2>{{ request()->filled('q') ? '当前分类未找到匹配商品' : '该分类暂无上架商品' }}</h2>
        <p>{{ request()->filled('q') ? '试试清空搜索词，或在全部商品中搜索。' : '去其他分类逛逛，浏览更多精选数字商品。' }}</p>
        <a href="/" class="ml-btn ml-btn-primary ml-btn-sm">浏览全部商品</a>
    </div>
    @endif
@endsection
