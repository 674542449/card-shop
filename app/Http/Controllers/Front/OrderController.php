<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\QueryOrderRequest;
use App\Exceptions\CheckoutException;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    /**
     * Create a new order and initiate payment.
     */
    public function create(CreateOrderRequest $request)
    {
        $validated = $request->validated();
        try {
            $data = $validated + ['ip' => (string) $request->ip()];
            $create = fn () => app(\App\Services\OrderService::class)->createOrder($data);
            $order = !empty($validated['checkout_key'])
                ? app(\App\Services\CheckoutIntentService::class)->reserve($validated['checkout_key'], $data, $create)
                : $create();
            // A replay must not restore an old proof after a shop owner changed credentials.
            if (mb_strtolower($order->email) !== mb_strtolower($validated['email'])
                || !Hash::check($validated['query_password'], $order->query_password)) {
                return redirect('/order/query?order_no='.$order->order_no)->withErrors(['error' => '订单凭据已变化，请重新验证。']);
            }
            $this->grantAccess(collect([$order]));
            if (!$order->isPending()) return redirect('/order/pay/'.$order->order_no);
            $paymentUrl = $this->initiatePayment($order);
            return redirect($paymentUrl ?: '/order/pay/'.$order->order_no);
        } catch (\Throwable $e) {
            Log::error('Order creation failed', ['exception_class' => $e::class]);
            $message = $e instanceof CheckoutException || $e instanceof \App\Exceptions\IdempotencyConflictException
                ? $e->getMessage() : '下单失败，请稍后重试';
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
        $initialization = \App\Models\PaymentAttempt::where('order_id', $order->id)->value('status');

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

            return response()->json(['status' => $order->status, 'payment_initialization' => $initialization,
                'payment_review' => !$order->isPaid() && ($order->requiresPaymentReview() || ($order->isPending() && $initialization === 'uncertain')),
                'verification_required' => $order->isPaid() && ! $this->isVerified($order),
                'expires_at' => $order->expires_at->toIso8601String()])
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
                'cards' => $order->deliveryCards()->get(),
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

        if ($order->isPending() && in_array($initialization, ['processing', 'uncertain'], true)) {
            return theme_view('order.pay', ['order' => $order, 'expired' => true, 'paymentReview' => true,
                'deadTitle' => $initialization === 'processing' ? '付款正在创建' : '付款创建待核对',
                'deadReason' => '请稍后刷新原订单或联系客服核对，请勿重复下单或付款。', 'paymentUrl' => null]);
        }

        // Decide from the STATUS, not from isExpired(). isExpired() is
        // `status === 'pending' && expires_at->isPast()`, so the moment the scheduler
        // has already flipped the row to 'expired' — or an operator closed it — it
        // returns false and this used to fall through to the live payment page. The
        // buyer then got a countdown initialised from a timestamp in the past, which
        // front.js reads as finished and reloads two seconds later, forever.
        if (!$order->isPending() || !empty($order->payment_no)) {
            $paymentReview = $order->requiresPaymentReview();
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

            $initialization = \App\Models\PaymentAttempt::where('order_id', $order->id)->value('status');
            if (!$paymentUrl && in_array($initialization, ['processing', 'uncertain'], true)) {
                return theme_view('order.pay', ['order' => $order, 'expired' => true, 'paymentReview' => true,
                    'deadTitle' => $initialization === 'processing' ? '付款正在创建' : '付款创建待核对',
                    'deadReason' => '请稍后刷新原订单或联系客服核对，请勿重复下单或付款。', 'paymentUrl' => null]);
            }

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
        app(\App\Services\OrderService::class)->expireOrder($order);
    }

    /**
     * Show the order query form.
     */
    public function queryForm(Request $request)
    {
        $query = $request->validate(['order_no' => ['nullable', 'string', 'max:30', new \App\Rules\BuyerText]]);
        return theme_view('order.query', ['lookupOrderNo' => $query['order_no'] ?? '']);
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

        $cards = $order->isPaid() ? $order->deliveryCards()->get() : collect();

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

        $cards = $order->isPaid() ? $order->deliveryCards()->get() : collect();
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
        try {
            $result = app(\App\Services\OrderService::class)->processPayment($order, $order->payment_method);
            $url = \App\Support\SafeUrl::http($result['url'] ?? $result['payment_url'] ?? null);
            if ($url) session(['payment_url_'.$order->order_no => $url]);
            return $url;
        } catch (\Throwable $e) {
            Log::error('Payment initialization unavailable', ['order_no' => $order->order_no, 'exception_class' => $e::class]);
            return null;
        }
    }
}
