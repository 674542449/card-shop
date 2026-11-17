@switch($status)
    @case('pending') <span class="m-pill m-order-status-pending"><span class="m-order-status-dot" aria-hidden="true"></span>待支付</span> @break
    @case('paid') <span class="m-pill m-pill-success"><svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m3 8 3 3 7-7"/></svg>已支付</span> @break
    @case('expired') <span class="m-pill m-pill-muted">已过期</span> @break
    @case('closed') <span class="m-pill m-pill-danger">已关闭</span> @break
    @default <span class="m-pill m-pill-muted">{{ $status }}</span>
@endswitch
