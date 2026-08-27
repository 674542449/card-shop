@extends('layouts.front')

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', $currentCategory ? url('/articles/category/' . $currentCategory->slug) : url('/articles'))

@section('content')
    <div class="pd-layout">
        {{-- Main: Article List --}}
        <div class="pd-main">
            <div class="pd-info" style="padding:15px 20px 5px;">
                <h2 class="pd-title" style="font-size:18px;margin-bottom:5px;">
                    {{ $currentCategory ? $currentCategory->name : '全部文章' }}
                </h2>
            </div>

            @if($articles->count() > 0)
            <div class="article-page-list">
                @foreach($articles as $article)
                <article class="article-page-item">
                    <a href="/articles/{{ $article->slug }}" class="title">{{ $article->title }}</a>
                    <div class="meta">
                        @if($article->articleCategory)
                        <span style="color:var(--teal);margin-right:8px;">{{ $article->articleCategory->name }}</span>
                        @endif
                        {{ $article->created_at->format('Y-m-d') }}
                    </div>
                </article>
                @endforeach
            </div>

            <div class="pagination-wrap">{{ $articles->links() }}</div>
            @else
            <div class="text-center" style="padding:60px 0;color:var(--text-light)">
                <div style="font-size:48px;margin-bottom:15px">&#128209;</div>
                <p>暂无文章</p>
                <a href="/" class="btn-buy-sm" style="margin-top:10px">返回首页</a>
            </div>
            @endif
        </div>

        {{-- Sidebar: Categories --}}
        <div class="pd-sidebar">
            <div class="sidebar-card">
                <div class="sidebar-card-header">文章分类</div>
                <ul class="sidebar-article-list">
                    <li>
                        <a href="/articles" style="{{ !$currentCategory ? 'color:var(--teal);font-weight:600' : '' }}">全部文章</a>
                    </li>
                    @foreach($categories as $cat)
                    <li>
                        <a href="/articles/category/{{ $cat->slug }}"
                           style="{{ $currentCategory && $currentCategory->id === $cat->id ? 'color:var(--teal);font-weight:600' : '' }}">
                            {{ $cat->name }}
                        </a>
                    </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endsection
