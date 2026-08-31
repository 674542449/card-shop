@extends(theme_view_path('layout'))

@section('title', setting('seo_default_title', $siteName))
@section('meta_description', setting('seo_default_description', ''))
@section('meta_keywords', setting('seo_default_keywords', ''))

@section('structured_data')
@php
    // 数组构造再 json_encode，不写字面量 JSON：行首的 "@context" 会被当成真正的
    // @context 指令，编译出一个没闭合的 if()，整页 500。@php 块在指令编译前就被
    // 当作原始块存下来，放这里才安全。
    $structuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $siteName,
        'url' => url('/'),
        'description' => setting('seo_default_description', ''),
    ];
@endphp
<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endsection

@php
    $anyCategoryImage = $categories->contains(fn ($c) => filled($c->image));
@endphp

@section('content')

    {{-- 首屏。目标站把站点公告放在这块深色卡片里当正文，而不是像 default 那样做成
         一条引用条——公告是这类店铺最该被读到的东西（质保、售后、免责），给它整个
         首屏比给它一行引用更合适。没有配公告时退回一句站点描述，卡片不留空。 --}}
    <section class="hero">
        <span class="hero-badge">欢迎来到 {{ $siteName }}</span>
        <h1>{{ $siteName }}</h1>

        <div class="hero-body">
            @if($siteAnnouncement)
            {!! \App\Support\ContentRenderer::toHtml($siteAnnouncement) !!}
            @elseif($siteDescription)
            <p>{{ $siteDescription }}</p>
            @else
            <p>自动发货，付款后立即获取卡密。支持支付宝、微信与 USDT。</p>
            @endif
        </div>

        <div class="hero-actions">
            <a href="#products" class="hero-btn hero-btn-primary">
                浏览商品
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" width="15" height="15" aria-hidden="true">
                    <path d="M5 12h14M13 6l6 6-6 6"></path>
                </svg>
            </a>
            <a href="/order/query" class="hero-btn hero-btn-ghost">查询订单</a>
        </div>
    </section>

    {{-- 商品。目标站按分类分段展示，每段一个标题加一个网格。 --}}
    <div id="products"></div>

    @foreach($categories as $category)
        @php $catProducts = $groupedProducts->get($category->id, collect()); @endphp
        @if($catProducts->isNotEmpty())
        <section aria-label="{{ $category->name }}">
            <div class="sec-head">
                <div>
                    <h2>{{ $category->name }}</h2>
                    @if($category->description)
                    <p class="sec-sub">{{ $category->description }}</p>
                    @endif
                </div>
                <a href="/category/{{ $category->slug }}" class="sec-link">查看全部 &rsaquo;</a>
            </div>

            <div class="mgrid">
                @foreach($catProducts as $product)
                @themeInclude('partials.product-card', ['product' => $product])
                @endforeach
            </div>
        </section>
        @endif
    @endforeach

    @if($products->isEmpty())
    <div class="empty-state text-center">
        @themeInclude('partials.image-placeholder', ['class' => 'empty-state-glyph'])
        <p>还没有上架任何商品。</p>
    </div>
    @endif

    {{-- 最新动态。目标站用卡片而不是列表——公告在这类店铺里是要被读的内容，
         卡片给得下摘要，列表只给得下标题。 --}}
    @if($latestArticles->isNotEmpty())
    <section>
        <div class="sec-head">
            <div>
                <h2>最新动态</h2>
                <p class="sec-sub">随时掌握我们的最新消息和公告</p>
            </div>
            <a href="/articles" class="sec-link">全部文章 &rsaquo;</a>
        </div>

        <div class="mgrid">
            @foreach($latestArticles->take(3) as $article)
            <a href="/articles/{{ $article->slug }}" class="ncard">
                <span class="ncard-tag">公告</span>
                <h3>{{ $article->title }}</h3>
                <p>{{ \Illuminate\Support\Str::limit(trim(strip_tags((string) $article->content)), 90) }}</p>
                <span class="ncard-more">阅读更多 &rsaquo;</span>
            </a>
            @endforeach
        </div>
    </section>
    @endif

    @if($categories->isNotEmpty())
    <div class="tag-cloud">
        @foreach($categories as $category)
        <a href="/category/{{ $category->slug }}" class="tag-item">
            @themeInclude('partials.category-thumb', ['category' => $category, 'size' => 16, 'reserve' => $anyCategoryImage])
            {{ $category->name }}
        </a>
        @endforeach
    </div>
    @endif

    @if(setting('contact_qr_image') || setting('contact_text'))
    <section class="contact-section">
        @if(setting('contact_qr_image'))
        <img src="{{ setting('contact_qr_image') }}" alt="客服联系二维码" class="qr-img"
             width="96" height="96" loading="lazy" decoding="async">
        @endif
        <div class="contact-text">
            <div class="contact-title">联系我们</div>
            @if(setting('contact_text'))
            <div class="contact-sub">{{ setting('contact_text') }}</div>
            @endif
            <div class="contact-hint">扫描二维码或点击右下角按钮联系客服</div>
        </div>
    </section>
    @endif

@endsection
