<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\QueryOrderRequest;
use App\Models\Card;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Services\EpusdtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

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
                    throw new \RuntimeException("购买数量必须在 {$product->min_quantity} 到 {$product->max_quantity} 之间");
                }

                // Check stock
                $stockCount = $product->stockCount();
                if ($stockCount < $quantity) {
                    throw new \RuntimeException('库存不足，当前库存: ' . $stockCount);
                }

                // Creating an order locks cards out of sale until it expires, so a slow
                // drip of orders is enough to empty the shelf without ever paying. The
                // per-minute throttle on the route does not stop that on its own; this
                // caps how much stock one visitor can hold at a time.
                $held = Order::where('ip', $request->ip())
                    ->where('status', 'pending')
                    ->where('expires_at', '>', now())
                    ->count();

                if ($held >= 3) {
                    throw new \RuntimeException('您有未完成的订单，请先完成支付或等待订单过期');
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
                        throw new \RuntimeException('优惠码不存在');
                    }

                    if (!$coupon->isValid()) {
                        throw new \RuntimeException('优惠码已过期或已达使用上限');
                    }

                    // Check if coupon is product-specific
                    if ($coupon->product_id && $coupon->product_id !== $product->id) {
                        throw new \RuntimeException('该优惠码不适用于此商品');
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
                    throw new \RuntimeException('库存不足，请稍后重试');
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
                if ($couponId && $discountAmount > 0) {
                    $claimed = Coupon::where('id', $couponId)
                        ->where(function ($q) {
                            $q->where('max_uses', '<=', 0)
                              ->orWhereColumn('used_count', '<', 'max_uses');
                        })
                        ->increment('used_count');

                    if ($claimed === 0) {
                        throw new \RuntimeException('优惠码已达使用上限');
                    }
                }

                return $order;
            });

            // Initiate payment
            $paymentUrl = $this->initiatePayment($order);

            if ($paymentUrl) {
                return redirect($paymentUrl);
            }

            return redirect('/order/pay/' . $order->order_no);

        } catch (\Throwable $e) {
            Log::error('Order creation failed: ' . $e->getMessage(), ['exception' => $e]);

            // Only the RuntimeExceptions thrown deliberately above carry a message meant
            // for the buyer. Anything else — a QueryException above all — would put
            // schema or configuration detail on the page.
            $message = $e instanceof \RuntimeException
                ? $e->getMessage()
                : '下单失败，请稍后重试';

            return back()->withInput()->withErrors(['error' => $message]);
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

            return response()->json(['status' => $order->status]);
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
                'cards' => $order->cards,
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
        if (!$order->isPending()) {
            return theme_view('order.pay', [
                'order' => $order,
                'expired' => true,
                'deadReason' => $order->status === 'closed'
                    ? '此订单已关闭，请重新下单。'
                    : '此订单已超过支付时限，请重新下单。',
                'deadTitle' => $order->status === 'closed' ? '订单已关闭' : '订单已过期',
                'paymentUrl' => null,
            ]);
        }

        // Try to generate payment URL if needed
        $paymentUrl = session('payment_url_' . $order->order_no);

        return theme_view('order.pay', [
            'order' => $order,
            'expired' => false,
            'paymentUrl' => $paymentUrl,
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
            return back()->withInput()->withErrors(['error' => '尝试次数过多，请稍后再试']);
        }

        $matched = $this->matchOrders($validated['email'], $validated['query_password']);

        if ($matched->isEmpty()) {
            // One message for "no such email" and for "wrong password". Two different
            // messages would tell an attacker which email addresses have bought here.
            return back()->withInput()->withErrors(['error' => '邮箱或查询密码错误']);
        }

        $this->grantAccess($matched);

        return theme_view('order.result', ['orders' => $matched->load('product')]);
    }

    /**
     * Find the orders for an email whose query password matches.
     *
     * Previously only the OLDEST order for the address was consulted. That meant a
     * password could never be rotated — and worse, anyone who placed the first order
     * against someone else's email address held the password that unlocked every order
     * that person placed afterwards.
     *
     * Bounded to the 25 most recent, because each candidate costs a bcrypt verification
     * and this endpoint is reachable by anyone.
     */
    private function matchOrders(string $email, string $password)
    {
        $lower = mb_strtolower($email);

        // PHASE 1 — authenticate.
        //
        // Bounded to 25 because every candidate costs a bcrypt at cost 12 and anyone
        // can call this endpoint. The window is also restricted to orders that are paid
        // or still live: nobody verifies the email at checkout and nothing deletes an
        // expired order, so without that restriction 25 junk orders placed against a
        // stranger's address would push their real one out of the window for good.
        $probe = Order::whereRaw('lower(email) = ?', [$lower])
            ->where(function ($q) {
                $q->where('status', 'paid')
                  ->orWhere(function ($q2) {
                      $q2->where('status', 'pending')->where('expires_at', '>', now());
                  });
            })
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        if ($probe->isEmpty()) {
            // Equalise the cost of "this address has never bought here" with a real
            // check. Without it the reply time says exactly what the deliberately
            // identical error message refuses to: an unknown address returns in
            // single-digit ms, a known one after a bcrypt at cost 12.
            Hash::check($password, $this->timingPaddingHash());

            return $probe;
        }

        $matched = $probe->filter(fn (Order $o) => Hash::check($password, $o->query_password));

        if ($matched->isEmpty()) {
            return $matched->values();
        }

        // PHASE 2 — now that ownership is proved, show everything.
        //
        // The phase-1 restrictions exist to bound what an ANONYMOUS caller can cost us
        // and to stop eviction; neither applies once a password has matched. Keeping
        // them for the result set had two consequences the buyer felt: a regular
        // customer with more than 25 purchases could not reach the cards in their older
        // orders, and an expired or closed order was invisible — the page told them
        // their password was wrong, when their order had simply lapsed.
        //
        // Still capped: a bcrypt per order is real work, and no genuine buyer is near
        // this number.
        return Order::whereRaw('lower(email) = ?', [$lower])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->filter(fn (Order $o) => Hash::check($password, $o->query_password))
            ->values();
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
        session(['order_verified_ids' => $orders->pluck('id')->all()]);
    }

    private function isVerified(Order $order): bool
    {
        return in_array($order->id, session('order_verified_ids', []), true);
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
        $emailHash = sha1(mb_strtolower($email));

        $pairKey = 'order-auth-pair|' . $emailHash . '|' . $request->ip();
        $emailKey = 'order-auth-email|' . $emailHash;
        $ipKey = 'order-auth-ip|' . $request->ip();

        foreach ([$pairKey => 5, $emailKey => 50, $ipKey => 20] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return true;
            }
        }

        // Hit before checking the password, and never cleared on success: a success
        // must not reset a bucket an attacker can manufacture successes in.
        RateLimiter::hit($pairKey, 900);
        RateLimiter::hit($emailKey, 900);
        RateLimiter::hit($ipKey, 900);

        return false;
    }

    /**
     * Show order detail page.
     */
    public function detail(string $orderNo)
    {
        $order = Order::where('order_no', $orderNo)
            ->with(['product', 'cards'])
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

        $cards = $order->isPaid() ? $order->cards : collect();

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
            ->with(['product', 'cards'])
            ->firstOrFail();

        if (!$this->isVerified($order)) {
            return redirect('/order/query')
                ->withErrors(['error' => '请先验证身份后下载卡密']);
        }

        if (!$order->isPaid() || $order->cards->isEmpty()) {
            return redirect('/order/detail/' . $order->order_no)
                ->withErrors(['error' => '该订单暂无可下载的卡密']);
        }

        // CRLF and a BOM because the buyer opens this in Notepad on Windows more
        // often than anywhere else, and without either they get one run-on line of
        // mojibake instead of their cards.
        $lines = [
            '订单编号: ' . $order->order_no,
            '商品名称: ' . ($order->product->name ?? '—'),
            '购买数量: ' . $order->quantity,
            '支付时间: ' . ($order->paid_at ? $order->paid_at->format('Y-m-d H:i:s') : '—'),
            str_repeat('-', 40),
        ];

        foreach ($order->cards as $card) {
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
        $request->validate([
            'email' => ['required', 'email'],
            'query_password' => ['required', 'string'],
        ]);

        // This endpoint is not behind the turnstile that protects /order/query, so
        // without a limiter it is a free brute-force oracle for query passwords.
        if ($this->tooManyAttempts($request, $request->input('email'))) {
            return response()->json([
                'success' => false,
                'message' => '尝试次数过多，请稍后再试',
            ], 429);
        }

        $matched = $this->matchOrders($request->input('email'), $request->input('query_password'));

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
        // EpusdtService is the single implementation: it checks the gateway's
        // status_code rather than only the HTTP status, and it is the one that knows
        // how to pin the payment to the chain the buyer chose. This method used to
        // carry a second copy that did neither.
        $chain = str_replace('usdt_', '', (string) $order->payment_method);

        try {
            $result = app(EpusdtService::class)->createPayment($order, $chain);
        } catch (\Throwable $e) {
            Log::error('EPUSDT payment error: ' . $e->getMessage(), ['order_no' => $order->order_no]);

            return null;
        }

        if (empty($result['payment_url'])) {
            Log::error('EPUSDT returned no payment_url', ['order_no' => $order->order_no]);

            return null;
        }

        session(['payment_url_' . $order->order_no => $result['payment_url']]);

        return $result['payment_url'];
    }
}
