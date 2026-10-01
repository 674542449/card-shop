@extends(theme_view_path('layout'))
@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/category/' . $category->slug))
@section('content')
<form method="GET" action="{{ request()->url() }}" class="m-catalog-search-form" role="search">
 <label for="global-product-search">搜索全部商品</label><div class="m-catalog-search-controls"><input id="global-product-search" type="search" name="q" value="{{ request('q') }}" maxlength="200" placeholder="商品名称"><button class="m-button m-button-primary" type="submit">搜索</button>@if(request()->filled('q'))<a href="{{ request()->url() }}">清空</a>@endif</div>
 </form>
    <nav class="m-breadcrumb" aria-label="面包屑"><a href="/">商品目录</a><span aria-hidden="true">/</span><span>{{ $category->name }}</span></nav>
    <section class="m-category-heading"><div><span class="m-eyebrow">按分类探索</span><h1 class="m-page-heading">{{ $category->name }}</h1>@if($category->description)<p class="m-lead">{{ $category->description }}</p>@endif</div><a class="m-button m-button-secondary" href="/">全部商品 <span aria-hidden="true">↗</span></a></section>
    <div class="m-section-header"><h2 class="m-section-title">商品目录</h2><span class="m-muted">共 {{ $products->total() }} 件商品</span></div>
    @if($products->isNotEmpty())
    <div class="m-product-grid m-product-grid-full">@foreach($products as $product)@themeInclude('partials.product-card', ['product' => $product])@endforeach</div>
    {{ $products->links() }}
    @else
    <div class="m-empty">@themeInclude('partials.image-placeholder')<h3>这个分类还没有商品</h3><p>去其他分类逛逛，也许会有新的发现。</p><a class="m-button m-button-secondary" href="/">返回商品目录</a></div>
    @endif
@endsection