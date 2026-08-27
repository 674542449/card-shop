@extends('layouts.front')

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/category/' . $category->slug))

@section('content')
<div class="container py-4">
    {{-- Breadcrumb --}}
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/">首页</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $category->name }}</li>
        </ol>
    </nav>

    {{-- Category Header --}}
    <div class="mb-4">
        <h1 class="h3 fw-bold mb-2">{{ $category->name }}</h1>
        @if($category->description)
        <p class="text-secondary">{{ $category->description }}</p>
        @endif
    </div>

    {{-- Product Grid --}}
    @if($products->count() > 0)
    <div class="row g-3 mb-4">
        @foreach($products as $product)
        <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
            @include('front.partials.product-card', ['product' => $product])
        </div>
        @endforeach
    </div>

    {{-- Pagination --}}
    <div class="d-flex justify-content-center">
        {{ $products->links() }}
    </div>
    @else
    <div class="empty-state">
        <div class="icon"><i class="bi bi-box-seam"></i></div>
        <p>该分类暂无商品</p>
        <a href="/" class="btn btn-outline-primary">返回首页</a>
    </div>
    @endif
</div>
@endsection
