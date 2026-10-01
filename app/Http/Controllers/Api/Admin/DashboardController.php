<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\NotificationDelivery;
use App\Models\OrderRefund;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();

        $todayOrders = Order::where('status', 'paid')->whereDate('paid_at', $today)->count();
        $todayRevenue = Order::where('status', 'paid')->whereDate('paid_at', $today)->sum('total_amount');
        $monthOrders = Order::where('status', 'paid')->where('paid_at', '>=', $monthStart)->count();
        $monthRevenue = Order::where('status', 'paid')->where('paid_at', '>=', $monthStart)->sum('total_amount');
        $totalOrders = Order::where('status', 'paid')->count();
        $totalRevenue = Order::where('status', 'paid')->sum('total_amount');
        $totalProducts = Product::active()->count();
        $pendingOrders = Order::where('status', 'pending')->count();
        $lowStockQuery = Product::active()->whereNotNull('low_stock_threshold')
            ->whereRaw('(select count(*) from cards where cards.product_id = products.id and cards.status = ?) <= products.low_stock_threshold', ['unsold']);
        $lowStockCount = (clone $lowStockQuery)->count();
        $lowStockProducts = $lowStockQuery->withCount(['cards as stock_count' => fn ($q) => $q->where('status', 'unsold')])
            ->orderBy('id')->limit(10)->get(['id', 'name', 'low_stock_threshold']);

        $recentOrders = Order::with('product')->orderByDesc('created_at')->take(10)->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'order_no' => $o->order_no,
                'product_name' => $o->displayName(),
                'total_amount' => $o->total_amount,
                'status' => $o->status,
                'created_at' => $o->created_at->format('Y-m-d H:i'),
            ]);

        $chartLabels = [];
        $chartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $chartLabels[] = $date->format('m-d');
            $chartData[] = (float) Order::where('status', 'paid')
                ->whereDate('paid_at', $date)
                ->sum('total_amount');
        }

        // Extra-payment reimbursements are displayed separately. They are not
        // deducted from sales that never included those extra receipts.
        $salesRefunds = OrderRefund::query()->join('orders', 'orders.id', '=', 'order_refunds.order_id')
            ->leftJoin('payment_receipts', 'payment_receipts.id', '=', 'order_refunds.payment_receipt_id')
            ->where('order_refunds.status', 'completed')->where('orders.status', 'paid')
            ->where(fn ($q) => $q->whereNull('order_refunds.payment_receipt_id')->orWhereColumn('payment_receipts.trade_no', 'orders.payment_no'));
        $todaySalesRefunds = (clone $salesRefunds)->whereDate('order_refunds.completed_at', $today)->sum('order_refunds.amount');
        $monthSalesRefunds = (clone $salesRefunds)->where('order_refunds.completed_at', '>=', $monthStart)->sum('order_refunds.amount');
        $totalSalesRefunds = (clone $salesRefunds)->sum('order_refunds.amount');
        $completedRefunds = OrderRefund::where('status', 'completed');

        return response()->json([
            'today_refund_amount' => (float) (clone $completedRefunds)->whereDate('completed_at', $today)->sum('amount'),
            'month_refund_amount' => (float) (clone $completedRefunds)->where('completed_at', '>=', $monthStart)->sum('amount'),
            'total_refund_amount' => (float) (clone $completedRefunds)->sum('amount'),
            'today_sales_refund_amount' => (float) $todaySalesRefunds,
            'today_net_revenue' => (float) bcsub((string) $todayRevenue, (string) $todaySalesRefunds, 2),
            'month_net_revenue' => (float) bcsub((string) $monthRevenue, (string) $monthSalesRefunds, 2),
            'total_net_revenue' => (float) bcsub((string) $totalRevenue, (string) $totalSalesRefunds, 2),
            'requested_refunds' => OrderRefund::where('status', 'requested')->count(),
            'approved_refunds' => OrderRefund::where('status', 'approved')->count(),
            'today_orders' => $todayOrders,
            'today_revenue' => (float) $todayRevenue,
            'month_orders' => $monthOrders,
            'month_revenue' => (float) $monthRevenue,
            'total_orders' => $totalOrders,
            'total_revenue' => (float) $totalRevenue,
            'total_products' => $totalProducts,
            'pending_orders' => $pendingOrders,
            'payment_review_orders' => Order::paymentReview()->count(),
            'low_stock_count' => $lowStockCount,
            'low_stock_products' => $lowStockProducts,
            'failed_notifications' => NotificationDelivery::where('status', 'failed')->count(),
            'recent_orders' => $recentOrders,
            'chart_labels' => $chartLabels,
            'chart_data' => $chartData,
        ]);
    }
}
