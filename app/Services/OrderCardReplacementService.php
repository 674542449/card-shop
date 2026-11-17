<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Models\{Admin, Card, OperationLog, Order, OrderCardReplacement, Product};
use App\Rules\Utf8Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A replacement retires a delivered secret permanently; it never replenishes stock. */
final class OrderCardReplacementService
{
    public function __construct(private readonly CardService $cards, private readonly NotificationQueue $notifications) {}

    public function replace(Order $order, array $oldIds, string $reason, string $requestToken, Admin $admin): OrderCardReplacement
    {
        if (! $admin->is_active || ! $admin->allows('orders', 'write') || ! $admin->allows('cards', 'write')) {
            throw new CheckoutException('售后换卡需要订单修改与卡密修改权限。');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000 || ! Utf8Text::isValid($reason) || ! Str::isUuid($requestToken)) {
            throw new CheckoutException('请填写有效换卡原因和操作编号。');
        }
        if (! $oldIds || count($oldIds) > 200 || count(array_unique($oldIds)) !== count($oldIds)) {
            throw new CheckoutException('每次请选择 1 至 200 张不同的当前卡密。');
        }
        foreach ($oldIds as $id) {
            if (! is_int($id) || $id < 1) { throw new CheckoutException('卡密编号无效。'); }
        }
        sort($oldIds, SORT_NUMERIC);

        return DB::transaction(function () use ($order, $oldIds, $reason, $requestToken, $admin) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = $locked->cardReplacements()->where('request_token', $requestToken)->first();
            if ($existing) {
                $previous = $existing->items()->pluck('old_card_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                if ($existing->reason !== $reason || $previous !== $oldIds) {
                    throw new CheckoutException('此操作编号已用于其他换卡请求，请刷新后重试。');
                }
                return $existing->load(['items', 'admin:id,username']);
            }
            if (! $locked->isPaid()) { throw new CheckoutException('仅已付款订单可以售后换卡。'); }
            $primaryReceiptId = $locked->payment_no
                ? $locked->paymentReceipts()->where('trade_no', $locked->payment_no)->value('id') : null;
            $primaryRefunds = $locked->refunds()
                ->where(fn ($q) => $q->whereNull('payment_receipt_id')
                    ->when($primaryReceiptId, fn ($q) => $q->orWhere('payment_receipt_id', $primaryReceiptId)));
            if ((clone $primaryRefunds)->whereIn('status', ['requested', 'approved'])->exists()) {
                throw new CheckoutException('订单原付款退款正在处理，请先完成或拒绝退款申请后再换卡。');
            }
            $refunded = (clone $primaryRefunds)->where('status', 'completed')->sum('amount');
            if (bccomp((string) $refunded, $locked->total_amount, 2) >= 0) {
                throw new CheckoutException('订单原付款已全额退款，不能再分配新卡密。');
            }
            Product::whereKey($locked->product_id)->lockForUpdate()->firstOrFail();
            $current = $locked->deliveryCards()->lockForUpdate()->get();
            if ($current->count() !== $locked->quantity) {
                throw new CheckoutException('当前交付卡密数量与订单不一致，请先核对订单。');
            }
            $old = $current->whereIn('id', $oldIds)->sortBy('id')->values();
            if ($old->count() !== count($oldIds)) {
                throw new CheckoutException('所选卡密已更换或不属于此订单，请刷新后重试。');
            }
            $new = $this->cards->lockCards($locked->product_id, count($oldIds))->sortBy('id')->values();
            $replacement = $locked->cardReplacements()->create([
                'admin_id' => $admin->id, 'reason' => $reason, 'request_token' => $requestToken,
            ]);
            $now = now();
            foreach ($old as $index => $card) {
                $replacement->items()->create(['old_card_id' => $card->id, 'new_card_id' => $new[$index]->id]);
            }
            Card::whereIn('id', $oldIds)->update(['replaced_at' => $now]);
            Card::whereIn('id', $new->modelKeys())->update([
                'status' => 'sold', 'order_id' => $locked->id, 'sold_at' => $now, 'locked_at' => null,
            ]);
            $this->notifications->enqueue('card-replacement:'.$replacement->id, 'order_email', $locked);
            // IDs and reason only: old/new secrets must never enter audit details.
            OperationLog::create(['admin_id' => $admin->id, 'action' => '售后换卡', 'target_type' => 'order',
                'target_id' => $locked->id, 'ip' => request()->ip(),
                'detail' => $locked->order_no.'；旧卡 #'.implode(',#', $oldIds).'；新卡 #'.implode(',#', $new->modelKeys()).'；原因：'.$reason]);
            return $replacement->load(['items', 'admin:id,username']);
        });
    }
}
