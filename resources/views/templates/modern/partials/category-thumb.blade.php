{{--
    分类的小方块标记。

    对齐：一串分类里只有部分配了图时，没图的那些直接不渲染，它们的名字就比有图的往左
    错开一个缩略图的宽度，整列读起来是毛的。传 $reserve => true 会用一个空盘子把位置占住，
    名字仍在同一条左边线上。交给调用方决定，是因为一个分类图一张都没配的店铺不该收获
    一排空盒子——见各页面里的 $anyCategoryImage 判断。

    外观完全走 front.css 的 .cat-thumb / .cat-thumb-empty：这两条规则的圆角、底色都是
    token 驱动的（--radius、--thumb-bg），而本主题在样式表开头把这些 token 重指到了自己的
    配色上，所以不必另写一套，深色模式也跟着走。

    参数：
      $category - Category model
      $size     - 边长像素（同时写进 width/height 属性预留空间，避免图片到达时页面跳动）
      $modifier - 附加类名，例如 'cat-thumb-lg'
      $reserve  - 这个分类没有图时，是否把位置占住
--}}
@php
    $catThumbSize = $size ?? 20;
    $catThumbClass = trim('cat-thumb ' . ($modifier ?? ''));
@endphp

@if($category->image)
<img src="{{ $category->image }}" alt="" class="{{ $catThumbClass }}"
     width="{{ $catThumbSize }}" height="{{ $catThumbSize }}" loading="lazy" decoding="async">
@elseif($reserve ?? false)
{{-- 这里的行内尺寸不是样式，是这一次调用的参数：$size 每个调用点都不一样
     （标签云 16、分类标题 20），样式表没法表达。 --}}
<span class="{{ $catThumbClass }} cat-thumb-empty" aria-hidden="true"
      style="width:{{ $catThumbSize }}px;height:{{ $catThumbSize }}px"></span>
@endif
