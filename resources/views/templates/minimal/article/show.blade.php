@extends(theme_view_path('layout'))
@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/articles/' . $article->slug))
@section('og_type', 'article')
@section('structured_data')
@php
    $structuredData = ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $article->title, 'description' => $article->seo_description ?: $article->summary ?: \Illuminate\Support\Str::limit(strip_tags($contentHtml), 200), 'url' => url('/articles/' . $article->slug), 'datePublished' => $article->created_at?->toIso8601String(), 'dateModified' => $article->updated_at?->toIso8601String(), 'publisher' => ['@type' => 'Organization', 'name' => setting('site_name', 'CardShop')]];
    if ($article->cover_image) { $structuredData['image'] = url($article->cover_image); }
@endphp
<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
@section('content')
<div class="n-reading-wrap">
    <nav class="n-breadcrumb" aria-label="面包屑"><a href="/">商店</a><span aria-hidden="true">/</span><a href="/articles">公告与指南</a></nav>
    <article class="n-reading-article"><header class="n-reading-header">@if($article->articleCategory)<a href="/articles/category/{{ $article->articleCategory->slug }}" class="n-kicker">{{ $article->articleCategory->name }}</a>@else<span class="n-kicker">STORE NOTICE</span>@endif<h1>{{ $article->title }}</h1><div><time datetime="{{ $article->created_at->toDateString() }}">{{ $article->created_at->format('Y-m-d') }}</time><span>{{ $article->views }} 次阅读</span></div></header>
        @if($article->cover_image)<figure class="n-reading-cover"><img src="{{ $article->cover_image }}" alt="{{ $article->title }} 封面图" decoding="async" fetchpriority="high"></figure>@endif
        <div class="n-rich n-reading-body">{!! $contentHtml !!}</div>
        <footer class="n-reading-footer"><a href="/articles" class="n-button n-button-text">← 返回文章列表</a><a href="/" class="n-button n-button-secondary">浏览商品 →</a></footer>
    </article>
    @if($relatedArticles->isNotEmpty())<section class="n-related"><h2>相关阅读</h2><div>@foreach($relatedArticles as $related)<a href="/articles/{{ $related->slug }}"><span>{{ $related->title }}</span><time datetime="{{ $related->created_at->toDateString() }}">{{ $related->created_at->format('Y.m.d') }}</time><i aria-hidden="true">→</i></a>@endforeach</div></section>@endif
</div>
@endsection