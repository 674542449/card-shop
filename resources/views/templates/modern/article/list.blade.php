@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', $currentCategory ? url('/articles/category/' . $currentCategory->slug) : url('/articles'))

@section('content')
{{-- 和商品详情页共用同一个布局原语，不再为文章页另造一套两栏。 --}}
<div class="pd-layout">
    <div class="pd-col-main">
        <header class="al-head">
            <h1 class="al-title">{{ $currentCategory ? $currentCategory->name : '全部文章' }}</h1>
            @if($articles->total() > 0)
            <p class="al-count">共 {{ $articles->total() }} 篇</p>
            @endif
        </header>

        @if($articles->count() > 0)
        <ul class="al-list">
            @foreach($articles as $article)
            <li>
                {{-- 整行都是链接，而不是只有标题那几个字可点。原来的点击区就是标题的
                     文字宽度，手机上要精准点中一行字。 --}}
                <a href="/articles/{{ $article->slug }}" class="al-item">
                    <span class="al-item-title">{{ $article->title }}</span>
                    <span class="al-item-meta">
                        @if($article->articleCategory)
                        <span class="al-cat">{{ $article->articleCategory->name }}</span>
                        @endif
                        <time datetime="{{ $article->created_at->toDateString() }}">{{ $article->created_at->format('Y-m-d') }}</time>
                    </span>
                </a>
            </li>
            @endforeach
        </ul>

        <div class="pagination-wrap">{{ $articles->links() }}</div>
        @else
        <section class="panel">
            <div class="panel-body">
                <div class="state">
                    <div class="state-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>
                        </svg>
                    </div>
                    <p class="state-title">这里还没有文章</p>
                    <p class="state-desc">{{ $currentCategory ? '这个分类下暂时没有内容，看看其他分类。' : '公告和教程发布后会出现在这里。' }}</p>
                    <a href="/" class="btn">返回首页</a>
                </div>
            </div>
        </section>
        @endif
    </div>

    <aside class="pd-col-side">
        <section class="panel">
            <div class="panel-head">文章分类</div>
            <div class="panel-body">
                <ul class="al-cats">
                    <li>
                        <a href="/articles" @class(['is-current' => !$currentCategory])>全部文章</a>
                    </li>
                    @foreach($categories as $cat)
                    <li>
                        <a href="/articles/category/{{ $cat->slug }}"
                           @class(['is-current' => $currentCategory && $currentCategory->id === $cat->id])>{{ $cat->name }}</a>
                    </li>
                    @endforeach
                </ul>
            </div>
        </section>
    </aside>
</div>
@endsection
