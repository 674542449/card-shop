<?php

// This worker is only for committed, synthetic fixtures in the test database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'cardshop_testing'
    || (int) config('database.redis.default.database') !== 10
    || (int) config('database.redis.cache.database') !== 11) {
    throw new RuntimeException('Replacement races require cardshop_testing and Redis 10/11.');
}
\Illuminate\Support\Facades\Http::preventStrayRequests();
[$script, $operation, $orderId, $adminId, $oldCardId, $token, $barrier, $slot, $leader] = $argv;
if (! in_array($operation, ['replace', 'refund'], true) || ! in_array($slot, ['0', '1'], true)
    || ! in_array($leader, ['none', '0'], true)) {
    throw new RuntimeException('Invalid race operation.');
}
$expectedParent = realpath(base_path('.local'));
$realBarrier = realpath($barrier);
if (! $realBarrier || ! $expectedParent
    || realpath(dirname($realBarrier)) !== $expectedParent
    || ! str_starts_with(basename($realBarrier), 'replacement-race-')) {
    throw new RuntimeException('The race barrier must stay in the project .local directory.');
}
$barrier = $realBarrier;
$wait = static function (string $file): void {
    $deadline = microtime(true) + 15;
    while (! is_file($file)) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Replacement race barrier timed out.'); }
        usleep(20000);
    }
};
// The transaction leader has acquired the order lock before these events fire.
// Pause it until the other process starts its call, making the overlap observable.
$hold = static function () use ($barrier, $slot, $wait): void {
    file_put_contents($barrier.'/held-'.$slot, 'held');
    $wait($barrier.'/attempting-'.(1 - (int) $slot));
    usleep(400000);
};
if ($operation === 'replace') {
    \App\Models\OrderCardReplacement::creating($hold);
} else {
    \App\Models\OrderRefund::creating($hold);
}
file_put_contents($barrier.'/ready-'.$slot, 'ready');
$wait($barrier.'/go');
if ($leader === '0' && $slot === '1') { $wait($barrier.'/held-0'); }
$started = microtime(true);
file_put_contents($barrier.'/attempting-'.$slot, 'attempting');
try {
    $order = \App\Models\Order::findOrFail((int) $orderId);
    $admin = \App\Models\Admin::findOrFail((int) $adminId);
    if ($operation === 'replace') {
        $replacement = app(\App\Services\OrderCardReplacementService::class)->replace(
            $order, [(int) $oldCardId], 'Synthetic replacement race', $token, $admin);
        $result = ['status' => 'replaced', 'replacement_id' => $replacement->id];
    } else {
        $service = app(\App\Services\RefundService::class);
        $refund = $service->request($order, $order->total_amount, 'Synthetic concurrent full refund');
        $refund = $service->transition($refund, 'approved', null, null, $admin->id, 'Synthetic approval');
        $refund = $service->transition($refund, 'completed', 'DUMMY-REFUND-RACE', null, $admin->id, 'Synthetic refund complete');
        $result = ['status' => 'refunded', 'refund_id' => $refund->id];
    }
} catch (\App\Exceptions\CheckoutException $exception) {
    $result = ['status' => 'refused', 'reason' => $exception->getMessage()];
}
echo json_encode($result + ['started' => $started, 'finished' => microtime(true)], JSON_THROW_ON_ERROR);
