<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\OperationLog;
use App\Support\AdminListQuery;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CouponController extends Controller
{
    public function index(Request $request)
    {
        $pageSize = AdminListQuery::pageSize($request, 20, [
            'code' => 'nullable|string|max:50',
            'type' => 'nullable|in:fixed,percent',
            'product_id' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
        ]);
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

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $coupons = $query->orderByDesc('id')->paginate($pageSize);

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
            // A percent coupon is a percentage; above 100 it is a discount larger than
            // the order, and the total lands on the 0.01 floor in both checkout paths.
            // The max:100 rule existed only in a FormRequest no controller uses.
            'value' => [
                'required', 'numeric', 'min:0.01', 'decimal:0,2', 'max:99999999.99',
                function (string $attr, $value, \Closure $fail) use ($request) {
                    if ($request->input('type') === 'percent' && (float) $value > 100) {
                        $fail('百分比折扣不能超过 100。');
                    }
                },
            ],
            'product_id' => 'nullable|integer|min:1|exists:products,id',
            // 0 is the unlimited sentinel and the column default, so min:1 made every
            // coupon created with the default impossible to save again.
            'max_uses' => 'nullable|integer|min:0|max:2147483647',
            'min_amount' => 'nullable|numeric|min:0|decimal:0,2|max:99999999.99',
            'starts_at' => 'nullable|date',
            'is_active' => 'boolean',
            'expires_at' => array_filter(['nullable', 'date', $request->filled('starts_at') ? 'after:starts_at' : null]),
        ]);

        if (empty($data['code'])) {
            $data['code'] = strtoupper(Str::random(8));
        }
        $data['min_amount'] = $data['min_amount'] ?? 0;
        $data['max_uses'] = $data['max_uses'] ?? 0;

        $coupon = Coupon::create($data);
        OperationLog::log('创建优惠码', 'coupon', $coupon->id, $coupon->code);

        return response()->json($coupon, 201);
    }

    public function update(Request $request, Coupon $coupon)
    {
        $request->merge([
            'starts_at' => $request->input('starts_at', $coupon->starts_at?->format('Y-m-d H:i:s')),
            'expires_at' => $request->input('expires_at', $coupon->expires_at?->format('Y-m-d H:i:s')),
        ]);
        $data = $request->validate([
            'code' => 'nullable|string|max:50|unique:coupons,code,' . $coupon->id,
            'type' => 'required|in:fixed,percent',
            // A percent coupon is a percentage; above 100 it is a discount larger than
            // the order, and the total lands on the 0.01 floor in both checkout paths.
            // The max:100 rule existed only in a FormRequest no controller uses.
            'value' => [
                'required', 'numeric', 'min:0.01', 'decimal:0,2', 'max:99999999.99',
                function (string $attr, $value, \Closure $fail) use ($request) {
                    if ($request->input('type') === 'percent' && (float) $value > 100) {
                        $fail('百分比折扣不能超过 100。');
                    }
                },
            ],
            'product_id' => 'nullable|integer|min:1|exists:products,id',
            // 0 is the unlimited sentinel and the column default, so min:1 made every
            // coupon created with the default impossible to save again.
            'max_uses' => 'nullable|integer|min:0|max:2147483647',
            'min_amount' => 'nullable|numeric|min:0|decimal:0,2|max:99999999.99',
            'starts_at' => 'nullable|date',
            'is_active' => 'boolean',
            'expires_at' => array_filter(['nullable', 'date', $request->filled('starts_at') ? 'after:starts_at' : null]),
        ]);

        foreach (['min_amount', 'max_uses'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                $data[$key] = 0;
            }
        }
        if (array_key_exists('code', $data) && !$data['code']) {
            $data['code'] = $coupon->code;
        }

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
