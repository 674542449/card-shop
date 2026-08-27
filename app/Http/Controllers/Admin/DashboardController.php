<?php

namespace App\Http\Controllers\Admin;

use App\Models\Order;
use App\Models\Product;
use App\Models\Card;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    public function index()
    {
        $today = Carbon::today();
        $weekStart = Carbon::now()->startOfWeek();
        $monthStart = Carbon::now()->startOfMonth();

        $todayOrders = Order::where('status', 'paid')->whereDate('paid_at', $today)->count();
        $todayRevenue = Order::where('status', 'paid')->whereDate('paid_at', $today)->sum('total_amount');

        $weekOrders = Order::where('status', 'paid')->where('paid_at', '>=', $weekStart)->count();
        $weekRevenue = Order::where('status', 'paid')->where('paid_at', '>=', $weekStart)->sum('total_amount');

        $monthOrders = Order::where('status', 'paid')->where('paid_at', '>=', $monthStart)->count();
        $monthRevenue = Order::where('status', 'paid')->where('paid_at', '>=', $monthStart)->sum('total_amount');

        $totalOrders = Order::where('status', 'paid')->count();
        $totalRevenue = Order::where('status', 'paid')->sum('total_amount');

        $recentOrders = Order::with('product')->recent()->take(10)->get();

        $lowStockProducts = Product::where('is_active', true)
            ->withCount(['cards as stock_count' => function ($query) {
                $query->where('status', 'unsold');
            }])
            ->get()
            ->where('stock_count', '<', 10);

        $orderStatusDistribution = Order::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $chartLabels = [];
        $chartData = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $chartLabels[] = $date->format('m-d');
            $chartData[] = Order::where('status', 'paid')
                ->whereDate('paid_at', $date)
                ->sum('total_amount');
        }

        return view('admin.dashboard', compact(
            'todayOrders',
            'todayRevenue',
            'weekOrders',
            'weekRevenue',
            'monthOrders',
            'monthRevenue',
            'totalOrders',
            'totalRevenue',
            'recentOrders',
            'lowStockProducts',
            'orderStatusDistribution',
            'chartLabels',
            'chartData'
        ));
    }
}
