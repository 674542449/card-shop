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

@php
    $allCategories = \App\Models\Category::active()
        ->ordered()
        ->withCount(['products' => fn ($q) => $q->active()])
        ->get();
    $totalActiveProducts = (int) $allCategories->sum('products_count');
@endphp

@section('content')
    <nav class="m-breadcrumb" aria-label="面包屑">
        <a href="/">商品目录</a><span aria-hidden="true">/</span><span aria-current="page">{{ $category->name }}</span>
    </nav>

    <section class="m-store-strip m-category-strip">
        <div class="m-store-strip-copy">
            <div class="m-store-strip-title">
                <h1>{{ $category->name }}</h1>
                <span class="m-pill m-pill-muted">共 {{ $products->total() }} 件</span>
            </div>
            @if($category->description)
            <p class="m-store-strip-desc">{{ $category->description }}</p>
            @endif
        </div>
        <div class="m-category-strip-tools">
            <search>
                <form method="GET" action="{{ request()->url() }}" class="m-inline-search">
                    <label for="global-product-search" class="m-sr-only">搜索当前分类</label>
                    <div class="m-search-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5" stroke-linecap="round"/></svg>
                        <input id="global-product-search" type="search" name="q" value="{{ request('q') }}" maxlength="200" placeholder="搜索当前分类…">
                        @if(request()->filled('q'))
                        <a href="{{ request()->url() }}" class="m-search-clear" aria-label="清空搜索">清空</a>
                        @endif
                    </div>
                    <button class="m-button m-button-primary m-button-sm" type="submit">搜索</button>
                </form>
            </search>
            <a class="m-text-link m-search-all-link" href="{{ url('/') . (request()->filled('q') ? '?' . http_build_query(['q' => request('q')]) : '') }}">在全部商品中搜索 <span aria-hidden="true">→</span></a>
        </div>
    </section>

    <section class="m-catalog-layout" id="catalog">
        <aside class="m-catalog-sidebar" aria-label="商品分类导航">
            <span class="m-eyebrow">切换分类</span>
            <nav class="m-category-nav" aria-label="商品分类">
                <a href="/">
                    <span>全部商品</span>
                    <span>{{ $totalActiveProducts }}</span>
                </a>
                @foreach($allCategories as $cat)
                @php $isActiveCat = $cat->id === $category->id; @endphp
                <a href="/category/{{ $cat->slug }}" @class(['is-active' => $isActiveCat]) @if($isActiveCat) aria-current="page" @endif>
                    <span>{{ $cat->name }}</span>
                    <span>{{ $cat->products_count ?? 0 }}</span>
                </a>
                @endforeach
            </nav>
        </aside>

        <div class="m-catalog-main">
            @if($products->isNotEmpty())
            <div class="m-product-grid" id="catalog-grid">
                @foreach($products as $product)
                    @themeInclude('partials.product-card', ['product' => $product, 'lcp' => $loop->first])
                @endforeach
            </div>
            {{ $products->links() }}
            @else
            <div class="m-empty">
                @themeInclude('partials.image-placeholder')
                <h3>{{ request()->filled('q') ? '当前分类下未找到匹配商品' : '这个分类还没有商品' }}</h3>
                <p>{{ request()->filled('q') ? '可尝试清空关键词，或在全部商品中检索。' : '去其他分类逛逛，也许会有新的发现。' }}</p>
                <a class="m-button m-button-secondary m-button-sm" href="/">返回商品目录</a>
            </div>
            @endif
        </div>
    </section>
@endsection
