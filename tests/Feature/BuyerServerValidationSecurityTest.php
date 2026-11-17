<?php

namespace Tests\Feature;

use App\Models\{ApiToken, Card, Category, NotificationDelivery, Order, OrderRefund, Product, Setting};
use App\Services\{ApiOrderCredentialProof, OrderFulfilmentService, OrderLookupService};
use Illuminate\Support\Facades\{Cache, Hash, Http, Log, Route};
use Tests\TestCase;

class BuyerServerValidationSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        foreach (['epay_api_url' => 'https://gateway.example.test', 'epay_merchant_id' => 'validation-test', 'epay_merchant_key' => 'validation-test-key'] as $key => $value) {
            Setting::set($key, $value);
        }
        $this->token('validation-token-a');
        $this->token('validation-token-b');
    }

    private function token(string $secret, array $changes = []): ApiToken
    {
        return ApiToken::create($changes + ['name' => $secret, 'token' => hash('sha256', $secret), 'is_active' => true]);
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Server validation', 'slug' => 'server-validation', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Server validation', 'slug' => 'server-validation',
            'price' => '10.00', 'min_quantity' => 1, 'max_quantity' => 3, 'is_active' => true]);
        for ($i = 0; $i < 3; $i++) {
            Card::create(['product_id' => $product->id, 'content' => 'validation-secret-'.$i, 'status' => 'unsold']);
        }
        return $product;
    }

    private function order(Product $product, string $password = 'public-validation-password', array $changes = []): Order
    {
        return Order::create($changes + ['order_no' => generate_order_no(), 'product_id' => $product->id,
            'api_token_id' => ApiToken::where('name', 'validation-token-a')->firstOrFail()->id,
            'email' => 'validation@example.test', 'query_password' => Hash::make($password),
            'query_password_key' => Order::passwordKey('validation@example.test', $password),
            'quantity' => 1, 'unit_price' => '10.00', 'total_amount' => '10.00', 'payment_method' => 'alipay',
            'status' => 'pending', 'ip' => '192.0.2.80', 'expires_at' => now()->addMinutes(30)]);
    }

    private function credentials(Order $order, string $password = 'public-validation-password'): array
    {
        return ['email' => $order->email, 'query_password' => $password, 'order_no' => $order->order_no];
    }

    public function test_query_password_byte_limit_rejects_bcrypt_suffixes_without_rewriting_order_index(): void
    {
        $password = str_repeat('密', 24);
        $order = $this->order($this->product(), $password);
        $originalKey = $order->query_password_key;
        $forged = $password.'x';
        $this->assertSame(72, strlen($password));
        $this->assertSame(25, mb_strlen($forged));
        // This proves why a character-count validation alone is insufficient.
        $this->assertTrue(Hash::check($forged, $order->query_password));

        $credentials = $this->credentials($order, $forged);
        $this->postJson('/order/verify', $credentials)->assertUnprocessable()->assertJsonValidationErrors('query_password');
        $this->postJson('/order/query', $credentials)->assertUnprocessable()->assertJsonValidationErrors('query_password');
        $this->withToken('validation-token-a')->postJson('/api/v1/orders/'.$order->order_no.'/query', $credentials)
            ->assertUnprocessable()->assertJsonValidationErrors('query_password');
        $this->postJson('/api/v1/orders/'.$order->order_no.'/cancel', $credentials)
            ->assertUnprocessable()->assertJsonValidationErrors('query_password');
        $this->assertTrue(app(OrderLookupService::class)->search($order->email, $forged, $order->order_no)['orders']->isEmpty());
        $this->assertSame($originalKey, $order->fresh()->query_password_key);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertEmpty(session('order_verified_ids', []));

        $this->postJson('/api/v1/orders/'.$order->order_no.'/query', $this->credentials($order, $password))
            ->assertOk()->assertJsonPath('data.order_no', $order->order_no);
        $this->postJson('/api/v1/orders/'.$order->order_no.'/query', $credentials)
            ->assertUnprocessable()->assertJsonValidationErrors('query_password');
        $this->postJson('/order/verify', $this->credentials($order, $password))->assertOk()->assertJsonPath('success', true);
    }

    public function test_guessing_limit_is_shared_by_api_query_cancel_browser_verify_and_token_rotation(): void
    {
        $order = $this->order($this->product());
        $wrong = $this->credentials($order, 'wrong-password');
        $this->postJson('/order/verify', $wrong)->assertOk()->assertJsonPath('success', false);
        $this->withToken('validation-token-a')->postJson('/api/v1/orders/'.$order->order_no.'/query', $wrong)->assertNotFound();
        $this->withToken('validation-token-b')->postJson('/api/v1/orders/'.$order->order_no.'/query', $wrong)->assertNotFound();
        $this->withToken('validation-token-a')->postJson('/api/v1/orders/'.$order->order_no.'/cancel', $wrong)->assertNotFound();
        $this->postJson('/order/verify', $wrong)->assertOk()->assertJsonPath('success', false);

        $this->withToken('validation-token-b')->postJson('/api/v1/orders/'.$order->order_no.'/query', $this->credentials($order))
            ->assertStatus(429)->assertHeader('Retry-After')->assertDontSee('validation-secret-');
        $this->withToken('validation-token-a')->postJson('/api/v1/orders/'.$order->order_no.'/cancel', $this->credentials($order))->assertStatus(429);
        $this->post('/order/query', $this->credentials($order))->assertRedirect()->assertSessionHasErrors('error')->assertSessionMissing('_old_input.query_password');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertEmpty(session('order_verified_ids', []));
    }

    public function test_api_ip_guessing_budget_cannot_be_reset_by_email_or_token_rotation(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->withToken($i % 2 ? 'validation-token-a' : 'validation-token-b')
                ->postJson('/api/v1/orders/UNKNOWN/query', ['email' => 'guess-'.$i.'@example.test', 'query_password' => 'wrong-password'])
                ->assertNotFound();
        }
        $this->postJson('/api/v1/orders/UNKNOWN/query', ['email' => 'new-target@example.test', 'query_password' => 'wrong-password'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_api_distributed_guessing_budget_is_email_scoped_across_ip_and_token_changes(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.(100 + $i)])
                ->withToken($i % 2 ? 'validation-token-a' : 'validation-token-b')
                ->postJson('/api/v1/orders/UNKNOWN/query', ['email' => $i % 2 ? 'GUESS@example.test' : 'guess@example.test', 'query_password' => 'wrong-password'])
                ->assertNotFound();
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.200'])->withToken('validation-token-b')
            ->postJson('/api/v1/orders/UNKNOWN/query', ['email' => 'guess@example.test', 'query_password' => 'wrong-password'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(0, Order::count());
    }

    public function test_cached_owner_authentication_does_not_clear_the_shared_guessing_budget_or_transfer_to_another_token(): void
    {
        $order = $this->order($this->product());
        $credentials = $this->credentials($order);
        $path = '/api/v1/orders/'.$order->order_no;
        $this->withToken('validation-token-a')->postJson($path.'/query', $credentials)->assertOk();
        for ($i = 0; $i < 4; $i++) {
            $this->postJson($path.'/query', $this->credentials($order, 'wrong-password'))->assertNotFound();
        }
        // A verified buyer can still poll and cancel after the guess allowance is
        // spent, while these successful calls do not reset the failed attempts.
        $this->postJson($path.'/query', $credentials)->assertOk();
        $this->postJson($path.'/cancel', $credentials)->assertOk()->assertJsonPath('data.status', 'closed');
        $this->postJson($path.'/query', $this->credentials($order, 'wrong-password'))->assertStatus(429);
        $this->withToken('validation-token-b')->postJson($path.'/query', $credentials)->assertStatus(429);
        $this->postJson('/order/verify', $credentials)->assertStatus(429)->assertJsonPath('success', false);
        $this->assertSame('closed', $order->fresh()->status);
    }

    public function test_api_owner_can_poll_repeatedly_and_still_observe_verified_payment_delivery(): void
    {
        $product = $this->product();
        $this->withToken('validation-token-a')->postJson('/api/v1/orders', [
            'product_id' => $product->id, 'quantity' => 1, 'email' => 'validation@example.test',
            'query_password' => 'public-validation-password', 'payment_method' => 'alipay',
        ])->assertCreated();
        $order = Order::firstOrFail();
        $path = '/api/v1/orders/'.$order->order_no.'/query';
        for ($i = 0; $i < 15; $i++) {
            $this->postJson($path, $this->credentials($order))->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.cards');
        }
        $params = ['pid' => 'validation-test', 'out_trade_no' => $order->order_no, 'trade_no' => 'validation-real-payment',
            'trade_status' => 'TRADE_SUCCESS', 'money' => '10.00'];
        ksort($params);
        $params['sign'] = md5(implode('&', array_map(fn ($key, $value) => $key.'='.$value, array_keys($params), array_values($params))).'validation-test-key');
        $this->post('/payment/epay/notify', $params)->assertContent('success');
        $this->postJson($path, $this->credentials($order))->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonCount(1, 'data.cards');
        $this->assertSame(1, $order->cards()->where('status', 'sold')->count());
        $this->assertSame(1, $order->paymentReceipts()->count());
    }

    public function test_successful_api_batch_creation_proves_only_its_new_orders_without_resetting_guess_limits(): void
    {
        $product = $this->product();
        for ($i = 3; $i < 7; $i++) {
            Card::create(['product_id' => $product->id, 'content' => 'validation-secret-'.$i, 'status' => 'unsold']);
        }
        $token = ApiToken::where('name', 'validation-token-a')->firstOrFail();
        $token->update(['orders_per_minute' => 20, 'max_pending_orders' => 10, 'max_pending_quantity' => 20]);
        $unrelated = $this->order($product);
        $this->withToken('validation-token-a');
        $created = [];
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/v1/orders', ['product_id' => $product->id, 'quantity' => 1,
                'email' => 'validation@example.test', 'query_password' => 'public-validation-password', 'payment_method' => 'alipay'])
                ->assertCreated();
            $created[] = Order::where('order_no', $response->json('data.order_no'))->firstOrFail();
        }
        $proof = app(ApiOrderCredentialProof::class);
        $this->assertFalse($proof->has($unrelated, $token->id, $unrelated->email, 'public-validation-password'));
        foreach ($created as $order) {
            $this->assertTrue($proof->has($order, $token->id, $order->email, 'public-validation-password'));
            $this->postJson('/api/v1/orders/'.$order->order_no.'/query', $this->credentials($order))
                ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.cards');
        }
        $path = '/api/v1/orders/'.$created[0]->order_no.'/query';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($path, $this->credentials($created[0], 'wrong-password'))->assertNotFound();
        }
        $this->postJson($path, $this->credentials($created[0], 'wrong-password'))->assertStatus(429);
        $this->postJson($path, $this->credentials($created[0]))->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(6, $product->cards()->where('status', 'locked')->count());
        $this->assertSame(1, $product->stockCount());
    }

    public function test_failed_api_payment_initiation_does_not_create_a_credential_proof(): void
    {
        $product = $this->product();
        Setting::set('epay_merchant_key', '');
        $token = ApiToken::where('name', 'validation-token-a')->firstOrFail();
        $this->withToken('validation-token-a')->postJson('/api/v1/orders', ['product_id' => $product->id, 'quantity' => 1,
            'email' => 'validation@example.test', 'query_password' => 'public-validation-password', 'payment_method' => 'alipay'])
            ->assertUnprocessable();
        $order = Order::firstOrFail();
        $this->assertSame('closed', $order->status);
        $this->assertFalse(app(ApiOrderCredentialProof::class)->has($order, $token->id, $order->email, 'public-validation-password'));
        $this->assertSame(3, $product->stockCount());
        $this->assertSame(0, $product->cards()->where('status', 'locked')->count());
        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_legacy_password_key_migrates_only_after_bcrypt_and_then_supports_owner_polling(): void
    {
        $order = $this->order($this->product(), 'public-validation-password', ['query_password_key' => null]);
        $path = '/api/v1/orders/'.$order->order_no.'/query';
        $this->withToken('validation-token-a')->postJson($path, $this->credentials($order, 'wrong-password'))->assertNotFound();
        $this->assertNull($order->fresh()->query_password_key);
        $this->postJson($path, $this->credentials($order))->assertOk();
        $this->assertSame(Order::passwordKey($order->email, 'public-validation-password'), $order->fresh()->query_password_key);
        $this->assertTrue(app(ApiOrderCredentialProof::class)->has($order->fresh(), $order->api_token_id, $order->email, 'public-validation-password'));
        for ($i = 0; $i < 8; $i++) {
            $this->postJson($path, $this->credentials($order))->assertOk();
        }
    }

    public function test_api_credential_proof_is_invalidated_when_the_stored_password_hash_changes(): void
    {
        $order = $this->order($this->product());
        $path = '/api/v1/orders/'.$order->order_no.'/query';
        $this->withToken('validation-token-a')->postJson($path, $this->credentials($order))->assertOk();
        $this->assertTrue(app(ApiOrderCredentialProof::class)->has($order->fresh(), $order->api_token_id, $order->email, 'public-validation-password'));
        $order->update(['query_password' => Hash::make('replacement-validation-password')]);
        $this->assertFalse(app(ApiOrderCredentialProof::class)->has($order->fresh(), $order->api_token_id, $order->email, 'public-validation-password'));
        $this->postJson($path, $this->credentials($order))->assertNotFound();
        $updated = $this->credentials($order, 'replacement-validation-password');
        $this->postJson($path, $updated)->assertOk();
        for ($i = 0; $i < 8; $i++) {
            $this->postJson($path, $updated)->assertOk();
        }
        $this->postJson($path, $this->credentials($order))->assertNotFound();
    }

    public function test_api_cancellation_rejects_credentials_in_url_even_when_valid_body_is_also_present(): void
    {
        $order = $this->order($this->product());
        $path = '/api/v1/orders/'.$order->order_no.'/cancel';
        $credentials = $this->credentials($order);
        $this->withToken('validation-token-a')->postJson($path.'?'.http_build_query($credentials), $credentials)->assertUnprocessable();
        $this->assertSame('pending', $order->fresh()->status);
        $this->postJson($path, $credentials)->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertSame('closed', $order->fresh()->status);
    }

    public function test_dynamic_order_number_cannot_select_a_different_api_permission(): void
    {
        $queryToken = $this->token('query-read-only', ['scopes' => ['orders:query']]);
        $order = $this->order($this->product(), 'public-validation-password', ['order_no' => 'products-legacy-order', 'api_token_id' => $queryToken->id]);
        $this->token('products-read-only', ['scopes' => ['products:read']]);
        $path = '/api/v1/orders/'.$order->order_no.'/query';
        $this->withToken('products-read-only')->postJson($path, $this->credentials($order))->assertForbidden();
        $this->withToken('query-read-only')->postJson($path, $this->credentials($order))->assertOk()->assertJsonPath('data.order_no', $order->order_no);
        $this->getJson('/api/v1/products')->assertForbidden();
    }

    public function test_fresh_non_owner_token_cannot_read_paid_api_or_web_orders_even_with_correct_credentials(): void
    {
        $product = $this->product();
        $apiOrder = $this->order($product);
        $webOrder = $this->order($product, 'public-validation-password', ['api_token_id' => null]);
        app(OrderFulfilmentService::class)->fulfilManually($apiOrder);
        app(OrderFulfilmentService::class)->fulfilManually($webOrder);
        $proof = app(ApiOrderCredentialProof::class);
        $this->assertFalse($proof->has($apiOrder->fresh(), $apiOrder->api_token_id, $apiOrder->email, 'public-validation-password'));

        // These are the first credential checks: refusal must be ownership based,
        // rather than a previously exhausted guess limit or absent password.
        $this->withToken('validation-token-b')->postJson('/api/v1/orders/'.$apiOrder->order_no.'/query', $this->credentials($apiOrder))
            ->assertNotFound()->assertDontSee('validation-secret-')->assertJsonMissingPath('data');
        $this->withToken('validation-token-a')->postJson('/api/v1/orders/'.$webOrder->order_no.'/query', $this->credentials($webOrder))
            ->assertNotFound()->assertDontSee('validation-secret-')->assertJsonMissingPath('data');
        $this->assertFalse($proof->has($apiOrder->fresh(), $apiOrder->api_token_id, $apiOrder->email, 'public-validation-password'));
        $this->assertSame('paid', $apiOrder->fresh()->status);
        $this->assertSame('paid', $webOrder->fresh()->status);
        $this->assertSame(2, $product->cards()->where('status', 'sold')->count());

        $this->postJson('/api/v1/orders/'.$apiOrder->order_no.'/query', $this->credentials($apiOrder))
            ->assertOk()->assertJsonCount(1, 'data.cards');
        $this->postJson('/order/verify', $this->credentials($webOrder))->assertOk()->assertJsonPath('success', true);
        $this->get('/order/detail/'.$webOrder->order_no)->assertOk()->assertSee('validation-secret-');
    }

    public function test_api_creation_validates_multibyte_password_bytes_before_reserving_order_or_cards(): void
    {
        $product = $this->product();
        $password = str_repeat('密', 24);
        $data = ['product_id' => $product->id, 'quantity' => 1, 'email' => 'validation@example.test',
            'query_password' => $password.'x', 'payment_method' => 'alipay'];
        $this->withToken('validation-token-a')->postJson('/api/v1/orders', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('query_password');
        $this->assertSame(0, Order::count());
        $this->assertSame(3, $product->stockCount());
        $this->assertSame(0, $product->cards()->where('status', 'locked')->count());
        $this->assertSame(0, NotificationDelivery::count());

        $data['query_password'] = $password;
        $this->postJson('/api/v1/orders', $data)->assertCreated();
        $this->assertSame(1, Order::count());
        $this->assertSame(1, $product->cards()->where('status', 'locked')->count());
        $this->assertSame(2, $product->stockCount());
    }

    public function test_encoded_routes_cannot_bypass_create_or_cancel_token_permissions(): void
    {
        $product = $this->product();
        $order = $this->order($product);
        $this->token('query-only', ['scopes' => ['orders:query']]);
        $this->withToken('query-only')->postJson('/api/v1/%6frders', [
            'product_id' => $product->id, 'quantity' => 1, 'email' => 'validation@example.test',
            'query_password' => 'public-validation-password', 'payment_method' => 'alipay',
        ])->assertForbidden();
        $this->postJson('/api/v1/orders/'.$order->order_no.'/%63ancel', $this->credentials($order))->assertForbidden();
        $this->assertSame(1, Order::count());
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(3, $product->stockCount());
        $this->assertSame(0, $product->cards()->where('status', 'locked')->count());
    }

    public function test_encoded_create_route_cannot_bypass_the_token_order_rate_limit(): void
    {
        $product = $this->product();
        $this->token('one-order-per-minute', ['scopes' => ['orders:create'], 'orders_per_minute' => 1]);
        $data = ['product_id' => $product->id, 'quantity' => 1, 'email' => 'validation@example.test',
            'query_password' => 'public-validation-password', 'payment_method' => 'alipay'];
        $this->withToken('one-order-per-minute')->postJson('/api/v1/%6frders', $data)->assertCreated();
        $this->postJson('/api/v1/%6frders', $data)->assertStatus(429)->assertHeader('Retry-After');
        $this->assertSame(1, Order::count());
        $this->assertSame(1, $product->cards()->where('status', 'locked')->count());
        $this->assertSame(2, $product->stockCount());
    }

    public function test_unknown_api_actions_fail_closed_even_with_legacy_all_scope_tokens(): void
    {
        Route::get('/api/v1/unmapped-closure', fn () => response()->json(['unexpected' => true]))
            ->middleware(\App\Http\Middleware\ApiTokenAuth::class);
        Route::get('/api/v1/unmapped-product-action', [\App\Http\Controllers\Api\ProductController::class, 'unmapped'])
            ->middleware(\App\Http\Middleware\ApiTokenAuth::class);
        Route::post('/api/v1/unmapped-order-action', [\App\Http\Controllers\Api\OrderController::class, 'unmapped'])
            ->middleware(\App\Http\Middleware\ApiTokenAuth::class);
        $this->withToken('validation-token-a')->getJson('/api/v1/unmapped-closure')->assertForbidden()->assertJsonMissingPath('unexpected');
        $this->head('/api/v1/unmapped-closure')->assertForbidden();
        $this->getJson('/api/v1/unmapped-product-action')->assertForbidden();
        $this->head('/api/v1/unmapped-product-action')->assertForbidden();
        $this->postJson('/api/v1/unmapped-order-action')->assertForbidden();
        $this->assertSame(0, Order::count());
        $this->assertSame(0, NotificationDelivery::count());

        // The deliberately retained migration response remains accessible only
        // with the actual order-query scope, not an arbitrary closure fallback.
        $this->token('legacy-query-only', ['scopes' => ['orders:query']]);
        $this->token('legacy-products-only', ['scopes' => ['products:read']]);
        $this->withToken('legacy-query-only')->getJson('/api/v1/orders/UNKNOWN')->assertStatus(405)->assertHeader('Allow', 'POST');
        $this->withToken('legacy-products-only')->getJson('/api/v1/orders/UNKNOWN')->assertForbidden();
    }

    public function test_head_requests_keep_the_same_read_permissions_as_get_without_enabling_write_actions(): void
    {
        $product = $this->product();
        $this->token('head-products-reader', ['scopes' => ['products:read']]);
        $this->token('head-order-reader', ['scopes' => ['orders:query']]);
        $this->withToken('head-products-reader')->head('/api/v1/products')->assertOk()->assertContent('');
        $this->head('/api/v1/products/'.$product->id)->assertOk()->assertContent('');
        $this->head('/api/v1/orders/UNKNOWN')->assertForbidden();
        $this->withToken('head-order-reader')->head('/api/v1/products')->assertForbidden();
        $this->head('/api/v1/products/'.$product->id)->assertForbidden();
        $this->head('/api/v1/orders/UNKNOWN')->assertStatus(405)->assertHeader('Allow', 'POST')->assertContent('');
        $this->head('/api/v1/orders')->assertStatus(405);
        $this->head('/api/v1/orders/UNKNOWN/cancel')->assertStatus(405);
        $this->assertSame(0, Order::count());
        $this->assertSame(3, $product->stockCount());
        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_invalid_api_product_identifiers_are_rejected_without_a_server_error(): void
    {
        $this->withToken('validation-token-a')->getJson('/api/v1/products/not-a-number')->assertNotFound();
        $this->getJson('/api/v1/products/0')->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->getJson('/api/v1/products/'.str_repeat('9', 100))->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->getJson('/api/v1/products/'.PHP_INT_MAX)->assertNotFound();
    }

    public function test_api_creation_cannot_mass_assign_prices_delivery_state_or_another_token_owner(): void
    {
        $product = $this->product();
        $owner = ApiToken::where('name', 'validation-token-a')->firstOrFail();
        $other = ApiToken::where('name', 'validation-token-b')->firstOrFail();
        $this->withToken('validation-token-a')->postJson('/api/v1/orders', [
            'product_id' => $product->id, 'quantity' => 1, 'email' => 'validation@example.test',
            'query_password' => 'public-validation-password', 'payment_method' => 'alipay',
            'total_amount' => '0', 'unit_price' => '-1', 'discount_amount' => '999999', 'status' => 'paid',
            'api_token_id' => $other->id, 'payment_no' => 'FORGED', 'paid_at' => now()->toIso8601String(),
            'order_no' => 'FORGED', 'cards' => ['forged'], 'ip' => '192.0.2.250',
        ])->assertCreated()->assertJsonPath('data.total_amount', '10.00');
        $order = Order::firstOrFail();
        $this->assertSame($owner->id, $order->api_token_id);
        $this->assertSame('127.0.0.1', $order->ip);
        $this->assertSame('pending', $order->status);
        $this->assertSame('10.00', $order->unit_price);
        $this->assertSame('0.00', $order->discount_amount);
        $this->assertNull($order->payment_no);
        $this->assertNull($order->paid_at);
        $this->assertNotSame('FORGED', $order->order_no);
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
        $this->assertSame(0, NotificationDelivery::count());
        $this->postJson('/api/v1/orders/'.$order->order_no.'/query', $this->credentials($order))->assertOk()->assertJsonMissingPath('data.cards');
    }

    public function test_buyer_refund_cannot_mass_assign_approval_source_or_other_payment_receipt(): void
    {
        Setting::set('refund_enabled', '1');
        $order = $this->order($this->product());
        app(OrderFulfilmentService::class)->fulfilManually($order);
        $this->withBuyerSession($order)->post('/order/refund/'.$order->order_no, [
            'amount' => '1.00', 'reason' => 'Customer test', 'source' => 'admin', 'status' => 'completed',
            'admin_id' => 9999, 'payment_receipt_id' => 9999, 'reference' => 'FORGED-TRANSFER',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $refund = OrderRefund::firstOrFail();
        $this->assertSame($order->id, $refund->order_id);
        $this->assertSame('buyer', $refund->source);
        $this->assertSame('requested', $refund->status);
        $this->assertSame('1.00', $refund->amount);
        $this->assertNull($refund->admin_id);
        $this->assertNull($refund->payment_receipt_id);
        $this->assertNull($refund->reference);
        $this->assertNull($refund->completed_at);
    }

    public function test_rejected_payment_callbacks_do_not_log_signatures_or_other_credentials(): void
    {
        Setting::set('epusdt_api_token', 'public-validation-usdt-token');
        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        Log::swap($logger);
        $secret = 'public-rejected-signature-do-not-log';
        $input = ['sign' => $secret, 'signature' => $secret, 'query_password' => $secret,
            'cf-turnstile-response' => $secret, 'out_trade_no' => 'UNKNOWN', 'order_id' => 'UNKNOWN'];
        $this->post('/payment/epay/notify', $input)->assertContent('fail');
        $this->postJson('/payment/epusdt/notify', $input)->assertContent('invalid signature');
        foreach (['EPay notify: invalid signature', 'EPUSDT notify: invalid signature'] as $message) {
            $records = array_values(array_filter($logger->records, fn ($record) => $record['level'] === 'warning' && $record['message'] === $message));
            $this->assertCount(1, $records);
            $context = $records[0]['context'];
            $this->assertArrayNotHasKey('sign', $context);
            $this->assertArrayNotHasKey('signature', $context);
            $this->assertArrayNotHasKey('query_password', $context);
            $this->assertArrayNotHasKey('cf-turnstile-response', $context);
            $this->assertStringNotContainsString($secret, json_encode($context));
        }
        $this->assertSame(0, Order::count());
        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_old_payment_url_session_and_cache_entries_cannot_inject_active_schemes(): void
    {
        $order = $this->order($this->product());
        foreach (['default', 'modern', 'minimal'] as $theme) {
            Setting::set('site_theme', $theme);
            Cache::put('payment_url:'.$order->order_no, 'data:text/html,<script>alert(1)</script>', 60);
            $this->withSession(['payment_url_'.$order->order_no => 'javascript:alert(1)'])
                ->get('/order/pay/'.$order->order_no)->assertOk()
                ->assertSee('https://gateway.example.test/submit.php?', false)
                ->assertDontSee('javascript:alert(1)', false)->assertDontSee('data:text/html', false);
            $this->assertStringStartsWith('https://gateway.example.test/submit.php?', session('payment_url_'.$order->order_no));
            $this->assertStringStartsWith('https://gateway.example.test/submit.php?', Cache::get('payment_url:'.$order->order_no));
        }
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
    }

    public function test_legacy_invalid_gateway_configuration_never_redirects_the_browser_to_active_content(): void
    {
        $product = $this->product();
        Setting::set('epay_api_url', 'javascript:alert(1)');
        $this->post('/order/create', ['product_id' => $product->id, 'quantity' => 1, 'email' => 'validation@example.test',
            'query_password' => 'public-validation-password', 'payment_method' => 'alipay'])->assertRedirect()->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertNull(session('payment_url_'.$order->order_no));
        $this->get('/order/pay/'.$order->order_no)->assertOk()->assertDontSee('javascript:alert(1)', false);
        $this->assertSame('pending', $order->status);
        $this->assertSame(0, $order->cards()->where('status', 'sold')->count());
    }
}
