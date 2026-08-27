<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Models\Coupon;
use App\Models\OperationLog;
use App\Models\Product;
use Illuminate\Support\Str;

class CouponController extends Controller
{
    public function index()
    {
        $coupons = Coupon::withCount('orders as used_count_actual')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.coupons.index', compact('coupons'));
    }

    public function create()
    {
        $products = Product::where('is_active', true)->ordered()->get();

        return view('admin.coupons.form', ['coupon' => null, 'products' => $products]);
    }

    public function store(CouponRequest $request)
    {
        $data = $request->validated();

        if (empty($data['code'])) {
            $data['code'] = strtoupper(Str::random(8));
        }

        $coupon = Coupon::create($data);

        OperationLog::log('创建优惠码', 'coupon', $coupon->id, $coupon->code);

        return redirect('/admin/coupons')->with('success', '优惠码已创建。');
    }

    public function edit(Coupon $coupon)
    {
        $products = Product::where('is_active', true)->ordered()->get();

        return view('admin.coupons.form', compact('coupon', 'products'));
    }

    public function update(CouponRequest $request, Coupon $coupon)
    {
        $coupon->update($request->validated());

        OperationLog::log('更新优惠码', 'coupon', $coupon->id, $coupon->code);

        return redirect('/admin/coupons')->with('success', '优惠码已更新。');
    }

    public function destroy(Coupon $coupon)
    {
        $coupon->delete();

        OperationLog::log('删除优惠码', 'coupon', $coupon->id, $coupon->code);

        return redirect()->back()->with('success', '优惠码已删除。');
    }
}
