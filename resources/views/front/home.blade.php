@extends('layouts.front')

@section('title', setting('seo_default_title', 'CardShop'))
@section('meta_description', setting('seo_default_description', ''))
@section('meta_keywords', setting('seo_default_keywords', ''))

@section('content')
    {{-- Hero Section --}}
    <section class="hero-section">
        <div class="container">
            <h1>{{ $siteName }}</h1>
            @if($siteDescription)
            <p>{{ $siteDescription }}</p>
            @endif
        </div>
    </section>

    <div class="container">
        {{-- Category Filter --}}
        <div class="category-pills">
            <a href="/"
               class="category-pill {{ !request('category') ? 'active' : '' }}">
                全部商品
            </a>
            @foreach($categories as $category)
            <a href="/?category={{ $category->slug }}"
               class="category-pill {{ request('category') === $category->slug ? 'active' : '' }}">
                {{ $category->name }}
                <span class="badge">{{ $category->products_count }}</span>
            </a>
            @endforeach
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
        <div class="d-flex justify-content-center mb-4">
            {{ $products->links() }}
        </div>
        @else
        <div class="empty-state">
            <div class="icon"><i class="bi bi-box-seam"></i></div>
            <p>暂无商品</p>
        </div>
        @endif

        {{-- Latest Articles --}}
        @if($articles->count() > 0)
        <section class="mb-4">
            <div class="section-header">
                <h2>最新文章</h2>
                <a href="/articles" class="view-all">查看全部 <i class="bi bi-chevron-right"></i></a>
            </div>

            <div class="row g-3">
                @foreach($articles as $article)
                <div class="col-12 col-md-4">
                    <div class="article-card">
                        @if($article->cover_image)
                        <img src="{{ $article->cover_image }}" alt="{{ $article->title }}" class="cover-image">
                        @else
                        <div class="cover-placeholder">
                            <i class="bi bi-file-text"></i>
                        </div>
                        @endif
                        <div class="card-body">
                            <h3 class="article-title">
                                <a href="/articles/{{ $article->slug }}">{{ $article->title }}</a>
                            </h3>
                            @if($article->summary)
                            <p class="article-summary">{{ $article->summary }}</p>
                            @endif
                            <div class="article-meta">
                                <span>{{ $article->created_at->format('Y-m-d') }}</span>
                                @if($article->articleCategory)
                                <span class="category-label">{{ $article->articleCategory->name }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </section>
        @endif
    </div>
@endsection
