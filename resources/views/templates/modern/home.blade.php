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

    {{-- 大 Hero 已移除：它是纯装饰性首屏，桌面上要吃掉约 350px（48px 上下内距 +
         站名 h1 + 按钮 + 40px 下外边距），1080p 视口因此少看到一整排商品；而公告是
         运营写的富文本，几段话就能把它顶到 600px+，把商品整个挤出首屏。

         但公告本身是这类店铺最该被读到的东西（质保、售后、免责），不能跟着一起删。
         所以保留内容、只换形态：一条窄通知条，没有配公告时整块不渲染。
         站名 h1 也一并去掉——layout 里已经有一个 seo-h1，原来那个是页面上第二个 h1。
         「查询订单」按钮不补：页头导航里本来就有。 --}}
    @if($siteAnnouncement)
    <section class="mnotice" aria-label="站点公告">
        <span class="mnotice-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 11v2a1 1 0 0 0 1 1h2l4.5 3.5a1 1 0 0 0 1.5-.9V7.4a1 1 0 0 0-1.5-.9L6 10H4a1 1 0 0 0-1 1z"/>
                <path d="M16.5 9a4 4 0 0 1 0 6"/>
            </svg>
        </span>
        <div class="mnotice-body rich-text">
            {!! \App\Support\ContentRenderer::toHtml($siteAnnouncement) !!}
        </div>
    </section>
    @endif

    {{-- 商品。目标站按分类分段展示，每段一个标题加一个网格。 --}}

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

        <div class="mgrid mgrid-news">
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
