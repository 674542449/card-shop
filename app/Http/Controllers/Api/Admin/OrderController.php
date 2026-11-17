<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Coupon;
use App\Models\OperationLog;
use App\Models\Order;
use App\Services\NotificationQueue;
use App\Services\OrderFulfilmentService;
use App\Services\OrderCardReplacementService;
use App\Services\RefundService;
use App\Exceptions\CheckoutException;
use App\Support\AdminListQuery;
use App\Support\PaymentInitializationSummary;
use App\Http\Resources\Admin\{AdminRecordResource, OrderResource};
use App\Policies\AdminPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly NotificationQueue $notifications,
        private readonly OrderFulfilmentService $fulfilment,
    ) {
    }

    /**
     * The filters behind both the order list and the CSV export.
     *
     * Shared because they had drifted: the list honoured seven filters and the export
     * honoured two, so 导出订单 on a filtered screen silently downloaded every order in
     * the shop — a file that looks right until you open it.
     */
    private function applyFilters(Request $request, Builder $query): Builder
    {
        $request->validate([
            'status' => 'nullable|in:pending,paid,closed,expired',
            'payment_review' => 'nullable|boolean',
            'payment_initialization' => 'nullable|in:created,processing,uncertain,succeeded,failed,none',
            'payment_method' => 'nullable|in:alipay,wechat,usdt_trc20,usdt_bep20,usdt_polygon,manual',
            'order_no' => 'nullable|string|max:100',
            'email' => 'nullable|string|max:254',
            'keyword' => 'nullable|string|max:254',
            'date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => 'nullable|date_format:Y-m-d',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
        ]);
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->boolean('payment_review')) {
            $query->paymentReview();
        }
        if ($request->filled('payment_initialization')) {
            $state = $request->input('payment_initialization');
            $condition = fn ($attempt) => $attempt->selectRaw('1')->from('payment_attempts')
                ->whereColumn('payment_attempts.order_id', 'orders.id')
                ->when($state !== 'none', fn ($attempt) => $attempt->where('status', $state));
            $state === 'none' ? $query->whereNotExists($condition) : $query->whereExists($condition);
        }
        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }
        // The table's search form submits column names; accept those as well as the
        // combined `keyword` so the 订单号 and 邮箱 search boxes actually filter.
        if (filled($request->input('order_no'))) {
            $query->where('order_no', 'ilike', '%' . $request->input('order_no') . '%');
        }
        if (filled($request->input('email'))) {
            $query->where('email', 'ilike', '%' . $request->input('email') . '%');
        }
        if ($request->filled('keyword')) {
            $kw = $request->keyword;
            $query->where(function ($q) use ($kw) {
                $q->where('order_no', 'ilike', "%{$kw}%")
                  ->orWhere('email', 'ilike', "%{$kw}%");
            });
        }

        $dateFrom = $request->input('date_from', $request->input('start_date'));
        $dateTo = $request->input('date_to', $request->input('end_date'));
        if (filled($dateFrom)) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if (filled($dateTo)) {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        return $query;
    }

    public function index(Request $request)
    {
        $pageSize = AdminListQuery::pageSize($request, 20, [
            'sort' => 'nullable|string|max:50',
            'dir' => 'nullable|string|max:10',
        ]);
        $query = $this->applyFilters($request, Order::with('product')->withPaymentReviewFlag());

        $sortBy = $request->get('sort', 'created_at');
        $sortDir = strtolower((string) $request->get('dir', 'desc'));
        if (!in_array($sortBy, ['created_at', 'total_amount'], true)) {
            $sortBy = 'created_at';
        }
        // orderBy() throws on anything other than asc/desc, which would surface as a 500.
        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }

        // ->orderBy('id') is the tiebreaker. created_at is written at whole-second
        // precision, so a burst of orders shares a timestamp and PostgreSQL is free to
        // return the tied rows in a different order per page — the same order appears
        // twice and another is never shown.
        $orders = $query->orderBy($sortBy, $sortDir)
            ->orderBy('id', $sortDir)
            ->paginate($pageSize);
        PaymentInitializationSummary::attach($orders->items());

        return response()->json([
            'data' => OrderResource::collection($orders->items())->resolve($request),
            'total' => $orders->total(),
        ]);
    }

    public function show(Request $request, Order $order, RefundService $refunds)
    {
        $order->load(['product', 'deliveryCards', 'coupon', 'paymentReceipts', 'notifications', 'refunds', 'cardReplacements.items', 'cardReplacements.admin:id,username']);
        $canReadCards = $request->attributes->get('admin')->allows('cards', 'read');
        // Pending orders still need their locked-card status for administration.
        // Retired cards are historical and never appear in the current summary.
        $currentCards = $order->isPaid() ? $order->deliveryCards
            : $order->cards()->whereNull('replaced_at')->orderBy('id')->get();
        if ($canReadCards) {
            $currentCards->each(fn (Card $card) => $card->makeVisible('content'));
        }
        $order->setRelation('cards', $currentCards);
        $order->unsetRelation('deliveryCards');
        $order->setAttribute('cards_accessible', $canReadCards);
        $order->setAttribute('has_payment_review', $order->requiresPaymentReview());
        $order->setAttribute('refund_enabled', $refunds->enabled());
        $order->setAttribute('refund_balance', $refunds->balance($order));
        $order->paymentReceipts->each(fn ($receipt) => $receipt->setAttribute('refund_balance', $refunds->balance($order, $receipt)));
        PaymentInitializationSummary::attach([$order]);

        return response()->json((new OrderResource($order))->resolve($request));
    }

    public function replaceCards(Request $request, Order $order, OrderCardReplacementService $service)
    {
        AdminPolicy::authorize($request->attributes->get('admin'), 'orders.replace_cards');
        $data = $request->validate([
            'card_ids' => 'required|array|min:1|max:200',
            'card_ids.*' => 'required|integer|min:1|max:2147483647|distinct',
            'reason' => 'required|string|max:2000', 'request_token' => 'required|uuid',
        ]);
        try {
            $replacement = $service->replace($order, array_map('intval', $data['card_ids']), $data['reason'], $data['request_token'], $request->attributes->get('admin'));
        } catch (CheckoutException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['message' => '已完成换卡，旧卡保留售后历史且不会再次售出；当前卡密邮件已加入发送队列。', 'data' => (new AdminRecordResource($replacement))->resolve($request)], 201);
    }

    public function close(Order $order, \App\Services\OrderService $service)
    {
        try {
            $service->closeOrder($order, true);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        OperationLog::log('关闭订单', 'order', $order->id, "关闭订单 {$order->order_no}");
        return response()->json(['message' => '订单已关闭。']);
    }

    public function markPaid(Request $request, Order $order)
    {
        // Closing or resending orders does not authorize dispensing stock for an
        // unpaid order. Manual receipt confirmation is a separate funds privilege.
        AdminPolicy::authorize($request->attributes->get('admin'), 'orders.mark_paid');
        // Expired and closed are allowed on purpose. This is the repair path for a
        // payment that reached the gateway after the order lapsed and could not be
        // delivered automatically — refusing everything but 'pending' meant those
        // sales could only be fixed by editing the database by hand.
        if (!in_array($order->status, ['pending', 'expired', 'closed'], true)) {
            return response()->json(['message' => '该订单无法确认支付。'], 422);
        }

        // Confirming payment runs the same fulfilment the gateway callback runs —
        // card allocation, the status flip and the delivery email — so the two
        // paths cannot drift. The service re-checks the pending status under a
        // row lock, which is what makes a click racing a real callback safe.
        $result = $this->fulfilment->fulfilManually($order);

        if (!$result->wasFulfilled()) {
            // Either no stock to allocate, or the order stopped being pending
            // between the check above and the lock — a callback landing first.
            return response()->json(['message' => $result->reason], 422);
        }

        OperationLog::log('手动确认支付', 'order', $order->id, "手动确认订单 {$order->order_no}");

        return response()->json(['message' => '订单已确认支付，卡密可在查单页获取，邮件已加入发送队列。']);
    }

    public function resend(Order $order)
    {
        if ($order->status !== 'paid') {
            return response()->json(['message' => '只能对已支付订单补发卡密。'], 422);
        }

        $order->load(['product', 'deliveryCards']);

        if ($order->deliveryCards->isEmpty()) {
            return response()->json(['message' => '该订单没有已发放的卡密，无法补发。'], 422);
        }

        // Queue acceptance is distinct from successful transport delivery.
        try {
            $delivery = $this->notifications->resend($order);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        OperationLog::log('补发卡密', 'order', $order->id, "订单 {$order->order_no} 补发卡密");

        return response()->json(['message' => '卡密邮件已加入发送队列，请刷新查看投递状态。', 'data' => (new AdminRecordResource($delivery))->resolve(request())], 202);
    }

    public function export(Request $request)
    {
        $statusMap = [
            'pending' => '待支付', 'paid' => '已支付',
            'expired' => '已过期', 'closed' => '已关闭',
        ];

        // Every field goes through this, not just the product name. The email is
        // buyer-supplied: an unquoted one containing a comma broke the column
        // alignment of the whole row, and one beginning with = + - or @ is executed
        // as a formula when the operator opens the file — a spreadsheet is a
        // programming environment, and this export hands it attacker input.
        $cell = static function ($value): string {
            $value = (string) $value;

            if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
                $value = "'" . $value;
            }

            return '"' . str_replace('"', '""', $value) . '"';
        };

        // 边查边写，而不是先 get() 成一个大数组再拼一整个字符串。
        //
        // 之前是 ->get() 取出全部匹配订单，再把整份 CSV 拼进一个 PHP 字符串。两份
        // 数据同时驻留在内存里：几万笔订单（每笔还 with('product')）就能超过
        // memory_limit，而运维看到的是「导出订单点了没反应」或者一个 500，而且订单
        // 越多越容易触发——恰恰是越需要导出的站点越导不出来。
        //
        // 改成 StreamedResponse + chunkById：每次只取 500 行，写完即刷出，内存占用
        // 与订单总数无关。chunkById 而不是 chunk：后者用 OFFSET 翻页，导出期间有新
        // 订单写入就会漏行或重复行。
        $query = $this->applyFilters($request, Order::with('product'))->orderBy('id');

        $filename = 'orders_' . date('Ymd_His') . '.csv';

        return Response::stream(function () use ($query, $statusMap, $cell) {
            $out = fopen('php://output', 'w');

            // BOM：Excel 没有它就会把 UTF-8 的中文认成本地编码，导出的表打开是乱码。
            fwrite($out, "\xEF\xBB\xBF");
            fwrite($out, "订单号,商品名称,邮箱,数量,总金额,支付方式,状态,创建时间,支付时间\n");

            $query->chunkById(500, function ($orders) use ($out, $statusMap, $cell) {
                foreach ($orders as $order) {
                    fwrite($out, implode(',', array_map($cell, [
                        $order->order_no,
                        $order->displayName(),
                        $order->email,
                        $order->quantity,
                        $order->total_amount,
                        $order->payment_method ?? '',
                        $statusMap[$order->status] ?? $order->status,
                        $order->created_at,
                        $order->paid_at ?? '',
                    ])) . "\n");
                }

                // 及时推给客户端，别攒在 PHP 的输出缓冲里——那样就白流式了。
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            });

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename=' . $filename,
            // 流式响应长度未知，明确关掉 nginx 的缓冲，否则它会等整个响应结束再转发，
            // 大导出又会卡在代理那一层。
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
