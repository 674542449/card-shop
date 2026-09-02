@extends(theme_view_path('layout'))

@section('title', $seoTitle)
@section('meta_description', $seoDescription)
@section('meta_keywords', $seoKeywords)
@section('canonical', $currentCategory ? url('/articles/category/' . $currentCategory->slug) : url('/articles'))

@section('content')
{{-- 文章页用主题自己的两栏原语 .articles-layout（1fr + 280px 侧栏），不是商品页的
     .pd-layout——后者只在 front.css 里有规则，配色还是 front.css 那套暖青色。

     标题放在左栏内部而不是栅格外面：兼容层把 .main-container 的上留白清成了 0，
     栅格外的元素会直接贴住页头，而 .articles-layout 自带 28px 上外边距。 --}}
<div class="articles-layout">
    <div class="article-list-wrap">
        <div class="section-header">
            <div class="section-title-wrap">
                <h1 class="section-title">{{ $currentCategory ? $currentCategory->name : '全部文章' }}</h1>
                @if($articles->total() > 0)
                <span class="section-count">共 {{ $articles->total() }} 篇</span>
                @endif
            </div>
        </div>

        @if($articles->count() > 0)
        @foreach($articles as $article)
        {{-- 整张卡片就是那个链接，而不是只有标题那几个字可点。原来的点击区就是标题的
             文字宽度，手机上要精准点中一行字。<a> 里没有第二个可交互元素，所以嵌
             div、p 是合法的。 --}}
        <a href="/articles/{{ $article->slug }}" class="article-card">
            {{-- 缩略块是分类名色带，不是 cover_image：样式表给这个 160px 的槽位
                 只写了宽度，没有任何图片适配规则（object-fit / 高度都没有），真图
                 会按原始比例把这一栏撑变形。分类名在右边的标签里重复出现，所以这
                 里对读屏隐藏。 --}}
            <div class="article-card-thumb" aria-hidden="true">
                <strong>{{ $article->articleCategory->name ?? '文章' }}</strong>
            </div>

            <div class="article-card-content">
                <div class="article-card-header">
                    @if($article->articleCategory)
                    {{-- 模板里的 .article-tag-badge 在样式表里没有任何规则（空类），
                         照抄过来就是一个没有外观的标签。.home-ann-tag 是样式表里
                         唯一等价的品牌色小标签，正好等于模板给文章详情页那个标签
                         打的行内补丁。 --}}
                    <span class="home-ann-tag">{{ $article->articleCategory->name }}</span>
                    @endif
                    <span class="article-card-title">{{ $article->title }}</span>
                </div>

                @if(filled($article->summary))
                {{-- 摘要由运营手写，长度没有上限，而 .article-card-excerpt 不截断行数；
                     不设上限的话一条长摘要会把这张卡撑到半屏高。 --}}
                <p class="article-card-excerpt">{{ \Illuminate\Support\Str::limit(strip_tags($article->summary), 120) }}</p>
                @endif

                <div class="article-card-footer">
                    <time datetime="{{ $article->created_at->toDateString() }}">{{ $article->created_at->format('Y-m-d') }}</time>
                    <span class="home-ann-link">阅读全文 →</span>
                </div>
            </div>
        </a>
        @endforeach

        {{-- .pagination-wrap 这层不能省：分页器本身的样式全挂在 .pagination-wrap a /
             .pagination-wrap .pg-current 上。 --}}
        <div class="pagination-wrap">{{ $articles->links() }}</div>
        @else
        <div class="empty-state">
            <svg class="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>
            </svg>
            <h2 class="success-title">这里还没有文章</h2>
            <p class="success-subtitle">{{ $currentCategory ? '这个分类下暂时没有内容，看看其他分类。' : '公告和教程发布后会出现在这里。' }}</p>
            <a href="/" class="btn-buy">返回首页</a>
        </div>
        @endif
    </div>

    <aside class="articles-sidebar">
        <div class="sidebar-widget">
            <h2 class="widget-title">文章分类</h2>
            <ul class="widget-list">
                <li>
                    {{-- 高亮类是 active，不是旧主题的 is-current：新样式表只写了
                         .widget-list a.active。 --}}
                    <a href="/articles" @class(['active' => ! $currentCategory]) @if(! $currentCategory) aria-current="page" @endif>全部文章</a>
                </li>
                @foreach($categories as $cat)
                <li>
                    <a href="/articles/category/{{ $cat->slug }}"
                       @class(['active' => $currentCategory && $currentCategory->id === $cat->id])
                       @if($currentCategory && $currentCategory->id === $cat->id) aria-current="page" @endif>{{ $cat->name }}</a>
                </li>
                @endforeach
            </ul>
        </div>

        <div class="sidebar-widget">
            <h2 class="widget-title">快捷指引</h2>
            {{-- 这段说明没有专属类可用；.article-card-excerpt 是样式表里现成的
                 「灰色小正文」，借它而不是自造一个样式表里不存在的类名。 --}}
            <p class="article-card-excerpt">付款后卡密会直接显示在页面上，同时发一份到你下单时填的邮箱。想再看一次，用邮箱加下单时设置的查询密码到 <a href="/order/query" class="home-ann-link">查询订单</a> 里打开。</p>
        </div>
    </aside>
</div>
@endsection
