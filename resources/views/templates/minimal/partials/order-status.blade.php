@php
    $labels = ['pending' => '待支付', 'paid' => '已支付', 'expired' => '已过期', 'closed' => '已关闭'];
    $state = in_array($status, ['pending', 'paid', 'expired', 'closed'], true) ? $status : 'unknown';
@endphp
<span class="n-status n-status-{{ $state }}"><span aria-hidden="true"></span>{{ $labels[$status] ?? $status }}</span>