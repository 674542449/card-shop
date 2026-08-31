@extends(theme_view_path('layout'))

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
            {{-- Same .empty-state block as front/product/list.blade.php: one wrapper, one
                 stylesheet rule, and the quiet SVG mark instead of an emoji that renders
                 at a different weight and colour on every platform. --}}
            <div class="empty-state">
                @themeInclude('partials.image-placeholder', ['class' => 'empty-state-glyph'])
                <p>暂无文章</p>
                <a href="/" class="btn-buy-sm">返回首页</a>
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
