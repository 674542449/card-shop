<?php

namespace App\Services;

use App\Models\Card;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class OrderService
{
    public function __construct(
        private readonly CardService $cardService,
        private readonly EpayService $epayService,
        private readonly EpusdtService $epusdtService,
        private readonly NotificationService $notificationService,
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
     *     ip: string
     * } $data
     *
     * @throws RuntimeException On validation failure or insufficient stock.
     */
    public function createOrder(array $data): Order
    {
        // 1. Validate product exists and is active
        $product = Product::where('id', $data['product_id'])
            ->where('is_active', true)
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

        // 4. Calculate price (with wholesale check)
        $unitPrice = $product->getEffectivePrice($quantity);
        $totalAmount = bcmul((string) $unitPrice, (string) $quantity, 2);

        // 5. Validate and apply coupon if provided
        $coupon = null;
        $discountAmount = '0.00';
        if (!empty($data['coupon_code'])) {
            $coupon = Coupon::where('code', $data['coupon_code'])->first();

            if (!$coupon) {
                throw new RuntimeException('优惠券不存在');
            }

            if (!$coupon->isValid()) {
                throw new RuntimeException('优惠券已失效或已使用完');
            }

            // Check product-specific coupon
            if ($coupon->product_id && $coupon->product_id !== $product->id) {
                throw new RuntimeException('此优惠券不适用于该商品');
            }

            // Check minimum amount requirement
            if ((float) $totalAmount < (float) $coupon->min_amount) {
                throw new RuntimeException(
                    "订单金额不满足优惠券最低消费 ¥{$coupon->min_amount} 的要求"
                );
            }

            $discountAmount = number_format($coupon->calculateDiscount((float) $totalAmount), 2, '.', '');
            $totalAmount = bcsub($totalAmount, $discountAmount, 2);

            // Ensure total doesn't go below zero
            if (bccomp($totalAmount, '0', 2) <= 0) {
                $totalAmount = '0.01';
            }
        }

        // 6. Lock cards and create order in a transaction
        return DB::transaction(function () use (
            $product, $data, $quantity, $unitPrice, $totalAmount, $coupon, $discountAmount
        ) {
            // Lock cards via Redis + DB lockForUpdate
            $cards = $this->cardService->lockCards($product->id, $quantity);

            try {
                $expireMinutes = (int) setting('order_expire_minutes', 30);

                $order = Order::create([
                    'order_no' => generate_order_no(),
                    'product_id' => $product->id,
                    'email' => $data['email'],
                    'query_password' => Hash::make($data['query_password']),
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
            DB::transaction(function () use ($order) {
                $order->update(['status' => 'expired']);

                // Release locked cards back to unsold
                $lockedCards = $order->cards()->where('status', 'locked')->get();
                $this->cardService->releaseCards($lockedCards);
            });
            $count++;
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

        $this->notificationService->sendOrderEmail($order);
    }

    /**
     * Close an order manually and release its cards.
     *
     * @throws RuntimeException If the order cannot be closed.
     */
    public function closeOrder(Order $order): void
    {
        if ($order->isPaid()) {
            throw new RuntimeException('已支付的订单不能关闭');
        }

        if ($order->status === 'closed') {
            throw new RuntimeException('订单已关闭');
        }

        DB::transaction(function () use ($order) {
            $order->update(['status' => 'closed']);

            $lockedCards = $order->cards()->where('status', 'locked')->get();
            $this->cardService->releaseCards($lockedCards);
        });
    }
}
