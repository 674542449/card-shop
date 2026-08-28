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
        // Alias must not be `used_count`: that is a real column, and withCount would
        // overwrite it in the payload so the admin sees a different number from the one
        // the redemption limit is actually enforced against.
        $query = Coupon::with('product:id,name')->withCount('orders as orders_count');

        // The table renders a search form for these two; without the filters it
        // submitted them and got the unfiltered list back.
        if (filled($request->input('code'))) {
            $query->where('code', 'ilike', '%' . $request->input('code') . '%');
        }
        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $coupons = $query->orderByDesc('id')->paginate($request->get('pageSize', 20));

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
            // 0 is the unlimited sentinel and the column default, so min:1 made every
            // coupon created with the default impossible to save again.
            'max_uses' => 'nullable|integer|min:0',
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
            // 0 is the unlimited sentinel and the column default, so min:1 made every
            // coupon created with the default impossible to save again.
            'max_uses' => 'nullable|integer|min:0',
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
