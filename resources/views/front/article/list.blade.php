@extends('layouts.front')

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', $currentCategory ? url('/articles/category/' . $currentCategory->slug) : url('/articles'))

@section('content')
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/">首页</a></li>
            <li class="breadcrumb-item {{ $currentCategory ? '' : 'active' }}">
                @if($currentCategory)
                    <a href="/articles">文章中心</a>
                @else
                    文章中心
                @endif
            </li>
            @if($currentCategory)
            <li class="breadcrumb-item active" aria-current="page">{{ $currentCategory->name }}</li>
            @endif
        </ol>
    </nav>

    <div class="row g-4">
        {{-- Sidebar --}}
        <div class="col-lg-3">
            <div class="article-sidebar">
                <h2 class="h6 fw-bold mb-3">文章分类</h2>
                <div class="list-group">
                    <a href="/articles"
                       class="list-group-item list-group-item-action {{ !$currentCategory ? 'active' : '' }}">
                        全部文章
                    </a>
                    @foreach($categories as $cat)
                    <a href="/articles/category/{{ $cat->slug }}"
                       class="list-group-item list-group-item-action {{ $currentCategory && $currentCategory->id === $cat->id ? 'active' : '' }}">
                        {{ $cat->name }}
                    </a>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Article List --}}
        <div class="col-lg-9">
            <h1 class="h4 fw-bold mb-4">
                {{ $currentCategory ? $currentCategory->name : '全部文章' }}
            </h1>

            @if($articles->count() > 0)
            <div class="row g-3">
                @foreach($articles as $article)
                <div class="col-12 col-sm-6">
                    <div class="article-card">
                        @if($article->cover_image)
                        <a href="/articles/{{ $article->slug }}">
                            <img src="{{ $article->cover_image }}" alt="{{ $article->title }}" class="cover-image">
                        </a>
                        @else
                        <a href="/articles/{{ $article->slug }}">
                            <div class="cover-placeholder">
                                <i class="bi bi-file-text"></i>
                            </div>
                        </a>
                        @endif
                        <div class="card-body">
                            <h3 class="article-title">
                                <a href="/articles/{{ $article->slug }}">{{ $article->title }}</a>
                            </h3>
                            @if($article->summary)
                            <p class="article-summary">{{ $article->summary }}</p>
                            @endif
                            <div class="article-meta">
                                <span>
                                    <i class="bi bi-calendar3 me-1"></i>{{ $article->created_at->format('Y-m-d') }}
                                    <span class="ms-2"><i class="bi bi-eye me-1"></i>{{ $article->views }}</span>
                                </span>
                                @if($article->articleCategory)
                                <span class="category-label">{{ $article->articleCategory->name }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            <div class="d-flex justify-content-center mt-4">
                {{ $articles->links() }}
            </div>
            @else
            <div class="empty-state">
                <div class="icon"><i class="bi bi-journal-x"></i></div>
                <p>暂无文章</p>
                <a href="/" class="btn btn-outline-primary">返回首页</a>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection
