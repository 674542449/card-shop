@extends('layouts.front')

@section('title', setting('seo_default_title', $siteName))
@section('meta_description', setting('seo_default_description', ''))
@section('meta_keywords', setting('seo_default_keywords', ''))

@section('structured_data')
@php
    // Built as an array, not as literal JSON in the template: "@context" at the start
    // of a line is the real @context Blade directive, which compiles to an unclosed
    // if() and takes the whole page down with a 500. Inside @php the content is stored
    // as a raw block before directives are compiled, so it is safe here.
    $structuredData = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $siteName,
        'url' => url('/'),
        'description' => setting('seo_default_description', ''),
    ];
@endphp
<script type="application/ld+json">{!! json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@section('content')
    @if($siteAnnouncement)
    <blockquote class="site-quote">{!! nl2br(e($siteAnnouncement)) !!}</blockquote>
    @endif

    {{-- Desktop: Product Tables grouped by category --}}
    @foreach($categories as $category)
        @php $catProducts = $groupedProducts->get($category->id, collect()); @endphp
        @if($catProducts->isNotEmpty())
        <section class="product-table-section desktop-only" aria-label="{{ $category->name }}">
            <table class="product-table">
                <thead>
                    <tr>
                        <th>
                            <span class="cat-head">
                                @include('front.partials.category-thumb', ['category' => $category, 'size' => 20])
                                {{ $category->name }}
                            </span>
                        </th>
                        <th style="width:100px">发货模式</th>
                        <th style="width:80px">库存</th>
                        <th style="width:100px">单价</th>
                        <th style="width:100px">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($catProducts as $product)
                    @include('front.partials.product-row', ['product' => $product])
                    @endforeach
                </tbody>
            </table>
        </section>
        @endif
    @endforeach

    {{-- Mobile: Product Card Grid --}}
    @foreach($categories as $category)
        @php $catProducts = $groupedProducts->get($category->id, collect()); @endphp
        @if($catProducts->isNotEmpty())
        <section class="mobile-only" aria-label="{{ $category->name }}">
            <h2 class="card-section-title">
                @include('front.partials.category-thumb', ['category' => $category, 'size' => 22])
                {{ $category->name }}
            </h2>
            <div class="product-grid">
                @foreach($catProducts as $product)
                @include('front.partials.product-card', ['product' => $product])
                @endforeach
            </div>
        </section>
        @endif
    @endforeach

    {{-- Articles: Two Column --}}
    @if($latestArticles->isNotEmpty() || $recommendedArticles->isNotEmpty())
    <section class="two-col-section mt-3">
        <div class="article-block">
            <div class="article-block-header">
                <h3>最新发布</h3>
                <a href="/articles">更多 &raquo;</a>
            </div>
            <ol class="article-list">
                @foreach($latestArticles as $article)
                <li>
                    <a href="/articles/{{ $article->slug }}">{{ $article->title }}</a>
                    <span class="date">{{ $article->created_at->format('m-d') }}</span>
                </li>
                @endforeach
            </ol>
        </div>
        <div class="article-block">
            <div class="article-block-header">
                <h3>推荐文章</h3>
                <a href="/articles">更多 &raquo;</a>
            </div>
            <ol class="article-list">
                @foreach($recommendedArticles as $article)
                <li>
                    <a href="/articles/{{ $article->slug }}">{{ $article->title }}</a>
                    <span class="date">{{ $article->created_at->format('m-d') }}</span>
                </li>
                @endforeach
            </ol>
        </div>
    </section>

    <div class="text-center">
        <a href="/articles" class="btn-all-articles">阅读所有文章</a>
    </div>
    @endif

    {{-- Category Tags --}}
    @if($categories->isNotEmpty())
    <div class="tag-cloud mt-3">
        @foreach($categories as $category)
        <a href="/category/{{ $category->slug }}" class="tag-item">
            @include('front.partials.category-thumb', ['category' => $category, 'size' => 16])
            {{ $category->name }}
        </a>
        @endforeach
    </div>
    @endif

    {{-- Contact Section --}}
    @if(setting('contact_qr_image') || setting('contact_text'))
    <section class="contact-section">
        @if(setting('contact_qr_image'))
        <img src="{{ setting('contact_qr_image') }}" alt="联系二维码" class="qr-img">
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
