{{--
    Payment gateway mark, inline so it costs no request and cannot 404.

    Built from geometric primitives rather than traced logo outlines: they stay legible
    at 24px, and each one carries its own colour so it sits on the tile's white
    background without needing a plate behind it.

    Params:
      $method - one of alipay | wechat | usdt_* (anything else falls through to a
                neutral card mark, so an unrecognised gateway still renders something)
--}}
@php $payIconKey = (string) ($method ?? ''); @endphp

@if($payIconKey === 'alipay')
    {{-- Alipay's identity is a blue plate carrying 支. Drawing that stroke as a path by
         hand produced a shape that read as neither the logo nor a character, so the
         glyph itself is used: it cannot render wrong on a device that has a Chinese
         font, which every visitor to this shop does. --}}
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
        <rect width="32" height="32" rx="7" fill="#1677ff"/>
        <text x="16" y="23" text-anchor="middle" fill="#fff"
              font-size="20" font-weight="700"
              font-family="-apple-system, BlinkMacSystemFont, 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', sans-serif">支</text>
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
@elseif($payIconKey === 'usdt_trc20')
    {{-- TRON. Its mark is an angular triangle; drawn as an outline so it reads as a
         shape rather than a solid blob at 22px. --}}
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
        <circle cx="16" cy="16" r="15" fill="#ef0027"/>
        <path d="M8 9.5 23.5 12.3 16.3 25.5Z" fill="none" stroke="#fff"
              stroke-width="1.9" stroke-linejoin="round"/>
        <path d="M8 9.5 13.6 14.4 23.5 12.3M13.6 14.4 16.3 25.5" fill="none" stroke="#fff"
              stroke-width="1.4" stroke-linejoin="round"/>
    </svg>
@elseif($payIconKey === 'usdt_bep20')
    {{-- BNB Chain: four diamonds around a fifth. Explicit polygon points rather than
         rotated squares, so the geometry is exact at any size. --}}
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
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
    {{-- Polygon: a hexagon outline, the shape the network is named for. --}}
    <svg class="pay-svg" viewBox="0 0 32 32" role="img" aria-hidden="true" focusable="false">
        <circle cx="16" cy="16" r="15" fill="#8247e5"/>
        <polygon points="16,6.8 23.9,11.4 23.9,20.6 16,25.2 8.1,20.6 8.1,11.4"
                 fill="none" stroke="#fff" stroke-width="2.2" stroke-linejoin="round"/>
        <circle cx="16" cy="16" r="2.6" fill="#fff"/>
    </svg>
@elseif(str_starts_with($payIconKey, 'usdt_'))
    {{-- A USDT network we have not drawn: the Tether disc still identifies the token. --}}
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
