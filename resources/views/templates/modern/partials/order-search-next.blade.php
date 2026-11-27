<section class="m-panel m-order-pager-bar">
    <p class="m-muted">{{ ($hasMore ?? false) ? '本次展示一批匹配订单，可继续查询下一批记录。' : '本次匹配订单已展示完毕；如需精确找回旧订单，可填写订单号重新查询。' }}</p>
    @if($hasMore ?? false)
    <form action="/order/query/page" method="POST" class="m-order-pager-form" data-guard>
        @csrf
        @if(turnstile_site_key())<div class="cf-turnstile" data-sitekey="{{ turnstile_site_key() }}" data-size="compact"></div>@endif
        <button class="m-button m-button-primary m-button-sm" type="submit">继续查询下一批 ↗</button>
    </form>
    @else
    <a class="m-text-link" href="/order/query">重新查询 ↗</a>
    @endif
</section>
