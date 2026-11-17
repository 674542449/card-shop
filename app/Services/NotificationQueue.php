<?php

namespace App\Services;

use App\Models\{NotificationDelivery, Order};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Database outbox: insert in the sale transaction, send outside it. */
class NotificationQueue
{
    public function __construct(private readonly NotificationService $sender) {}

    public function enqueue(string $key, string $type, ?Order $order = null, array $payload = [], ?int $productId = null): NotificationDelivery
    {
        return NotificationDelivery::firstOrCreate(['dedupe_key' => $key], [
            'type' => $type, 'order_id' => $order?->id, 'product_id' => $productId,
            'payload' => $payload, 'status' => 'pending', 'available_at' => now(),
        ]);
    }

    public function enqueuePaid(Order $order): void
    {
        $this->enqueue('order-email:'.$order->id, 'order_email', $order);
        if ($this->sender->telegramConfigured()) {
            $this->enqueue('new-order:'.$order->id, 'new_order', $order);
        }
    }

    public function enqueueRefund(Order $order, \App\Models\OrderRefund $refund): NotificationDelivery
    {
        return $this->enqueue('refund-email:'.$refund->id.':'.$refund->status, 'refund_email', $order, $refund->customerProjection());
    }

    public function resend(Order $order): NotificationDelivery
    {
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
        try {
            $order = $delivery->order_id ? Order::find($delivery->order_id) : null;
            if ($delivery->type === 'order_email') {
                if (!$order?->isPaid()) {
                    throw new RuntimeException('订单未支付，不能发送卡密邮件。');
                }
                if ($order->deliveryCards()->count() !== $order->quantity) {
                    throw new RuntimeException('已发放卡密数量与订单不一致。');
                }
                if (!$this->sender->sendOrderEmail($order)) {
                    throw new RuntimeException('邮件发送失败，请检查 SMTP 配置及服务器日志。');
                }
            } elseif ($delivery->type === 'refund_email') {
                if (!$order || !$this->sender->sendRefundEmail($order, $delivery->payload)) {
                    throw new RuntimeException('邮件发送失败，请检查 SMTP 配置及服务器日志。');
                }
            } elseif (!$this->sender->telegramConfigured()) {
                $status = 'skipped';
            } elseif ($delivery->type === 'new_order') {
                if (!$order || !$this->sender->notifyNewOrder($order)) {
                    throw new RuntimeException('Telegram 订单通知发送失败，请检查通知配置。');
                }
            } elseif (in_array($delivery->type, ['payment_review', 'low_stock'], true)) {
                if (!$this->sender->sendTelegramNotification($delivery->payload['message'])) {
                    throw new RuntimeException('Telegram 通知发送失败，请检查通知配置。');
                }
            } else {
                throw new RuntimeException('未知通知类型。');
            }
        } catch (\Throwable $exception) {
            $status = $delivery->attempts >= 5 ? 'failed' : 'pending';
            // Do not persist transport exception text, which may contain credentials.
            $error = $exception instanceof RuntimeException && str_starts_with($exception->getMessage(), '邮件发送失败')
                ? $exception->getMessage() : '通知发送失败，请检查配置及服务器日志。';
        }
        NotificationDelivery::whereKey($delivery->id)->where('lease_token', $delivery->lease_token)->update([
            'status' => $status, 'last_error' => $error, 'lease_token' => null, 'reserved_at' => null,
            'health_acknowledged_at' => null,
            'sent_at' => $status === 'sent' ? now() : null,
            'available_at' => $error ? now()->addSeconds([60, 300, 900, 3600, 3600][$delivery->attempts - 1]) : now(),
        ]);
    }
}
