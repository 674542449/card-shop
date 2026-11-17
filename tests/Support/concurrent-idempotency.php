<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'cardshop_testing' || (int) config('database.redis.default.database') !== 10 || (int) config('database.redis.cache.database') !== 11) {
    throw new RuntimeException('Idempotency race requires isolated test storage.');
}
\Illuminate\Support\Facades\Http::preventStrayRequests();
[$script, $productId, $tokenId, $barrier, $slot] = $argv;
file_put_contents($barrier.'/ready-'.$slot, 'ready');
$deadline = microtime(true) + 15;
while (!file_exists($barrier.'/go')) {
    if (microtime(true) > $deadline) throw new RuntimeException('Barrier timed out.');
    usleep(20000);
}
\App\Models\Order::creating(fn () => usleep(400000));
$data = ['product_id' => (int) $productId, 'api_token_id' => (int) $tokenId, 'quantity' => 2, 'email' => 'race@example.test', 'query_password' => 'race-password', 'coupon_code' => 'RACE-ONCE', 'payment_method' => 'alipay', 'ip' => '192.0.2.90'];
$service = app(\App\Services\ApiOrderIdempotency::class);
$record = $service->reserve((int) $tokenId, 'race-key', $data, fn () => app(\App\Services\OrderService::class)->createOrder($data));
[$payload, $status, $replayed] = $service->respond($record, fn ($order) => [['order_no' => $order->order_no, 'payment_url' => app(\App\Services\OrderService::class)->processPayment($order, 'alipay')['url']], 201]);
echo json_encode(['payload' => $payload, 'status' => $status, 'replayed' => $replayed], JSON_THROW_ON_ERROR);
