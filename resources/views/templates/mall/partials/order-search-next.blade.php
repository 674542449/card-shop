<section class="ml-card ml-order-pager">
    <p class="ml-muted">{{ ($hasMore ?? false) ? '本次展示了一批匹配订单，点击按钮继续加载下一批记录。' : '当前匹配订单已全部展示完毕；如需精确找回历史订单，可填写订单号重新查询。' }}</p>
    @if($hasMore ?? false)
    <form action="/order/query/page" method="POST" class="ml-order-pager-form" data-guard>
        @csrf
        @if(turnstile_site_key())<div class="cf-turnstile" data-sitekey="{{ turnstile_site_key() }}" data-size="compact"></div>@endif
        <button class="ml-btn ml-btn-primary ml-btn-sm" type="submit">继续查询下一批 →</button>
    </form>
    @else
    <a class="ml-link" href="/order/query">重新查询 →</a>
    @endif
</section>
