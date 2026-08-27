@extends('layouts.front')

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/category/' . $category->slug))

@section('content')
    <blockquote class="site-quote">
        <strong>{{ $category->name }}</strong>
        @if($category->description)
         — {{ $category->description }}
        @endif
    </blockquote>

    @if($products->count() > 0)
    {{-- Desktop Table --}}
    <section class="product-table-section desktop-only">
        <table class="product-table">
            <thead>
                <tr>
                    <th>{{ $category->name }}</th>
                    <th style="width:100px">发货模式</th>
                    <th style="width:80px">库存</th>
                    <th style="width:100px">单价</th>
                    <th style="width:100px">操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($products as $product)
                @php $stock = $product->stockCount(); @endphp
                <tr>
                    <td>
                        <div class="prod-name-cell">
                            @if($product->image)
                            <img src="{{ $product->image }}" alt="{{ $product->name }}" class="prod-thumb">
                            @endif
                            <a href="/product/{{ $product->slug }}" class="prod-name">{{ $product->name }}</a>
                        </div>
                    </td>
                    <td><span class="badge-auto">自动发货</span></td>
                    <td class="stock-num">{{ $stock }}</td>
                    <td class="prod-price">¥{{ number_format($product->price, 2) }}</td>
                    <td>
                        @if($stock > 0)
                        <a href="/product/{{ $product->slug }}" class="btn-buy-sm">购买</a>
                        @else
                        <span class="text-muted" style="font-size:13px">缺货</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    {{-- Mobile Cards --}}
    <div class="mobile-only">
        <div class="product-grid">
            @foreach($products as $product)
            @php $stock = $product->stockCount(); @endphp
            <div class="product-card">
                <a href="/product/{{ $product->slug }}">
                    @if($product->image)
                    <img src="{{ $product->image }}" alt="{{ $product->name }}" class="card-img">
                    @else
                    <div class="card-img-placeholder">&#128230;</div>
                    @endif
                    <div class="card-body">
                        <div class="card-badge"><span class="badge-auto">自动发货</span></div>
                        <div class="card-title">{{ $product->name }}</div>
                        <div class="card-footer">
                            <span class="card-stock">库存 {{ $stock }}</span>
                            <span class="card-price">¥{{ number_format($product->price, 2) }}</span>
                        </div>
                    </div>
                </a>
            </div>
            @endforeach
        </div>
    </div>

    <div class="pagination-wrap">{{ $products->links() }}</div>
    @else
    <div class="text-center" style="padding:60px 0;color:var(--text-light)">
        <div style="font-size:48px;margin-bottom:15px">&#128230;</div>
        <p>该分类暂无商品</p>
        <a href="/" class="btn-buy-sm" style="margin-top:10px">返回首页</a>
    </div>
    @endif
@endsection
