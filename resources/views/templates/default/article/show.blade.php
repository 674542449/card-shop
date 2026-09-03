@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/articles/' . $article->slug))
@section('og_type', 'article')

@section('structured_data')
@php
    // See the note in front/home.blade.php: a literal "@context" in the template is
    // parsed as the @context Blade directive and produces an unclosed if().
    $structuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $article->title,
        'description' => $article->seo_description
            ?: $article->summary
            // See front/product/show.blade.php: no class aliases are registered, so a
            // bare Str:: fatals when both fallbacks above are blank.
            ?: \Illuminate\Support\Str::limit(strip_tags($contentHtml), 200),
        'url' => url('/articles/' . $article->slug),
        'datePublished' => $article->created_at?->toIso8601String(),
        'dateModified' => $article->updated_at?->toIso8601String(),
        'publisher' => [
            '@type' => 'Organization',
            'name' => setting('site_name', 'CardShop'),
        ],
    ];

    if ($article->cover_image) {
        // schema.org needs an absolute URL, and the uploader now writes site-relative
        // paths like /storage/uploads/... rather than the absolute URLs an operator
        // used to paste in by hand.
        $structuredData['image'] = url($article->cover_image);
    }
@endphp
<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@section('content')
    <div class="pd-layout">
        {{-- Main: Article Content --}}
        <div class="pd-main">
            <article class="article-detail">
                <h2 class="article-title">{{ $article->title }}</h2>
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

                {{--
                    Cover banner. Same shape as .pd-image-wrap on the product page: a slot
                    with a fixed ratio, and the image fills it. Styling lives in front.css
                    under .article-detail .article-cover — the slot reserves the band before
                    the file arrives, so the article text does not jump as it loads, and no
                    upload can set its own size. No width/height attributes: the slot's
                    aspect-ratio already reserves the space and the file's own dimensions
                    are unknown. Not lazy-loaded — it is above the fold on this page.
                --}}
                @if($article->cover_image)
                <div class="article-cover">
                    <img src="{{ $article->cover_image }}" alt="{{ $article->title }} 封面图"
                         decoding="async" fetchpriority="high">
                </div>
                @endif

                {{-- .rich-text: the shared marker for editor-authored HTML printed
                     unescaped — see the full note in front/product/show.blade.php. The
                     .content class stays; front.css keys the article's type rules on it. --}}
                <div class="content rich-text">
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
