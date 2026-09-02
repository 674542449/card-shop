{{--
    前台分页器。

    这个文件不是被 @themeInclude 引进来的，而是 AppServiceProvider 里
    `Paginator::defaultView(theme_view_path('partials.pagination'))` 指过来的——也就是说
    商品列表页和文章列表页的每一次 ->links() 都渲染它。因此 markup 是契约：
    `<nav role="navigation">` 外壳、上一页/下一页的 .pg-disabled、当前页的 .pg-current。
    改名会让 front.css 里那组按这三个名字写的规则全部落空，页面不报错，只是分页条变成
    一串裸链接。

    没有它的时候 ->links() 会回落到 Laravel 自带的视图，那份是给 Tailwind 写的：
    本站没有 Tailwind，于是 `sm:hidden` 不再隐藏，移动端和桌面端两套块前后各渲染一遍，
    箭头 SVG 失去尺寸约束，翻页文案还是英文的 "« Previous"。

    外观：本主题没有自己的分页组件，走的是 front.css 的 `.pagination-wrap nav` 那一组
    （34px 药丸、8px 圆角、当前页填品牌色）。那些规则全是 token 驱动的，而本主题在样式表
    开头把 --teal / --card-bg / --radius 重指到了自己的配色，所以观感自动跟着走，深色模式
    也一样。**前提是调用方把它包在 `<div class="pagination-wrap">` 里**——那些规则一条都
    没有落在 nav 自身上，少了外层容器，分页条会完全没有样式。
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="分页导航">
        @if ($paginator->onFirstPage())
            <span class="pg-disabled" aria-disabled="true">上一页</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">上一页</a>
        @endif

        @foreach ($elements as $element)
            {{-- A string element is the "..." gap the paginator inserts. --}}
            @if (is_string($element))
                <span class="pg-disabled" aria-hidden="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pg-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" aria-label="第 {{ $page }} 页">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">下一页</a>
        @else
            <span class="pg-disabled" aria-disabled="true">下一页</span>
        @endif
    </nav>
@endif
