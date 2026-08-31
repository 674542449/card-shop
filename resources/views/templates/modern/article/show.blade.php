@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', url('/articles/' . $article->slug))
@section('og_type', 'article')

@section('structured_data')
@php
    // 见 home.blade.php 的说明：模板里字面量的 "@context" 会被当成 @context 指令解析，
    // 编译出一个没有闭合的 if()。所以这段必须留在 @php 块里。
    $structuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $article->title,
        'description' => $article->seo_description
            ?: $article->summary
            // 见 product/show.blade.php：没有注册类别名，上面两个回退都为空时
            // 裸写 Str:: 会致命错误。
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
        // schema.org 要绝对 URL，而上传器现在写的是 /storage/uploads/... 这样的站内相对路径。
        $structuredData['image'] = url($article->cover_image);
    }
@endphp
<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@section('content')
<div class="pd-layout">
    <div class="pd-col-main">
        <article class="as-article">
            <p class="as-eyebrow">
                <a href="/articles">文章</a>
                @if($article->articleCategory)
                <span aria-hidden="true">/</span>
                <a href="/articles/category/{{ $article->articleCategory->slug }}">{{ $article->articleCategory->name }}</a>
                @endif
            </p>

            {{-- 标题是这一页的主语，所以它是最大的字。改之前它是 22px，而页面上的
                 区块标题有 28px——标题反而比它的下级还小。 --}}
            <h1 class="as-title">{{ $article->title }}</h1>

            <p class="as-meta">
                <time datetime="{{ $article->created_at->toDateString() }}">{{ $article->created_at->format('Y-m-d') }}</time>
                <span class="as-dot" aria-hidden="true"></span>
                <span>{{ $article->views }} 次阅读</span>
            </p>

            {{--
                封面。和商品页的 .pd-image-wrap 同一个形状：一个定比例的槽位，图片填满它。
                槽位在文件到达之前就把那条带子占好，正文不会因为图片加载而跳动，
                上传的文件也定不了自己的尺寸。不做懒加载——它在这一页的首屏里。
                不写 width/height：槽位的 aspect-ratio 已经占好了位，而文件本身的尺寸未知。
            --}}
            @if($article->cover_image)
            <div class="as-cover">
                <img src="{{ $article->cover_image }}" alt="{{ $article->title }} 封面图"
                     decoding="async" fetchpriority="high">
            </div>
            @endif

            {{-- .rich-text：编辑器写的 HTML 原样输出时统一带的标记，说明见
                 product/show.blade.php。.content 保留，front.css 的排版规则挂在它上面。 --}}
            <div class="content rich-text as-body">
                {!! $contentHtml !!}
            </div>
        </article>
    </div>

    <aside class="pd-col-side">
        @if($relatedArticles->count() > 0)
        <section class="panel">
            <div class="panel-head">相关文章</div>
            <div class="panel-body">
                <ul class="pd-links">
                    @foreach($relatedArticles as $related)
                    <li>
                        <a href="/articles/{{ $related->slug }}">
                            {{ $related->title }}
                            <span class="as-rel-date">{{ $related->created_at->format('Y-m-d') }}</span>
                        </a>
                    </li>
                    @endforeach
                </ul>
            </div>
        </section>
        @endif

        <a href="/articles" class="btn btn-secondary btn-block">返回文章列表</a>
    </aside>
</div>
@endsection
