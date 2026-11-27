@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', $seoCanonical)

@section('structured_data')
@php
    $articleBreadcrumbs = [
        ['name' => '商城首页', 'url' => \App\Support\Seo::url('/')],
        ['name' => '帮助中心', 'url' => \App\Support\Seo::url('/articles')],
    ];
    if ($currentCategory) {
        $articleBreadcrumbs[] = [
            'name' => (string) $currentCategory->name,
            'url' => \App\Support\Seo::url('/articles/category/' . $currentCategory->slug),
        ];
    }
    $articleSchemaItems = $articles->getCollection()
        ->map(fn ($a) => ['name' => (string) $a->title, 'url' => \App\Support\Seo::url('/articles/' . $a->slug)])
        ->all();
@endphp
{{ \App\Support\Seo::jsonLdScript(\App\Support\Seo::collectionSchema(
    $currentCategory ? (string) $currentCategory->name : '帮助中心',
    $seoCanonical,
    (string) $seoDescription,
    $articleBreadcrumbs,
    $articleSchemaItems,
)) }}
@endsection

@section('content')
    <nav class="ml-breadcrumb" aria-label="面包屑">
        <ol>
            <li><a href="/">商城首页</a></li>
            @if($currentCategory)
            <li><a href="/articles">帮助中心</a></li>
            <li><a href="/articles/category/{{ $currentCategory->slug }}" aria-current="page">{{ $currentCategory->name }}</a></li>
            @else
            <li><a href="/articles" aria-current="page">帮助中心</a></li>
            @endif
        </ol>
    </nav>

    <section class="ml-dept-header">
        <div class="ml-dept-copy">
            <div class="ml-hero-title-row">
                <h1>{{ $currentCategory ? $currentCategory->name : '帮助中心与店铺公告' }}</h1>
                <span class="ml-badge ml-badge-neutral">共 {{ $articles->total() }} 篇</span>
            </div>
            <p class="ml-hero-desc">浏览商品使用说明、常见问题解答与最新站点通知。</p>
        </div>
    </section>

    <div class="ml-article-grid-layout">
        <section class="ml-article-feed" aria-label="文章列表">
            @forelse($articles as $article)
            @php
                $summaryText = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('/\[\[product:\d+\]\]/', '', (string) $article->summary))));
                $coverThumb = \App\Support\SafeUrl::asset($article->cover_image);
            @endphp
            <article class="ml-article-card">
                @if($coverThumb)
                <div class="ml-article-thumb">
                    <img src="{{ $coverThumb }}" alt="" width="240" height="150" @if($loop->first) fetchpriority="high" @else loading="lazy" @endif decoding="async">
                </div>
                @endif
                <div class="ml-article-card-body">
                    <div class="ml-article-meta">
                        @if($article->articleCategory)
                        <span class="ml-badge ml-badge-brand">{{ $article->articleCategory->name }}</span>
                        @endif
                        <time datetime="{{ $article->created_at->toDateString() }}">{{ $article->created_at->format('Y-m-d') }}</time>
                    </div>
                    <h2><a class="ml-card-link" href="/articles/{{ $article->slug }}">{{ $article->title }}</a></h2>
                    @if($summaryText !== '')
                    <p>{{ \Illuminate\Support\Str::limit($summaryText, 130) }}</p>
                    @endif
                    <span class="ml-article-read-more">阅读全文 <span aria-hidden="true">→</span></span>
                </div>
            </article>
            @empty
            <div class="ml-empty">
                @themeInclude('partials.image-placeholder')
                <h2>这里还没有发布文章</h2>
                <p>{{ $currentCategory ? '当前分类下暂时没有内容，可以切换到其他分类浏览。' : '公告与使用指南发布后将展示在这里。' }}</p>
                <a href="/" class="ml-btn ml-btn-primary ml-btn-sm">返回商品目录</a>
            </div>
            @endforelse

            @if($articles->hasPages())
            {{ $articles->links() }}
            @endif
        </section>

        <aside class="ml-article-aside" aria-label="帮助中心侧栏">
            <section class="ml-card ml-aside-card">
                <h2 class="ml-aside-title">文档分类</h2>
                <nav class="ml-aside-nav" aria-label="文章分类">
                    <a href="/articles" @class(['is-active' => !$currentCategory]) @if(!$currentCategory) aria-current="page" @endif>
                        <span>全部文档</span><span aria-hidden="true">→</span>
                    </a>
                    @foreach($categories as $cat)
                    <a href="/articles/category/{{ $cat->slug }}" @class(['is-active' => $currentCategory && $currentCategory->id === $cat->id]) @if($currentCategory && $currentCategory->id === $cat->id) aria-current="page" @endif>
                        <span>{{ $cat->name }}</span><span aria-hidden="true">→</span>
                    </a>
                    @endforeach
                </nav>
            </section>

            <section class="ml-card ml-aside-card ml-aside-cta">
                <h2 class="ml-aside-title">快捷服务</h2>
                <p class="ml-muted">自助提取已购卡密，或前往商城浏览最新在售商品。</p>
                <div class="ml-aside-actions">
                    <a href="/" class="ml-btn ml-btn-primary ml-btn-wide ml-btn-sm">逛逛全部商品 →</a>
                    <a href="/order/query" class="ml-btn ml-btn-secondary ml-btn-wide ml-btn-sm">自助查询订单</a>
                </div>
            </section>
        </aside>
    </div>
@endsection
