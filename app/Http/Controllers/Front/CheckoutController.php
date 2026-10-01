<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CheckoutPricingService;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function quote(Request $request, CheckoutPricingService $pricing)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|min:1',
            'quantity' => 'required|integer|min:1|max:100000',
            'coupon_code' => 'nullable|string|max:50',
        ]);
        $product = Product::active()->with('wholesalePrices')->findOrFail($data['product_id']);
        if ($data['quantity'] < $product->min_quantity || $data['quantity'] > $product->max_quantity) {
            return response()->json(['message' => "购买数量必须在 {$product->min_quantity} - {$product->max_quantity} 之间"], 422)->header('Cache-Control', 'no-store');
        }
        if ($data['quantity'] > $product->stockCount()) {
            return response()->json(['message' => '当前库存不足，请减少数量。'], 422)->header('Cache-Control', 'no-store');
        }
        try {
            $quote = $pricing->calculate($product, $data['quantity'], $data['coupon_code'] ?? null, false);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422)->header('Cache-Control', 'no-store');
        }
        return response()->json(['data' => [
            'quantity' => $data['quantity'], 'unit_price' => $quote['unit_price'],
            'subtotal' => bcmul($quote['unit_price'], (string) $data['quantity'], 2),
            'discount_amount' => $quote['discount_amount'], 'total_amount' => $quote['total_amount'],
        ], 'message' => '试算不占用库存或优惠次数，最终金额以提交订单时的校验结果为准。'])->header('Cache-Control', 'no-store');
    }
}
