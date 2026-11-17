<?php

namespace Tests\Feature;

use App\Models\{ApiToken, Card, Category, Coupon, Order, Product, Setting};
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BuyerIdempotencyConcurrencyTest extends TestCase
{
    protected function connectionsToTransact(): array { return []; }
    protected function setUp(): void { parent::setUp(); $this->assertSame(0, $this->artisan('migrate:fresh', ['--force' => true])); }
    protected function tearDown(): void
    {
        try { $this->assertSame(0, $this->artisan('migrate:fresh', ['--force' => true])); }
        finally { parent::tearDown(); }
    }

    public function test_two_processes_with_same_key_reserve_last_stock_and_coupon_once(): void
    {
        Setting::set('epay_api_url', 'https://gateway.example.test', 'payment');
        Setting::set('epay_merchant_id', '1', 'payment');
        Setting::set('epay_merchant_key', 'test-only-key', 'payment');
        $category = Category::create(['name' => 'Race', 'slug' => 'race', 'is_active' => true]);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Race', 'slug' => 'race', 'price' => '10', 'min_quantity' => 1, 'max_quantity' => 2, 'is_active' => true]);
        for ($i = 0; $i < 2; $i++) Card::create(['product_id' => $product->id, 'content' => 'PUBLIC-DUMMY-'.$i, 'status' => 'unsold']);
        $token = ApiToken::create(['name' => 'Race', 'token' => hash('sha256', 'race-token'), 'is_active' => true, 'max_pending_orders' => 1, 'max_pending_quantity' => 2]);
        $coupon = Coupon::create(['code' => 'RACE-ONCE', 'type' => 'fixed', 'value' => '1', 'max_uses' => 1, 'is_active' => true]);
        $barrier = base_path('.local/idempotency-'.Str::uuid());
        mkdir($barrier, 0700, true);
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_DATABASE' => 'cardshop_testing', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'), 'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password'), 'REDIS_HOST' => config('database.redis.default.host'), 'REDIS_PORT' => (string) config('database.redis.default.port'), 'REDIS_DB' => '10', 'REDIS_CACHE_DB' => '11', 'REDIS_PREFIX' => 'cardshop_testing_', 'CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'array', 'BCRYPT_ROUNDS' => '4', 'MAIL_MAILER' => 'log'];
        $workers = [];
        try {
            for ($slot = 0; $slot < 2; $slot++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/concurrent-idempotency.php'), (string) $product->id, (string) $token->id, $barrier, (string) $slot], base_path(), $env);
                $worker->setTimeout(30); $worker->start(); $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while (!is_file($barrier.'/ready-0') || !is_file($barrier.'/ready-1')) {
                if (microtime(true) > $deadline) $this->fail('Race workers did not initialize.');
                usleep(20000);
            }
            file_put_contents($barrier.'/go', 'go');
            $results = array_map(function ($worker) { $worker->wait(); $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput()); return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR); }, $workers);
            $this->assertSame($results[0]['payload'], $results[1]['payload']);
            $this->assertCount(1, array_filter($results, fn ($r) => $r['replayed']));
            $this->assertSame(1, Order::count());
            $this->assertSame(2, Card::where('status', 'locked')->count());
            $this->assertSame(1, $coupon->fresh()->used_count);
        } finally {
            foreach ($workers as $worker) if ($worker->isRunning()) $worker->stop();
            foreach (['ready-0', 'ready-1', 'go'] as $name) if (is_file($barrier.'/'.$name)) unlink($barrier.'/'.$name);
            rmdir($barrier);
        }
    }
}
