@php
    $popupHtml = \App\Support\ContentRenderer::toHtml(setting('popup_announcement'));

    // Hours before the same announcement is shown to the same browser again.
    // 0 means show it every visit.
    $popupHours = (int) setting('popup_interval_hours', 24);
    if ($popupHours < 0) {
        $popupHours = 0;
    }

    // The rendered HTML is the identity of the announcement. Editing the text
    // changes this, which is what makes an updated notice reach people who already
    // dismissed the previous one instead of staying hidden for the whole interval.
    $popupSignature = substr(md5($popupHtml), 0, 12);
@endphp

@if($popupHtml !== '')
{{--
    Announcement dialog. Shown by public/js/front.js, which owns the whole decision:
    whether this browser has seen this exact announcement recently, the countdown
    before closing is allowed, and remembering the dismissal.

    Rendered hidden and revealed by script rather than shown by default — a dialog
    that flashes up and disappears again on a slow connection is worse than one that
    appears a moment late. The whole element is absent when the operator has not
    written an announcement, so there is nothing to hide in that case.
--}}
<div class="ann-modal" id="announcement-modal" hidden
     data-signature="{{ $popupSignature }}"
     data-hours="{{ $popupHours }}"
     data-countdown="5">
    <div class="ann-modal-backdrop" data-ann-dismiss></div>

    {{-- tabindex="-1"：弹窗打开时焦点要落进对话框里。倒计时期间关闭按钮是禁用的，
         没有这个落点，Tab 会直接走到背后的页面上去。 --}}
    <div class="ann-modal-box" role="dialog" aria-modal="true"
         aria-labelledby="ann-modal-title" tabindex="-1">
        <div class="ann-modal-header">
            <h2 class="ann-modal-title" id="ann-modal-title">公告</h2>
        </div>

        <div class="ann-modal-body site-quote-content">
            {!! $popupHtml !!}
        </div>

        <div class="ann-modal-footer">
            {{-- Disabled until the countdown finishes. aria-disabled as well as the
                 attribute so a screen reader announces why it does not respond. --}}
            <button type="button" class="ann-modal-close" id="ann-modal-close"
                    data-ann-dismiss disabled aria-disabled="true">
                <span class="ann-modal-close-text">我知道了</span>
                <span class="ann-modal-countdown" id="ann-modal-countdown"></span>
            </button>
        </div>
    </div>
</div>
@endif
