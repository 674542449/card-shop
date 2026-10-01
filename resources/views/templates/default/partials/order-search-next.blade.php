<div style="padding:16px;margin-bottom:16px;border:1px solid #ddd;border-radius:8px">
    <p>{{ ($hasMore ?? false) ? "本次展示一批匹配订单，可以继续查询下一批。" : "本次匹配订单已展示。可重新查询，或填写订单号精确找回。" }}</p>
    @if($hasMore ?? false)
    <form action="/order/query/page" method="POST" data-guard>
        @csrf
        @if(setting('turnstile_site_key'))<div class="cf-turnstile" data-sitekey="{{ setting('turnstile_site_key') }}" data-size="compact"></div>@endif
        <button type="submit">继续查询下一批</button>
    </form>
    @else<p>本批次已查询完成。<a href="/order/query">重新查询</a></p>@endif
</div>
