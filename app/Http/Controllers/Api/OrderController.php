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
            'query_password' => ['bail', 'required', 'string', 'min:6', 'max:50', new \App\Rules\QueryPasswordBytes],
            'quantity' => ['required', 'integer', 'min:1'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'payment_method' => ['required', \Illuminate\Validation\Rule::in(\App\Support\PaymentMethods::supported())],
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

        $order = null;
        try {
            $order = $this->orderService->createOrder([
                'product_id' => (int) $request->input('product_id'),
                'email' => $request->input('email'),
                'query_password' => $request->input('query_password'),
                'quantity' => (int) $request->input('quantity'),
                'coupon_code' => $request->input('coupon_code'),
                'payment_method' => $request->input('payment_method'),
                'ip' => $request->ip(),
                'api_token_id' => $request->attributes->get('api_token')->id,
            ]);

            // Process payment to get the payment URL
            $paymentData = $this->orderService->processPayment(
                $order,
                $request->input('payment_method')
            );

            // This exact order was just created from these accepted credentials.
            // Recording its proof only after payment initiation succeeds lets a
            // legitimate batch buyer query each order without spending guesses.
            app(\App\Services\ApiOrderCredentialProof::class)->remember(
                $order, $request->attributes->get('api_token')->id,
                $request->input('email'), $request->input('query_password'),
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
            $this->releaseFailedOrder($order);
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            $this->releaseFailedOrder($order);
            Log::error('API order creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => '系统错误，请稍后再试',
            ], 500);
        }
    }

    public function cancel(Request $request, string $orderNo): JsonResponse
    {
        if ($request->query->has('email') || $request->query->has('query_password')) {
            return response()->json(['message' => '请通过 POST 请求正文传递邮箱和查询密码，不要放在 URL 中。'], 422)->header('Cache-Control', 'no-store');
        }
        $input = $request->isJson() ? $request->json()->all() : $request->request->all();
        $data = Validator::make($input, [
            'email' => 'required|email|max:200',
            'query_password' => ['bail', 'required', 'string', 'max:50', new \App\Rules\QueryPasswordBytes],
        ])->validate();
        $order = $this->authenticate($request, $data['email'], $data['query_password'], $orderNo);
        if ($order instanceof JsonResponse) {
            return $order;
        }
        if ($order->api_token_id !== $request->attributes->get('api_token')->id) {
            return response()->json(['message' => '订单不存在或查询密码错误'], 404)->header('Cache-Control', 'no-store');
        }
        try {
            $this->orderService->closeOrder($order, true);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422)->header('Cache-Control', 'no-store');
        }
        return response()->json(['message' => '订单已取消，库存和优惠次数已释放。', 'data' => ['order_no' => $order->order_no, 'status' => 'closed']])->header('Cache-Control', 'no-store');
    }

    private function releaseFailedOrder(?Order $order): void
    {
        if (!$order) {
            return;
        }
        try {
            $this->orderService->closeOrder($order->fresh());
        } catch (\Throwable $e) {
            Log::warning('Could not close failed API checkout', [
                'order_no' => $order->order_no,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Show order details.
     *
     * Credentials are accepted only in the POST body, never the URL.
     */
    public function show(Request $request, string $orderNo): JsonResponse
    {
        if ($request->query->has('email') || $request->query->has('query_password')) {
            return response()->json(['message' => '请通过 POST 请求正文传递邮箱和查询密码，不要放在 URL 中。'], 422)->header('Cache-Control', 'no-store');
        }
        $input = $request->isJson() ? $request->json()->all() : $request->request->all();
        $validator = Validator::make($input, [
            'email' => ['required', 'email', 'max:200'],
            'query_password' => ['bail', 'required', 'string', 'max:50', new \App\Rules\QueryPasswordBytes],
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

        $order = $this->authenticate($request, $input['email'], $input['query_password'], $orderNo);
        if ($order instanceof JsonResponse) {
            return $order;
        }
        $order->load(['product', 'cards']);

        $response = [
            'data' => [
                'order_no' => $order->order_no,
                'product' => [
                    'id' => $order->product->id,
                    'name' => $order->displayName(),
                ],
                'email' => $order->email,
                'quantity' => $order->quantity,
                'unit_price' => $order->unit_price,
                'total_amount' => $order->total_amount,
                'discount_amount' => $order->discount_amount,
                'payment_method' => $order->payment_method,
                'status' => $order->status,
                'payment_review' => Order::paymentReview()->whereKey($order->id)->exists(),
                'payment_received_amount' => $order->payment_received_amount,
                'payment_received_at' => $order->payment_received_at?->toIso8601String(),
                'payment_received_currency' => 'CNY',
                'refunds' => $order->refunds()->get(['amount', 'status', 'created_at', 'completed_at']),
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

        return response()->json($response)->header('Cache-Control', 'no-store');
    }

    private function authenticate(Request $request, string $email, string $password, string $orderNo): Order|JsonResponse
    {
        $tokenId = $request->attributes->get('api_token')->id;
        $proof = app(\App\Services\ApiOrderCredentialProof::class);
        $candidate = Order::where('order_no', $orderNo)
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
        if ($candidate && $proof->has($candidate, $tokenId, $email, $password)) {
            // Correct, previously verified owner polling still uses the route/IP
            // and token request limits, but does not consume the guessing budget.
            return $candidate;
        }
        if ($limited = $this->credentialLimit($request, $email)) {
            return $limited;
        }
        $order = app(\App\Services\OrderLookupService::class)->search($email, $password, $orderNo)['orders']->first();
        if (! $order || $order->api_token_id !== $tokenId) {
            // Missing credentials and another tenant's order share one response.
            // Only the creating token may read or cancel this API order.
            return response()->json(['message' => '订单不存在或查询密码错误'], 404)->header('Cache-Control', 'no-store');
        }
        $proof->remember($order, $tokenId, $email, $password);
        return $order;
    }

    private function credentialLimit(Request $request, string $email): ?JsonResponse
    {
        $retryAfter = app(\App\Services\OrderCredentialRateLimiter::class)
            ->attempt($email, (string) $request->ip());
        return $retryAfter === null ? null : response()->json(['message' => '尝试次数过多，请稍后再试'], 429)
            ->header('Retry-After', (string) $retryAfter)->header('Cache-Control', 'no-store');
    }
}
