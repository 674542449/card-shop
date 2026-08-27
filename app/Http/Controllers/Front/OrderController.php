<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\QueryOrderRequest;
use App\Models\Card;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * Create a new order and initiate payment.
     */
    public function create(CreateOrderRequest $request)
    {
        $validated = $request->validated();

        try {
            $order = DB::transaction(function () use ($validated, $request) {
                $product = Product::active()->findOrFail($validated['product_id']);
                $quantity = (int) $validated['quantity'];

                // Validate quantity range
                if ($quantity < $product->min_quantity || $quantity > $product->max_quantity) {
                    throw new \Exception("购买数量必须在 {$product->min_quantity} 到 {$product->max_quantity} 之间");
                }

                // Check stock
                $stockCount = $product->stockCount();
                if ($stockCount < $quantity) {
                    throw new \Exception('库存不足，当前库存: ' . $stockCount);
                }

                // Calculate price
                $unitPrice = (float) $product->getEffectivePrice($quantity);
                $totalAmount = round($unitPrice * $quantity, 2);
                $discountAmount = 0;
                $couponId = null;

                // Apply coupon if provided
                if (!empty($validated['coupon_code'])) {
                    $coupon = Coupon::where('code', $validated['coupon_code'])->first();

                    if (!$coupon) {
                        throw new \Exception('优惠码不存在');
                    }

                    if (!$coupon->isValid()) {
                        throw new \Exception('优惠码已过期或已达使用上限');
                    }

                    // Check if coupon is product-specific
                    if ($coupon->product_id && $coupon->product_id !== $product->id) {
                        throw new \Exception('该优惠码不适用于此商品');
                    }

                    $discountAmount = $coupon->calculateDiscount($totalAmount);
                    $couponId = $coupon->id;
                }

                $finalAmount = max(0.01, round($totalAmount - $discountAmount, 2));

                // Create order
                $order = Order::create([
                    'order_no' => generate_order_no(),
                    'product_id' => $product->id,
                    'email' => $validated['email'],
                    'query_password' => Hash::make($validated['query_password']),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $finalAmount,
                    'coupon_id' => $couponId,
                    'discount_amount' => $discountAmount,
                    'payment_method' => $validated['payment_method'],
                    'status' => 'pending',
                    'ip' => $request->ip(),
                    'expires_at' => now()->addMinutes((int) setting('order_expire_minutes', 30)),
                ]);

                // Lock cards for this order
                $cards = Card::where('product_id', $product->id)
                    ->unsold()
                    ->limit($quantity)
                    ->lockForUpdate()
                    ->get();

                if ($cards->count() < $quantity) {
                    throw new \Exception('库存不足，请稍后重试');
                }

                foreach ($cards as $card) {
                    $card->update([
                        'order_id' => $order->id,
                        'status' => 'locked',
                        'locked_at' => now(),
                    ]);
                }

                // Increment coupon usage
                if ($couponId) {
                    Coupon::where('id', $couponId)->increment('used_count');
                }

                return $order;
            });

            // Initiate payment
            $paymentUrl = $this->initiatePayment($order);

            if ($paymentUrl) {
                return redirect($paymentUrl);
            }

            return redirect('/order/pay/' . $order->order_no);

        } catch (\Exception $e) {
            Log::error('Order creation failed: ' . $e->getMessage());
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Show payment page for an order.
     */
    public function pay(string $orderNo)
    {
        $order = Order::where('order_no', $orderNo)
            ->with('product')
            ->firstOrFail();

        if ($order->isPaid()) {
            return view('front.order.detail', [
                'order' => $order,
                'cards' => $order->cards,
                'message' => '订单已支付成功',
                'verified' => true,
            ]);
        }

        if ($order->isExpired()) {
            // Release locked cards
            Card::where('order_id', $order->id)
                ->where('status', 'locked')
                ->update([
                    'order_id' => null,
                    'status' => 'unsold',
                    'locked_at' => null,
                ]);
            $order->update(['status' => 'expired']);

            return view('front.order.pay', [
                'order' => $order,
                'expired' => true,
                'paymentUrl' => null,
            ]);
        }

        // Try to generate payment URL if needed
        $paymentUrl = session('payment_url_' . $order->order_no);

        return view('front.order.pay', [
            'order' => $order,
            'expired' => false,
            'paymentUrl' => $paymentUrl,
        ]);
    }

    /**
     * Show the order query form.
     */
    public function queryForm()
    {
        return view('front.order.query');
    }

    /**
     * Query orders by email and password.
     */
    public function query(QueryOrderRequest $request)
    {
        $validated = $request->validated();

        $firstOrder = Order::where('email', $validated['email'])
            ->orderBy('created_at')
            ->first();

        if (!$firstOrder) {
            return back()->withInput()->withErrors(['error' => '未找到相关订单']);
        }

        if (!Hash::check($validated['query_password'], $firstOrder->query_password)) {
            return back()->withInput()->withErrors(['error' => '查询密码错误']);
        }

        // Set session verification flag
        session(['order_verified_email' => $validated['email']]);

        $orders = Order::where('email', $validated['email'])
            ->with('product')
            ->recent()
            ->get();

        return view('front.order.result', compact('orders'));
    }

    /**
     * Show order detail page.
     */
    public function detail(string $orderNo)
    {
        $order = Order::where('order_no', $orderNo)
            ->with(['product', 'cards'])
            ->firstOrFail();

        $verified = session('order_verified_email') === $order->email;

        if (!$verified) {
            return redirect('/order/query')
                ->withErrors(['error' => '请先验证身份后查看订单详情']);
        }

        $cards = $order->isPaid() ? $order->cards : collect();

        return view('front.order.detail', compact('order', 'cards', 'verified'));
    }

    /**
     * AJAX endpoint to verify email and query password.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'query_password' => ['required', 'string'],
        ]);

        $firstOrder = Order::where('email', $request->input('email'))
            ->orderBy('created_at')
            ->first();

        if (!$firstOrder) {
            return response()->json([
                'success' => false,
                'message' => '未找到相关订单',
            ]);
        }

        if (!Hash::check($request->input('query_password'), $firstOrder->query_password)) {
            return response()->json([
                'success' => false,
                'message' => '查询密码错误',
            ]);
        }

        session(['order_verified_email' => $request->input('email')]);

        return response()->json([
            'success' => true,
            'message' => '验证成功',
        ]);
    }

    /**
     * Initiate payment with the appropriate gateway.
     */
    private function initiatePayment(Order $order): ?string
    {
        $method = $order->payment_method;

        if (in_array($method, ['alipay', 'wechat'])) {
            return $this->initiateEpayPayment($order);
        }

        if (str_starts_with($method, 'usdt_')) {
            return $this->initiateEpusdtPayment($order);
        }

        return null;
    }

    /**
     * Initiate EPay payment (Alipay / WeChat).
     */
    private function initiateEpayPayment(Order $order): ?string
    {
        $apiUrl = setting('epay_api_url');
        $merchantId = setting('epay_merchant_id');
        $merchantKey = setting('epay_merchant_key');

        if (!$apiUrl || !$merchantId || !$merchantKey) {
            Log::error('EPay not configured');
            return null;
        }

        $payType = $order->payment_method === 'alipay' ? 'alipay' : 'wxpay';

        $params = [
            'pid' => $merchantId,
            'type' => $payType,
            'out_trade_no' => $order->order_no,
            'notify_url' => url('/payment/epay/notify'),
            'return_url' => url('/payment/epay/return'),
            'name' => $order->product->name ?? '商品购买',
            'money' => $order->total_amount,
        ];

        // Generate signature
        ksort($params);
        $signStr = '';
        foreach ($params as $k => $v) {
            if ($v !== '' && $k !== 'sign' && $k !== 'sign_type') {
                $signStr .= $k . '=' . $v . '&';
            }
        }
        $signStr = rtrim($signStr, '&') . $merchantKey;
        $params['sign'] = md5($signStr);
        $params['sign_type'] = 'MD5';

        $paymentUrl = rtrim($apiUrl, '/') . '/submit.php?' . http_build_query($params);

        session(['payment_url_' . $order->order_no => $paymentUrl]);

        return $paymentUrl;
    }

    /**
     * Initiate EPUSDT payment.
     */
    private function initiateEpusdtPayment(Order $order): ?string
    {
        $apiUrl = setting('epusdt_api_url');
        $apiToken = setting('epusdt_api_token');

        if (!$apiUrl || !$apiToken) {
            Log::error('EPUSDT not configured');
            return null;
        }

        try {
            $params = [
                'order_id' => $order->order_no,
                'amount' => (float) $order->total_amount,
                'notify_url' => url('/payment/epusdt/notify'),
                'redirect_url' => url('/order/pay/' . $order->order_no),
            ];

            // Generate signature
            ksort($params);
            $signStr = '';
            foreach ($params as $k => $v) {
                if ($v !== '') {
                    $signStr .= $k . '=' . $v . '&';
                }
            }
            $signStr = rtrim($signStr, '&') . $apiToken;
            $params['signature'] = md5($signStr);

            $response = Http::post(rtrim($apiUrl, '/') . '/api/v1/order/create-transaction', $params);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['data']['payment_url'])) {
                    $paymentUrl = $data['data']['payment_url'];
                    session(['payment_url_' . $order->order_no => $paymentUrl]);
                    return $paymentUrl;
                }
            }

            Log::error('EPUSDT payment initiation failed', ['response' => $response->body()]);
        } catch (\Exception $e) {
            Log::error('EPUSDT payment error: ' . $e->getMessage());
        }

        return null;
    }
}
