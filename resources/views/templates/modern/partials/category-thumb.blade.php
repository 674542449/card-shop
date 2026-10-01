@if($category->image)
<img src="{{ $category->image }}" alt="" class="m-category-thumb {{ $class ?? '' }}" width="32" height="32" loading="lazy" decoding="async">
@else
<span class="m-category-thumb m-category-initial {{ $class ?? '' }}" aria-hidden="true">{{ mb_substr($category->name, 0, 1) }}</span>
@endif