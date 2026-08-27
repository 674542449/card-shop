<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
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
        $totalProducts = Product::where('is_active', true)->count();
        $pendingOrders = Order::where('status', 'pending')->count();

        $recentOrders = Order::with('product')->orderByDesc('created_at')->take(10)->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'order_no' => $o->order_no,
                'product_name' => $o->product->name ?? '—',
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

        return response()->json([
            'today_orders' => $todayOrders,
            'today_revenue' => (float) $todayRevenue,
            'month_orders' => $monthOrders,
            'month_revenue' => (float) $monthRevenue,
            'total_orders' => $totalOrders,
            'total_revenue' => (float) $totalRevenue,
            'total_products' => $totalProducts,
            'pending_orders' => $pendingOrders,
            'recent_orders' => $recentOrders,
            'chart_labels' => $chartLabels,
            'chart_data' => $chartData,
        ]);
    }
}
