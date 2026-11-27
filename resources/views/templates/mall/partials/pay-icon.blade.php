@php
    $payIconKey = (string) ($method ?? '');
    $payMark = $payIconKey === 'alipay' ? '支' : ($payIconKey === 'wechat' ? '微' : (str_starts_with($payIconKey, 'usdt_') ? 'T' : '¥'));
@endphp
<svg @class(['ml-pay-svg', 'ml-pay-alipay' => $payIconKey === 'alipay', 'ml-pay-wechat' => $payIconKey === 'wechat', 'ml-pay-usdt' => str_starts_with($payIconKey, 'usdt_')]) viewBox="0 0 32 32" aria-hidden="true" focusable="false">
    <rect x="1.5" y="1.5" width="29" height="29" rx="8" fill="currentColor" fill-opacity=".1" stroke="currentColor" stroke-opacity=".28"/>
    <text x="16" y="21.5" text-anchor="middle" fill="currentColor" font-size="16" font-family="system-ui, 'Microsoft YaHei', sans-serif" font-weight="600">{{ $payMark }}</text>
</svg>
