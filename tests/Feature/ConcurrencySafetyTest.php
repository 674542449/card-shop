<?php

namespace Tests\Feature;

use App\Models\{ApiToken, Card, Category, NotificationDelivery, Order, Product};
use App\Services\OrderService;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrencySafetyTest extends TestCase
{
    // Child processes need committed fixtures. This class uses explicit isolated
    // migrate:fresh cleanup instead of the usual surrounding test transaction.
    protected function connectionsToTransact(): array { return []; }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(0, $this->artisan('migrate:fresh', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        try {
            $this->assertSame(0, $this->artisan('migrate:fresh', ['--force' => true]));
        } finally {
            parent::tearDown();
        }
    }

    private function fixtures(array $quota): array
    {
        $category = Category::create(['name' => 'Concurrent', 'slug' => 'concurrent', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Concurrent', 'slug' => 'concurrent', 'price' => '10.00', 'min_quantity' => 1, 'max_quantity' => 10, 'is_active' => true]);
        for ($i = 0; $i < 5; $i++) { Card::create(['product_id' => $product->id, 'content' => 'concurrent-dummy-'.$i, 'status' => 'unsold']); }
        $token = ApiToken::create(['name' => 'Concurrent', 'token' => hash('sha256', 'concurrent-test-only'), 'is_active' => true] + $quota);
        return [$product, $token];
    }

    private function race(string $operation, Product $product, ApiToken $token, int $quantity, string $orderNo = ''): array
    {
        $barrier = base_path('.local/concurrency-'.Str::uuid());
        mkdir($barrier, 0700, true);
        $env = [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
            'DB_DATABASE' => 'cardshop_testing', 'DB_HOST' => config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'),
            'REDIS_HOST' => config('database.redis.default.host'), 'REDIS_PORT' => (string) config('database.redis.default.port'),
            'REDIS_DB' => '10', 'REDIS_CACHE_DB' => '11', 'REDIS_PREFIX' => 'cardshop_testing_',
            'CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'array', 'BCRYPT_ROUNDS' => '4', 'MAIL_MAILER' => 'log',
        ];
        $workers = [];
        try {
            for ($slot = 0; $slot < 2; $slot++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent-operation.php'), $operation, (string) $product->id, (string) $token->id, (string) $quantity, $orderNo, $barrier, (string) $slot], base_path(), $env);
                $process->setTimeout(30);
                $process->start();
                $workers[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (!file_exists($barrier.'/ready-0') || !file_exists($barrier.'/ready-1')) {
                if (microtime(true) > $deadline) { $this->fail('Concurrency worker failed to initialize.'); }
                usleep(20000);
            }
            file_put_contents($barrier.'/go', 'go');
            return array_map(function (Process $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }, $workers);
        } finally {
            foreach ($workers as $worker) { if ($worker->isRunning()) { $worker->stop(); } }
            foreach (['ready-0', 'ready-1', 'go'] as $file) { if (is_file($barrier.'/'.$file)) { unlink($barrier.'/'.$file); } }
            rmdir($barrier);
        }
    }

    public function test_concurrent_orders_cannot_exceed_token_order_quota(): void
    {
        [$product, $token] = $this->fixtures(['max_pending_orders' => 1]);
        $results = $this->race('order', $product, $token, 1);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['status'] === 'created'));
        $this->assertStringContainsString('额度', collect($results)->firstWhere('status', 'refused')['reason']);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, Card::where('status', 'locked')->count());
    }

    public function test_concurrent_orders_cannot_exceed_token_quantity_quota(): void
    {
        [$product, $token] = $this->fixtures(['max_pending_orders' => 10, 'max_pending_quantity' => 3]);
        $results = $this->race('order', $product, $token, 2);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['status'] === 'created'));
        $this->assertStringContainsString('额度', collect($results)->firstWhere('status', 'refused')['reason']);
        $this->assertSame(2, Card::where('status', 'locked')->count());
    }

    public function test_concurrent_payment_callbacks_fulfil_and_enqueue_only_once(): void
    {
        [$product, $token] = $this->fixtures([]);
        $order = app(OrderService::class)->createOrder(['product_id' => $product->id, 'quantity' => 1, 'email' => 'concurrent@example.test', 'query_password' => 'concurrent-password', 'payment_method' => 'alipay', 'ip' => '192.0.2.40']);
        $results = $this->race('pay', $product, $token, 1, $order->order_no);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['status'] === 'fulfilled'));
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, Card::where('status', 'sold')->count());
        $this->assertSame(1, NotificationDelivery::where('type', 'order_email')->count());
        $this->assertSame(1, $order->paymentReceipts()->count());
    }

    public function test_browser_requests_share_an_atomic_ip_reservation_limit(): void
    {
        [$product, $token] = $this->fixtures([]);
        for ($i = 0; $i < 2; $i++) {
            app(OrderService::class)->createOrder(['product_id' => $product->id, 'quantity' => 1,
                'email' => 'existing-'.$i.'@example.test', 'query_password' => 'concurrency-password',
                'payment_method' => 'alipay', 'ip' => '192.0.2.50']);
        }
        $results = $this->race('web-order', $product, $token, 1);
        $this->assertCount(1, array_filter($results, fn ($r) => $r['status'] === 'created'));
        $this->assertSame(3, Order::where('ip', '192.0.2.50')->where('status', 'pending')->count());
        $this->assertSame(3, Card::where('status', 'locked')->count());
    }
}
