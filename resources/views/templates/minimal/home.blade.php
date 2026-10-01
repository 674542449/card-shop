@extends(theme_view_path('layout'))
@section('title', setting('seo_default_title', $siteName))
@section('meta_description', setting('seo_default_description', ''))
@section('meta_keywords', setting('seo_default_keywords', ''))
@section('canonical', url('/'))
@section('structured_data')
@php $structuredData = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $siteName, 'url' => url('/'), 'description' => setting('seo_default_description', '')]; @endphp
<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
@section('content')
<section class="n-home-hero">
    <div><span class="n-kicker">DIGITAL STORE / 数字商店</span><h1>你需要的，<br>即刻就绪。</h1><p>{{ $siteDescription ?: '挑选数字商品，完成支付，领取卡密。让每一次购买都简单一点。' }}</p><div class="n-actions"><a href="#catalog" class="n-button n-button-primary">浏览商品 <span aria-hidden="true">↓</span></a><a href="/order/query" class="n-button n-button-text">查询已购订单 →</a></div></div>
    <div class="n-home-checklist" aria-label="购买流程"><span class="n-kicker">从选择到使用</span><ol><li><span>01</span><strong>选择商品</strong><small>找到适合你的数字工具</small></li><li><span>02</span><strong>安全付款</strong><small>确认订单金额与支付方式</small></li><li><span>03</span><strong>领取卡密</strong><small>订单页查看、复制或下载</small></li></ol></div>
</section>
@if($siteAnnouncement)<section class="n-announcement" aria-label="站点公告"><span class="n-kicker">公告</span><div class="n-rich">{!! \App\Support\ContentRenderer::toHtml($siteAnnouncement) !!}</div></section>@endif
<section class="n-catalog" id="catalog">
    <div class="n-section-head"><div><span class="n-kicker">CATALOG</span><h2>商品目录 <small>{{ $products->total() }} 件</small></h2></div></div>
<form method="GET" action="{{ request()->url() }}" class="n-catalog-search-form" role="search">
 <label for="global-product-search">搜索全部商品</label><div class="n-catalog-search-controls"><input id="global-product-search" type="search" name="q" value="{{ request('q') }}" maxlength="200" placeholder="商品名称或分类"><button class="n-button n-button-primary" type="submit">搜索</button>@if(request()->filled('q'))<a href="{{ request()->url() }}">清空</a>@endif</div>
 </form>
    <nav class="n-filter-bar" aria-label="商品分类"><a href="/#catalog" class="is-active" aria-current="page">全部商品</a>@foreach($categories as $cat)<a href="/category/{{ $cat->slug }}">{{ $cat->name }} <small>{{ $cat->products_count ?? 0 }}</small></a>@endforeach</nav>
    @if($products->isNotEmpty())
    <div class="n-catalog-grid" id="catalog-grid">@foreach($products as $product)@themeInclude('partials.product-card', ['product' => $product])@endforeach</div>
    <div class="n-empty" id="catalog-empty" hidden><h3>没有找到匹配的商品</h3><p>换一个关键词，或清空搜索查看全部商品。</p></div><p class="n-sr" id="catalog-search-status" role="status" aria-live="polite"></p>
    @else<div class="n-empty">@themeInclude('partials.image-placeholder')<h3>{{ request()->filled("q") ? "没有找到匹配商品" : "商品正在准备中" }}</h3><p>试试其他关键词，或切换商品分类。</p></div>@endif
</section>
{{ $products->links() }}
@if($latestArticles->isNotEmpty())
<section class="n-home-resources"><div class="n-section-head"><div><span class="n-kicker">RESOURCES</span><h2>公告与使用指南</h2></div><a href="/articles" class="n-button n-button-text">全部文章 →</a></div><div class="n-resource-grid">@foreach($latestArticles->take(3) as $article)<a href="/articles/{{ $article->slug }}" class="n-resource-card"><time datetime="{{ $article->created_at?->toDateString() }}">{{ $article->created_at?->format('Y.m.d') }}</time><h3>{{ $article->title }}</h3><p>{{ \Illuminate\Support\Str::limit(trim(strip_tags((string) ($article->summary ?: $article->content))), 96) }}</p><span class="n-resource-arrow" aria-hidden="true">↗</span></a>@endforeach</div></section>
@endif
@if(\App\Support\SafeUrl::asset(setting('contact_qr_image')) || setting('contact_text'))<section class="n-contact"><div><span class="n-kicker">SUPPORT</span><h2>有疑问，随时联系。</h2>@if(setting('contact_text'))<p>{{ setting('contact_text') }}</p>@endif @if(\App\Support\SafeUrl::contact(setting('contact_url')))<a href="{{ \App\Support\SafeUrl::contact(setting('contact_url')) }}" class="n-button n-button-secondary" target="_blank" rel="noopener">联系客服 ↗</a>@endif</div>@if(\App\Support\SafeUrl::asset(setting('contact_qr_image')))<img src="{{ \App\Support\SafeUrl::asset(setting('contact_qr_image')) }}" alt="客服联系二维码" width="104" height="104" loading="lazy" decoding="async">@endif</section>@endif
@endsection