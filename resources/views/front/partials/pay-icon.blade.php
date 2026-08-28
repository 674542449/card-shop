{{--
    Payment gateway mark, inline so it costs no request and cannot 404.

    Built from geometric primitives rather than traced logo outlines: they stay legible
    at 24px, and each one carries its own colour so it sits on the tile's white
    background without needing a plate behind it.

    Params:
      $method - one of alipay | wechat | usdt_* (anything else falls through to a
                neutral card mark, so an unrecognised gateway still renders something)
--}}
@php $payIconKey = str_starts_with((string) ($method ?? ''), 'usdt_') ? 'usdt' : ($method ?? ''); @endphp

@if($payIconKey === 'alipay')
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
        <rect width="32" height="32" rx="7" fill="#1677ff"/>
        <g fill="#fff">
            <rect x="8" y="9" width="16" height="2" rx="1"/>
            <rect x="15" y="6" width="2" height="9" rx="1"/>
            <path d="M9 22.4c0-2 1.8-3.2 4.2-3.2 3 0 6.3 1.2 9.8 2.6l-.9 2.2c-3.2-1.3-6.1-2.4-8.5-2.4-1.3 0-2.1.5-2.1 1.2 0 .7.7 1.2 1.9 1.2 1.7 0 3-1 4-2.6l2 1.1c-1.4 2.2-3.4 3.6-6.1 3.6-2.6 0-4.3-1.4-4.3-3.4z"/>
        </g>
    </svg>
@elseif($payIconKey === 'wechat')
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
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
@elseif($payIconKey === 'usdt')
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
        <circle cx="16" cy="16" r="15" fill="#26a17b"/>
        <g fill="#fff">
            <rect x="7.5" y="8" width="17" height="3.4" rx="1"/>
            <rect x="14.2" y="8" width="3.6" height="16" rx="1"/>
        </g>
        <ellipse cx="16" cy="14.2" rx="7.4" ry="2.6" fill="none" stroke="#fff" stroke-width="2"/>
    </svg>
@else
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
        <rect x="2" y="6" width="28" height="20" rx="3" fill="#009688"/>
        <rect x="2" y="11" width="28" height="3.5" fill="#fff" opacity=".9"/>
        <rect x="6" y="19" width="9" height="2.5" rx="1.2" fill="#fff" opacity=".9"/>
    </svg>
@endif
