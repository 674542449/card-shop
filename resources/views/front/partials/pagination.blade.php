{{--
    The storefront's paginator.

    Without this, ->links() fell back to Laravel's default view, which is written for
    Tailwind. This site has no Tailwind, so every utility class was inert: `sm:hidden`
    stopped hiding, so the mobile and desktop blocks rendered one after the other, the
    `w-5 h-5` arrow SVGs lost their size constraint, and the labels read "« Previous"
    in English on a Chinese shop.

    front.css already had rules waiting for a paginator (.pagination-wrap nav and
    friends); this is the markup they were written for.
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
