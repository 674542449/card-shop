<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ApiToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class OrderService
{
    public function __construct(
        private readonly CardService $cardService,
        private readonly EpayService $epayService,
        private readonly EpusdtService $epusdtService,
        private readonly CheckoutPricingService $pricing,
    ) {}

    /**
     * Create a new order.
     *
     * Validates product, checks stock, validates coupon, calculates price,
     * locks cards, and creates order within a DB transaction.
     *
     * @param array{
     *     product_id: int,
     *     email: string,
     *     query_password: string,
     *     quantity: int,
     *     coupon_code?: string|null,
     *     payment_method: string,
     *     api_token_id?: int|null,
     *     ip: string
     * } $data
     *
     * @throws RuntimeException On validation failure or insufficient stock.
     */
    public function createOrder(array $data): Order
    {
        if (!in_array($data['payment_method'], \App\Support\PaymentMethods::supported(), true)) {
            throw new RuntimeException('当前支付网关不支持所选支付方式或网络。');
        }
        // bcrypt ignores bytes beyond 72; reject them before reserving any stock.
        if (strlen($data['query_password']) > 72) {
            throw new RuntimeException('查询密码不能超过72字节，中文等字符会占用多个字节');
        }

        // 1. Validate product exists and is active
        $product = Product::active()->where('id', $data['product_id'])
            ->first();

        if (!$product) {
            throw new RuntimeException('商品不存在或已下架');
        }

        $quantity = (int) $data['quantity'];

        // Validate quantity within product limits
        if ($quantity < $product->min_quantity || $quantity > $product->max_quantity) {
            throw new RuntimeException(
                "购买数量必须在 {$product->min_quantity} - {$product->max_quantity} 之间"
            );
        }

        // 2. Check stock before attempting lock
        $stockCount = $this->cardService->getStockCount($product->id);
        if ($stockCount < $quantity) {
            throw new RuntimeException("库存不足，当前库存: {$stockCount}");
        }

        // 3. Check blacklist
        if (\App\Models\Blacklist::isBlocked($data['ip'], $data['email'])) {
            throw new RuntimeException('访问被拒绝');
        }

        return DB::transaction(function () use ($product, $data, $quantity) {
            $tokenId = $data['api_token_id'] ?? null;
            if ($tokenId !== null) {
                // Lock the quota owner before counting reservations. Concurrent
                // requests across products/IPs cannot both claim the final allowance.
                $token = ApiToken::whereKey($tokenId)->where('is_active', true)->lockForUpdate()->first();
                if (!$token || $token->expires_at?->isPast() || ($token->scopes !== null && !in_array('orders:create', $token->scopes, true)) || ($token->allowed_ips && !\Symfony\Component\HttpFoundation\IpUtils::checkIp($data['ip'], $token->allowed_ips))) {
                    throw new RuntimeException('API 令牌已失效');
                }
                // A deadline alone does not release card rows. Count reservations
                // until the expiry transaction actually changes the order status.
                $held = Order::where('api_token_id', $token->id)->where('status', 'pending')
                    ->selectRaw('count(*) as orders, COALESCE(sum(quantity), 0) as quantity')->first();
                if ((int) $held->orders >= $token->max_pending_orders
                    || (int) $held->quantity + $quantity > $token->max_pending_quantity) {
                    throw new RuntimeException('API 未付款订单或库存占用已达额度，请先完成支付或等待过期资源释放');
                }
            }
            $quote = $this->pricing->calculate($product, $quantity, $data['coupon_code'] ?? null);
            $unitPrice = $quote['unit_price'];
            $totalAmount = $quote['total_amount'];
            $discountAmount = $quote['discount_amount'];
            $coupon = $quote['coupon'];

            // Lock cards via Redis + DB lockForUpdate
            $cards = $this->cardService->lockCards($product->id, $quantity);

            try {
                $expireMinutes = (int) setting('order_expire_minutes', 30);

                $order = Order::create([
                    'order_no' => generate_order_no(),
                    'api_token_id' => $tokenId,
                    'product_id' => $product->id,
                    'email' => $data['email'],
                    'query_password' => Hash::make($data['query_password']),
                    'query_password_key' => Order::passwordKey($data['email'], $data['query_password']),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $totalAmount,
                    'coupon_id' => $coupon?->id,
                    'discount_amount' => $discountAmount,
                    'payment_method' => $data['payment_method'],
                    'status' => 'pending',
                    'ip' => $data['ip'],
                    'expires_at' => now()->addMinutes($expireMinutes),
                ]);

                // Associate locked cards with the order
                Card::whereIn('id', $cards->pluck('id'))
                    ->update(['order_id' => $order->id]);

                // This path applied the discount and stored coupon_id but never
                // touched used_count — the counter is written in exactly one place in
                // the codebase, and it was the web checkout. So on /api/v1/orders,
                // max_uses was a check with no act: isValid() read a number nothing
                // ever incremented, and a max_uses=1 100%-off coupon stayed redeemable
                // forever while the admin list kept showing 0/1.
                //
                // The conditional UPDATE is the gate, not bookkeeping: isValid() above
                // is a check-then-act two concurrent callers both pass, so the limit
                // has to be enforced by the write itself.
                if ($coupon && bccomp($discountAmount, '0', 2) > 0) {
                    $claimed = Coupon::where('id', $coupon->id)
                        ->where(function ($q) {
                            $q->where('max_uses', '<=', 0)
                              ->orWhereColumn('used_count', '<', 'max_uses');
                        })
                        ->increment('used_count');

                    if ($claimed === 0) {
                        throw new RuntimeException('优惠券已达使用上限');
                    }
                }

                return $order;
            } catch (\Throwable $e) {
                // Release cards if order creation fails
                $this->cardService->releaseCards($cards);
                throw $e;
            }
        });
    }

    /**
     * Process payment for an order and return payment URL/data.
     *
     * @return array{url: string, trade_id?: string}
     * @throws RuntimeException If the payment method is unsupported.
     */
    public function processPayment(Order $order, string $method): array
    {
        if (!$order->isPending()) {
            throw new RuntimeException('订单状态不允许支付');
        }

        if ($order->expires_at->isPast()) {
            throw new RuntimeException('订单已过期');
        }
        if ($order->payment_no) {
            throw new RuntimeException('订单已有付款回执，请等待核对，不要重复支付');
        }

        return match ($method) {
            'alipay' => [
                'url' => $this->epayService->createPayment($order, 'alipay'),
            ],
            'wechat' => [
                'url' => $this->epayService->createPayment($order, 'wxpay'),
            ],
            'usdt_trc20' => $this->epusdtService->createPayment($order, 'trc20'),
            'usdt_bep20' => $this->epusdtService->createPayment($order, 'bep20'),
            'usdt_polygon' => $this->epusdtService->createPayment($order, 'polygon'),
            default => throw new RuntimeException('不支持的支付方式'),
        };
    }

    /**
     * Expire pending orders that have passed their expires_at time.
     *
     * @return int Number of orders expired.
     */
    public function expireOrders(): int
    {
        $expiredOrders = Order::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->get();

        $count = 0;
        foreach ($expiredOrders as $order) {
            // Claim the row with a conditional UPDATE rather than writing the status
            // by primary key. The select above happened outside any transaction, so a
            // gateway callback can fulfil the order in the gap — and fulfilment only
            // checks status, not expires_at, so it legitimately does. An unconditional
            // write then stamps 'expired' over a paid order: the buyer has their cards
            // and the gateway has the money, but the sale disappears from the books and
            // neither resend nor markPaid will touch it again.
            $expired = DB::transaction(function () use ($order) {
                $claimed = Order::where('id', $order->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'expired']);

                if ($claimed === 0) {
                    return false;
                }

                // Only after the claim: releasing first would hand a paying buyer's
                // cards to the next visitor. Scoped to 'locked', so cards already sold
                // by a callback that won the race are left alone.
                $lockedCards = $order->cards()->where('status', 'locked')->get();
                $this->cardService->releaseCards($lockedCards);

                // The coupon goes back with the cards. Inside the claimed branch, so
                // the same conditional UPDATE that stops a double release of the
                // cards stops a double release of the coupon.
                if ($order->coupon_id && (float) $order->discount_amount > 0) {
                    Coupon::release($order->coupon_id);
                }

                return true;
            });

            if ($expired) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Resend card contents to the order email.
     *
     * @throws RuntimeException If order is not paid.
     */
    public function resendCards(Order $order): void
    {
        if (!$order->isPaid()) {
            throw new RuntimeException('只能重发已支付订单的卡密');
        }

        // Use the same durable resend path as the admin controller.
        app(NotificationQueue::class)->resend($order);
    }

    /**
     * Close an order manually and release its cards.
     *
     * @throws RuntimeException If the order cannot be closed.
     */
    public function closeOrder(Order $order, bool $pendingOnly = false): void
    {
        if ($order->isPaid()) {
            throw new RuntimeException('已支付的订单不能关闭');
        }

        if ($order->status === 'closed') {
            throw new RuntimeException('订单已关闭');
        }

        DB::transaction(function () use ($order, $pendingOnly) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($pendingOnly && $fresh->status !== 'pending') {
                throw new RuntimeException('只能取消待支付订单，请刷新查看订单状态。');
            }
            if ($fresh->payment_no || $fresh->paymentReceipts()->exists()) {
                throw new RuntimeException('订单已收到付款回执，请先完成付款核对，不能取消。');
            }
            $releaseCoupon = $fresh->status === 'pending';
            // Conditional, like every other status write: the checks above read a
            // model loaded before the transaction, so a gateway callback can pay the
            // order in the gap and an unconditional write would stamp 'closed' over
            // a completed sale.
            $claimed = Order::where('id', $order->id)
                ->whereIn('status', ['pending', 'expired'])
                ->update(['status' => 'closed']);

            if ($claimed === 0) {
                throw new RuntimeException('订单状态已变化，请刷新后重试');
            }

            $lockedCards = $order->cards()->where('status', 'locked')->get();
            $this->cardService->releaseCards($lockedCards);

            if ($releaseCoupon && $fresh->coupon_id && (float) $fresh->discount_amount > 0) {
                Coupon::release($fresh->coupon_id);
            }
        });
    }
}
