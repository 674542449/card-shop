<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Card, Category, NotificationDelivery, Order, OrderCardReplacement, OrderRefund, PaymentReceipt, Product, Setting};
use App\Services\NotificationQueue;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderCardReplacementTest extends TestCase
{
    private function actor(array $permissions = [], string $role = 'owner'): Admin
    {
        $admin = Admin::create(['username' => uniqid('replace-'), 'password' => Hash::make('dummy-password-123'),
            'role' => $role, 'permissions' => $permissions, 'is_active' => true]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
        return $admin;
    }

    private function fixture(int $available = 3): array
    {
        $category = Category::create(['name' => 'Dummy', 'slug' => uniqid('cat-'), 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Dummy replacement', 'slug' => uniqid('product-'),
            'price' => '10.00', 'min_quantity' => 1, 'max_quantity' => 10, 'is_active' => true]);
        $order = Order::create(['order_no' => uniqid('DUMMY-'), 'product_id' => $product->id, 'email' => 'dummy@example.test',
            'query_password' => Hash::make('dummy-password'), 'quantity' => 2, 'unit_price' => '10.00', 'total_amount' => '20.00',
            'discount_amount' => '0.00', 'payment_method' => 'alipay', 'status' => 'paid', 'ip' => '192.0.2.20',
            'paid_at' => now(), 'expires_at' => now()->addMinutes(30)]);
        $old = collect();
        for ($i = 0; $i < 2; $i++) {
            $old->push(Card::create(['product_id' => $product->id, 'order_id' => $order->id, 'content' => 'DUMMY-OLD-'.$i, 'status' => 'sold', 'sold_at' => now()]));
        }
        for ($i = 0; $i < $available; $i++) {
            Card::create(['product_id' => $product->id, 'content' => 'DUMMY-NEW-'.$i, 'status' => 'unsold']);
        }
        return [$order, $old, $product];
    }

    private function payload(array $ids, ?string $token = null): array
    {
        return ['card_ids' => $ids, 'reason' => 'Verified dummy after-sale problem', 'request_token' => $token ?? (string) Str::uuid()];
    }

    public function test_partial_replacement_preserves_history_and_only_current_cards_are_delivered_and_emailed(): void
    {
        $this->actor(); [$order, $old, $product] = $this->fixture();
        $request = $this->payload([$old[0]->id]);
        $this->postJson('/api/admin/orders/'.$order->id.'/replacements', $request)->assertCreated();
        $this->assertNotNull($old[0]->fresh()->replaced_at);
        $this->assertSame('sold', $old[0]->fresh()->status);
        $this->assertSame($order->id, $old[0]->fresh()->order_id);
        $this->assertCount(3, $order->cards()->get());
        $this->assertEqualsCanonicalizing(['DUMMY-OLD-1', 'DUMMY-NEW-0'], $order->deliveryCards()->pluck('content')->all());
        $this->assertSame(2, $product->cards()->where('status', 'unsold')->count());
        $replacement = OrderCardReplacement::firstOrFail();
        $this->assertSame($old[0]->id, $replacement->items()->first()->old_card_id);
        $detail = $this->getJson('/api/admin/orders/'.$order->id)->assertOk()->assertJsonCount(2, 'cards')->assertJsonCount(1, 'card_replacements');
        $this->assertStringNotContainsString('DUMMY-OLD-0', $detail->getContent());
        $this->patchJson('/api/admin/cards/'.$old[0]->id.'/status', ['status' => 'unsold'])->assertUnprocessable();
        $this->deleteJson('/api/admin/cards/'.$old[0]->id)->assertUnprocessable();

        Setting::set('mail_host', '');
        $capture = new class {
            public string $body = '';
            public function html(string $body, $callback): void { $this->body = $body; }
        };
        Mail::swap($capture);
        app(NotificationQueue::class)->process(10);
        $this->assertStringNotContainsString('DUMMY-OLD-0', $capture->body);
        $this->assertStringContainsString('DUMMY-NEW-0', $capture->body);
        $this->assertStringContainsString('DUMMY-OLD-1', $capture->body);
        $this->assertSame('sent', NotificationDelivery::firstOrFail()->status);
    }

    public function test_retry_is_idempotent_and_stale_old_card_selection_cannot_consume_more_stock(): void
    {
        $this->actor(); [$order, $old, $product] = $this->fixture();
        $request = $this->payload([$old[0]->id]);
        $this->postJson('/api/admin/orders/'.$order->id.'/replacements', $request)->assertCreated();
        $this->postJson('/api/admin/orders/'.$order->id.'/replacements', $request)->assertCreated();
        $this->assertSame(1, OrderCardReplacement::count());
        $this->assertSame(1, NotificationDelivery::count());
        $this->postJson('/api/admin/orders/'.$order->id.'/replacements', $this->payload([$old[0]->id]))->assertUnprocessable();
        $this->postJson('/api/admin/orders/'.$order->id.'/replacements', [...$request, 'card_ids' => [$old[1]->id]])->assertUnprocessable();
        $this->assertSame(2, $product->cards()->where('status', 'unsold')->count());
        $this->assertSame(2, $order->deliveryCards()->count());
    }

    public function test_missing_either_sensitive_permission_refuses_replacement(): void
    {
        [$order, $old, $product] = $this->fixture();
        foreach ([['orders:write'], ['cards:write']] as $permissions) {
            $this->actor($permissions, 'staff');
            $this->postJson('/api/admin/orders/'.$order->id.'/replacements', $this->payload([$old[0]->id]))->assertForbidden();
        }
        $this->assertSame(3, $product->cards()->where('status', 'unsold')->count());
        $this->assertSame(0, OrderCardReplacement::count());
    }

    public function test_stock_shortage_and_foreign_cards_roll_back_without_history_or_notifications(): void
    {
        $this->actor(); [$order, $old, $product] = $this->fixture(1);
        $this->postJson('/api/admin/orders/'.$order->id.'/replacements', $this->payload($old->pluck('id')->all()))->assertUnprocessable();
        [$other, $foreign] = $this->fixture();
        $this->postJson('/api/admin/orders/'.$order->id.'/replacements', $this->payload([$foreign[0]->id]))->assertUnprocessable();
        $this->assertSame(1, $product->cards()->where('status', 'unsold')->count());
        $this->assertNull($old[0]->fresh()->replaced_at);
        $this->assertSame(0, OrderCardReplacement::count());
        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_unpaid_or_fully_refunded_orders_cannot_receive_free_replacement_stock(): void
    {
        $this->actor(); [$order, $old, $product] = $this->fixture();
        $order->update(['status' => 'pending']);
        $this->postJson('/api/admin/orders/'.$order->id.'/replacements', $this->payload([$old[0]->id]))->assertUnprocessable();
        $order->update(['status' => 'paid']);
        OrderRefund::create(['order_id' => $order->id, 'amount' => '20.00', 'reason' => 'dummy full reimbursement', 'status' => 'completed', 'completed_at' => now()]);
        $this->postJson('/api/admin/orders/'.$order->id.'/replacements', $this->payload([$old[0]->id]))->assertUnprocessable();
        $this->assertSame(3, $product->cards()->where('status', 'unsold')->count());
        $this->assertSame(0, OrderCardReplacement::count());
    }

    public function test_primary_refund_reservations_freeze_replacement_but_extra_receipt_refunds_do_not(): void
    {
        $this->actor(); [$order, $old, $product] = $this->fixture();
        $order->update(['payment_no' => 'DUMMY-PRIMARY']);
        $primary = PaymentReceipt::create(['order_id' => $order->id, 'channel' => 'epay', 'trade_no' => 'DUMMY-PRIMARY', 'amount' => '20.00', 'received_at' => now()]);
        $extra = PaymentReceipt::create(['order_id' => $order->id, 'channel' => 'epay', 'trade_no' => 'DUMMY-EXTRA', 'amount' => '20.00', 'received_at' => now(), 'review_reason' => 'Duplicate receipt']);
        foreach (['requested', 'approved'] as $status) {
            foreach ([null, $primary->id] as $receiptId) {
                $refund = OrderRefund::create(['order_id' => $order->id, 'payment_receipt_id' => $receiptId, 'amount' => '1.00', 'reason' => 'Synthetic pending primary refund', 'status' => $status]);
                $this->postJson('/api/admin/orders/'.$order->id.'/replacements', $this->payload([$old[0]->id]))->assertUnprocessable();
                $this->assertNull($old[0]->fresh()->replaced_at);
                $refund->update(['status' => 'rejected']);
            }
        }
        OrderRefund::create(['order_id' => $order->id, 'payment_receipt_id' => $extra->id, 'amount' => '20.00', 'reason' => 'Synthetic extra refund', 'status' => 'approved']);
        $this->postJson('/api/admin/orders/'.$order->id.'/replacements', $this->payload([$old[0]->id]))->assertCreated();
        $this->assertSame(2, $product->cards()->where('status', 'unsold')->count());
        $this->assertSame(1, OrderCardReplacement::count());
    }
}
