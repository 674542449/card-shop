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
            <strong class="ml-ann-title" id="ann-modal-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <span>商城公告</span>
            </strong>
            <div class="ml-ann-body ml-rich">{!! $popupHtml !!}</div>
        </div>
        <button type="button" class="ml-btn ml-btn-secondary ml-btn-xs" id="ann-modal-close" data-ann-dismiss>关闭公告</button>
    </div>
</aside>
@endif
