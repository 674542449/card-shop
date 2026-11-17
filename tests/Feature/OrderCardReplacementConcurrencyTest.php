<?php

namespace Tests\Feature;

use App\Models\{Admin, Card, Category, NotificationDelivery, Order, OrderCardReplacement, OrderRefund, Product, Setting};
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OrderCardReplacementConcurrencyTest extends TestCase
{
    // Independent processes need committed fixtures, not an enclosing transaction.
    protected function connectionsToTransact(): array { return []; }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, $this->artisan('migrate:fresh', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        try { $this->assertSame(0, $this->artisan('migrate:fresh', ['--force' => true])); }
        finally { parent::tearDown(); }
    }

    private function fixture(): array
    {
        $category = Category::create(['name' => 'Replacement race', 'slug' => 'replacement-race', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Replacement race', 'slug' => 'replacement-race',
            'price' => '10.00', 'min_quantity' => 1, 'max_quantity' => 10, 'is_active' => true]);
        $admin = Admin::create(['username' => 'replacement-race-owner', 'password' => Hash::make('DUMMY-password-123'), 'role' => 'owner', 'is_active' => true]);
        $order = Order::create(['order_no' => 'DUMMY-REPLACEMENT-RACE', 'product_id' => $product->id, 'email' => 'dummy-race@example.test',
            'query_password' => Hash::make('dummy-password'), 'quantity' => 1, 'unit_price' => '10.00', 'total_amount' => '10.00',
            'discount_amount' => '0.00', 'payment_method' => 'alipay', 'status' => 'paid', 'ip' => '192.0.2.61',
            'paid_at' => now(), 'expires_at' => now()->addMinutes(30)]);
        $old = Card::create(['product_id' => $product->id, 'order_id' => $order->id, 'content' => 'PUBLIC-DUMMY-OLD', 'status' => 'sold', 'sold_at' => now()]);
        for ($i = 0; $i < 2; $i++) { Card::create(['product_id' => $product->id, 'content' => 'PUBLIC-DUMMY-NEW-'.$i, 'status' => 'unsold']); }
        Setting::set('refund_enabled', '1');
        return [$order, $admin, $old, $product];
    }

    private function race(Order $order, Admin $admin, Card $old, array $operations, array $tokens, bool $firstLeads = false): array
    {
        $barrier = base_path('.local/replacement-race-'.Str::uuid());
        mkdir($barrier, 0700, true);
        $env = [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'cardshop_testing',
            'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'),
            'REDIS_HOST' => config('database.redis.default.host'), 'REDIS_PORT' => (string) config('database.redis.default.port'),
            'REDIS_DB' => '10', 'REDIS_CACHE_DB' => '11', 'REDIS_PREFIX' => 'cardshop_testing_',
            'CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'array', 'BCRYPT_ROUNDS' => '4', 'MAIL_MAILER' => 'log',
        ];
        $workers = [];
        try {
            for ($slot = 0; $slot < 2; $slot++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/concurrent-replacement.php'), $operations[$slot],
                    (string) $order->id, (string) $admin->id, (string) $old->id, $tokens[$slot], $barrier, (string) $slot,
                    $firstLeads ? '0' : 'none'], base_path(), $env);
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while (! is_file($barrier.'/ready-0') || ! is_file($barrier.'/ready-1')) {
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) { $this->fail('Race worker stopped: '.$worker->getErrorOutput()); }
                }
                if (microtime(true) > $deadline) { $this->fail('Replacement workers did not initialize.'); }
                usleep(20000);
            }
            file_put_contents($barrier.'/go', 'go');
            $results = array_map(function (Process $worker): array {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $this->assertJson($worker->getOutput(), $worker->getOutput().$worker->getErrorOutput());
                return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }, $workers);
            $this->assertLessThan(min(array_column($results, 'finished')), max(array_column($results, 'started')), 'The independent calls must overlap.');
            return $results;
        } finally {
            foreach ($workers as $worker) { if ($worker->isRunning()) { $worker->stop(); } }
            foreach (['ready-0', 'ready-1', 'go', 'attempting-0', 'attempting-1', 'held-0', 'held-1'] as $name) {
                if (is_file($barrier.'/'.$name)) { unlink($barrier.'/'.$name); }
            }
            rmdir($barrier);
        }
    }

    private function assertRetiredAndCurrent(Order $order, Card $old, Product $product): void
    {
        $this->assertSame('sold', $old->fresh()->status);
        $this->assertSame($order->id, $old->fresh()->order_id);
        $this->assertNotNull($old->fresh()->replaced_at);
        $this->assertSame(1, $order->deliveryCards()->count());
        $this->assertFalse($order->deliveryCards()->whereKey($old->id)->exists());
        $this->assertSame(1, $product->cards()->where('status', 'unsold')->count());
        $this->assertSame(2, $order->cards()->count());
        $this->assertSame(1, OrderCardReplacement::count());
        $this->assertSame(1, NotificationDelivery::where('type', 'order_email')->count());
    }

    public function test_competing_tokens_cannot_replace_the_same_old_card_twice(): void
    {
        [$order, $admin, $old, $product] = $this->fixture();
        $results = $this->race($order, $admin, $old, ['replace', 'replace'], [(string) Str::uuid(), (string) Str::uuid()]);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['status'] === 'replaced'));
        $this->assertCount(1, array_filter($results, fn ($r) => $r['status'] === 'refused'));
        $this->assertRetiredAndCurrent($order, $old, $product);
    }

    public function test_competing_retries_return_one_history_and_consume_stock_once(): void
    {
        [$order, $admin, $old, $product] = $this->fixture();
        $token = (string) Str::uuid();
        $results = $this->race($order, $admin, $old, ['replace', 'replace'], [$token, $token]);
        $this->assertSame(['replaced', 'replaced'], array_column($results, 'status'));
        $this->assertSame($results[0]['replacement_id'], $results[1]['replacement_id']);
        $this->assertRetiredAndCurrent($order, $old, $product);
    }

    public function test_refund_locking_first_prevents_any_replacement_stock_allocation(): void
    {
        [$order, $admin, $old, $product] = $this->fixture();
        $results = $this->race($order, $admin, $old, ['refund', 'replace'], ['unused', (string) Str::uuid()], true);
        $this->assertSame(['refunded', 'refused'], array_column($results, 'status'));
        $this->assertSame('completed', OrderRefund::firstOrFail()->status);
        $this->assertNull($old->fresh()->replaced_at);
        $this->assertSame(2, $product->cards()->where('status', 'unsold')->count());
        $this->assertSame(0, OrderCardReplacement::count());
        $this->assertSame(0, NotificationDelivery::where('type', 'order_email')->count());
    }

    public function test_replacement_locking_first_finishes_before_the_later_refund_request(): void
    {
        [$order, $admin, $old, $product] = $this->fixture();
        $results = $this->race($order, $admin, $old, ['replace', 'refund'], [(string) Str::uuid(), 'unused'], true);
        $this->assertSame(['replaced', 'refunded'], array_column($results, 'status'));
        $this->assertSame('completed', OrderRefund::firstOrFail()->status);
        $this->assertRetiredAndCurrent($order, $old, $product);
    }
}
