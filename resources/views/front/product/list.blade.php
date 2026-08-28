@extends('layouts.front')

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/category/' . $category->slug))

@section('content')
    <blockquote class="site-quote cat-quote">
        @include('front.partials.category-thumb', ['category' => $category, 'size' => 40, 'modifier' => 'cat-thumb-lg'])
        <span class="cat-quote-text">
            <strong>{{ $category->name }}</strong>
            @if($category->description)
             — {{ $category->description }}
            @endif
        </span>
    </blockquote>

    @if($products->count() > 0)
    {{-- Desktop Table --}}
    <section class="product-table-section desktop-only">
        <table class="product-table">
            <thead>
                <tr>
                    {{-- No category mark here: this page's header block above already
                         carries it, and repeating it 20px lower is just noise. On the
                         home page the table head is the only place the category
                         appears, so it does carry the mark there. --}}
                    <th>{{ $category->name }}</th>
                    <th style="width:100px">发货模式</th>
                    <th style="width:80px">库存</th>
                    <th style="width:100px">单价</th>
                    <th style="width:100px">操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($products as $product)
                @include('front.partials.product-row', ['product' => $product])
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Mobile Cards --}}
    <div class="mobile-only">
        <div class="product-grid">
            @foreach($products as $product)
            @include('front.partials.product-card', ['product' => $product])
            @endforeach
        </div>
    </div>

    <div class="pagination-wrap">{{ $products->links() }}</div>
    @else
    <div class="empty-state">
        @include('front.partials.image-placeholder', ['class' => 'empty-state-glyph'])
        <p>该分类暂无商品</p>
        <a href="/" class="btn-buy-sm">返回首页</a>
    </div>
    @endif
@endsection
