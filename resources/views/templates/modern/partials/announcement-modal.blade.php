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
    公告弹窗。逻辑完全由 public/js/front.js 拥有：这台浏览器最近有没有看过这条公告、
    倒计时多久之后才允许关闭、关掉之后记多久。本文件只负责结构和外观。

    默认渲染成隐藏、由脚本揭开，而不是先显示再藏起来——慢网络下闪一下再消失比晚一点
    出现更糟。运营没写公告时整个元素根本不渲染，也就没有「藏起来」这回事。

    —— 换皮时最要紧的一条 ——
    最外层**只能**带 .ann-modal，不能再加模板的 .modal-overlay。front.js 是用 hidden
    属性开合的（annModal.hidden = false），能让它显示出来的规则是 front.css 的
    `.ann-modal { display: flex }`；而 .modal-overlay 的基础态是 `display: none`，靠
    `.modal-overlay.active` 才显示——那个 .active 类没有任何代码会加。两条规则具体度
    相同，本主题的样式表加载在后，`display: none` 赢，结果是公告弹窗**永远不出现**，
    而且不报任何错。遮罩的观感由下面的 .ann-modal-backdrop 提供，视觉上是一样的。

    对话框本体则两套类都带：布局（flex 纵向、限高、正文内部滚动）来自 .ann-modal-box，
    圆角/阴影/内边距来自模板的 .modal-box。
--}}
<div class="ann-modal" id="announcement-modal" hidden
     data-signature="{{ $popupSignature }}"
     data-hours="{{ $popupHours }}"
     data-countdown="5">
    <div class="ann-modal-backdrop" data-ann-dismiss></div>

    {{-- tabindex="-1"：弹窗打开时焦点要落进对话框里。倒计时期间关闭按钮是禁用的，
         没有这个落点，Tab 会直接走到背后的页面上去。 --}}
    <div class="ann-modal-box modal-box" role="dialog" aria-modal="true"
         aria-labelledby="ann-modal-title" tabindex="-1">
        <div class="ann-modal-header modal-header">
            <h2 class="ann-modal-title modal-title" id="ann-modal-title">公告</h2>
            {{-- 模板的标题栏右边有个 X。这里不放：front.js 的所有关闭入口都走同一个
                 annClose()，而它开头就 `if (annCloseBtn.disabled) return`——倒计时没走完
                 时点了不会有任何反应。一个看起来能点、点了却没反应的 X 比没有 X 更糟，
                 底部那个按钮的禁用态则是明写出来的。 --}}
        </div>

        {{-- rich-text 是本主题给运营手写 HTML 准备的排版容器。缺了它，样式表顶部那条
             `ul, ol { list-style: none }` 会把公告里排好的编号列表的序号全抹掉，
             读者看不出步骤顺序。 --}}
        <div class="ann-modal-body modal-body rich-text">
            {!! $popupHtml !!}
        </div>

        <div class="ann-modal-footer modal-footer">
            {{-- 倒计时结束前是禁用的，attribute 和 aria-disabled 都给，读屏才会说明
                 为什么点不动。
                 这里**不加**模板的 .btn-buy：它的 `:disabled` 规则带 !important，会把
                 文字压成 --text-subtle 铺在 --bg-subtle 上（实测对比度约 2.2:1）。而
                 倒计时的秒数「(3)」就印在这个按钮里，用户必须读得到它才知道还要等多久，
                 front.css 的禁用态特意选了 --text-muted 就是为了这个。 --}}
            <button type="button" class="ann-modal-close" id="ann-modal-close"
                    data-ann-dismiss disabled aria-disabled="true">
                <span class="ann-modal-close-text">我知道了</span>
                <span class="ann-modal-countdown" id="ann-modal-countdown"></span>
            </button>
        </div>
    </div>
</div>
@endif
