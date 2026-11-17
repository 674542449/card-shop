<?php

namespace Tests\Feature;

use App\Models\{Card, Category, Order, Product, Setting};
use App\Services\{BrowserOrderCredentialProof, OrderFulfilmentService, OrderLookupService};
use Illuminate\Support\Facades\{Hash, Http};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CardSecretAccessSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Setting::set('epay_api_url', 'https://gateway.example.test');
        Setting::set('epay_merchant_id', 'public-card-test');
        Setting::set('epay_merchant_key', 'public-card-test-key');
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Card access', 'slug' => 'card-access', 'is_active' => true]);
        return Product::create(['name' => 'Card access', 'slug' => 'card-access', 'category_id' => $category->id,
            'price' => '10.00', 'is_active' => true, 'min_quantity' => 1, 'max_quantity' => 5]);
    }

    private function order(Product $product, string $secret, array $changes = []): Order
    {
        $order = Order::create($changes + ['order_no' => generate_order_no(), 'product_id' => $product->id,
            'email' => 'card-owner@example.test', 'query_password' => Hash::make('public-card-password'),
            'query_password_key' => Order::passwordKey('card-owner@example.test', 'public-card-password'),
            'quantity' => 1, 'unit_price' => '10.00', 'total_amount' => '10.00', 'status' => 'paid',
            'payment_method' => 'alipay', 'ip' => '192.0.2.70', 'paid_at' => now(), 'expires_at' => now()->addMinutes(30)]);
        Card::create(['product_id' => $product->id, 'order_id' => $order->id, 'content' => $secret,
            'status' => $order->isPaid() ? 'sold' : 'locked', 'sold_at' => $order->isPaid() ? now() : null]);
        return $order;
    }

    private function verify(Order $order, string $password = 'public-card-password'): void
    {
        $this->postJson('/order/verify', ['email' => $order->email, 'order_no' => $order->order_no,
            'query_password' => $password])->assertOk()->assertJsonPath('success', true);
    }

    private function expectStoredSessionDestruction(\Illuminate\Session\Store $session, string $oldId): void
    {
        $handler = $session->getHandler();
        $observed = $this->createMock(\SessionHandlerInterface::class);
        foreach (['open', 'close', 'read', 'write', 'gc'] as $method) {
            $observed->method($method)->willReturnCallback([$handler, $method]);
        }
        $observed->expects($this->once())->method('destroy')->with($oldId)
            ->willReturnCallback([$handler, 'destroy']);
        $session->setHandler($observed);
    }

    private function assertRotatedCookieOwnership(\Illuminate\Session\Store $session, string $oldId, string $newId, Order $order, string $secret): void
    {
        // HTTP feature tests retain one Store instance across requests; PHP-FPM
        // does not. Clear its in-memory attributes so each browser really reloads
        // only the record selected by its own encrypted session cookie.
        $session->flush();
        $this->withCookie($session->getName(), $oldId)->get('/order/detail/'.$order->order_no)
            ->assertRedirect('/order/query')->assertDontSee($secret);
        $this->assertEmpty(session('order_buyer_proofs', []));
        $session->flush();
        $this->withCookie($session->getName(), $oldId)->get('/order/cards/'.$order->order_no.'/download')
            ->assertRedirect('/order/query')->assertDontSee($secret);
        $session->flush();
        $this->withCookie($session->getName(), $newId)->get('/order/detail/'.$order->order_no)
            ->assertOk()->assertSee($secret);
    }

    public static function rotatedCandidatePositions(): array
    {
        return ['revoked order is older' => [false], 'revoked order is newer' => [true]];
    }

    #[DataProvider('rotatedCandidatePositions')]
    public function test_batch_lookup_authenticates_every_actual_hash_even_when_old_indexes_match(bool $rotatedNewer): void
    {
        $product = $this->product();
        $older = $this->order($product, 'PUBLIC-DUMMY-older');
        $newer = $this->order($product, 'PUBLIC-DUMMY-newer');
        $rotated = $rotatedNewer ? $newer : $older;
        $valid = $rotatedNewer ? $older : $newer;
        $oldIndex = $rotated->query_password_key;
        $rotated->update(['query_password' => Hash::make('public-replacement-password')]);

        $result = app(OrderLookupService::class)->search($valid->email, 'public-card-password');
        $this->assertSame([$valid->id], $result['orders']->pluck('id')->all());
        $this->assertSame($oldIndex, $rotated->fresh()->query_password_key);
        $this->post('/order/query', ['email' => $valid->email, 'query_password' => 'public-card-password'])
            ->assertOk()->assertSee($valid->order_no)->assertDontSee($rotated->order_no);
        $this->assertSame([$valid->id], session('order_verified_ids'));
        $this->get('/order/detail/'.$rotated->order_no)->assertRedirect('/order/query');
        $this->get('/order/cards/'.$rotated->order_no.'/download')->assertRedirect('/order/query');
        $this->get('/order/detail/'.$valid->order_no)->assertOk()->assertSee($valid->cards()->firstOrFail()->content);
    }

    public function test_successful_lookup_cache_cannot_survive_a_real_password_hash_change(): void
    {
        $order = $this->order($this->product(), 'PUBLIC-DUMMY-cache-bound');
        $lookup = app(OrderLookupService::class);
        $this->assertSame([$order->id], $lookup->search($order->email, 'public-card-password')['orders']->pluck('id')->all());
        $this->assertSame([$order->id], $lookup->search($order->email, 'public-card-password')['orders']->pluck('id')->all());
        $order->update(['query_password' => Hash::make('public-replacement-password')]);
        $this->assertEmpty($lookup->search($order->email, 'public-card-password')['orders']);
        $this->assertEmpty($lookup->search($order->email, 'public-card-password', $order->order_no)['orders']);
        $this->assertSame([$order->id], $lookup->search($order->email, 'public-replacement-password', $order->order_no)['orders']->pluck('id')->all());
        $this->assertSame([$order->id], $lookup->search($order->email, 'public-replacement-password')['orders']->pluck('id')->all());
    }

    public function test_old_id_only_browser_grants_cannot_read_cards_or_submit_buyer_actions(): void
    {
        $order = $this->order($this->product(), 'PUBLIC-DUMMY-id-only');
        $this->withSession(['order_verified_ids' => [$order->id]]);
        $this->get('/order/detail/'.$order->order_no)->assertRedirect('/order/query');
        $this->get('/order/pay/'.$order->order_no)->assertRedirect('/order/query');
        $this->get('/order/cards/'.$order->order_no.'/download')->assertRedirect('/order/query');
        $this->post('/order/refund/'.$order->order_no, ['amount' => '10.00', 'reason' => 'forged'])->assertForbidden();
        $this->post('/order/cancel/'.$order->order_no)->assertForbidden();
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_buyer_verification_destroys_fixed_session_id_and_preserves_existing_csrf_token(): void
    {
        $order = $this->order($this->product(), 'PUBLIC-DUMMY-session-rotation');
        $this->withSession(['visitor-marker' => 'legitimate-cart']);
        $session = $this->app['session.store'];
        $session->token();
        $oldId = $session->getId();
        $csrfToken = $session->token();
        $session->save();
        $this->assertNotSame('', $session->getHandler()->read($oldId));
        $this->expectStoredSessionDestruction($session, $oldId);
        $session->flush();

        $this->withCookie($session->getName(), $oldId)->withCredentials();
        $this->verify($order);
        $newId = $session->getId();
        $this->assertNotSame($oldId, $newId);
        $this->assertSame('', $session->getHandler()->read($oldId));
        $this->assertSame($csrfToken, $session->token());
        $this->assertSame('legitimate-cart', session('visitor-marker'));
        $this->assertRotatedCookieOwnership($session, $oldId, $newId, $order, 'PUBLIC-DUMMY-session-rotation');
    }

    public function test_checkout_grants_current_order_under_a_new_session_id(): void
    {
        $product = $this->product();
        Card::create(['product_id' => $product->id, 'content' => 'PUBLIC-DUMMY-checkout', 'status' => 'unsold']);
        $this->withSession(['visitor-marker' => 'new-checkout']);
        $session = $this->app['session.store'];
        $oldId = $session->getId();
        $session->save();
        $this->assertNotSame('', $session->getHandler()->read($oldId));
        $this->expectStoredSessionDestruction($session, $oldId);
        $session->flush();
        $this->withCookie($session->getName(), $oldId)->post('/order/create', ['product_id' => $product->id, 'quantity' => 1, 'email' => 'checkout-owner@example.test',
            'query_password' => 'public-card-password', 'payment_method' => 'alipay'])->assertRedirect()->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $newId = $session->getId();
        $this->assertNotSame($oldId, $newId);
        $this->assertSame('', $session->getHandler()->read($oldId));
        $this->assertTrue(app(BrowserOrderCredentialProof::class)->has($order));
        $this->assertSame('new-checkout', session('visitor-marker'));
        app(OrderFulfilmentService::class)->fulfilManually($order);
        $this->assertRotatedCookieOwnership($session, $oldId, $newId, $order, 'PUBLIC-DUMMY-checkout');
        $this->get('/order/pay/'.$order->order_no)->assertOk()->assertSee('PUBLIC-DUMMY-checkout');
    }

    public function test_browser_grant_is_revoked_when_password_changes_and_can_be_verified_again(): void
    {
        $order = $this->order($this->product(), 'PUBLIC-DUMMY-password-revoked');
        $this->verify($order);
        $this->get('/order/detail/'.$order->order_no)->assertOk()->assertSee('PUBLIC-DUMMY-password-revoked');
        $order->update(['query_password' => Hash::make('public-replacement-password')]);
        $this->get('/order/detail/'.$order->order_no)->assertRedirect('/order/query');
        $this->get('/order/pay/'.$order->order_no)->assertRedirect('/order/query');
        $this->get('/order/cards/'.$order->order_no.'/download')->assertRedirect('/order/query');
        $this->post('/order/refund/'.$order->order_no, ['amount' => '10.00', 'reason' => 'revoked'])->assertForbidden();
        $this->verify($order, 'public-replacement-password');
        $this->get('/order/cards/'.$order->order_no.'/download')->assertOk()->assertSee('PUBLIC-DUMMY-password-revoked');
    }

    public function test_browser_grant_is_revoked_when_order_email_changes(): void
    {
        $order = $this->order($this->product(), 'PUBLIC-DUMMY-email-revoked');
        $this->verify($order);
        $order->update(['email' => 'replacement-owner@example.test']);
        $this->get('/order/detail/'.$order->order_no)->assertRedirect('/order/query');
        $this->verify($order);
        $this->get('/order/detail/'.$order->order_no)->assertOk()->assertSee('PUBLIC-DUMMY-email-revoked');
    }

    public function test_browser_proof_has_a_fixed_lifetime_even_while_the_session_remains_active(): void
    {
        $order = $this->order($this->product(), 'PUBLIC-DUMMY-expiring-grant');
        $this->verify($order);
        $expiresAt = session('order_buyer_proofs')[$order->id]['expires_at'];
        $this->travel(25)->minutes();
        $this->get('/order/detail/'.$order->order_no)->assertOk()->assertSee('PUBLIC-DUMMY-expiring-grant');
        $this->assertSame($expiresAt, session('order_buyer_proofs')[$order->id]['expires_at']);
        $this->travel(6)->minutes();
        $this->get('/order/detail/'.$order->order_no)->assertRedirect('/order/query');
        $this->get('/order/cards/'.$order->order_no.'/download')->assertRedirect('/order/query');
        $this->verify($order);
        $this->get('/order/detail/'.$order->order_no)->assertOk()->assertSee('PUBLIC-DUMMY-expiring-grant');
    }

    public function test_current_pending_order_proof_cannot_reveal_locked_stock_and_hash_revocation_blocks_cancel(): void
    {
        $order = $this->order($this->product(), 'PUBLIC-DUMMY-locked', ['status' => 'pending', 'paid_at' => null]);
        $this->verify($order);
        $this->get('/order/detail/'.$order->order_no)->assertOk()->assertDontSee('PUBLIC-DUMMY-locked');
        $this->get('/order/cards/'.$order->order_no.'/download')->assertRedirect('/order/detail/'.$order->order_no);
        $order->update(['query_password' => Hash::make('public-replacement-password')]);
        $this->post('/order/cancel/'.$order->order_no)->assertForbidden();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('locked', $order->cards()->firstOrFail()->status);
    }

    public function test_paid_browser_pages_and_downloads_expose_only_delivered_sold_cards(): void
    {
        $product = $this->product();
        $order = $this->order($product, 'PUBLIC-DUMMY-delivered');
        foreach (['locked', 'unsold'] as $status) {
            Card::create(['product_id' => $product->id, 'order_id' => $order->id,
                'content' => 'PUBLIC-DUMMY-not-delivered-'.$status, 'status' => $status]);
        }
        $this->verify($order);
        foreach (['/order/detail/', '/order/pay/'] as $prefix) {
            $this->get($prefix.$order->order_no)->assertOk()->assertSee('PUBLIC-DUMMY-delivered')
                ->assertDontSee('PUBLIC-DUMMY-not-delivered-locked')->assertDontSee('PUBLIC-DUMMY-not-delivered-unsold');
        }
        $this->get('/order/cards/'.$order->order_no.'/download')->assertOk()->assertSee('PUBLIC-DUMMY-delivered')
            ->assertDontSee('PUBLIC-DUMMY-not-delivered-locked')->assertDontSee('PUBLIC-DUMMY-not-delivered-unsold');
    }
}
