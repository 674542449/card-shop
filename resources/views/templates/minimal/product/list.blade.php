@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/category/' . $category->slug))

@section('content')
    <blockquote class="site-quote cat-quote">
        @themeInclude('partials.category-thumb', ['category' => $category, 'size' => 40, 'modifier' => 'cat-thumb-lg'])
        <span class="cat-quote-text">
            <strong>{{ $category->name }}</strong>
            @if($category->description)
             — {{ $category->description }}
            @endif
        </span>
    </blockquote>

    @if($products->count() > 0)
    {{-- 与首页同一个卡片 partial。首页那边按分类分组，这里是单分类的一整页，
         所以不需要 .cat-heading，卡片直接铺开。 --}}
    <div class="card-grid">
        @foreach($products as $product)
        @themeInclude('partials.product-card', ['product' => $product])
        @endforeach
    </div>

    <div class="pagination-wrap">{{ $products->links() }}</div>
    @else
    <div class="empty-state text-center">
        @themeInclude('partials.image-placeholder', ['class' => 'empty-state-glyph'])
        <p>该分类暂无商品</p>
        <a href="/" class="btn-buy-sm">返回首页</a>
    </div>
    @endif
@endsection
