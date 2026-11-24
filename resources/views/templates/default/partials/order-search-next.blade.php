<div class="search-batch-bar">
    <p>{{ ($hasMore ?? false) ? '当前仅显示部分匹配记录，可继续加载后续订单。' : '已显示全部匹配记录。如需精确检索，请在查询页面输入对应订单编号。' }}</p>
    @if($hasMore ?? false)
    <form action="/order/query/page" method="POST" data-guard>
        @csrf
        @if(turnstile_site_key())<div class="cf-turnstile" data-sitekey="{{ turnstile_site_key() }}" data-size="compact"></div>@endif
        <button type="submit" class="ws-btn ws-btn-primary ws-btn-sm">加载下一批订单</button>
    </form>
    @else
    <a href="/order/query" class="ws-btn ws-btn-secondary ws-btn-sm">重新查询</a>
    @endif
</div>
