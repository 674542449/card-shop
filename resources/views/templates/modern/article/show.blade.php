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
{{-- 和列表页共用 .articles-layout（1fr + 280px 侧栏）。正文卡片借用模板的
     .pd-main-card——模板自己的文章详情页也是这么借的。 --}}
<div class="articles-layout">
    <article class="pd-main-card">
        {{-- 面包屑。模板里这一条是行内样式、没有类名，而样式表里没有面包屑组件。
             .pd-facts-wrap 给横排 + 下外边距，.article-card-footer 给小号次级配色，
             两个都是已有的类，比自造一个样式表里不存在的类名安全。
             分类那一级放到了下面的彩色标签里，避免同一个分类名在这一屏出现两次。 --}}
        <nav class="pd-facts-wrap article-card-footer" aria-label="面包屑">
            <a href="/" class="home-ann-link">首页</a>
            <span aria-hidden="true">/</span>
            <a href="/articles" class="home-ann-link">文章</a>
        </nav>

        {{-- 标题是这一页的主语，所以它是最大的字。 --}}
        <h2 class="pd-title">{{ $article->title }}</h2>

        <div class="pd-facts-wrap">
            @if($article->articleCategory)
            {{-- 模板的 .article-tag-badge 是空类（样式表里没有规则），.home-ann-tag 才是
                 那个品牌色小标签的实际外观。做成链接，分类入口不因为改版而消失。 --}}
            <a href="/articles/category/{{ $article->articleCategory->slug }}" class="home-ann-tag">{{ $article->articleCategory->name }}</a>
            @endif
            <time class="badge-stock" datetime="{{ $article->created_at->toDateString() }}">{{ $article->created_at->format('Y-m-d') }}</time>
            <span class="badge-stock">{{ $article->views }} 次阅读</span>
        </div>

        @if($article->cover_image)
        {{-- 定高 180px 的槽位：位置在文件到达之前就占好了，正文不会因为封面加载而往下跳，
             上传的图也定不了自己的尺寸。不做懒加载——它在这一页的首屏里。 --}}
        <div class="pd-hero-showcase">
            <img src="{{ $article->cover_image }}" alt="{{ $article->title }} 封面图"
                 decoding="async" fetchpriority="high">
        </div>
        @endif

        {{-- 正文容器只挂 .rich-text，故意不加模板的 .rich-content：
             .rich-content li 里写死了 list-style: disc，直接落在 li 上，压过 ol 继承下来的
             decimal——教程类文章的有序步骤会全变成圆点，读者看不出顺序。
             .rich-text 这一条同时是编辑器大图的守卫（img 的行内 width/height 靠它的
             !important 兜住）和段落间距的还原，不能换成别的类。 --}}
        <div class="rich-text">
            {!! $contentHtml !!}
        </div>

        {{-- 底部导航。.pd-section-box 只给上分隔线和间距，横排和字号来自
             .article-card-footer，两个类各管一半。 --}}
        <div class="pd-section-box article-card-footer">
            <a href="/articles" class="home-ann-link">← 返回文章列表</a>
            <a href="/" class="btn-buy">去挑选商品</a>
        </div>
    </article>

    <aside class="articles-sidebar">
        @if($relatedArticles->count() > 0)
        <div class="sidebar-widget">
            <h2 class="widget-title">相关文章</h2>
            <ul class="widget-list">
                @foreach($relatedArticles as $related)
                <li>
                    <a href="/articles/{{ $related->slug }}">
                        {{ $related->title }}
                        <span class="article-card-footer">
                            <time datetime="{{ $related->created_at->toDateString() }}">{{ $related->created_at->format('Y-m-d') }}</time>
                        </span>
                    </a>
                </li>
                @endforeach
            </ul>
        </div>
        @endif

        <div class="sidebar-widget">
            <h2 class="widget-title">快捷入口</h2>
            <ul class="widget-list">
                <li><a href="/articles">全部文章</a></li>
                <li><a href="/order/query">查询订单</a></li>
                <li><a href="/">挑选商品</a></li>
            </ul>
        </div>
    </aside>
</div>
@endsection
