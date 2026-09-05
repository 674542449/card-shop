@php
    $stock = $product->stockCount();
    // Not `$stock > 0`. A product with min_quantity 5 and 3 cards left cannot be
    // bought at all — the order form rejects every quantity — so showing 购买 sends
    // the buyer to a page that can only fail. It is out of stock in practice.
    $buyable = $stock >= max(1, (int) $product->min_quantity);
@endphp

{{--
    The product list row. ONE markup for every viewport — included from
    front/home.blade.php and front/product/list.blade.php.

    There used to be a second, card-shaped partial for phones. Two markups for one
    list is how the two stopped agreeing, so the row is now the only one and
    front.css reflows it: a five column table on md+, and below 768px the same
    cells laid out as a wrapping flex row (name on its own line, then the meta
    line: mode, stock, price, and the buy button pushed to the right edge).

    Because that reflow changes `display` on the table elements, the browser drops
    the implicit table roles — hence the explicit role attributes here and on the
    <table>/<thead>/<tbody> in the including pages. They are no-ops on desktop and
    they are what keeps rows and cells grouped for a screen reader on a phone.

    The 40px thumbnail slot is always rendered — image or placeholder — so the
    product names stay on one vertical line down the list instead of jagging left
    and right depending on which rows happen to have an image.
--}}
<tr role="row" @class(['row-sold-out' => !$buyable])>
    <td role="cell" class="cell-name">
        <div class="prod-name-cell">
            {{-- alt="" on purpose: the product name follows immediately in the same
                 cell, so the thumbnail is decorative to a screen reader. --}}
            @if($product->image)
            <img src="{{ $product->image }}" alt="" class="prod-thumb"
                 width="40" height="40" loading="lazy" decoding="async">
            @else
            @themeInclude('partials.image-placeholder', ['class' => 'prod-thumb-ph'])
            @endif
            <a href="/product/{{ $product->slug }}" class="prod-name">{{ $product->name }}</a>
        </div>
    </td>
    <td role="cell" class="cell-mode"><span class="badge-auto">自动发货</span></td>
    {{-- .cell-label carries the column heading down into the cell. The <thead>
         column labels are hidden once the row stacks, and a bare "12" with no
         header above it means nothing — to a sighted reader or to a screen
         reader. It is display:none on md+, where the real <th> does the job. --}}
    <td role="cell" class="cell-stock"><span class="cell-label">库存</span><span class="stock-num">{{ $stock }}</span></td>
    <td role="cell" class="prod-price">¥{{ number_format($product->price, 2) }}</td>
    <td role="cell" class="cell-action">
        @if($buyable)
        <a href="/product/{{ $product->slug }}" class="btn-buy-sm">购买</a>
        @else
        <span class="text-muted cell-oos">缺货</span>
        @endif
    </td>
</tr>
