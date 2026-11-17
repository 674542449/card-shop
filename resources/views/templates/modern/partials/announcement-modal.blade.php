@php
    $popupHtml = \App\Support\ContentRenderer::toHtml(setting('popup_announcement'));
    $popupHours = max(0, (int) setting('popup_interval_hours', 24));
    $popupSignature = substr(md5($popupHtml), 0, 12);
@endphp
@if($popupHtml !== '')
<div class="m-ann-modal" id="announcement-modal" hidden data-signature="{{ $popupSignature }}" data-hours="{{ $popupHours }}" data-countdown="5">
    <div class="m-ann-backdrop" data-ann-dismiss></div>
    <div class="ann-modal-box m-ann-box" role="dialog" aria-modal="true" aria-labelledby="ann-modal-title" tabindex="-1">
        <header><span class="m-eyebrow">店主的话</span><h2 id="ann-modal-title">欢迎来到 {{ setting('site_name', 'CardShop') }}</h2></header>
        <div class="m-ann-body m-rich-text">{!! $popupHtml !!}</div>
        <footer><button type="button" class="m-button m-button-primary" id="ann-modal-close" data-ann-dismiss disabled aria-disabled="true">我知道了 <span id="ann-modal-countdown"></span></button></footer>
    </div>
</div>
@endif