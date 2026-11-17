@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', $seoCanonical)

@section('structured_data')
@php
    $categorySchemaItems = $products->getCollection()
        ->map(fn ($p) => ['name' => (string) $p->name, 'url' => \App\Support\Seo::url('/product/' . $p->slug)])
        ->all();
@endphp
{{ \App\Support\Seo::jsonLdScript(\App\Support\Seo::collectionSchema(
    (string) $category->name,
    $seoCanonical,
    (string) $seoDescription,
    [
        ['name' => '商品目录', 'url' => \App\Support\Seo::url('/')],
        ['name' => (string) $category->name, 'url' => \App\Support\Seo::url('/category/' . $category->slug)],
    ],
    $categorySchemaItems,
)) }}
@endsection

@php
    $allCategories = \App\Models\Category::active()
        ->ordered()
        ->withCount(['products' => fn ($q) => $q->active()])
        ->get();
    $totalActiveProducts = (int) $allCategories->sum('products_count');
@endphp

@section('content')
    <div class="fb-marketplace-layout">
        {{-- Facebook Marketplace Left Category Sidebar --}}
        <aside class="fb-left-rail" aria-label="商品分类导航">
            <div class="fb-rail-card">
                <nav class="ws-breadcrumb" aria-label="面包屑导航" style="margin-bottom:8px;">
                    <a href="/">商品目录</a>
                    <span class="sep">/</span>
                    <span class="current">{{ $category->name }}</span>
                </nav>

                <div class="fb-rail-head">
                    <h1>{{ $category->name }}</h1>
                    <span class="ws-pill ws-pill-accent">{{ $products->total() }} 款</span>
                </div>
                <p class="fb-rail-desc">{{ $category->description ?: '浏览当前分类下的在售数字商品，支持按关键词检索与在线下单。' }}</p>

                <search>
                    <form method="GET" action="{{ request()->url() }}" class="fb-rail-search">
                        <label for="category-product-search" class="ws-sr-only">搜索当前分类</label>
                        <div class="fb-search-pill">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="11" cy="11" r="7"></circle>
                                <path d="M20 20l-3.5-3.5"></path>
                            </svg>
                            <input id="category-product-search" type="search" name="q" value="{{ request('q') }}" maxlength="200" placeholder="搜索「{{ $category->name }}」...">
                            @if(request()->filled('q'))
                            <a href="{{ request()->url() }}" class="fb-search-clear" title="清空搜索" aria-label="清空搜索关键词">&times;</a>
                            @endif
                        </div>
                        <button type="submit" class="ws-btn ws-btn-primary ws-btn-sm fb-search-submit">搜索</button>
                    </form>
                </search>

                <hr class="fb-divider">

                <div class="fb-rail-section-title">
                    <span>切换分类</span>
                    <small>{{ $allCategories->count() }} 类</small>
                </div>

                <nav class="fb-cat-list" aria-label="商品分类">
                    <a href="/" class="fb-cat-item">
                        <span class="fb-cat-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
                                <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
                                <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
                                <rect x="14" y="14" width="7" height="7" rx="1.5"></rect>
                            </svg>
                        </span>
                        <span class="fb-cat-name">浏览全部商品</span>
                        <span class="fb-cat-badge">{{ $totalActiveProducts }}</span>
                    </a>

                    @foreach($allCategories as $cat)
                    @php
                        $isActiveCat = $cat->id === $category->id;
                        $catThumb = \App\Support\SafeUrl::asset($cat->image);
                    @endphp
                    <a href="/category/{{ $cat->slug }}"
                       class="fb-cat-item {{ $isActiveCat ? 'is-active' : '' }}"
                       @if($isActiveCat) aria-current="page" @endif>
                        <span class="fb-cat-icon" aria-hidden="true">
                            @if($catThumb)
                            <img src="{{ $catThumb }}" alt="" width="36" height="36" decoding="async">
                            @else
                            <span>{{ mb_substr($cat->name, 0, 1) }}</span>
                            @endif
                        </span>
                        <span class="fb-cat-name">{{ $cat->name }}</span>
                        <span class="fb-cat-badge">{{ $cat->products_count ?? 0 }}</span>
                    </a>
                    @endforeach
                </nav>
            </div>
        </aside>

        <div class="fb-main-stage">
            <div class="fb-stage-toolbar">
                <div class="fb-stage-head">
                    <div class="fb-stage-title">
                        <h2>{{ $category->name }}</h2>
                        <span class="ws-pill ws-pill-neutral"><span id="ws-visible-count">{{ $products->count() }}</span> / {{ $products->total() }} 件</span>
                    </div>

                    <div class="fb-stage-controls">
                        <button type="button" id="ws-filter-instock" class="ws-chip-toggle" aria-pressed="false">仅看有货</button>

                        <div class="fb-view-toggle" role="group" aria-label="视图切换">
                            <button type="button" class="ws-view-btn is-active" data-ws-view="grid" aria-pressed="true" aria-label="卡片网格视图" title="卡片网格">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <rect x="3" y="3" width="7" height="7" rx="1"></rect>
                                    <rect x="14" y="3" width="7" height="7" rx="1"></rect>
                                    <rect x="3" y="14" width="7" height="7" rx="1"></rect>
                                    <rect x="14" y="14" width="7" height="7" rx="1"></rect>
                                </svg>
                                <span>网格</span>
                            </button>
                            <button type="button" class="ws-view-btn" data-ws-view="list" aria-pressed="false" aria-label="紧凑列表视图" title="紧凑列表">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                    <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"></path>
                                </svg>
                                <span>列表</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            @if($products->isNotEmpty())
            <div class="ws-catalog-stage" id="ws-catalog-stage" data-view-mode="grid">
                @foreach($products as $product)
                    @themeInclude('partials.product-row', ['product' => $product, 'lcp' => $loop->first, 'eager' => $loop->index < 4])
                @endforeach
            </div>

            <div class="ws-empty" id="ws-filter-empty" hidden>
                <h3>当前筛选条件下暂无匹配商品</h3>
                <p>请取消「仅看有货」或清空筛选关键词后重新查看。</p>
                <button type="button" class="ws-btn ws-btn-secondary ws-btn-sm" id="ws-filter-reset">重置筛选条件</button>
            </div>

            <div class="pagination-wrap">{{ $products->links() }}</div>
            @else
            <div class="ws-empty">
                @themeInclude('partials.image-placeholder', ['class' => 'empty-state-glyph'])
                <h3>{{ request()->filled('q') ? '当前分类下未找到匹配商品' : '当前分类暂无上架商品' }}</h3>
                <p>建议尝试在全部商品目录中重新检索，或切换左侧其他分类查看。</p>
                <div style="display:flex;justify-content:center;gap:10px;flex-wrap:wrap;">
                    @if(request()->filled('q'))
                    <a href="/?q={{ urlencode((string) request('q')) }}" class="ws-btn ws-btn-primary ws-btn-sm">在全部商品中搜索</a>
                    @endif
                    <a href="/" class="ws-btn ws-btn-secondary ws-btn-sm">返回商品目录</a>
                </div>
            </div>
            @endif
        </div>
    </div>

    @themeInclude('partials.quick-drawer')
@endsection
