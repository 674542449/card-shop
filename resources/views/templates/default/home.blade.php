@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', setting('seo_default_description', '') ?: $siteDescription)
@section('canonical', $seoCanonical)
@section('meta_keywords', setting('seo_default_keywords', ''))

@section('structured_data')
@php
    $homeSchemaItems = $products->getCollection()
        ->map(fn ($p) => ['name' => (string) $p->name, 'url' => \App\Support\Seo::url('/product/' . $p->slug)])
        ->all();
@endphp
{{ \App\Support\Seo::jsonLdScript(\App\Support\Seo::homeSchema((string) $siteName, (string) (setting('seo_default_description') ?: $siteDescription), $homeSchemaItems)) }}
@endsection

@section('content')
    <div class="fb-marketplace-layout" id="catalog">
        {{-- Facebook Marketplace Left Navigation Rail --}}
        <aside class="fb-left-rail" aria-label="商城导航与分类">
            <div class="fb-rail-card">
                <div class="fb-rail-head">
                    <h1>{{ $siteName }}</h1>
                    <span class="fb-live-pill"><i aria-hidden="true"></i>24/7 自动交付</span>
                </div>
                @if($siteDescription)
                <p class="fb-rail-desc">{{ $siteDescription }}</p>
                @endif

                <search>
                    <form method="GET" action="{{ request()->url() }}" class="fb-rail-search">
                        <label for="global-product-search" class="ws-sr-only">搜索全部商品</label>
                        <div class="fb-search-pill">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="11" cy="11" r="7"></circle>
                                <path d="M20 20l-3.5-3.5"></path>
                            </svg>
                            <input id="global-product-search" type="search" name="q" value="{{ request('q') }}" maxlength="200" placeholder="搜索全部商品">
                            @if(request()->filled('q'))
                            <a href="{{ request()->url() }}" class="fb-search-clear" title="清空搜索" aria-label="清空搜索关键词">&times;</a>
                            @endif
                        </div>
                        <button type="submit" class="ws-btn ws-btn-primary ws-btn-sm fb-search-submit">搜索</button>
                    </form>
                </search>

                <hr class="fb-divider">

                <div class="fb-rail-section-title">
                    <span>商品分类</span>
                    <small>共 {{ $categories->count() }} 类</small>
                </div>

                <nav class="fb-cat-list" aria-label="商品分类">
                    <a href="/" class="fb-cat-item {{ !request()->filled('q') ? 'is-active' : '' }}" @if(!request()->filled('q')) aria-current="page" @endif>
                        <span class="fb-cat-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
                                <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
                                <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
                                <rect x="14" y="14" width="7" height="7" rx="1.5"></rect>
                            </svg>
                        </span>
                        <span class="fb-cat-name">浏览全部商品</span>
                        <span class="fb-cat-badge">{{ $catalogTotal }}</span>
                    </a>

                    @foreach($categories as $category)
                    @php $catImg = \App\Support\SafeUrl::asset($category->image); @endphp
                    <a href="/category/{{ $category->slug }}" class="fb-cat-item">
                        <span class="fb-cat-icon" aria-hidden="true">
                            @if($catImg)
                            <img src="{{ $catImg }}" alt="" width="36" height="36" decoding="async">
                            @else
                            <span>{{ mb_substr($category->name, 0, 1) }}</span>
                            @endif
                        </span>
                        <span class="fb-cat-name">{{ $category->name }}</span>
                        <span class="fb-cat-badge">{{ $category->products_count ?? 0 }}</span>
                    </a>
                    @endforeach
                </nav>
            </div>
        </aside>

        {{-- Facebook Marketplace Main Feed & Product Stage --}}
        <div class="fb-main-stage">
            @if($siteAnnouncement)
            <section class="fb-feed-banner" aria-label="站点公告">
                <div class="fb-banner-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                </div>
                <div class="ws-notice-body rich-text">{!! \App\Support\ContentRenderer::toHtml($siteAnnouncement) !!}</div>
            </section>
            @endif

            {{-- Marketplace Filter & View Bar --}}
            <div class="fb-stage-toolbar">
                <div class="fb-stage-head">
                    <div class="fb-stage-title">
                        <h2>{{ request()->filled('q') ? '「' . e(request('q')) . '」的检索结果' : '在售数字商品' }}</h2>
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
                <p>请取消「仅看有货」或重置筛选条件后重新查看。</p>
                <button type="button" class="ws-btn ws-btn-secondary ws-btn-sm" id="ws-filter-reset">重置筛选条件</button>
            </div>

            <div class="pagination-wrap">{{ $products->links() }}</div>
            @else
            <div class="ws-empty">
                @themeInclude('partials.image-placeholder', ['class' => 'empty-state-glyph'])
                <h3>{{ request()->filled('q') ? '未找到匹配的商品' : '暂无上架商品' }}</h3>
                <p>{{ request()->filled('q') ? '请更换检索关键词，或清空搜索条件查看全部商品。' : '当前目录暂无可售商品。' }}</p>
                @if(request()->filled('q'))
                <a href="{{ request()->url() }}" class="ws-btn ws-btn-primary ws-btn-sm">查看全部商品</a>
                @endif
            </div>
            @endif

            @if($latestArticles->isNotEmpty())
            <section class="ws-section" aria-label="公告与帮助文档">
                <div class="ws-section-head">
                    <h2>公告与帮助指南</h2>
                    <a href="/articles">查看全部<span class="ws-sr-only">公告与帮助指南</span> &rarr;</a>
                </div>
                <div class="ws-guide-grid">
                    @foreach($latestArticles->take(4) as $article)
                    @php
                        $guideExcerpt = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) preg_replace('/\[\[product:\d+\]\]/', '', (string) ($article->summary ?: $article->content)))));
                    @endphp
                    <a href="/articles/{{ $article->slug }}" class="ws-guide-card">
                        <div>
                            <time datetime="{{ $article->created_at?->toDateString() }}">{{ $article->created_at?->format('Y-m-d') }}</time>
                            <h3>{{ $article->title }}</h3>
                        </div>
                        <p>{{ \Illuminate\Support\Str::limit($guideExcerpt ?: '点击查看完整说明文档。', 64) }}</p>
                    </a>
                    @endforeach
                </div>
            </section>
            @endif

            @if(\App\Support\SafeUrl::asset(setting('contact_qr_image')) || setting('contact_text') || \App\Support\SafeUrl::contact(setting('contact_url')))
            <section class="ws-support-banner" aria-label="客户服务支持">
                <div class="ws-support-left">
                    @if(\App\Support\SafeUrl::asset(setting('contact_qr_image')))
                    <img src="{{ \App\Support\SafeUrl::asset(setting('contact_qr_image')) }}" alt="客服联系二维码" class="ws-support-qr"
                         width="72" height="72" loading="lazy" decoding="async">
                    @endif
                    <div>
                        <h3>客户服务支持</h3>
                        @if(setting('contact_text'))
                        <p>{{ setting('contact_text') }}</p>
                        @endif
                        <p>如需人工协助，请联系在线客服并提供对应订单编号以便核实处理。</p>
                    </div>
                </div>
                @if(\App\Support\SafeUrl::contact(setting('contact_url')))
                <a href="{{ \App\Support\SafeUrl::contact(setting('contact_url')) }}" class="ws-btn ws-btn-primary" target="_blank" rel="noopener">联系在线客服 &nearr;</a>
                @endif
            </section>
            @endif
        </div>
    </div>

    @themeInclude('partials.quick-drawer')
@endsection
