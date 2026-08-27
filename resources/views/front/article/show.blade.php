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
<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="/">首页</a></li>
            <li class="breadcrumb-item"><a href="/articles">文章中心</a></li>
            @if($article->articleCategory)
            <li class="breadcrumb-item">
                <a href="/articles/category/{{ $article->articleCategory->slug }}">
                    {{ $article->articleCategory->name }}
                </a>
            </li>
            @endif
            <li class="breadcrumb-item active" aria-current="page">{{ Str::limit($article->title, 30) }}</li>
        </ol>
    </nav>

    <div class="row g-4">
        {{-- Main Content --}}
        <div class="col-lg-8">
            <article>
                <h1 class="h3 fw-bold mb-3">{{ $article->title }}</h1>

                <div class="d-flex align-items-center gap-3 mb-4 text-secondary small">
                    <span><i class="bi bi-calendar3 me-1"></i>{{ $article->created_at->format('Y-m-d') }}</span>
                    @if($article->articleCategory)
                    <a href="/articles/category/{{ $article->articleCategory->slug }}" class="text-decoration-none">
                        <span class="category-label" style="background-color: var(--primary-bg); color: var(--primary); padding: 0.15em 0.5em; border-radius: 0.25rem; font-weight: 500;">
                            {{ $article->articleCategory->name }}
                        </span>
                    </a>
                    @endif
                    <span><i class="bi bi-eye me-1"></i>{{ $article->views }} 次阅读</span>
                </div>

                @if($article->cover_image)
                <div class="mb-4">
                    <img src="{{ $article->cover_image }}" alt="{{ $article->title }}"
                         class="img-fluid rounded" style="width: 100%; max-height: 400px; object-fit: cover;">
                </div>
                @endif

                <div class="article-content">
                    {!! $contentHtml !!}
                </div>
            </article>
        </div>

        {{-- Sidebar --}}
        <div class="col-lg-4">
            {{-- Related Articles --}}
            @if($relatedArticles->count() > 0)
            <div class="order-summary-card">
                <div class="card-header">
                    <i class="bi bi-journal-text me-1"></i> 相关文章
                </div>
                <div class="card-body related-articles">
                    @foreach($relatedArticles as $related)
                    <div class="related-item">
                        <div class="flex-grow-1">
                            <a href="/articles/{{ $related->slug }}" class="title">
                                {{ $related->title }}
                            </a>
                            <div class="date mt-1">{{ $related->created_at->format('Y-m-d') }}</div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Back to list --}}
            <div class="mt-3">
                <a href="/articles" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-arrow-left me-1"></i> 返回文章列表
                </a>
            </div>
        </div>
    </div>
</div>
@endsection
