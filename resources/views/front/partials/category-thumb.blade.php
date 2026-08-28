{{--
    Small square category mark. Renders nothing when the category has no image,
    so a category without one simply reads as its name — the existing design —
    rather than leaving a hole.

    Params:
      $category - Category model
      $size     - rendered edge length in px (also emitted as width/height to
                  reserve space and avoid layout shift)
      $modifier - optional extra class, e.g. 'cat-thumb-lg'
--}}
@if($category->image)
@php $catThumbSize = $size ?? 20; @endphp
<img src="{{ $category->image }}" alt="" class="cat-thumb {{ $modifier ?? '' }}"
     width="{{ $catThumbSize }}" height="{{ $catThumbSize }}" loading="lazy" decoding="async">
@endif
