<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ApiToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Exceptions\CheckoutException;

class OrderService
{
    public function __construct(
        private readonly CardService $cardService,
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
     * @throws CheckoutException On validation failure or insufficient stock.
     */
    public function createOrder(array $data): Order
    {
        if (!in_array($data['payment_method'], \App\Support\PaymentMethods::supported(), true)) {
            throw new CheckoutException('当前支付网关不支持所选支付方式或网络。');
        }
        if (str_contains($data['query_password'], "\0") || !mb_check_encoding($data['query_password'], 'UTF-8') || strlen($data['query_password']) > 72) {
            throw new CheckoutException('查询密码不能超过72字节或包含无效编码、空字符。');
        }
        $ip = \App\Support\IpAddress::normalize((string) $data['ip']);
        if (\App\Models\Blacklist::isBlocked($ip, $data['email'])) {
            throw new CheckoutException('访问被拒绝');
        }
        $passwordHash = Hash::make($data['query_password']);
        return DB::transaction(function () use ($data, $ip, $passwordHash) {
            $quantity = (int) $data['quantity'];
            if ($quantity < 1 || $quantity > 2147483647) {
                throw new CheckoutException('购买数量超出允许范围');
            }
            $tokenId = $data['api_token_id'] ?? null;
            if ($tokenId !== null) {
                $token = ApiToken::whereKey($tokenId)->where('is_active', true)->lockForUpdate()->first();
                if (!$token || $token->expires_at?->isPast() || ($token->scopes !== null && !in_array('orders:create', $token->scopes, true))
                    || ($token->allowed_ips && !\Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, $token->allowed_ips))) {
                    throw new CheckoutException('API 令牌已失效');
                }
                $held = Order::where('api_token_id', $token->id)->where('status', 'pending')
                    ->selectRaw('count(*) as orders, COALESCE(sum(quantity), 0) as quantity')->first();
                if ((int) $held->orders >= $token->max_pending_orders || (int) $held->quantity + $quantity > $token->max_pending_quantity) {
                    throw new CheckoutException('API 未付款订单或库存占用已达额度，请先完成支付或等待过期资源释放');
                }
            } elseif (setting('order_limit_enabled', '1') === '1') {
                $subnet = \App\Support\IpAddress::subnet64($ip);
                $normalizedEmail = mb_strtolower(trim($data['email']));
                $lockKeys = ['ip:' . ($subnet ?? $ip), 'email:' . $normalizedEmail];
                sort($lockKeys);
                foreach ($lockKeys as $lockKey) {
                    DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', ['web-order-reservation', $lockKey]);
                }

                $rawMaxIp = (int) setting('order_max_pending_per_ip', 3);
                $maxPerIp = $rawMaxIp > 0 ? min(50, $rawMaxIp) : 3;
                $pendingCount = $subnet !== null
                    ? Order::where('status', 'pending')->where('ip', 'like', '%:%')->pluck('ip')
                        ->filter(fn (string $existing) => \App\Support\IpAddress::subnet64($existing) === $subnet)->count()
                    : Order::where('ip', $ip)->where('status', 'pending')->count();
                if ($pendingCount >= $maxPerIp) {
                    throw new CheckoutException('您有未完成的订单，请先完成支付或等待订单过期');
                }

                $rawMaxEmail = (int) setting('order_max_pending_per_email', 3);
                $maxPerEmail = $rawMaxEmail > 0 ? min(50, $rawMaxEmail) : 3;
                $emailPending = Order::where('status', 'pending')
                    ->whereRaw('lower(email) = ?', [$normalizedEmail])
                    ->count();
                if ($emailPending >= $maxPerEmail) {
                    throw new CheckoutException('该邮箱当前未支付订单过多，请先完成支付或等待订单过期');
                }
            }
            // Both channels read current product rules under the same inventory lock.
            $product = Product::active()->whereKey($data['product_id'])->lockForUpdate()->first();
            if (!$product) throw new CheckoutException('商品不存在或已下架');
            if ($quantity < $product->min_quantity || $quantity > $product->max_quantity) {
                throw new CheckoutException("购买数量必须在 {$product->min_quantity} - {$product->max_quantity} 之间");
            }
            if ($tokenId === null && setting('order_limit_enabled', '1') === '1') {
                $rawLockedPct = (int) setting('order_max_locked_percent', 80);
                $maxLockedPct = ($rawLockedPct >= 20 && $rawLockedPct <= 100) ? $rawLockedPct : 80;
                if ($maxLockedPct < 100) {
                    $counts = Card::where('product_id', $product->id)
                        ->whereIn('status', ['unsold', 'locked'])
                        ->selectRaw("status, count(*) as total")
                        ->groupBy('status')
                        ->pluck('total', 'status');
                    $unsoldCount = (int) ($counts['unsold'] ?? 0);
                    $lockedCount = (int) ($counts['locked'] ?? 0);
                    $poolTotal = $unsoldCount + $lockedCount;
                    $allowedLocked = max(10, (int) ceil($poolTotal * ($maxLockedPct / 100)));
                    if ($lockedCount >= 10 && ($lockedCount + $quantity) > $allowedLocked) {
                        throw new CheckoutException('当前商品待支付锁单较多，请稍后再试或等待未付款订单自动释放库存');
                    }
                }
            }
            $quote = $this->pricing->calculate($product, $quantity, $data['coupon_code'] ?? null);
            $cards = $this->cardService->lockCards($product->id, $quantity);
            $rawExpire = (int) setting('order_expire_minutes', 30);
            $expireMinutes = $rawExpire > 0 ? min(10080, $rawExpire) : 30;
            $order = Order::create([
                'order_no' => generate_order_no(), 'api_token_id' => $tokenId, 'product_id' => $product->id,
                'email' => $data['email'], 'query_password' => $passwordHash,
                'query_password_key' => Order::passwordKey($data['email'], $data['query_password']),
                'quantity' => $quantity, 'unit_price' => $quote['unit_price'], 'total_amount' => $quote['total_amount'],
                'coupon_id' => $quote['coupon']?->id, 'discount_amount' => $quote['discount_amount'],
                'payment_method' => $data['payment_method'], 'status' => 'pending', 'ip' => $ip,
                'expires_at' => now()->addMinutes($expireMinutes),
            ]);
            Card::whereIn('id', $cards->pluck('id'))->update(['order_id' => $order->id]);
            if ($quote['coupon'] && bccomp($quote['discount_amount'], '0', 2) > 0) {
                $claimed = Coupon::whereKey($quote['coupon']->id)->where(fn ($q) => $q->where('max_uses', '<=', 0)
                    ->orWhereColumn('used_count', '<', 'max_uses'))->increment('used_count');
                if ($claimed === 0) throw new CheckoutException('优惠券已达使用上限');
            }
            return $order;
        }, 3);
    }

