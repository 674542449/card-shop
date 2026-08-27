@extends('layouts.front')

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/articles/' . $article->slug))
@section('og_type', 'article')

@section('structured_data')
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": "{{ e($article->title) }}",
    "description": "{{ e($article->seo_description ?: $article->summary ?: Str::limit(strip_tags($contentHtml), 200)) }}",
    "url": "{{ url('/articles/' . $article->slug) }}",
    "datePublished": "{{ $article->created_at->toIso8601String() }}",
    "dateModified": "{{ $article->updated_at->toIso8601String() }}",
    @if($article->cover_image)
    "image": "{{ $article->cover_image }}",
    @endif
    "publisher": {
        "@type": "Organization",
        "name": "{{ e(setting('site_name', 'CardShop')) }}"
    }
}
</script>
@endsection

@section('content')
    <div class="pd-layout">
        {{-- Main: Article Content --}}
        <div class="pd-main">
            <article class="article-detail">
                <h1>{{ $article->title }}</h1>
                <div class="meta">
                    <span>{{ $article->created_at->format('Y-m-d') }}</span>
                    @if($article->articleCategory)
                    <span style="margin:0 8px;">|</span>
                    <a href="/articles/category/{{ $article->articleCategory->slug }}" style="color:var(--teal);">
                        {{ $article->articleCategory->name }}
                    </a>
                    @endif
                    <span style="margin:0 8px;">|</span>
                    <span>{{ $article->views }} 次阅读</span>
                </div>

                @if($article->cover_image)
                <div style="margin-bottom:20px;">
                    <img src="{{ $article->cover_image }}" alt="{{ $article->title }}"
                         style="width:100%;max-height:400px;object-fit:cover;border-radius:4px;">
                </div>
                @endif

                <div class="content">
                    {!! $contentHtml !!}
                </div>
            </article>
        </div>

        {{-- Sidebar --}}
        <div class="pd-sidebar">
            @if($relatedArticles->count() > 0)
            <div class="sidebar-card">
                <div class="sidebar-card-header">相关文章</div>
                <ul class="sidebar-article-list">
                    @foreach($relatedArticles as $related)
                    <li>
                        <a href="/articles/{{ $related->slug }}">{{ $related->title }}</a>
                        <div class="date">{{ $related->created_at->format('Y-m-d') }}</div>
                    </li>
                    @endforeach
                </ul>
            </div>
            @endif

            <a href="/articles" class="btn-submit" style="text-align:center;display:block;">&larr; 返回文章列表</a>
        </div>
    </div>
@endsection
