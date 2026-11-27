@switch($status)
    @case('pending')
        <span class="ml-badge ml-badge-warn"><i aria-hidden="true"></i>待支付</span>
        @break
    @case('paid')
        <span class="ml-badge ml-badge-live"><i aria-hidden="true"></i>已支付</span>
        @break
    @case('expired')
        <span class="ml-badge ml-badge-neutral">已过期</span>
        @break
    @case('closed')
        <span class="ml-badge ml-badge-danger">已关闭</span>
        @break
    @default
        <span class="ml-badge ml-badge-neutral">{{ $status }}</span>
@endswitch
