@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/category/' . $category->slug))

@section('content')
    {{-- 分类名用 h2 而不是 h1：layout 的 .seo-h1 已经是本页唯一的 h1。
         借首页那块 .hero-simple 做页面抬头，分类描述接 .hero-desc——这是样式表里
         唯一一个「副标题」型的类，运营写的分类描述放这里读起来最顺。 --}}
    <section class="hero-simple">
        <h2 class="hero-title">{{ $category->name }}</h2>
        @if($category->description)
        <p class="hero-desc">{{ $category->description }}</p>
        @endif
    </section>

    <section class="products-section">
        <div class="section-header">
            <div class="section-title-wrap">
                <span class="section-count">共 {{ $products->total() }} 件在售</span>
            </div>

            {{-- 只有两枚：控制器给这个页面只传了当前分类，没传分类全集。为了一排分类
                 按钮而在视图里补一次 Category::active()->get() 不划算——回首页一步就
                 能拿到完整的分类导航，而首页那排本来就是这套按钮。
                 当前这枚指向自己并带 active + aria-current，是「当前位置」的标准写法。 --}}
            <nav class="category-filter" aria-label="按分类浏览">
                <a href="/" class="cat-btn">全部商品</a>
                <a href="/category/{{ $category->slug }}" class="cat-btn active"
                   aria-current="page">{{ $category->name }}</a>
            </nav>
        </div>

        @if($products->count() > 0)
        <div class="product-grid">
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
    </section>
@endsection
