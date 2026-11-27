@php
    $popupHtml = \App\Support\ContentRenderer::toHtml(setting('popup_announcement'));
    $popupHours = max(0, (int) setting('popup_interval_hours', 24));
    $popupSignature = substr(md5($popupHtml), 0, 12);
@endphp
@if($popupHtml !== '')
<aside class="ml-ann-notice" id="announcement-modal" hidden
       data-non-modal="1"
       data-signature="{{ $popupSignature }}"
       data-hours="{{ $popupHours }}"
       aria-labelledby="ann-modal-title">
    <div class="ann-modal-box ml-ann-inner">
        <div class="ml-ann-content">
            <strong class="ml-ann-title" id="ann-modal-title">商城公告</strong>
            <div class="ml-ann-body ml-rich">{!! $popupHtml !!}</div>
        </div>
        <button type="button" class="ml-btn ml-btn-secondary ml-btn-xs" id="ann-modal-close" data-ann-dismiss>关闭公告</button>
    </div>
</aside>
@endif
