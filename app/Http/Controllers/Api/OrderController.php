<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    /**
     * Create a new order via the API.
     *
     * Validates JSON input, creates order, and returns order info with payment URL.
     */
    public function create(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'email' => ['required', 'email', 'max:200'],
            'query_password' => ['required', 'string', 'min:6', 'max:50'],
            'quantity' => ['required', 'integer', 'min:1'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'payment_method' => ['required', 'in:alipay,wechat,usdt_trc20,usdt_bep20,usdt_polygon'],
        ], [
            'product_id.required' => '请选择商品',
            'product_id.exists' => '商品不存在',
            'email.required' => '请填写邮箱地址',
            'email.email' => '邮箱格式不正确',
            'email.max' => '邮箱地址不能超过200个字符',
            'query_password.required' => '请设置查询密码',
            'query_password.min' => '查询密码至少6个字符',
            'query_password.max' => '查询密码不能超过50个字符',
            'quantity.required' => '请输入购买数量',
            'quantity.integer' => '购买数量必须为整数',
            'quantity.min' => '购买数量至少为1',
            'coupon_code.max' => '优惠券代码不能超过50个字符',
            'payment_method.required' => '请选择支付方式',
            'payment_method.in' => '不支持的支付方式',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => '验证失败',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $order = $this->orderService->createOrder([
                'product_id' => (int) $request->input('product_id'),
                'email' => $request->input('email'),
                'query_password' => $request->input('query_password'),
                'quantity' => (int) $request->input('quantity'),
                'coupon_code' => $request->input('coupon_code'),
                'payment_method' => $request->input('payment_method'),
                'ip' => $request->ip(),
            ]);

            // Process payment to get the payment URL
            $paymentData = $this->orderService->processPayment(
                $order,
                $request->input('payment_method')
            );

            return response()->json([
                'message' => '订单创建成功',
                'data' => [
                    'order_no' => $order->order_no,
                    'total_amount' => $order->total_amount,
                    'discount_amount' => $order->discount_amount,
                    'payment_method' => $order->payment_method,
                    'expires_at' => $order->expires_at->toIso8601String(),
                    'payment_url' => $paymentData['url'] ?? $paymentData['payment_url'] ?? null,
                    'trade_id' => $paymentData['trade_id'] ?? null,
                ],
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('API order creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => '系统错误，请稍后再试',
            ], 500);
        }
    }

    /**
     * Show order details.
     *
     * Requires email and query_password in query params for authentication.
     */
    public function show(Request $request, string $orderNo): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'email' => ['required', 'email'],
            'query_password' => ['required', 'string'],
        ], [
            'email.required' => '请提供邮箱地址',
            'email.email' => '邮箱格式不正确',
            'query_password.required' => '请提供查询密码',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => '验证失败',
                'errors' => $validator->errors(),
            ], 422);
        }

        $order = Order::with(['product', 'cards'])
            ->where('order_no', $orderNo)
            ->where('email', $request->query('email'))
            ->first();

        if (!$order) {
            return response()->json([
                'message' => '订单不存在',
            ], 404);
        }

        // Verify query password
        if (!Hash::check($request->query('query_password'), $order->query_password)) {
            return response()->json([
                'message' => '查询密码错误',
            ], 403);
        }

        $response = [
            'data' => [
                'order_no' => $order->order_no,
                'product' => [
                    'id' => $order->product->id,
                    'name' => $order->product->name,
                ],
                'email' => $order->email,
                'quantity' => $order->quantity,
                'unit_price' => $order->unit_price,
                'total_amount' => $order->total_amount,
                'discount_amount' => $order->discount_amount,
                'payment_method' => $order->payment_method,
                'status' => $order->status,
                'paid_at' => $order->paid_at?->toIso8601String(),
                'expires_at' => $order->expires_at->toIso8601String(),
                'created_at' => $order->created_at->toIso8601String(),
            ],
        ];

        // Only include card contents if the order is paid
        if ($order->isPaid()) {
            $response['data']['cards'] = $order->cards
                ->where('status', 'sold')
                ->pluck('content')
                ->values()
                ->toArray();
        }

        return response()->json($response);
    }
}
