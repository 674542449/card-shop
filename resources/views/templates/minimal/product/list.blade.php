@extends(theme_view_path('layout'))
@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/category/' . $category->slug))
@section('content')
<form method="GET" action="{{ request()->url() }}" class="n-catalog-search-form" role="search">
 <label for="global-product-search">搜索当前分类</label><div class="n-catalog-search-controls"><input id="global-product-search" type="search" name="q" value="{{ request('q') }}" maxlength="200" placeholder="商品名称"><button class="n-button n-button-primary" type="submit">搜索</button>@if(request()->filled('q'))<a href="{{ request()->url() }}">清空</a>@endif</div>
 </form>
<p><a href="{{ url('/') . (request()->filled('q') ? '?' . http_build_query(['q' => request('q')]) : '') }}">在全部商品中搜索 →</a></p>
<nav class="n-breadcrumb" aria-label="面包屑"><a href="/">商品目录</a><span aria-hidden="true">/</span><span aria-current="page">{{ $category->name }}</span></nav>
<header class="n-page-header"><div><span class="n-kicker">COLLECTION</span><h1>{{ $category->name }}</h1>@if($category->description)<p>{{ $category->description }}</p>@endif</div><a href="/" class="n-button n-button-secondary">全部商品 →</a></header>
<div class="n-list-caption"><span>{{ $products->total() }} 件商品</span><span>付款后自动交付</span></div>
@if($products->isNotEmpty())<div class="n-catalog-grid">@foreach($products as $product)@themeInclude('partials.product-card', ['product' => $product])@endforeach</div>{{ $products->links() }}@else<div class="n-empty">@themeInclude('partials.image-placeholder')<h2>这个分类还没有商品</h2><p>返回目录，看看其他分类。</p><a href="/" class="n-button n-button-secondary">返回商品目录</a></div>@endif
@endsection