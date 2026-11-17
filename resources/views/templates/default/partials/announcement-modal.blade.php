@php
    $popupHtml = \App\Support\ContentRenderer::toHtml(setting('popup_announcement'));
    $popupHours = (int) setting('popup_interval_hours', 24);
    if ($popupHours < 0) {
        $popupHours = 0;
    }
    $popupSignature = substr(md5($popupHtml), 0, 12);
@endphp

@if($popupHtml !== '')
<aside class="ann-modal ann-notice-bar" id="announcement-modal" hidden
       data-non-modal="1"
       data-signature="{{ $popupSignature }}"
       data-hours="{{ $popupHours }}"
       aria-labelledby="ann-modal-title">
    <div class="ann-modal-box ann-notice-inner">
        <div class="ann-notice-content">
            <strong class="ann-modal-title" id="ann-modal-title">站点公告</strong>
            <div class="ann-modal-body site-quote-content rich-text">
                {!! $popupHtml !!}
            </div>
        </div>
        <button type="button" class="ann-modal-close ann-notice-close" id="ann-modal-close" data-ann-dismiss>
            关闭公告
        </button>
    </div>
</aside>
@endif