    /**
     * Process payment for an order and return payment URL/data.
     *
     * @return array{url: string, trade_id?: string}
     * @throws CheckoutException If the payment method is unsupported.
     */
    public function processPayment(Order $order, string $method): array
    {
        return app(PaymentInitiationService::class)->initiate($order, $method);
    }

    /** Release bounded batches instead of loading the entire pending-order backlog. */
    public function expireOrders(): int
    {
        $count = 0;
        Order::where('status', 'pending')->where('expires_at', '<', now())
            ->select('id')->chunkById(100, function ($orders) use (&$count) {
                foreach ($orders as $order) if ($this->expireOrder($order)) $count++;
            });
        return $count;
    }

    public function expireOrder(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->first();
            if (!$fresh || !$fresh->isPending() || !$fresh->expires_at->isPast()) return false;
            $fresh->update(['status' => 'expired']);
            $this->cardService->releaseCards($fresh->cards()->where('status', 'locked')->get());
            if ($fresh->coupon_id && bccomp($fresh->discount_amount, '0', 2) > 0) Coupon::release($fresh->coupon_id);
            return true;
        }, 3);
    }

    /**
     * Resend card contents to the order email.
     *
     * @throws CheckoutException If order is not paid.
     */
    public function resendCards(Order $order): void
    {
        if (!$order->isPaid()) {
            throw new CheckoutException('只能重发已支付订单的卡密');
        }

        // Use the same durable resend path as the admin controller.
        app(NotificationQueue::class)->resend($order);
    }

    /**
     * Close an order manually and release its cards.
     *
     * @throws CheckoutException If the order cannot be closed.
     */
    public function closeOrder(Order $order, bool $pendingOnly = false): void
    {
        if ($order->isPaid()) {
            throw new CheckoutException('已支付的订单不能关闭');
        }

        if ($order->status === 'closed') {
            throw new CheckoutException('订单已关闭');
        }

        DB::transaction(function () use ($order, $pendingOnly) {
            $fresh = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($pendingOnly && $fresh->status !== 'pending') {
                throw new CheckoutException('只能取消待支付订单，请刷新查看订单状态。');
            }
            if ($fresh->payment_no || $fresh->paymentReceipts()->exists()) {
                throw new CheckoutException('订单已收到付款回执，请先完成付款核对，不能取消。');
            }
            if (\App\Models\PaymentAttempt::where('order_id', $fresh->id)->whereIn('status', ['processing', 'uncertain'])->exists()) {
                throw new CheckoutException('付款创建结果待核对，暂不能取消；请查询原订单或联系客服。');
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
                throw new CheckoutException('订单状态已变化，请刷新后重试');
            }

            $lockedCards = $fresh->cards()->where('status', 'locked')->get();
            $this->cardService->releaseCards($lockedCards);

            if ($releaseCoupon && $fresh->coupon_id && bccomp((string) $fresh->discount_amount, '0', 2) > 0) {
                Coupon::release($fresh->coupon_id);
            }
        });
    }
}
