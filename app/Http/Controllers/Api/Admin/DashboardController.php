<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\NotificationDelivery;
use App\Models\OrderRefund;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        // Cache only financial summaries. Actionable orders/refunds/stock stay live.
        $statistics = Cache::remember('dashboard:financial:v2:'.$today->format('Y-m-d'), 15,
            fn () => $this->financialStatistics($today));
        $totalProducts = Product::active()->count();
        $pendingOrders = Order::where('status', 'pending')->count();
        $lowStockQuery = Product::active()->whereNotNull('low_stock_threshold')
            ->whereRaw('(select count(*) from cards where cards.product_id = products.id and cards.status = ?) <= products.low_stock_threshold', ['unsold']);
        $lowStockCount = (clone $lowStockQuery)->count();
        $lowStockProducts = $lowStockQuery->withCount(['cards as stock_count' => fn ($q) => $q->where('status', 'unsold')])
            ->orderBy('id')->limit(10)->get(['id', 'name', 'low_stock_threshold']);

        $recentOrders = Order::with('product')->withPaymentReviewFlag()->orderByDesc('created_at')->orderByDesc('id')->take(10)->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'order_no' => $o->order_no,
                'product_name' => $o->displayName(),
                'total_amount' => $o->total_amount,
                'status' => $o->status,
                'has_payment_review' => $o->requiresPaymentReview(),
                'created_at' => $o->created_at->format('Y-m-d H:i'),
            ]);

        return response()->json([
            ...$statistics,
            'requested_refunds' => OrderRefund::where('status', 'requested')->count(),
            'approved_refunds' => OrderRefund::where('status', 'approved')->count(),
            'total_products' => $totalProducts,
            'pending_orders' => $pendingOrders,
            'payment_review_orders' => Order::paymentReview()->count(),
            'low_stock_count' => $lowStockCount,
            'low_stock_products' => $lowStockProducts,
            'failed_notifications' => NotificationDelivery::where('status', 'failed')->count(),
            'recent_orders' => $recentOrders,
        ]);
    }

    private function financialStatistics(Carbon $today): array
    {
        $tomorrow = $today->copy()->addDay();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $monthStart->copy()->addMonth();
        $periods = [$today, $tomorrow, $monthStart, $monthEnd];
        $sales = Order::where('status', 'paid')->selectRaw('COUNT(*) AS total_orders, COALESCE(SUM(total_amount),0) AS total_revenue,
            COUNT(*) FILTER (WHERE paid_at >= ? AND paid_at < ?) AS today_orders,
            COALESCE(SUM(total_amount) FILTER (WHERE paid_at >= ? AND paid_at < ?),0) AS today_revenue,
            COUNT(*) FILTER (WHERE paid_at >= ? AND paid_at < ?) AS month_orders,
            COALESCE(SUM(total_amount) FILTER (WHERE paid_at >= ? AND paid_at < ?),0) AS month_revenue',
            [$today, $tomorrow, $today, $tomorrow, $monthStart, $monthEnd, $monthStart, $monthEnd])->first();
        $refunds = OrderRefund::where('status', 'completed')->selectRaw('COALESCE(SUM(amount),0) AS total_amount,
            COALESCE(SUM(amount) FILTER (WHERE completed_at >= ? AND completed_at < ?),0) AS today_amount,
            COALESCE(SUM(amount) FILTER (WHERE completed_at >= ? AND completed_at < ?),0) AS month_amount', $periods)->first();
        // Extra receipts were never counted as sales, so their refunds are separate.
        $salesRefunds = OrderRefund::query()->join('orders', 'orders.id', '=', 'order_refunds.order_id')
            ->leftJoin('payment_receipts', 'payment_receipts.id', '=', 'order_refunds.payment_receipt_id')
            ->where('order_refunds.status', 'completed')->where('orders.status', 'paid')
            ->where(fn ($q) => $q->whereNull('order_refunds.payment_receipt_id')->orWhereColumn('payment_receipts.trade_no', 'orders.payment_no'))
            ->selectRaw('COALESCE(SUM(order_refunds.amount),0) AS total_amount,
                COALESCE(SUM(order_refunds.amount) FILTER (WHERE order_refunds.completed_at >= ? AND order_refunds.completed_at < ?),0) AS today_amount,
                COALESCE(SUM(order_refunds.amount) FILTER (WHERE order_refunds.completed_at >= ? AND order_refunds.completed_at < ?),0) AS month_amount', $periods)->first();
        $chartStart = $today->copy()->subDays(6);
        $days = Order::where('status', 'paid')->where('paid_at', '>=', $chartStart)->where('paid_at', '<', $tomorrow)
            ->selectRaw('DATE(paid_at) AS sale_day, SUM(total_amount) AS revenue')->groupByRaw('DATE(paid_at)')->pluck('revenue', 'sale_day');
        $labels = []; $chart = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $chartStart->copy()->addDays($i);
            $labels[] = $date->format('m-d'); $chart[] = (float) ($days[$date->format('Y-m-d')] ?? 0);
        }
        return [
            'today_orders' => (int) $sales->today_orders, 'month_orders' => (int) $sales->month_orders, 'total_orders' => (int) $sales->total_orders,
            'today_revenue' => (float) $sales->today_revenue, 'month_revenue' => (float) $sales->month_revenue, 'total_revenue' => (float) $sales->total_revenue,
            'today_refund_amount' => (float) $refunds->today_amount, 'month_refund_amount' => (float) $refunds->month_amount, 'total_refund_amount' => (float) $refunds->total_amount,
            'today_sales_refund_amount' => (float) $salesRefunds->today_amount,
            'today_net_revenue' => (float) bcsub((string) $sales->today_revenue, (string) $salesRefunds->today_amount, 2),
            'month_net_revenue' => (float) bcsub((string) $sales->month_revenue, (string) $salesRefunds->month_amount, 2),
            'total_net_revenue' => (float) bcsub((string) $sales->total_revenue, (string) $salesRefunds->total_amount, 2),
            'chart_labels' => $labels, 'chart_data' => $chart,
            'financial_snapshot_at' => now()->toIso8601String(), 'financial_cache_seconds' => 15,
        ];
    }
}
