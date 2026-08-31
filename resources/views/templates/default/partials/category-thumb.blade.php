{{--
    Small square category mark.

    Alignment: when only some categories in a list have an image, rendering nothing
    for the others leaves their names starting one thumbnail further left, so the
    column reads ragged. Pass $reserve => true and the slot is held open with an
    empty plate, which keeps every name on the same left edge. The caller decides,
    because a shop with no category images at all should not get a row of empty
    boxes — see the $anyCategoryImage checks in the pages that include this.

    Params:
      $category - Category model
      $size     - rendered edge length in px (also emitted as width/height to
                  reserve space and avoid layout shift)
      $modifier - optional extra class, e.g. 'cat-thumb-lg'
      $reserve  - hold the slot open when this category has no image
--}}
@php
    $catThumbSize = $size ?? 20;
    $catThumbClass = trim('cat-thumb ' . ($modifier ?? ''));
@endphp

@if($category->image)
<img src="{{ $category->image }}" alt="" class="{{ $catThumbClass }}"
     width="{{ $catThumbSize }}" height="{{ $catThumbSize }}" loading="lazy" decoding="async">
@elseif($reserve ?? false)
<span class="{{ $catThumbClass }} cat-thumb-empty" aria-hidden="true"
      style="width:{{ $catThumbSize }}px;height:{{ $catThumbSize }}px"></span>
@endif
