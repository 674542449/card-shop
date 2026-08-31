@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/category/' . $category->slug))

@section('content')
    <div class="sec-head">
        <div>
            <h2>{{ $category->name }}</h2>
            @if($category->description)
            <p class="sec-sub">{{ $category->description }}</p>
            @endif
        </div>
        <a href="/" class="sec-link">返回首页</a>
    </div>

    @if($products->count() > 0)
    <div class="mgrid">
        @foreach($products as $product)
        @themeInclude('partials.product-card', ['product' => $product])
        @endforeach
    </div>

    <div class="pagination-wrap">{{ $products->links() }}</div>
    @else
    <div class="empty-state text-center">
        @themeInclude('partials.image-placeholder', ['class' => 'empty-state-glyph'])
        <p>该分类暂无商品</p>
    </div>
    @endif
@endsection
