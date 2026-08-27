<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\OperationLog;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CouponController extends Controller
{
    public function index(Request $request)
    {
        $coupons = Coupon::with('product:id,name')
            ->withCount('orders as used_count')
            ->orderByDesc('id')
            ->paginate($request->get('pageSize', 20));

        $products = Product::where('is_active', true)->ordered()->get(['id', 'name']);

        return response()->json([
            'data' => $coupons->items(),
            'total' => $coupons->total(),
            'products' => $products,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'nullable|string|max:50|unique:coupons,code',
            'type' => 'required|in:fixed,percent',
            'value' => 'required|numeric|min:0.01',
            'product_id' => 'nullable|exists:products,id',
            'max_uses' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'expires_at' => 'nullable|date',
        ]);

        if (empty($data['code'])) {
            $data['code'] = strtoupper(Str::random(8));
        }

        $coupon = Coupon::create($data);
        OperationLog::log('创建优惠码', 'coupon', $coupon->id, $coupon->code);

        return response()->json($coupon, 201);
    }

    public function update(Request $request, Coupon $coupon)
    {
        $data = $request->validate([
            'code' => 'nullable|string|max:50|unique:coupons,code,' . $coupon->id,
            'type' => 'required|in:fixed,percent',
            'value' => 'required|numeric|min:0.01',
            'product_id' => 'nullable|exists:products,id',
            'max_uses' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'expires_at' => 'nullable|date',
        ]);

        $coupon->update($data);
        OperationLog::log('更新优惠码', 'coupon', $coupon->id, $coupon->code);

        return response()->json($coupon);
    }

    public function destroy(Coupon $coupon)
    {
        OperationLog::log('删除优惠码', 'coupon', $coupon->id, $coupon->code);
        $coupon->delete();

        return response()->json(['message' => 'ok']);
    }
}
