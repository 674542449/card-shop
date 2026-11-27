@php $payIconKey = (string) ($method ?? ''); @endphp
@if($payIconKey === 'alipay')
<svg class="m-pay-svg m-pay-alipay" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
    <rect x="1" y="1" width="30" height="30" rx="8" fill="#1677ff"/>
    <text x="16" y="22.5" text-anchor="middle" fill="#fff" font-size="18" font-weight="700" font-family="system-ui, 'PingFang SC', 'Microsoft YaHei', sans-serif">支</text>
</svg>
@elseif($payIconKey === 'wechat')
<svg class="m-pay-svg m-pay-wechat" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
    <g fill="#07c160">
        <ellipse cx="12.5" cy="12" rx="9.5" ry="7.8"/>
        <path d="M6.4 17.4 4.2 21.3l4.6-2.2z"/>
        <ellipse cx="21.5" cy="20.5" rx="8.2" ry="6.8"/>
        <path d="M26.6 25.3 28.6 29l-4.3-2.1z"/>
    </g>
    <g fill="#fff">
        <circle cx="9.4" cy="10.4" r="1.3"/>
        <circle cx="15.6" cy="10.4" r="1.3"/>
        <circle cx="18.8" cy="19" r="1.1"/>
        <circle cx="24.2" cy="19" r="1.1"/>
    </g>
</svg>
@elseif($payIconKey === 'usdt_trc20')
<svg class="m-pay-svg m-pay-usdt" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
    <circle cx="16" cy="16" r="15" fill="#ef0027"/>
    <path d="M8 9.5 23.5 12.3 16.3 25.5Z" fill="none" stroke="#fff" stroke-width="1.9" stroke-linejoin="round"/>
    <path d="M8 9.5 13.6 14.4 23.5 12.3M13.6 14.4 16.3 25.5" fill="none" stroke="#fff" stroke-width="1.4" stroke-linejoin="round"/>
</svg>
@elseif($payIconKey === 'usdt_bep20')
<svg class="m-pay-svg m-pay-usdt" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
    <circle cx="16" cy="16" r="15" fill="#f0b90b"/>
    <g fill="#fff">
        <polygon points="16,6.4 19.1,9.5 16,12.6 12.9,9.5"/>
        <polygon points="16,19.4 19.1,22.5 16,25.6 12.9,22.5"/>
        <polygon points="9.5,12.9 12.6,16 9.5,19.1 6.4,16"/>
        <polygon points="22.5,12.9 25.6,16 22.5,19.1 19.4,16"/>
        <polygon points="16,12.9 19.1,16 16,19.1 12.9,16"/>
    </g>
</svg>
@elseif($payIconKey === 'usdt_polygon')
<svg class="m-pay-svg m-pay-usdt" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
    <circle cx="16" cy="16" r="15" fill="#8247e5"/>
    <polygon points="16,6.8 23.9,11.4 23.9,20.6 16,25.2 8.1,20.6 8.1,11.4" fill="none" stroke="#fff" stroke-width="2.2" stroke-linejoin="round"/>
    <circle cx="16" cy="16" r="2.6" fill="#fff"/>
</svg>
@elseif(str_starts_with($payIconKey, 'usdt_'))
<svg class="m-pay-svg m-pay-usdt" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
    <circle cx="16" cy="16" r="15" fill="#26a17b"/>
    <g fill="#fff">
        <rect x="7.5" y="8" width="17" height="3.4" rx="1"/>
        <rect x="14.2" y="8" width="3.6" height="16" rx="1"/>
    </g>
    <ellipse cx="16" cy="14.2" rx="7.4" ry="2.6" fill="none" stroke="#fff" stroke-width="2"/>
</svg>
@else
<svg class="m-pay-svg" viewBox="0 0 32 32" aria-hidden="true" focusable="false">
    <rect x="2" y="6" width="28" height="20" rx="4" fill="currentColor" fill-opacity=".16" stroke="currentColor" stroke-width="1.6"/>
    <rect x="2" y="11" width="28" height="3" fill="currentColor" fill-opacity=".5"/>
    <rect x="6" y="19" width="9" height="2.4" rx="1.2" fill="currentColor" fill-opacity=".7"/>
</svg>
@endif
