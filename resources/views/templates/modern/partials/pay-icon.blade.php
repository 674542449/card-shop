@php
    $payIconKey = (string) ($method ?? '');
    $payMark = $payIconKey === 'alipay' ? '支' : ($payIconKey === 'wechat' ? '微' : (str_starts_with($payIconKey, 'usdt_') ? 'T' : '¥'));
@endphp
<svg @class(['m-pay-svg', 'm-pay-alipay' => $payIconKey === 'alipay', 'm-pay-wechat' => $payIconKey === 'wechat', 'm-pay-usdt' => str_starts_with($payIconKey, 'usdt_')]) viewBox="0 0 32 32" aria-hidden="true" focusable="false">
    <rect x="1" y="1" width="30" height="30" rx="7" fill="currentColor" fill-opacity=".09" stroke="currentColor" stroke-opacity=".25"/>
    <text x="16" y="22" text-anchor="middle" fill="currentColor" font-size="18" font-family="system-ui, 'Microsoft YaHei', sans-serif" font-weight="550">{{ $payMark }}</text>
</svg>