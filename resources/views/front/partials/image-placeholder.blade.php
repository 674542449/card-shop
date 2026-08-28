{{--
    Quiet fallback for a missing product image.

    Inline SVG rather than an emoji glyph: emoji render at wildly different
    weights and colours per platform (a bright orange parcel on Windows, a flat
    grey one on Android) and always read louder than the surrounding UI. A
    stroked card mark inherits currentColor, so the placeholder stays as quiet
    as whatever container it sits in.

    Params:
      $class - wrapper class, e.g. 'card-img-placeholder' | 'prod-thumb-ph'
--}}
@php $placeholderClass = $class ?? 'card-img-placeholder'; @endphp
<div class="{{ $placeholderClass }}" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4"
         stroke-linecap="round" stroke-linejoin="round" focusable="false" role="presentation">
        <rect x="2.5" y="5.5" width="19" height="13" rx="2"></rect>
        <path d="M2.5 10h19"></path>
        <path d="M6.5 14.5h4"></path>
    </svg>
</div>
