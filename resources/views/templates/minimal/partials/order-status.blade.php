@php
    $labels = ['pending' => '待支付', 'paid' => '已支付', 'expired' => '已过期', 'closed' => '已关闭'];
    $state = in_array($status, ['pending', 'paid', 'expired', 'closed'], true) ? $status : 'unknown';
@endphp
<span class="n-status n-status-{{ $state }}">
    @if($state === 'paid')
    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>
    @elseif($state === 'pending')
    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
    @elseif($state === 'closed')
    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6m0-6 6 6"/></svg>
    @else
    <span aria-hidden="true"></span>
    @endif
    {{ $labels[$status] ?? $status }}
</span>
