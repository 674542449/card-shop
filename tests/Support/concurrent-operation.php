<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'cardshop_testing'
    || (int) config('database.redis.default.database') !== 10
    || (int) config('database.redis.cache.database') !== 11) {
    throw new RuntimeException('Concurrency checks require isolated test storage.');
}
\Illuminate\Support\Facades\Http::preventStrayRequests();
[$script, $operation, $productId, $tokenId, $quantity, $orderNo, $barrier, $slot] = $argv;
file_put_contents($barrier.'/ready-'.$slot, 'ready');
$deadline = microtime(true) + 15;
while (!file_exists($barrier.'/go')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Barrier timed out.'); }
    usleep(20000);
}
try {
    if ($operation === 'web-order') {
        // Hold the first request after its quota check so an overlapping request
        // really enters the vulnerable window, rather than relying on CPU timing.
        \App\Models\Order::creating(fn () => usleep(400000));
        $email = 'web-race-'.$slot.'@example.test';
        $request = \Illuminate\Http\Request::create('/order/create', 'POST', [
            'product_id' => (int) $productId, 'quantity' => (int) $quantity,
            'email' => $email, 'query_password' => 'concurrency-password', 'payment_method' => 'alipay',
        ], [], [], ['REMOTE_ADDR' => '192.0.2.50', 'HTTP_ACCEPT' => 'application/json']);
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $created = \App\Models\Order::where('email', $email)->first();
        echo json_encode($created ? ['status' => 'created', 'order_id' => $created->id] : ['status' => 'refused', 'http' => $response->getStatusCode()]);
        $kernel->terminate($request, $response);
    } elseif ($operation === 'order') {
        $order = app(\App\Services\OrderService::class)->createOrder([
            'product_id' => (int) $productId, 'api_token_id' => (int) $tokenId,
            'quantity' => (int) $quantity, 'email' => 'concurrency@example.test',
            'query_password' => 'concurrency-password', 'payment_method' => 'alipay',
            'ip' => '192.0.2.'.(100 + (int) $slot),
        ]);
        echo json_encode(['status' => 'created', 'order_id' => $order->id]);
    } else {
        $result = app(\App\Services\OrderFulfilmentService::class)->fulfilFromGateway($orderNo, 'concurrent-trade', '10.00', 'epay');
        echo json_encode(['status' => $result->status]);
    }
} catch (RuntimeException $exception) {
    echo json_encode(['status' => 'refused', 'reason' => $exception->getMessage()]);
}
