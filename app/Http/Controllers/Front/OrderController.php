<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\QueryOrderRequest;
use App\Exceptions\CheckoutException;
use App\Models\Card;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Services\CheckoutPricingService;
use App\Services\EpusdtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    public function __construct(private readonly CheckoutPricingService $pricing) {}

    /**
     * Create a new order and initiate payment.
     */
    public function create(CreateOrderRequest $request)
    {
        $validated = $request->validated();

        try {
            $order = DB::transaction(function () use ($validated, $request) {
                // Serialize this visitor's quota check across products and browser
                // sessions. Card locks alone run after the count and cannot prevent
                // two requests claiming the final pending-order allowance.
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', ['web-order-reservation', (string) $request->ip()]);
                $product = Product::active()->findOrFail($validated['product_id']);
                $quantity = (int) $validated['quantity'];

                // Validate quantity range
                if ($quantity < $product->min_quantity || $quantity > $product->max_quantity) {
                    throw new CheckoutException("购买数量必须在 {$product->min_quantity} 到 {$product->max_quantity} 之间");
                }

                // Check stock
                $stockCount = $product->stockCount();
                if ($stockCount < $quantity) {
                    throw new CheckoutException('库存不足，当前库存: ' . $stockCount);
                }

                // Creating an order locks cards out of sale until it expires, so a slow
                // drip of orders is enough to empty the shelf without ever paying. The
                // per-minute throttle on the route does not stop that on its own; this
                // caps how much stock one visitor can hold at a time.
                $held = Order::where('ip', $request->ip())
                    ->where('status', 'pending')
                    ->count();

                if ($held >= 3) {
                    throw new CheckoutException('您有未完成的订单，请先完成支付或等待订单过期');
                }

                $quote = $this->pricing->calculate($product, $quantity, $validated['coupon_code'] ?? null);
                $unitPrice = $quote['unit_price'];
                $finalAmount = $quote['total_amount'];
                $discountAmount = $quote['discount_amount'];
                $couponId = $quote['coupon']?->id;

                // Create order
                $order = Order::create([
                    'order_no' => generate_order_no(),
                    'product_id' => $product->id,
                    'email' => $validated['email'],
                    'query_password' => Hash::make($validated['query_password']),
                    'query_password_key' => Order::passwordKey($validated['email'], $validated['query_password']),
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
                    throw new CheckoutException('库存不足，请稍后重试');
                }

                foreach ($cards as $card) {
                    $card->update([
                        'order_id' => $order->id,
                        'status' => 'locked',
                        'locked_at' => now(),
                    ]);
                }

                // The conditional UPDATE is the gate, not bookkeeping. isValid() above
                // is a check-then-act that two concurrent buyers both pass, so the limit
                // has to be enforced by the write itself: whichever transaction loses
                // the row lock re-evaluates the predicate against the committed row,
                // affects zero rows, and rolls its own order back.
                if ($couponId && bccomp($discountAmount, '0.00', 2) > 0) {
                    $claimed = Coupon::where('id', $couponId)
                        ->where(function ($q) {
                            $q->where('max_uses', '<=', 0)
                              ->orWhereColumn('used_count', '<', 'max_uses');
                        })
                        ->increment('used_count');

                    if ($claimed === 0) {
                        throw new CheckoutException('优惠码已达使用上限');
                    }
                }

                return $order;
            });

            // 把这一单记到「本浏览器已验证」名下。
            //
            // 是这个会话亲手填了邮箱和查询密码并提交的，它当然拥有这一单——付款从网关
            // 跳回来（epayReturn 只做展示、送回 /order/pay/{no}）后就能直接看到卡密，
            // 不必再输一遍密码，合法买家的体验和以前一样。
            //
            // 这刻意取代了以前 epayReturn 依据「出站 submit URL 的签名」来授权的做法：
            // 那个签名同时被 pay() 公开渲染给任何知道订单号的人，等于谁都能拿它去
            // /payment/epay/return 换取别人已支付订单的卡密。所有权只认「亲手下的这一单」
            // 和 /order/query 的邮箱+密码，不认可被公开的签名。
            $this->grantAccess(collect([$order]));

            // Initiate payment
            $paymentUrl = $this->initiatePayment($order);

            if ($paymentUrl) {
                return redirect($paymentUrl);
            }

            return redirect('/order/pay/' . $order->order_no);

        } catch (\Throwable $e) {
            Log::error('Order creation failed', ['exception_class' => $e::class]);

            // Database/HTTP exceptions also inherit RuntimeException. Only explicit
            // checkout errors have a message intended for the buyer.
            $message = $e instanceof CheckoutException
                ? $e->getMessage()
                : '下单失败，请稍后重试';

            return back()->withInput($request->except(['query_password', 'cf-turnstile-response']))->withErrors(['error' => $message]);
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

        // The pay page polls this same URL every 5s waiting for the callback to land.
        // Answer it with the bare status. It used to get the full HTML page back and
        // substring-search it for '"paid"' — a test the page could satisfy while the
        // order was still pending, sending the buyer into a reload loop. Nothing
        // sensitive is disclosed: the status is already on the page this URL renders.
        if (request()->expectsJson()) {
            if ($order->isExpired()) {
                $this->expireOrder($order);
                $order->refresh();
            }

            return response()->json(['status' => $order->status, 'payment_review' => !$order->isPaid() && !empty($order->payment_no)])
                ->header('Cache-Control', 'no-store');
        }

        if ($order->isPaid()) {
            // Order numbers are predictable (timestamp + 5 digits), so knowing one must
            // not be enough to read the card secrets. Require the same session proof the
            // detail page requires.
            if (!$this->isVerified($order)) {
                return redirect('/order/query')
                    ->withErrors(['error' => '订单已支付，请验证邮箱和查询密码后查看卡密']);
            }

            return theme_view('order.detail', [
                'order' => $order,
                'cards' => $order->cards()->sold()->get(),
                'message' => '订单已支付成功',
                'verified' => true,
            ]);
        }

        // A pending order past its deadline: expire it here and now, then fall through
        // to the dead-order panel below.
        if ($order->isExpired()) {
            $this->expireOrder($order);
            $order->refresh();

            if ($order->isPaid()) {
                return redirect('/order/query')
                    ->withErrors(['error' => '订单已支付，请验证邮箱和查询密码后查看卡密']);
            }
        }

        // Decide from the STATUS, not from isExpired(). isExpired() is
        // `status === 'pending' && expires_at->isPast()`, so the moment the scheduler
        // has already flipped the row to 'expired' — or an operator closed it — it
        // returns false and this used to fall through to the live payment page. The
        // buyer then got a countdown initialised from a timestamp in the past, which
        // front.js reads as finished and reloads two seconds later, forever.
        if (!$order->isPending() || !empty($order->payment_no)) {
            $paymentReview = ! empty($order->payment_no);
            return theme_view('order.pay', [
                'order' => $order,
                'expired' => true,
                'paymentReview' => $paymentReview,
                'deadReason' => $paymentReview
                    ? '收到付款回执，订单暂未发货，请联系客服核对，请勿重复支付。'
                    : ($order->status === 'closed'
                    ? '此订单已关闭，请重新下单。'
                    : '此订单已超过支付时限。若已付款，请先查询订单或联系客服核对，请勿重复支付；尚未付款可重新下单。'),
                'deadTitle' => $paymentReview ? '付款待核对' : ($order->status === 'closed' ? '订单已关闭' : '订单已过期'),
                'paymentUrl' => null,
            ]);
        }

        // 支付链接：会话 -> 服务端缓存 -> 重新生成。
        //
        // 之前这里只读会话，注释却写着「Try to generate payment URL if needed」——
        // 从来没有 generate 过。后果是支付链接只存在于下单那个浏览器里：换设备、换
        // 浏览器、清了 cookie，或者只是把支付页链接发到手机上打开，页面上就没有
        // 「前往支付」按钮，买家看到一个只会转圈的页面，完全不知道该怎么办。
        //
        // 重新生成要分两种网关看：
        //   - 支付宝/微信（EPay）：链接是用订单自己的字段拼出来的一个提交 URL，
        //     纯构造、无副作用，随时可以重算。
        //   - USDT（EPUSDT）：createPayment 会真的去网关建一笔交易，反复调用可能
        //     产生重复交易，所以结果放服务端缓存里，缓存在就直接用，不再打网关。
        $cacheKey = 'payment_url:' . $order->order_no;
        $sessionKey = 'payment_url_' . $order->order_no;
        $rawSessionUrl = session($sessionKey);
        $paymentUrl = \App\Support\SafeUrl::http($rawSessionUrl);
        if ($rawSessionUrl !== null && $paymentUrl === null) {
            session()->forget($sessionKey);
        }

        if (!$paymentUrl) {
            try {
                // Upgrade-time cache entries must meet the same URL rules as
                // newly returned gateway data before becoming clickable links.
                $rawCachedUrl = Cache::get($cacheKey);
                $paymentUrl = \App\Support\SafeUrl::http($rawCachedUrl);
                if ($rawCachedUrl !== null && $paymentUrl === null) {
                    Cache::forget($cacheKey);
                }
            } catch (\Throwable) {
                $paymentUrl = null;
            }
        }

        if (!$paymentUrl) {
            $paymentUrl = $this->initiatePayment($order);

            if ($paymentUrl) {
                try {
                    // 缓存到订单失效为止即可，过期订单不需要支付链接。
                    $ttl = max(60, now()->diffInSeconds($order->expires_at, false));
                    Cache::put($cacheKey, $paymentUrl, $ttl);
                } catch (\Throwable) {
                    // 缓存不可用不该让支付页打不开，链接这次照样能用。
                }
            }
        }

        return theme_view('order.pay', [
            'order' => $order,
            'expired' => false,
            'paymentUrl' => $paymentUrl,
            // 生成不出链接时（网关没配置、或网关调用失败），要明确告诉买家，而不是
            // 让他对着一个「正在准备支付渠道」的骨架屏一直等。订单本身还占着库存，
            // 到期会自动关闭并把卡密放回去。
            'paymentUnavailable' => !$paymentUrl,
        ]);
    }

    /**
     * Expire a pending order and release the cards it was holding.
     *
     * A payment callback can be committing right now. The row is claimed with a
     * conditional UPDATE first: whoever wins holds the lock for the whole window, so
     * this either expires an order that is genuinely still pending, or affects
     * nothing because the callback already marked it paid. Releasing the cards
     * before claiming would hand the buyer's paid-for cards to the next visitor.
     */
    private function expireOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $claimed = Order::where('id', $order->id)
                ->where('status', 'pending')
                ->update(['status' => 'expired']);

            if ($claimed === 0) {
                return;
            }

            Card::where('order_id', $order->id)
                ->where('status', 'locked')
                ->update([
                    'order_id' => null,
                    'status' => 'unsold',
                    'locked_at' => null,
                ]);

            // The coupon use goes back with the cards — it was claimed when the order
            // was created, before any money moved.
            if ($order->coupon_id && (float) $order->discount_amount > 0) {
                Coupon::release($order->coupon_id);
            }
        });
    }

    /**
     * Show the order query form.
     */
    public function queryForm()
    {
        return theme_view('order.query');
    }

    /**
     * Query orders by email and password.
     */
    public function query(QueryOrderRequest $request)
    {
        $validated = $request->validated();

        if ($this->tooManyAttempts($request, $validated['email'])) {
            return back()->withInput($request->except(['query_password', 'cf-turnstile-response']))->withErrors(['error' => '尝试次数过多，请稍后再试']);
        }

        $result = app(\App\Services\OrderLookupService::class)->search($validated['email'], $validated['query_password'], $validated['order_no'] ?? null);
        return $this->lookupResponse($request, $result, $validated['email'], $validated['query_password'], $validated['order_no'] ?? null);
    }

    public function queryPage(Request $request)
    {
        \App\Support\BuyerCredentialInput::rejectUrlCredentials($request);
        $search = $request->session()->get('order_search');
        if (! is_array($search) || ! is_int($search['expires'] ?? null) || $search['expires'] < now()->timestamp
            || ! is_string($search['credentials'] ?? null) || ! is_int($search['cursor'] ?? null)) {
            $request->session()->forget('order_search');
            return redirect('/order/query')->withErrors(['error' => '查询已过期，请重新验证。']);
        }
        try {
            $credentials = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($search['credentials']), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($credentials) || ! is_string($credentials['email'] ?? null) || ! is_string($credentials['password'] ?? null)) {
                throw new \UnexpectedValueException;
            }
        } catch (\Throwable) {
            $request->session()->forget('order_search');
            return redirect('/order/query')->withErrors(['error' => '查询已过期，请重新验证。']);
        }
        $authenticatedOrderId = null;
        if (is_int($search['authenticated_order_id'] ?? null)) {
            $authenticatedOrder = Order::whereKey($search['authenticated_order_id'])->first();
            if ($authenticatedOrder && mb_strtolower($authenticatedOrder->email) === mb_strtolower($credentials['email'])
                && $this->isVerified($authenticatedOrder)) {
                $authenticatedOrderId = $authenticatedOrder->id;
            }
        }
        // A live proof of an earlier match permits continuing this server-held
        // search without spending another password guess. Each new candidate's
        // actual bcrypt hash is still checked within the bounded lookup budget.
        if ($authenticatedOrderId === null && $this->tooManyAttempts($request, $credentials['email'])) {
            return back()->withErrors(['error' => '尝试次数过多，请稍后再试']);
        }
        $result = app(\App\Services\OrderLookupService::class)->search($credentials['email'], $credentials['password'], null, $search['cursor']);
        return $this->lookupResponse($request, $result, $credentials['email'], $credentials['password'],
            authenticatedOrderId: $authenticatedOrderId);
    }

    private function lookupResponse(Request $request, array $result, string $email, string $password, ?string $orderNo = null, ?int $authenticatedOrderId = null)
    {
        $matched = $result['orders'];
        $request->session()->put('order_search', [
            'credentials' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(compact('email', 'password'))),
            'cursor' => $result['cursor'] ?? 0, 'expires' => now()->timestamp + 600,
            'authenticated_order_id' => $matched->first()?->id ?? $authenticatedOrderId,
        ]);
        if ($matched->isEmpty() && !$result['has_more']) {
            return redirect('/order/query')->withInput(['email' => $email, 'order_no' => $orderNo])->withErrors(['error' => '邮箱或查询密码错误；历史记录较多时请填写订单号精确查询。']);
        }
        $this->grantAccess($matched);
        return theme_view('order.result', ['orders' => $matched->load('product'), 'hasMore' => $result['has_more']]);
    }

    /**
     * Find the orders for an email whose query password matches.
     *
     * Previously only the OLDEST order for the address was consulted. That meant a
     * password could never be rotated — and worse, anyone who placed the first order
     * against someone else's email address held the password that unlocked every order
     * that person placed afterwards.
     *
     * 候选集按状态分桶取样（paid 8 + 未过期 pending 6 + expired/closed 6），因为每个
     * 候选都要做一次 bcrypt，而这个端点任何人都能调。分桶而不是单一时间窗口，见下面
     * phase 1 的说明。
     */
    private function matchOrders(string $email, string $password, ?string $orderNo = null)
    {
        return app(\App\Services\OrderLookupService::class)->search($email, $password, $orderNo)['orders'];
    }

    /**
     * A real bcrypt hash of a value nobody knows, used only to spend the time a
     * genuine verification would have spent.
     *
     * Computed once and cached rather than hard-coded: a hand-written hash that did
     * not parse would make password_verify() return false immediately and pay none
     * of the cost, which is the entire point of it.
     */
    private function timingPaddingHash(): string
    {
        try {
            return Cache::rememberForever(
                'order-auth-timing-padding',
                fn () => Hash::make(Str::random(40))
            );
        } catch (\Throwable $e) {
            return Hash::make(Str::random(40));
        }
    }

    /**
     * Record which specific orders this session has proven ownership of.
     */
    private function grantAccess($orders): void
    {
        app(\App\Services\BrowserOrderCredentialProof::class)->grant($orders);
    }

    private function isVerified(Order $order): bool
    {
        return app(\App\Services\BrowserOrderCredentialProof::class)->has($order);
    }

    public function requestRefund(Request $request, string $orderNo, \App\Services\RefundService $service)
    {
        \App\Support\BuyerCredentialInput::rejectUrlCredentials($request);
        $order = Order::where('order_no', $orderNo)->firstOrFail();
        abort_unless($this->isVerified($order), 403);
        $data = $request->validate(['amount' => 'required|numeric|gt:0|decimal:0,2|max:9999999999999999',
            'reason' => ['bail', 'required', 'string', 'max:2000', new \App\Rules\BuyerText(true)]]);
        try { $service->request($order, (string) $data['amount'], $data['reason'], null, 'buyer'); }
        catch (CheckoutException $e) { return back()->withErrors(['error' => $e->getMessage()]); }
        return back()->with('success', '退款申请已提交，请等待店主处理。');
    }

    public function cancel(Request $request, string $orderNo, \App\Services\OrderService $service)
    {
        \App\Support\BuyerCredentialInput::rejectUrlCredentials($request);
        $order = Order::where('order_no', $orderNo)->firstOrFail();
        abort_unless($this->isVerified($order), 403);
        try {
            $service->closeOrder($order, true);
        } catch (CheckoutException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
        return redirect('/order/detail/'.$order->order_no)->with('success', '订单已取消，库存和优惠次数已释放。');
    }

    /**
     * Rate limit the (email, IP) pair, the IP, and the email.
     *
     * An IP-only bucket is useless here: the attacker chooses the IP. But an
     * email-only bucket was worse than useless — it is keyed on the TARGET's
     * identifier, which anyone who knows the address can spend. Five cheap POSTs
     * every fifteen minutes locked a paying buyer out of the only self-service route
     * to the cards they had bought, indefinitely, and /order/verify needs no
     * Turnstile and no order to exist.
     *
     * So the tight bucket is on the pair — an attacker now has to burn one IP per
     * five attempts — while a much wider email bucket still bounds a distributed
     * guess against one buyer without being cheap enough to weaponise.
     */
    private function tooManyAttempts(Request $request, string $email): bool
    {
        return app(\App\Services\OrderCredentialRateLimiter::class)
            ->attempt($email, (string) $request->ip()) !== null;
    }

    /**
     * Show order detail page.
     */
    public function detail(string $orderNo)
    {
        $order = Order::where('order_no', $orderNo)
            ->with(['product', 'refunds'])
            ->firstOrFail();

        $verified = $this->isVerified($order);

        if (!$verified) {
            return redirect('/order/query')
                ->withErrors(['error' => '请先验证身份后查看订单详情']);
        }

        // Settle a lapsed order here, the same way the pay page does. Without it the
        // page contradicted itself: the header reads isExpired() — which is true for a
        // pending order past its deadline — and showed 订单已过期, while the status row
        // below prints the raw column and showed 待支付. Expiring in place makes the
        // two agree, and returns the cards to stock a little sooner than the job would.
        if ($order->isExpired()) {
            $this->expireOrder($order);
            $order->refresh();
        }

        $cards = $order->isPaid() ? $order->cards()->sold()->get() : collect();

        return theme_view('order.detail', compact('order', 'cards', 'verified'));
    }

    /**
     * Download the order's card secrets as a .txt file.
     *
     * Gated identically to detail(): the cards are the product, so the file must be
     * no easier to reach than the page it is linked from. Order numbers are
     * guessable, which is exactly why the session check — not the URL — is what
     * authorises this.
     */
    public function downloadCards(string $orderNo)
    {
        $order = Order::where('order_no', $orderNo)
            ->with('product')
            ->firstOrFail();

        if (!$this->isVerified($order)) {
            return redirect('/order/query')
                ->withErrors(['error' => '请先验证身份后下载卡密']);
        }

        $cards = $order->isPaid() ? $order->cards()->sold()->get() : collect();
        if ($cards->isEmpty()) {
            return redirect('/order/detail/' . $order->order_no)
                ->withErrors(['error' => '该订单暂无可下载的卡密']);
        }

        // CRLF and a BOM because the buyer opens this in Notepad on Windows more
        // often than anywhere else, and without either they get one run-on line of
        // mojibake instead of their cards.
        $lines = [
            '订单编号: ' . $order->order_no,
            '商品名称: ' . ($order->displayName()),
            '购买数量: ' . $order->quantity,
            '支付时间: ' . ($order->paid_at ? $order->paid_at->format('Y-m-d H:i:s') : '—'),
            str_repeat('-', 40),
        ];

        foreach ($cards as $card) {
            $lines[] = $card->content;
        }

        $body = "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";

        // The order number is generated, not user input, but it is being written
        // into a response header — so it is filtered rather than trusted.
        $safeNo = preg_replace('/[^A-Za-z0-9_-]/', '', $order->order_no);

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="cards-' . $safeNo . '.txt"',
            // Card secrets must not sit in a shared proxy or the browser's disk
            // cache after the buyer closes the tab.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * AJAX endpoint to verify email and query password.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = Validator::make(\App\Support\BuyerCredentialInput::body($request), [
            'email' => ['required', 'email', 'max:200'],
            'order_no' => ['bail', 'nullable', 'string', 'max:30', new \App\Rules\BuyerText],
            'query_password' => ['bail', 'required', 'string', 'max:50', new \App\Rules\QueryPasswordBytes],
        ])->validate();

        // This endpoint is not behind the turnstile that protects /order/query, so
        // without a limiter it is a free brute-force oracle for query passwords.
        if ($this->tooManyAttempts($request, $data['email'])) {
            return response()->json([
                'success' => false,
                'message' => '尝试次数过多，请稍后再试',
            ], 429);
        }

        $matched = $this->matchOrders($data['email'], $data['query_password'], $data['order_no'] ?? null);

        if ($matched->isEmpty()) {
            // Deliberately identical to the "no such email" case — see query().
            return response()->json([
                'success' => false,
                'message' => '邮箱或查询密码错误',
            ]);
        }

        $this->grantAccess($matched);

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
        try {
            // Use the same server-only signing and gateway URL validation as the
            // API checkout, rather than maintaining a second construction path.
            $paymentUrl = app(\App\Services\EpayService::class)->createPayment(
                $order, $order->payment_method === 'alipay' ? 'alipay' : 'wxpay',
            );
        } catch (\RuntimeException $e) {
            Log::error('EPay payment unavailable', ['order_no' => $order->order_no, 'exception_class' => $e::class]);
            return null;
        }

        session(['payment_url_' . $order->order_no => $paymentUrl]);

        return $paymentUrl;
    }

    /**
     * Initiate EPUSDT payment.
     */
    private function initiateEpusdtPayment(Order $order): ?string
    {
        // EpusdtService is the single implementation: it checks the gateway's
        // status_code rather than only the HTTP status, and it is the one that knows
        // how to pin the payment to the chain the buyer chose. This method used to
        // carry a second copy that did neither.
        $chain = str_replace('usdt_', '', (string) $order->payment_method);

        try {
            $result = app(EpusdtService::class)->createPayment($order, $chain);
        } catch (\Throwable $e) {
            Log::error('EPUSDT payment error', ['order_no' => $order->order_no, 'exception_class' => $e::class]);

            return null;
        }

        $paymentUrl = \App\Support\SafeUrl::http($result['payment_url'] ?? null);
        if ($paymentUrl === null) {
            Log::error('EPUSDT returned no payment_url', ['order_no' => $order->order_no]);

            return null;
        }

        session(['payment_url_' . $order->order_no => $paymentUrl]);

        return $paymentUrl;
    }
}
