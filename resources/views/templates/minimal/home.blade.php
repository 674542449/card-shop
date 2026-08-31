@extends(theme_view_path('layout'))

@section('title', setting('seo_default_title', $siteName))
@section('meta_description', setting('seo_default_description', ''))
@section('meta_keywords', setting('seo_default_keywords', ''))

@section('structured_data')
@php
    // 用数组构造再 json_encode，不在模板里直接写字面量 JSON：行首的 "@context" 会被
    // 当成真正的 @context 指令，编译出一个没闭合的 if()，整页 500。@php 块里的内容
    // 在指令编译前就被当作原始块存下来，所以放这里是安全的。
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
    // 只有真的有分类上传过图片时才给缩略图留位。一张都没有的店保持纯文字排版，
    // 而不是多出一列空盘子。与 default 同源，两边的判断必须一致。
    $anyCategoryImage = $categories->contains(fn ($c) => filled($c->image));
@endphp

@section('content')

    @if($siteAnnouncement)
    <blockquote class="site-quote">{!! \App\Support\ContentRenderer::toHtml($siteAnnouncement) !!}</blockquote>
    @endif

    {{--
        分类不再是表头，而是一行安静的小标题加一条延伸到右侧的发丝线（.cat-heading
        的 ::after）。线本身完成分组，不需要再叠一个背景色块——这是这套模板和 default
        最大的结构差别：那边一个分类是一张表，这边一个分类是一段留白里的一组卡片。
    --}}
    @foreach($categories as $category)
        @php $catProducts = $groupedProducts->get($category->id, collect()); @endphp
        @if($catProducts->isNotEmpty())
        <section aria-label="{{ $category->name }}">
            <h2 class="cat-heading">
                @themeInclude('partials.category-thumb', ['category' => $category, 'size' => 18, 'reserve' => $anyCategoryImage])
                {{ $category->name }}
                <span class="cat-count">{{ $catProducts->count() }}</span>
            </h2>

            <div class="card-grid">
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

    {{-- 文章与标签沿用 default 的结构：这两块的样式来自 front.css，而本模板覆盖了
         token 层，所以配色会自动跟着走，不需要在这里重写一遍标记。 --}}
    @if($latestArticles->isNotEmpty() || $recommendedArticles->isNotEmpty())
    <section class="two-col-section">
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
        {{-- 结构与 default 保持一致：这一块的样式来自 front.css，改了类名就会错位。
             正文用 {{ }} 而不是富文本渲染，也和 default 一样——这个字段是纯文本。 --}}
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
