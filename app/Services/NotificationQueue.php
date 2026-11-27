<?php

namespace App\Services;

use App\Models\{Card, NotificationDelivery, Order, Product};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Database outbox: insert in the sale transaction, send outside it. */
class NotificationQueue
{
    public function __construct(private readonly NotificationService $sender) {}

    public function emailEnabled(): bool
    {
        return NotificationService::isEmailEnabled();
    }

    public function enqueue(string $key, string $type, ?Order $order = null, array $payload = [], ?int $productId = null): NotificationDelivery
    {
        return NotificationDelivery::firstOrCreate(['dedupe_key' => $key], [
            'type' => $type, 'order_id' => $order?->id, 'product_id' => $productId,
            'payload' => $payload, 'status' => 'pending', 'available_at' => now(),
        ]);
    }

    public function enqueuePaid(Order $order): void
    {
        if ($this->emailEnabled()) {
            $this->enqueue('order-email:'.$order->id, 'order_email', $order);
        }
        if ($this->sender->telegramConfigured()) {
            $this->enqueue('new-order:'.$order->id, 'new_order', $order);
        }
        if ($this->sender->productSoldNotificationConfigured()) {
            foreach ($this->sender->enabledBroadcastTargetKinds() as $kind) {
                $this->enqueue(
                    "product-sold:order:{$order->id}:{$kind}",
                    'product_sold',
                    $order,
                    ['target_kind' => $kind, 'source' => 'order'],
                    $order->product_id
                );
            }
        }
    }

    public function enqueueOfflineCardSold(Card $card): void
    {
        if (!$this->sender->productSoldNotificationConfigured()) {
            return;
        }
        $product = $card->product()->with('category')->first();
        if (!$product) {
            return;
        }
        $stamp = (string) Str::uuid();
        foreach ($this->sender->enabledBroadcastTargetKinds() as $kind) {
            $this->enqueue(
                "product-sold:card:{$card->id}:{$stamp}:{$kind}",
                'product_sold',
                null,
                [
                    'target_kind' => $kind,
                    'source' => 'offline_card',
                    'product_name' => $product->name,
                    'category_name' => $product->category?->name,
                    'quantity' => 1,
                    'amount' => bcadd((string) $product->price, '0', 2),
                ],
                $product->id
            );
        }
    }

    public function enqueueProductListed(Product $product, string $event = 'listed', int $addedCount = 0): void
    {
        if (!$this->sender->productListedNotificationConfigured()) {
            return;
        }
        $product->loadMissing('category');
        if (!$product->is_active || ($product->category && !$product->category->is_active)) {
            return;
        }
        $stamp = (string) Str::uuid();
        foreach ($this->sender->enabledBroadcastTargetKinds() as $kind) {
            $this->enqueue(
                "product-listed:{$product->id}:{$event}:{$stamp}:{$kind}",
                'product_listed',
                null,
                [
                    'target_kind' => $kind,
                    'event' => $event,
                    'added_count' => $addedCount,
                ],
                $product->id
            );
        }
    }

    public function enqueueRefund(Order $order, \App\Models\OrderRefund $refund): ?NotificationDelivery
    {
        if (!$this->emailEnabled()) {
            return null;
        }

        return $this->enqueue('refund-email:'.$refund->id.':'.$refund->status, 'refund_email', $order, $refund->customerProjection());
    }

    public function enqueueCardReplacement(Order $order, int $replacementId): ?NotificationDelivery
    {
        if (!$this->emailEnabled()) {
            return null;
        }

        return $this->enqueue('card-replacement:'.$replacementId, 'order_email', $order);
    }

