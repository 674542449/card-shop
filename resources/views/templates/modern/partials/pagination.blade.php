@if($paginator->hasPages())
<nav class="m-pagination" aria-label="分页导航">
    @if($paginator->onFirstPage())<span class="is-disabled" aria-disabled="true">上一页</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">上一页</a>@endif
    @foreach($elements as $element)
        @if(is_string($element))<span class="is-gap" aria-hidden="true">{{ $element }}</span>@endif
        @if(is_array($element))
            @foreach($element as $page => $url)
                @if($page == $paginator->currentPage())<span class="is-current" aria-current="page">{{ $page }}</span>@else<a href="{{ $url }}" aria-label="第 {{ $page }} 页">{{ $page }}</a>@endif
            @endforeach
        @endif
    @endforeach
    @if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">下一页</a>@else<span class="is-disabled" aria-disabled="true">下一页</span>@endif
</nav>
@endif