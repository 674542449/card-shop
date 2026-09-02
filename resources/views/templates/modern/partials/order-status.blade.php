{{--
    订单状态药丸。四个调用点各自抄过一份 @switch，已经开始互相走样，所以收在这里。

    $status 是数据库里的原始列值。

    类名（order-status + pending|paid|expired|closed）是**渲染契约**，订单查询结果页和
    订单详情页的表格样式都挂在上面，不能改名。

    配色沿用 front.css 的四条状态规则，没有换成本主题的 token —— 本主题的样式表里能用的
    药丸只有 .badge-auto（绿）和 .badge-stock.out-of-stock（红）两种色调，凑不齐
    待支付/已支付/已过期/已关闭 这四个必须互相分得开的状态。状态是靠形状和文字先说清楚
    的（每个药丸都带汉字标签），颜色只是辅助，宁可四个色调一致地「不那么主题化」，
    也不能让其中两个撞成同一种颜色。
--}}
@switch($status)
    @case('pending') <span class="order-status pending">待支付</span> @break
    @case('paid') <span class="order-status paid">已支付</span> @break
    @case('expired') <span class="order-status expired">已过期</span> @break
    @case('closed') <span class="order-status closed">已关闭</span> @break
    @default <span class="order-status">{{ $status }}</span>
@endswitch
