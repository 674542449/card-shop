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
<nav class="n-breadcrumb" aria-label="面包屑">
    <a href="/">商品目录</a><span aria-hidden="true">/</span><span aria-current="page">{{ $category->name }}</span>
</nav>

<section class="n-store-bar n-category-bar">
    <div class="n-store-bar-copy">
        <div class="n-store-bar-title">
            <h1>{{ $category->name }}</h1>
            <span class="n-status">共 {{ $products->total() }} 件</span>
        </div>
        @if($category->description)
        <p class="n-store-bar-desc">{{ $category->description }}</p>
        @endif
    </div>
    <div class="n-category-tools">
        <search>
            <form method="GET" action="{{ request()->url() }}" class="n-inline-search">
                <label for="global-product-search" class="n-sr">搜索当前分类</label>
                <div class="n-search-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5" stroke-linecap="round"/></svg>
                    <input id="global-product-search" type="search" name="q" value="{{ request('q') }}" maxlength="200" placeholder="搜索当前分类…">
                    @if(request()->filled('q'))
                    <a href="{{ request()->url() }}" class="n-search-clear" aria-label="清空搜索">清空</a>
                    @endif
                </div>
                <button class="n-button n-button-primary n-button-sm" type="submit">搜索</button>
            </form>
        </search>
        <a class="n-search-all-link" href="{{ url('/') . (request()->filled('q') ? '?' . http_build_query(['q' => request('q')]) : '') }}">在全部商品中搜索 →</a>
    </div>
</section>

<nav class="n-filter-bar" aria-label="商品分类">
    <a href="/">全部商品 <small>{{ $totalActiveProducts }}</small></a>
    @foreach($allCategories as $cat)
    @php $isActiveCat = $cat->id === $category->id; @endphp
    <a href="/category/{{ $cat->slug }}" @class(['is-active' => $isActiveCat]) @if($isActiveCat) aria-current="page" @endif>{{ $cat->name }} <small>{{ $cat->products_count ?? 0 }}</small></a>
    @endforeach
</nav>

@if($products->isNotEmpty())
<div class="n-catalog-grid">
    @foreach($products as $product)
        @themeInclude('partials.product-card', ['product' => $product, 'lcp' => $loop->first])
    @endforeach
</div>
{{ $products->links() }}
@else
<div class="n-empty">
    @themeInclude('partials.image-placeholder')
    <h2>{{ request()->filled('q') ? '当前分类下未找到匹配商品' : '这个分类还没有商品' }}</h2>
    <p>{{ request()->filled('q') ? '可清空搜索词，或前往全部商品中检索。' : '返回目录，看看其他分类。' }}</p>
    <a href="/" class="n-button n-button-secondary n-button-sm">返回商品目录</a>
</div>
@endif
@endsection
