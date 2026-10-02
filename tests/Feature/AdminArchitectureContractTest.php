<?php

namespace Tests\Feature;

use App\Enums\PaymentReviewCode;
use App\Http\Middleware\AdminAuth;
use App\Http\Resources\Admin\{AdminRecordResource, CardResource, OrderResource};
use App\Models\{Admin, ApiToken, BackupRun, Card, Category, NotificationDelivery, Order, PaymentAttempt, PaymentReceipt, Product, Setting};
use App\Services\OrderFulfilmentService;
use Illuminate\Support\Facades\{Hash, Route};
use Tests\TestCase;

class AdminArchitectureContractTest extends TestCase
{
    private function signIn(string $role = 'owner', array $permissions = []): Admin
    {
        $admin = Admin::create(['username' => 'architecture-admin', 'password' => Hash::make('architecture-admin-password'),
            'role' => $role, 'permissions' => $permissions, 'is_active' => true]);
        $this->withSession(['admin_id' => $admin->id, 'admin_pw' => AdminAuth::passwordFingerprint($admin->password)]);
        return $admin;
    }

    private function order(): Order
    {
        $category = Category::create(['name' => 'Architecture', 'slug' => 'architecture', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Architecture product', 'slug' => 'architecture-product', 'price' => '10.00', 'is_active' => true]);
        return Order::create(['order_no' => generate_order_no(), 'product_id' => $product->id, 'email' => 'architecture@example.test',
            'query_password' => Hash::make('architecture-buyer-password'), 'quantity' => 1, 'unit_price' => '10.00', 'total_amount' => '10.00',
            'payment_method' => 'alipay', 'status' => 'pending', 'ip' => '192.0.2.15', 'expires_at' => now()->addMinutes(30)]);
    }

    public function test_every_registered_admin_session_endpoint_has_a_known_explicit_capability(): void
    {
        $owner = $this->signIn();
        foreach (Route::getRoutes() as $route) {
            if (! in_array('admin.auth', $route->gatherMiddleware(), true)) { continue; }
            $capability = $route->defaults['_admin_capability'] ?? null;
            $this->assertIsString($capability, $route->uri());
            $this->assertTrue(\App\Policies\AdminPolicy::allows($owner, $capability), $route->uri().' '.$capability);
        }
    }

    public function test_unknown_owner_route_is_denied_and_metadata_controls_renamed_routes(): void
    {
        $this->signIn();
        Route::middleware(['web', 'admin.auth'])->get('/api/admin/new-unsafe-endpoint', fn () => response()->json(['executed' => true]));
        $this->getJson('/api/admin/new-unsafe-endpoint')->assertForbidden()->assertJsonPath('code', 'permission_denied')->assertJsonMissingPath('executed');
    }

    public function test_safe_http_method_does_not_downgrade_an_explicit_write_capability(): void
    {
        $this->signIn('staff', ['orders:read']);
        Route::middleware(['web', 'admin.auth'])->get('/api/admin/renamed-order-action', fn () => response()->json(['executed' => true]))
            ->defaults('_admin_capability', 'orders:write');
        $this->getJson('/api/admin/renamed-order-action')->assertForbidden()->assertJsonMissingPath('executed');
        $this->getJson('/api/admin/%6f%72%64%65%72%73')->assertOk();
        $this->postJson('/api/admin/orders/999999/paid')->assertForbidden();
    }

    public function test_me_exposes_only_effective_compound_capabilities_from_the_shared_definition(): void
    {
        $this->signIn('staff', ['orders:write', 'payments:write', 'catalog:read']);
        $response = $this->getJson('/api/admin/me')->assertOk();
        $capabilities = $response->json('permission_definition.capabilities');
        $this->assertContains('orders.mark_paid', $capabilities);
        $this->assertNotContains('orders.replace_cards', $capabilities);
        $this->assertNotContains('orders.refund', $capabilities);
        $this->assertNotContains('backups.read', $capabilities);
        $this->assertSame('卡密（敏感内容）', $response->json('permission_definition.areas.cards'));
    }

    public function test_changing_policy_definition_invalidates_an_old_browser_context_before_action(): void
    {
        $admin = $this->signIn();
        $context = AdminAuth::contextFingerprint($admin);
        $definition = config('admin_permissions.combinations');
        $definition['orders.mark_paid'] = ['owner' => true, 'all' => ['orders:write', 'payments:write']];
        config(['admin_permissions.combinations' => $definition]);
        $this->withHeader('X-Admin-Context', $context)->getJson('/api/admin/me')->assertStatus(409)->assertJsonPath('code', 'admin_context_changed');
    }

    public function test_whitelists_hide_new_internal_attributes_even_when_models_make_them_visible(): void
    {
        $admin = $this->signIn();
        $request = request(); $request->attributes->set('admin', $admin);
        $order = $this->order();
        $order->setAttribute('future_internal_secret', 'must-not-leak');
        $order->makeVisible(['query_password', 'query_password_key']);
        $payload = (new OrderResource($order))->resolve($request);
        foreach (['future_internal_secret', 'query_password', 'query_password_key'] as $field) { $this->assertArrayNotHasKey($field, $payload); }
        foreach ([new ApiToken(['name' => 'API', 'token' => 'must-not-leak']), new NotificationDelivery(['payload' => ['secret' => 'must-not-leak'], 'lease_token' => 'must-not-leak']),
            new BackupRun(['lease_token' => 'must-not-leak', 'schedule_key' => 'must-not-leak'])] as $model) {
            $model->setAttribute('future_internal_secret', 'must-not-leak'); $model->setHidden([]);
            $result = (new AdminRecordResource($model))->resolve($request);
            $this->assertStringNotContainsString('must-not-leak', json_encode($result));
        }
    }

    public function test_card_resource_checks_permission_even_if_another_call_made_content_visible(): void
    {
        $admin = $this->signIn('staff', ['orders:read']);
        $order = $this->order();
        $card = Card::create(['product_id' => $order->product_id, 'content' => 'architecture-test-card', 'status' => 'unsold'])->makeVisible(['content', 'content_fingerprint']);
        $request = request(); $request->attributes->set('admin', $admin);
        $payload = (new CardResource($card))->resolve($request);
        $this->assertArrayNotHasKey('content', $payload);
        $this->assertArrayNotHasKey('content_fingerprint', $payload);
        $admin->permissions = ['cards:read'];
        $this->assertSame('architecture-test-card', (new CardResource($card))->resolve($request)['content']);
    }

    public function test_unknown_settings_are_not_returned_and_known_credentials_remain_masked(): void
    {
        $this->signIn();
        Setting::set('future_gateway_private_key', 'must-not-leak', 'payment');
        Setting::set('epay_merchant_key', 'architecture-test-key', 'payment');
        $this->getJson('/api/admin/settings')->assertOk()->assertJsonMissingPath('future_gateway_private_key')
            ->assertJsonPath('epay_merchant_key', '********')->assertDontSee('architecture-test-key');
    }

    public function test_admin_payment_initialization_summary_is_visible_without_exposing_gateway_response_or_changing_receipt_filters(): void
    {
        $this->signIn('staff', ['orders:read']);
        $order = $this->order();
        PaymentAttempt::create(['order_id' => $order->id, 'method' => 'alipay', 'status' => 'uncertain', 'error_code' => 'gateway_uncertain',
            'lease_token' => '11111111-2222-4333-8444-555555555555', 'lease_expires_at' => now()->addMinute(),
            'response_payload' => ['url' => 'https://private-gateway.example.test/signed-private-payment-url']]);
        $detail = $this->getJson('/api/admin/orders/'.$order->id)->assertOk()->assertJsonPath('payment_initialization', 'uncertain')
            ->assertJsonPath('payment_initialization_detail.error_code', 'gateway_uncertain')->assertJsonPath('has_payment_review', false)
            ->assertJsonMissingPath('payment_initialization_detail.lease_token')->assertJsonMissingPath('payment_initialization_detail.response_payload')
            ->assertDontSee('signed-private-payment-url')->assertDontSee('11111111-2222-4333-8444-555555555555');
        $this->assertNotEmpty($detail->json('payment_initialization_detail.updated_at'));
        $this->getJson('/api/admin/orders?payment_initialization=uncertain')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.payment_initialization', 'uncertain')->assertDontSee('signed-private-payment-url');
        $this->getJson('/api/admin/orders?payment_review=1')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/orders?payment_initialization=succeeded')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/orders?payment_initialization=none')->assertOk()->assertJsonPath('total', 0);
        PaymentAttempt::where('order_id', $order->id)->delete();
        $this->getJson('/api/admin/orders?payment_initialization=none')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/orders?payment_initialization=unconfigured')->assertUnprocessable()->assertJsonValidationErrors('payment_initialization');
    }

    public function test_review_resolution_uses_codes_after_display_messages_change(): void
    {
        $order = $this->order();
        Card::create(['product_id' => $order->product_id, 'order_id' => $order->id, 'content' => 'architecture-delivery-card', 'status' => 'locked']);
        $duplicate = PaymentReceipt::create(['order_id' => $order->id, 'channel' => 'epay', 'trade_no' => 'architecture-duplicate', 'amount' => '10.00',
            'received_at' => now(), 'review_code' => PaymentReviewCode::DuplicatePayment->value, 'review_reason' => 'The display text was changed.']);
        $stock = PaymentReceipt::create(['order_id' => $order->id, 'channel' => 'epay', 'trade_no' => 'architecture-stock', 'amount' => '10.00',
            'received_at' => now(), 'review_code' => PaymentReviewCode::InsufficientStock->value, 'review_reason' => 'Independent display text.']);
        $this->assertTrue(app(OrderFulfilmentService::class)->fulfilManually($order)->wasFulfilled());
        $this->assertNull($duplicate->fresh()->review_resolved_at);
        $this->assertNotNull($stock->fresh()->review_resolved_at);
        $this->assertTrue($order->fresh()->requiresPaymentReview());
    }

    public function test_json_errors_keep_status_headers_and_existing_codes_while_html_and_callbacks_are_unchanged(): void
    {
        $response = response()->json(['message' => 'A controlled validation failure.', 'errors' => ['amount' => ['Invalid amount.']]], 422)->header('X-Test-Header', 'preserved');
        $result = \App\Support\ApiErrorContract::apply($response);
        $this->assertSame(422, $result->getStatusCode());
        $this->assertSame('preserved', $result->headers->get('X-Test-Header'));
        $this->assertSame('validation_failed', $result->getData(true)['code']);
        $this->assertSame(['amount' => ['Invalid amount.']], $result->getData(true)['errors']);
        $context = response()->json(['message' => 'Account changed.', 'code' => 'admin_context_changed'], 409);
        $this->assertSame('admin_context_changed', \App\Support\ApiErrorContract::apply($context)->getData(true)['code']);
        foreach ([response('invalid signature', 400), response('<html>Login required</html>', 401)] as $plain) {
            $before = $plain->getContent();
            $this->assertSame($plain, \App\Support\ApiErrorContract::apply($plain));
            $this->assertSame($before, $plain->getContent());
        }
        $this->postJson('/api/admin/login', ['username' => 'missing-admin', 'password' => 'invalid-test-password'])
            ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
    }
}