    public function resend(Order $order): NotificationDelivery
    {
        if (!$this->emailEnabled()) {
            throw new RuntimeException('SMTP 邮件投递服务当前已关闭，请先在「系统设置 → 邮件设置」中开启后再补发卡密邮件。');
        }

        return DB::transaction(function () use ($order) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (!$locked->isPaid() || !$locked->deliveryCards()->exists()) {
                throw new RuntimeException('该订单未支付或没有已发放的卡密，无法补发。');
            }
            $latest = NotificationDelivery::where('order_id', $order->id)->where('type', 'order_email')->orderByDesc('id')->lockForUpdate()->first();
            if ($latest && in_array($latest->status, ['pending', 'processing'], true)) {
                return $latest;
            }
            if ($latest && $latest->status === 'failed') {
                $latest->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'reserved_at' => null, 'lease_token' => null, 'last_error' => null, 'health_acknowledged_at' => null]);
                return $latest;
            }
            return $this->enqueue('resend:'.$order->id.':'.Str::uuid(), 'order_email', $locked);
        });
    }

    public function process(int $limit = 10): int
    {
        $count = 0;
        while ($count < $limit && ($delivery = $this->claim())) {
            $this->deliver($delivery);
            $count++;
        }
        return $count;
    }

    private function claim(): ?NotificationDelivery
    {
        return DB::transaction(function () {
            // A crashed sender has a bounded lease. A final crashed attempt must also
            // become visible as failed, rather than stay processing forever.
            NotificationDelivery::where('status', 'processing')->where('reserved_at', '<', now()->subMinutes(5))
                ->where('attempts', '>=', 5)->update(['status' => 'failed', 'lease_token' => null, 'health_acknowledged_at' => null, 'last_error' => '发送进程中断，自动重试次数已耗尽，请人工重试。']);
            $delivery = NotificationDelivery::where('attempts', '<', 5)
                ->where(function ($query) {
                    $query->where(fn ($q) => $q->where('status', 'pending')->where('available_at', '<=', now()))
                        ->orWhere(fn ($q) => $q->where('status', 'processing')->where('reserved_at', '<', now()->subMinutes(5)));
                })->orderBy('id')->lock('FOR UPDATE SKIP LOCKED')->first();
            if (!$delivery) {
                return null;
            }
            $delivery->update(['status' => 'processing', 'attempts' => $delivery->attempts + 1, 'reserved_at' => now(), 'lease_token' => (string) Str::uuid()]);
            return $delivery;
        });
    }

    private function deliver(NotificationDelivery $delivery): void
    {
        $status = 'sent';
        $error = null;
        $payload = is_array($delivery->payload) ? $delivery->payload : [];
        $updatedPayload = null;
        try {
            $order = $delivery->order_id ? Order::find($delivery->order_id) : null;
            if ($delivery->type === 'order_email') {
                if (!$this->emailEnabled()) {
                    $status = 'skipped';
                } else {
                    if (!$order?->isPaid()) {
                        throw new RuntimeException('订单未支付，不能发送卡密邮件。');
                    }
                    if ($order->deliveryCards()->count() !== $order->quantity) {
                        throw new RuntimeException('已发放卡密数量与订单不一致。');
                    }
                    if (!$this->sender->sendOrderEmail($order)) {
                        throw new RuntimeException('邮件发送失败，请检查 SMTP 配置及服务器日志。');
                    }
                }
            } elseif ($delivery->type === 'refund_email') {
                if (!$this->emailEnabled()) {
                    $status = 'skipped';
                } elseif (!$order || !$this->sender->sendRefundEmail($order, $payload)) {
                    throw new RuntimeException('邮件发送失败，请检查 SMTP 配置及服务器日志。');
                }
            } elseif (in_array($delivery->type, ['product_sold', 'product_listed'], true)) {
                $product = $delivery->product_id
                    ? Product::with(['category', 'wholesalePrices'])->find($delivery->product_id)
                    : $order?->product;
                if ($delivery->type === 'product_listed' && (!$product || !$product->is_active || ($product->category && !$product->category->is_active))) {
                    $status = 'skipped';
                } else {
                    $targetKind = (string) ($payload['target_kind'] ?? '');
                    $res = $this->sender->deliverBroadcastNotification($delivery->type, $targetKind, $order, $product, $payload);
                    if ($res['sent_targets'] !== ($payload['sent_targets'] ?? [])) {
                        $updatedPayload = array_merge($payload, ['sent_targets' => $res['sent_targets']]);
                    }
                    if ($res['skipped']) {
                        $status = 'skipped';
                    } elseif (!$res['ok']) {
                        throw new RuntimeException($res['error'] ?? 'Telegram 通知发送失败，请检查机器人配置与目标会话权限。');
                    }
                }
            } elseif (!$this->sender->telegramConfigured()) {
                $status = 'skipped';
            } elseif ($delivery->type === 'new_order') {
                if (!$order || !$this->sender->notifyNewOrder($order)) {
                    throw new RuntimeException('Telegram 订单通知发送失败，请检查通知配置。');
                }
            } elseif (in_array($delivery->type, ['payment_review', 'low_stock'], true)) {
                if (!$this->sender->sendTelegramNotification($payload['message'] ?? '')) {
                    throw new RuntimeException('Telegram 通知发送失败，请检查通知配置。');
                }
            } else {
                throw new RuntimeException('未知通知类型。');
            }
        } catch (\Throwable $exception) {
            $status = $delivery->attempts >= 5 ? 'failed' : 'pending';
            // Do not persist transport exception text, which may contain credentials.
            $error = $exception instanceof RuntimeException && (
                str_starts_with($exception->getMessage(), '邮件发送失败')
                || str_starts_with($exception->getMessage(), 'Telegram ')
            ) ? $exception->getMessage() : '通知发送失败，请检查配置及服务器日志。';
        }
        $updates = [
            'status' => $status, 'last_error' => $error, 'lease_token' => null, 'reserved_at' => null,
            'health_acknowledged_at' => null,
            'sent_at' => $status === 'sent' ? now() : null,
            'available_at' => $error ? now()->addSeconds([60, 300, 900, 3600, 3600][$delivery->attempts - 1]) : now(),
        ];
        if ($updatedPayload !== null) {
            $updates['payload'] = $updatedPayload;
        }
        NotificationDelivery::whereKey($delivery->id)->where('lease_token', $delivery->lease_token)->update($updates);
    }
}
