@extends(theme_view_path('layout'))
@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', $currentCategory ? url('/articles/category/' . $currentCategory->slug) : url('/articles'))
@section('content')
<header class="n-page-header"><div><span class="n-kicker">KNOWLEDGE BASE</span><h1>{{ $currentCategory ? $currentCategory->name : '公告与指南' }}</h1><p>商店消息、商品说明与实用的使用方法。</p></div><span class="n-article-count">{{ $articles->total() }} 篇内容</span></header>
<nav class="n-filter-bar" aria-label="文章分类"><a href="/articles" @class(['is-active' => !$currentCategory]) @if(!$currentCategory) aria-current="page" @endif>全部文章</a>@foreach($categories as $cat)<a href="/articles/category/{{ $cat->slug }}" @class(['is-active' => $currentCategory && $currentCategory->id === $cat->id]) @if($currentCategory && $currentCategory->id === $cat->id) aria-current="page" @endif>{{ $cat->name }}</a>@endforeach</nav>
@if($articles->isNotEmpty())
<div class="n-resource-grid n-resource-grid-index">@foreach($articles as $article)<a href="/articles/{{ $article->slug }}" class="n-resource-card">@if($article->cover_image)<div class="n-resource-cover"><img src="{{ $article->cover_image }}" alt="" loading="lazy" decoding="async"></div>@endif<div class="n-resource-meta"><span>{{ $article->articleCategory->name ?? '商店公告' }}</span><time datetime="{{ $article->created_at->toDateString() }}">{{ $article->created_at->format('Y.m.d') }}</time></div><h2>{{ $article->title }}</h2>@if(filled($article->summary))<p>{{ \Illuminate\Support\Str::limit(strip_tags($article->summary), 160) }}</p>@endif<span class="n-resource-arrow" aria-hidden="true">↗</span></a>@endforeach</div>{{ $articles->links() }}
@else<div class="n-empty">@themeInclude('partials.image-placeholder')<h2>还没有文章</h2><p>{{ $currentCategory ? '这个分类暂时没有内容，可以查看其他分类。' : '公告与使用指南发布后会显示在这里。' }}</p><a href="/" class="n-button n-button-secondary">返回商品目录</a></div>@endif
<aside class="n-article-help"><div><h2>已经完成购买？</h2><p>使用下单邮箱与查询密码，找回订单和卡密。</p></div><a href="/order/query" class="n-button n-button-secondary">查询订单 →</a></aside>
@endsection