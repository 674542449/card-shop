@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', $currentCategory ? url('/articles/category/' . $currentCategory->slug) : url('/articles'))

@section('content')
<header class="m-article-index-heading">
    <span class="m-eyebrow">商店笔记 / JOURNAL</span>
    <h1 class="m-page-heading">{{ $currentCategory ? $currentCategory->name : '公告与指南' }}</h1>
    <p class="m-lead">关于商品、使用方法，以及商店的新消息。</p>
</header>

<div class="m-article-layout">
    <section class="m-article-list" aria-label="文章列表">
        <div class="m-article-list-caption">
            <span>{{ $currentCategory ? $currentCategory->name : '全部文章' }}</span>
            <span class="m-muted">{{ $articles->total() }} 篇内容</span>
        </div>

        @forelse($articles as $article)
        <a href="/articles/{{ $article->slug }}" class="m-article-row">
            <time class="m-article-row-date" datetime="{{ $article->created_at->toDateString() }}">
                <span>{{ $article->created_at->format('m.d') }}</span>
                <small>{{ $article->created_at->format('Y') }}</small>
            </time>
            <div class="m-article-row-content">
                @if($article->articleCategory)
                <span class="m-article-category">{{ $article->articleCategory->name }}</span>
                @endif
                <h2 class="m-article-row-title">{{ $article->title }}</h2>
                @if(filled($article->summary))
                <p class="m-article-row-summary">{{ \Illuminate\Support\Str::limit(strip_tags($article->summary), 160) }}</p>
                @endif
            </div>
            <span class="m-article-row-arrow" aria-hidden="true">↗</span>
        </a>
        @empty
        <div class="m-empty m-article-empty">
            <h2 class="m-section-title">这里还没有文章</h2>
            <p class="m-muted">{{ $currentCategory ? '这个分类下暂时没有内容，可以看看其他分类。' : '公告和使用指南发布后，会出现在这里。' }}</p>
            <a href="/" class="m-button m-button-secondary">去挑选商品</a>
        </div>
        @endforelse

        @if($articles->hasPages())
        <div class="m-article-pagination">{{ $articles->links() }}</div>
        @endif
    </section>

    <aside class="m-article-sidebar">
        <section class="m-article-sidebar-section">
            <h2 class="m-eyebrow">按分类阅读</h2>
            <nav class="m-article-category-nav" aria-label="文章分类">
                <a href="/articles" @class(['is-active' => ! $currentCategory]) @if(! $currentCategory) aria-current="page" @endif>
                    <span>全部文章</span><span aria-hidden="true">↗</span>
                </a>
                @foreach($categories as $cat)
                <a href="/articles/category/{{ $cat->slug }}" @class(['is-active' => $currentCategory && $currentCategory->id === $cat->id]) @if($currentCategory && $currentCategory->id === $cat->id) aria-current="page" @endif>
                    <span>{{ $cat->name }}</span><span aria-hidden="true">↗</span>
                </a>
                @endforeach
            </nav>
        </section>

        <section class="m-article-note m-panel m-panel-pad">
            <span class="m-article-note-mark" aria-hidden="true">✳</span>
            <h2 class="m-section-title">已经下过单？</h2>
            <p class="m-muted">用下单邮箱和查询密码找回订单，在订单详情中查看付款状态和卡密。</p>
            <a href="/order/query" class="m-button m-button-quiet">查询订单 <span aria-hidden="true">→</span></a>
        </section>
    </aside>
</div>
@endsection