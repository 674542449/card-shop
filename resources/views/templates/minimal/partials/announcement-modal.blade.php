@php
    $popupHtml = \App\Support\ContentRenderer::toHtml(setting('popup_announcement'));
    $popupHours = max(0, (int) setting('popup_interval_hours', 24));
    $popupSignature = substr(md5($popupHtml), 0, 12);
@endphp
@if($popupHtml !== '')
<div class="n-modal" id="announcement-modal" hidden data-signature="{{ $popupSignature }}" data-hours="{{ $popupHours }}" data-countdown="5"><div class="n-modal-backdrop" data-ann-dismiss></div><section class="ann-modal-box n-modal-box" role="dialog" aria-modal="true" aria-labelledby="ann-modal-title" tabindex="-1"><header><span class="n-kicker">STORE NOTICE</span><h2 id="ann-modal-title">商店公告</h2></header><div class="n-rich n-modal-content">{!! $popupHtml !!}</div><footer><button type="button" class="n-button n-button-primary" id="ann-modal-close" data-ann-dismiss disabled aria-disabled="true">我知道了 <span id="ann-modal-countdown"></span></button></footer></section></div>
@endif