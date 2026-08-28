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
    {{-- The same single list markup the home page uses; front.css reflows it into
         a stacked list below 768px rather than a second card-shaped copy of it
         existing here. Keep the <table>/<thead>/<tbody>/role attributes in step
         with front/home.blade.php — the reflow rules key off them. --}}
    <section class="product-table-section">
        <table class="product-table" role="table">
            <thead role="rowgroup">
                <tr role="row">
                    {{-- No category mark here: this page's header block above already
                         carries it, and repeating it 20px lower is just noise. On the
                         home page the table head is the only place the category
                         appears, so it does carry the mark there. --}}
                    {{-- .col-cat: below 768px the stylesheet promotes the first header
                         cell into a full-width bar, which on THIS page would repeat the
                         name the block above already shows. That class hides it there
                         and nowhere else. --}}
                    <th role="columnheader" class="col-cat">{{ $category->name }}</th>
                    <th role="columnheader" class="col-mode" style="width:100px">发货模式</th>
                    <th role="columnheader" class="col-stock" style="width:80px">库存</th>
                    <th role="columnheader" class="col-price" style="width:100px">单价</th>
                    <th role="columnheader" class="col-action" style="width:100px">操作</th>
                </tr>
            </thead>
            <tbody role="rowgroup">
                @foreach($products as $product)
                @include('front.partials.product-row', ['product' => $product])
                @endforeach
            </tbody>
        </table>
    </section>

    <div class="pagination-wrap">{{ $products->links() }}</div>
    @else
    <div class="empty-state">
        @include('front.partials.image-placeholder', ['class' => 'empty-state-glyph'])
        <p>该分类暂无商品</p>
        <a href="/" class="btn-buy-sm">返回首页</a>
    </div>
    @endif
@endsection
