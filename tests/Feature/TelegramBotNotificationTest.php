<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminAuth;
use App\Models\{Admin, Card, Category, NotificationDelivery, Order, Product, Setting};
use App\Security\SecretCipher;
use App\Services\{NotificationQueue, NotificationService, OrderFulfilmentService, OrderService};
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\{DB, Hash, Http, Mail};
use Tests\TestCase;

class TelegramBotNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function signIn(): Admin
    {
        $admin = Admin::create([
            'username' => 'tg-admin',
            'password' => Hash::make('tg-admin-password-123'),
            'role' => 'owner',
            'is_active' => true,
        ]);
        $this->withSession([
            'admin_id' => $admin->id,
            'admin_pw' => AdminAuth::passwordFingerprint($admin->password),
        ]);

        return $admin;
    }

    public function test_telegram_settings_encrypt_broadcast_token_and_validate_targets(): void
    {
        $this->signIn();

        $this->postJson('/api/admin/settings', [
            'telegram_bot_token' => 'invalid/token?query=1',
        ])->assertUnprocessable()->assertJsonValidationErrors('telegram_bot_token');

        $this->postJson('/api/admin/settings', [
            'telegram_target_channel_ids' => '@valid_channel, not a valid target!',
        ])->assertUnprocessable()->assertJsonValidationErrors('telegram_target_channel_ids');

        $this->postJson('/api/admin/settings', [
            'telegram_bot_token' => '123456:PRIMARY_BOT_SECRET_TOKEN',
            'telegram_broadcast_bot_token' => '987654:BROADCAST_BOT_SECRET_TOKEN',
            'telegram_chat_id' => '11223344',
            'telegram_notify_product_sold' => true,
            'telegram_notify_product_listed' => true,
            'telegram_target_bot_enabled' => true,
            'telegram_target_bot_chat_ids' => '55667788',
            'telegram_target_group_enabled' => true,
            'telegram_target_group_ids' => '-1001234567890#42, https://t.me/c/9988776655:88',
            'telegram_target_channel_enabled' => true,
            'telegram_target_channel_ids' => '@my_card_channel, https://t.me/second_channel',
        ])->assertOk();

        $rawBroadcastToken = (string) DB::table('settings')->where('key', 'telegram_broadcast_bot_token')->value('value');
        $this->assertTrue(SecretCipher::isEncrypted($rawBroadcastToken));
        $this->assertStringNotContainsString('BROADCAST_BOT_SECRET_TOKEN', $rawBroadcastToken);

        $this->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('telegram_bot_token', '********')
            ->assertJsonPath('telegram_broadcast_bot_token', '********')
            ->assertJsonPath('telegram_notify_product_sold', '1')
            ->assertJsonPath('telegram_notify_product_listed', '1')
            ->assertDontSee('BROADCAST_BOT_SECRET_TOKEN');

        // Posting masked secret preserves stored encrypted token.
        $this->postJson('/api/admin/settings', [
            'telegram_broadcast_bot_token' => '********',
        ])->assertOk();
        $this->assertSame('987654:BROADCAST_BOT_SECRET_TOKEN', setting('telegram_broadcast_bot_token'));

        $service = app(NotificationService::class);
        $groupTargets = $service->targetsForKind('group');
        $this->assertCount(2, $groupTargets);
        $this->assertSame('-1001234567890', $groupTargets[0]['chat_id']);
        $this->assertSame(42, $groupTargets[0]['message_thread_id']);
        $this->assertSame('-1009988776655', $groupTargets[1]['chat_id']);
        $this->assertSame(88, $groupTargets[1]['message_thread_id']);

        $channelTargets = $service->targetsForKind('channel');
        $this->assertSame(['@my_card_channel', '@second_channel'], array_column($channelTargets, 'chat_id'));
    }

    public function test_product_sold_broadcasts_to_bot_group_topic_and_channel_while_protecting_buyer_privacy(): void
    {
        Setting::set('site_url', 'https://shop.example.test', 'site');
        Setting::set('telegram_enabled', '0', 'telegram');
        Setting::set('telegram_bot_token', '111111:PRIMARY_TOKEN', 'telegram');
        Setting::set('telegram_broadcast_bot_token', '222222:DEDICATED_BROADCAST_TOKEN', 'telegram');
        Setting::set('telegram_chat_id', '777000111', 'telegram');
        Setting::set('telegram_notify_product_sold', '1', 'telegram');
        Setting::set('telegram_target_bot_enabled', '1', 'telegram');
        Setting::set('telegram_target_bot_chat_ids', '', 'telegram'); // Falls back to telegram_chat_id!
        Setting::set('telegram_target_group_enabled', '1', 'telegram');
        Setting::set('telegram_target_group_ids', '-100555666777#19', 'telegram');
        Setting::set('telegram_target_channel_enabled', '1', 'telegram');
        Setting::set('telegram_target_channel_ids', '@oracle_deals_channel', 'telegram');

        $category = Category::create(['name' => '甲骨文云', 'slug' => 'oracle-cloud', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Oracle <Singapore> 满月号',
            'slug' => 'oracle-sg',
            'price' => '68.00',
            'min_quantity' => 1,
            'max_quantity' => 10,
            'is_active' => true,
        ]);
        for ($i = 1; $i <= 3; $i++) {
            Card::create([
                'product_id' => $product->id,
                'content' => "SECRET-CARD-KEY-{$i}",
                'status' => 'unsold',
            ]);
        }

        $order = app(OrderService::class)->createOrder([
            'product_id' => $product->id,
            'email' => 'private-buyer@example.test',
            'query_password' => 'buyer-query-secret',
            'quantity' => 2,
            'payment_method' => 'usdt_trc20',
            'ip' => '198.51.100.12',
        ]);

        $sentRequests = [];
        Http::fake(function (HttpRequest $request) use (&$sentRequests) {
            $sentRequests[] = [
                'url' => $request->url(),
                'data' => $request->data(),
            ];
            return Http::response(['ok' => true], 200);
        });

        $this->assertTrue(app(OrderFulfilmentService::class)->fulfilManually($order)->wasFulfilled());

        $soldDeliveries = NotificationDelivery::where('type', 'product_sold')->orderBy('id')->get();
        $this->assertCount(3, $soldDeliveries);

        app(NotificationQueue::class)->process(10);

        $this->assertCount(3, $sentRequests);
        foreach ($sentRequests as $req) {
            $this->assertSame('https://api.telegram.org/bot222222:DEDICATED_BROADCAST_TOKEN/sendMessage', $req['url']);
            $this->assertSame('HTML', $req['data']['parse_mode']);
            $this->assertStringContainsString('Oracle &lt;Singapore&gt; 满月号', $req['data']['text']);
            $this->assertStringContainsString('售出数量：2 件', $req['data']['text']);
            $this->assertStringContainsString('¥136.00', $req['data']['text']);
            $this->assertStringContainsString('剩余库存：1 件', $req['data']['text']);
            $this->assertStringContainsString('https://shop.example.test/product/oracle-sg', $req['data']['text']);
            $this->assertStringNotContainsString('SECRET-CARD-KEY', $req['data']['text']);
            $this->assertStringNotContainsString('private-buyer@example.test', $req['data']['text']);
        }

        $byChat = collect($sentRequests)->keyBy(fn ($r) => $r['data']['chat_id']);

        // Bot private DM receives order_no, whereas public group & channel do NOT receive order_no.
        $this->assertStringContainsString($order->order_no, $byChat['777000111']['data']['text']);
        $this->assertArrayNotHasKey('message_thread_id', $byChat['777000111']['data']);

        $this->assertSame(19, $byChat['-100555666777']['data']['message_thread_id']);
        $this->assertStringNotContainsString($order->order_no, $byChat['-100555666777']['data']['text']);

        $this->assertStringNotContainsString($order->order_no, $byChat['@oracle_deals_channel']['data']['text']);

        // Admin Task Center filter supports product_sold.
        $this->signIn();
        $this->getJson('/api/admin/notifications?type=product_sold')
            ->assertOk()
            ->assertJsonPath('total', 3);
    }

    public function test_product_listed_triggers_on_create_activate_and_restock_without_duplicate_spam(): void
    {
        $this->signIn();
        Setting::set('site_url', 'https://shop.example.test', 'site');
        Setting::set('telegram_bot_token', '333333:SHOP_BOT_TOKEN', 'telegram');
        Setting::set('telegram_notify_product_listed', '1', 'telegram');
        Setting::set('telegram_notify_product_sold', '1', 'telegram');
        Setting::set('telegram_target_channel_enabled', '1', 'telegram');
        Setting::set('telegram_target_channel_ids', '@stock_alerts', 'telegram');

        $category = Category::create(['name' => 'VPS 节点', 'slug' => 'vps', 'is_active' => true]);

        $messages = [];
        Http::fake(function (HttpRequest $request) use (&$messages) {
            $messages[] = $request->data()['text'] ?? '';
            return Http::response(['ok' => true], 200);
        });

        // 1. Creating an active product triggers "新商品上架通知".
        $createRes = $this->postJson('/api/admin/products', [
            'name' => '东京 CN2 GIA 特惠包',
            'category_id' => $category->id,
            'price' => '99.00',
            'is_active' => true,
            'description' => '<p>高速直连线路，自动交付。</p>',
            'wholesale_prices' => [
                ['min_quantity' => 5, 'price' => '88.00'],
            ],
        ])->assertCreated();
        $productId = (int) $createRes->json('id');

        app(NotificationQueue::class)->process(10);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('新商品上架通知', $messages[0]);
        $this->assertStringContainsString('5件起 ¥88.00', $messages[0]);
        $this->assertStringContainsString('高速直连线路，自动交付。', $messages[0]);

        // 2. Updating description/price while ALREADY active must NOT re-trigger product_listed.
        $this->putJson("/api/admin/products/{$productId}", [
            'name' => '东京 CN2 GIA 特惠包',
            'category_id' => $category->id,
            'price' => '95.00',
            'is_active' => true,
        ])->assertOk();
        app(NotificationQueue::class)->process(10);
        $this->assertCount(1, $messages);

        // 3. Importing cards into active product triggers "商品补货上架通知", whereas duplicate-only import does not.
        $this->postJson("/api/admin/products/{$productId}/cards/import", [
            'content' => "CARD-A1\nCARD-A2\nCARD-A3",
        ])->assertOk();
        app(NotificationQueue::class)->process(10);
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('商品补货上架通知', $messages[1]);
        $this->assertStringContainsString('本次补货 +3 件（当前可售 3 件）', $messages[1]);
        $this->assertStringNotContainsString('CARD-A1', $messages[1]);

        $this->postJson("/api/admin/products/{$productId}/cards/import", [
            'content' => "CARD-A1\nCARD-A2",
        ])->assertOk();
        app(NotificationQueue::class)->process(10);
        $this->assertCount(2, $messages);

        // 4. Unlisting and re-listing triggers "商品上架通知".
        $this->putJson("/api/admin/products/{$productId}", [
            'name' => '东京 CN2 GIA 特惠包',
            'category_id' => $category->id,
            'price' => '95.00',
            'is_active' => false,
        ])->assertOk();
        $this->putJson("/api/admin/products/{$productId}", [
            'name' => '东京 CN2 GIA 特惠包',
            'category_id' => $category->id,
            'price' => '95.00',
            'is_active' => true,
        ])->assertOk();
        app(NotificationQueue::class)->process(10);
        $this->assertCount(3, $messages);
        $this->assertStringContainsString('商品上架通知', $messages[2]);

        // 5. Offline manual card sale also triggers product_sold.
        $firstCard = Card::where('product_id', $productId)->firstOrFail();
        $this->patchJson("/api/admin/cards/{$firstCard->id}/status", ['status' => 'sold'])->assertOk();
        app(NotificationQueue::class)->process(10);
        $this->assertCount(4, $messages);
        $this->assertStringContainsString('商品售出通知', $messages[3]);
        $this->assertStringContainsString('剩余库存：2 件', $messages[3]);
    }

    public function test_partial_multi_channel_failure_remembers_delivered_targets_and_test_endpoint_diagnoses_errors(): void
    {
        $this->signIn();
        Setting::set('telegram_bot_token', '444444:PRIVATE_BOT_TOKEN', 'telegram');
        Setting::set('telegram_notify_product_listed', '1', 'telegram');
        Setting::set('telegram_target_channel_enabled', '1', 'telegram');
        Setting::set('telegram_target_channel_ids', '@good_chan, @bad_chan', 'telegram');

        $category = Category::create(['name' => '测试分类', 'slug' => 'test-cat', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => '重试隔离测试商品',
            'slug' => 'retry-product',
            'price' => '20.00',
            'is_active' => true,
        ]);

        $deliveredTo = [];
        Http::fake(function (HttpRequest $request) use (&$deliveredTo) {
            $chatId = $request->data()['chat_id'] ?? '';
            $deliveredTo[] = $chatId;
            if ($chatId === '@bad_chan') {
                return Http::response('echo 444444:PRIVATE_BOT_TOKEN forbidden', 403);
            }
            return Http::response(['ok' => true], 200);
        });

        $queue = app(NotificationQueue::class);
        $queue->enqueueProductListed($product, 'created');
        $delivery = NotificationDelivery::where('type', 'product_listed')->firstOrFail();

        $queue->process(10);
        $delivery->refresh();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame(['@good_chan'], $delivery->payload['sent_targets'] ?? []);
        $this->assertStringContainsString('无发言权限', (string) $delivery->last_error);
        $this->assertStringNotContainsString('444444:PRIVATE_BOT_TOKEN', (string) $delivery->last_error);

        // Operator fixes @bad_chan -> @fixed_chan in Settings; retry sends ONLY to @fixed_chan!
        Setting::set('telegram_target_channel_ids', '@good_chan, @fixed_chan', 'telegram');
        $this->travel(2)->minutes();
        $queue->process(10);

        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame(['@good_chan', '@bad_chan', '@fixed_chan'], $deliveredTo);

        // Admin test-telegram endpoint diagnoses 403 safely and succeeds on valid target.
        $this->postJson('/api/admin/settings/test-telegram', [
            'scope' => 'channel',
            'target' => '@bad_chan',
        ])->assertStatus(422)
            ->assertDontSee('444444:PRIVATE_BOT_TOKEN');

        $this->postJson('/api/admin/settings/test-telegram', [
            'scope' => 'channel',
            'target' => '@good_chan',
        ])->assertOk();
    }
}
