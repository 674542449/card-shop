@switch($status)
    @case('pending')
        <span class="ml-badge ml-badge-warn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            <span>待支付</span>
        </span>
        @break
    @case('paid')
        <span class="ml-badge ml-badge-live">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>
            <span>已支付</span>
        </span>
        @break
    @case('expired')
        <span class="ml-badge ml-badge-neutral">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
            <span>已过期</span>
        </span>
        @break
    @case('closed')
        <span class="ml-badge ml-badge-danger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6m0-6 6 6"/></svg>
            <span>已关闭</span>
        </span>
        @break
    @default
        <span class="ml-badge ml-badge-neutral">{{ $status }}</span>
@endswitch
