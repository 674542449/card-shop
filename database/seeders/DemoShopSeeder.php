<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Blacklist;
use App\Models\Card;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\OperationLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Optional local preview data; never called by DatabaseSeeder or container boot. */
class DemoShopSeeder extends Seeder
{
    public const QUERY_EMAIL = 'demo@example.test';

    public const QUERY_PASSWORD = 'DemoShop2026!';

    public function run(): void
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $host = config("database.connections.{$connection}.host");
        if (! app()->environment('local') || $connection !== 'pgsql'
            || ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            || ! in_array($database, ['cardshop', 'cardshop_demo'], true)) {
            throw new \RuntimeException('DemoShopSeeder requires a local PostgreSQL development shop.');
        }

        $catalog = require __DIR__.'/data/demo-catalog.php';
        DB::transaction(function () use ($catalog) {
            // Serialise repeat invocations without affecting normal checkout.
            DB::select('select pg_advisory_xact_lock(20260930, 480180)');
            $products = $this->seedCatalog($catalog);
            $coupons = $this->seedCoupons($products);
            $this->seedOrders($products, $coupons);
            $this->seedAdminExamples($products);
        });

        // Fill empty presentation fields only; keep existing shop identity and config.
        $presentation = [
            'site_description' => '为创作、学习与日常工作准备的数字好物。这里的商品与订单均为本地演示数据。',
            'site_announcement' => '<p><strong>欢迎来到完整演示商城。</strong>这里有 8 个分类、48 款数字好物，以及不同库存与阶梯价展示。全部内容和卡密均为测试数据。</p><p>试用优惠码 <strong>DEMO-SAVE15</strong> 可享 15% 优惠；满 50 元可用 <strong>DEMO-WELCOME10</strong> 减 10 元。<a href="/articles/demo-shopping-guide">阅读购物指南 →</a></p>',
            'contact_text' => '演示客服 · 先看看购物指南，再到订单查询领取测试卡密。',
            'contact_url' => '/articles/demo-shopping-guide',
        ];
        foreach ($presentation as $key => $value) {
            if (trim((string) Setting::get($key, '')) === '') {
                Setting::set($key, $value, 'site');
            }
        }

        $counts = [
            'categories' => Category::where('slug', 'like', 'demo-%')->count(),
            'products' => Product::where('slug', 'like', 'demo-%')->count(),
            'articles' => Article::where('slug', 'like', 'demo-%')->count(),
            'coupons' => Coupon::where('code', 'like', 'DEMO-%')->count(),
            'orders' => Order::where('order_no', 'like', 'DEMO-%')->count(),
            'cards' => Card::whereHas('product', fn ($q) => $q->where('slug', 'like', 'demo-%'))->count(),
        ];
        $this->command?->info(json_encode($counts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->command?->info('Query preview: '.self::QUERY_EMAIL.' / '.self::QUERY_PASSWORD);
    }

    private function seedCatalog(array $catalog): array
    {
        $products = [];
        foreach ($catalog['categories'] as $entry) {
            $category = Category::firstOrCreate(['slug' => $entry['slug']],
                array_diff_key($entry, array_flip(['products', 'slug'])));
            foreach ($entry['products'] as $item) {
                $product = Product::firstOrCreate(['slug' => $item['slug']], [
                    'category_id' => $category->id,
                    ...array_diff_key($item, array_flip(['slug', 'stock_count', 'wholesale_prices'])),
                ]);
                foreach ($item['wholesale_prices'] ?? [] as $tier) {
                    $product->wholesalePrices()->firstOrCreate(
                        ['min_quantity' => $tier['min_quantity']], ['price' => $tier['price']]);
                }

                $existing = array_fill_keys($product->cards()->pluck('content')->all(), true);
                $cards = [];
                for ($n = 1; $n <= $item['stock_count']; $n++) {
                    $content = sprintf('DEMO-STOCK-%s-%04d | 仅供界面测试，无实际兑换价值', $item['slug'], $n);
                    if (! isset($existing[$content])) {
                        $cards[] = $this->cardAttributes($product->id, $content);
                    }
                }
                foreach (array_chunk($cards, 500) as $batch) {
                    Card::insert($batch);
                }
                $products[] = $product;
            }
        }

        $articleCategories = [];
        foreach ($catalog['article_categories'] as $entry) {
            $articleCategories[$entry['slug']] = ArticleCategory::firstOrCreate(
                ['slug' => $entry['slug']], array_diff_key($entry, ['slug' => true]));
        }
        foreach ($catalog['articles'] as $entry) {
            $created = now()->subDays($entry['published_days_ago']);
            $article = Article::firstOrCreate(['slug' => $entry['slug']], [
                'article_category_id' => $articleCategories[$entry['article_category_slug']]->id,
                ...array_diff_key($entry, array_flip(['slug', 'article_category_slug', 'published_days_ago'])),
            ]);
            if ($article->wasRecentlyCreated) {
                $article->forceFill(['created_at' => $created, 'updated_at' => $created,
                    'views' => 80 + count($entry) * 7 + $entry['published_days_ago'] * 13])->save();
            }
        }

        return $products;
    }

    private function seedCoupons(array $products): array
    {
        $definitions = [
            'DEMO-WELCOME10' => ['fixed', '10.00', '50.00', 200],
            'DEMO-SAVE15' => ['percent', '15.00', '0.00', 0],
            'DEMO-FIVE' => ['fixed', '5.00', '20.00', 100],
            'DEMO-HALF' => ['percent', '50.00', '10.00', 25],
            'DEMO-SPECIAL' => ['fixed', '3.00', '9.00', 100],
            'DEMO-EXHAUSTED' => ['fixed', '2.00', '0.00', 2],
            'DEMO-EXPIRED' => ['fixed', '8.00', '30.00', 50],
            'DEMO-UPCOMING' => ['percent', '20.00', '30.00', 50],
            'DEMO-DISABLED' => ['fixed', '6.00', '30.00', 50],
            'DEMO-BULK' => ['percent', '8.00', '100.00', 500],
        ];
        $coupons = [];
        foreach ($definitions as $code => [$type, $value, $minimum, $limit]) {
            $coupons[$code] = Coupon::firstOrCreate(['code' => $code], [
                'type' => $type, 'value' => $value, 'min_amount' => $minimum, 'max_uses' => $limit,
                'product_id' => $code === 'DEMO-SPECIAL' ? $products[0]->id : null,
                'starts_at' => $code === 'DEMO-UPCOMING' ? now()->addDays(7) : now()->subDays(40),
                'expires_at' => $code === 'DEMO-EXPIRED' ? now()->subDays(2) : now()->addDays(45),
                'is_active' => $code !== 'DEMO-DISABLED',
            ]);
        }

        return $coupons;
    }

    private function seedOrders(array $products, array $coupons): void
    {
        $hash = Hash::make(self::QUERY_PASSWORD);
        $eligible = array_values(array_filter($products, fn ($p) => $p->is_active && $p->stockCount() >= $p->min_quantity));
        if (! $eligible) {
            throw new \RuntimeException('Demo catalogue needs at least one stocked active product.');
        }
        for ($n = 1; $n <= 180; $n++) {
            $number = sprintf('DEMO-%06d', $n);
            if (Order::where('order_no', $number)->exists()) {
                continue;
            }
            $status = $n <= 120 ? 'paid' : ($n <= 140 ? 'pending' : ($n <= 165 ? 'expired' : 'closed'));
            $product = $eligible[($n * 7) % count($eligible)];
            $quantity = min($product->max_quantity, $product->min_quantity + $n % 4);
            $unit = $product->getEffectivePrice($quantity);
            $subtotal = bcmul($unit, (string) $quantity, 2);
            $coupon = in_array($n, [7, 8], true) ? $coupons['DEMO-EXHAUSTED']
                : ($n % 3 === 0 ? $coupons['DEMO-SAVE15'] : null);
            $discount = $coupon ? number_format($coupon->calculateDiscount((float) $subtotal), 2, '.', '') : '0.00';
            $total = bcsub($subtotal, $discount, 2);
            if (bccomp($total, '0.01', 2) < 0) {
                $total = '0.01';
                $discount = bcsub($subtotal, $total, 2);
            }
            $created = $status === 'pending' ? now()->subMinutes($n % 12)->subSeconds($n)
                : now()->subDays((int) floor(($n - 1) / 4) % 30)->subMinutes(15 + $n % 4 * 90)->subSeconds($n);
            $email = $n <= 6 || in_array($n, [121, 122, 141, 166], true)
                ? self::QUERY_EMAIL : sprintf('demo-buyer-%02d@example.test', $n % 36 + 1);
            $order = Order::create([
                'order_no' => $number, 'product_id' => $product->id, 'email' => $email,
                'query_password' => $hash, 'quantity' => $quantity, 'unit_price' => $unit,
                'total_amount' => $total, 'discount_amount' => $discount, 'coupon_id' => $coupon?->id,
                'payment_method' => $status === 'pending' ? 'manual' : ['alipay', 'wechat', 'usdt_trc20', 'manual'][$n % 4],
                'payment_no' => $status === 'paid' ? 'DEMO-TRANSACTION-'.$number : null,
                'status' => $status, 'ip' => '192.0.2.'.($n % 200 + 1),
                'paid_at' => $status === 'paid' ? $created->copy()->addMinutes(3) : null,
                // Demo pending receipts stay viewable for one day; normal checkout still uses its configured timeout.
                'expires_at' => $status === 'pending' ? now()->addDay() : $created->copy()->addMinutes(30),
            ]);
            $order->forceFill(['created_at' => $created, 'updated_at' => $order->paid_at ?? $created])->save();
            if ($coupon && bccomp($discount, '0.00', 2) > 0 && in_array($status, ['paid', 'pending'], true)) {
                Coupon::whereKey($coupon->id)->increment('used_count');
            }
            if (in_array($status, ['paid', 'pending'], true)) {
                $cards = [];
                for ($i = 1; $i <= $quantity; $i++) {
                    $card = $this->cardAttributes($product->id,
                        sprintf('DEMO-DELIVERY-%06d-%02d | 仅供测试，不可实际兑换', $n, $i));
                    $cards[] = [...$card, 'order_id' => $order->id,
                        'status' => $status === 'paid' ? 'sold' : 'locked',
                        'locked_at' => $created, 'sold_at' => $order->paid_at,
                        'created_at' => $created, 'updated_at' => $created];
                }
                Card::insert($cards);
            }
        }
    }

    private function cardAttributes(int $productId, string $content): array
    {
        return ['product_id' => $productId, 'order_id' => null, 'content' => $content,
            'status' => 'unsold', 'locked_at' => null, 'sold_at' => null,
            'created_at' => now(), 'updated_at' => now()];
    }

    private function seedAdminExamples(array $products): void
    {
        $adminId = DB::table('admins')->min('id');
        if (! $adminId) {
            throw new \RuntimeException('Initialise the shop administrator before importing demo data.');
        }
        foreach (array_slice($products, 0, 24) as $index => $product) {
            $log = OperationLog::firstOrCreate(['action' => 'demo_seed', 'target_type' => 'product',
                'target_id' => $product->id, 'detail' => '[演示] 导入商品与测试库存：'.$product->name],
                ['admin_id' => $adminId, 'ip' => '192.0.2.1']);
            if ($log->wasRecentlyCreated) {
                $log->forceFill(['created_at' => now()->subHours($index + 1)])->save();
            }
        }
        foreach ([
            ['ip', '198.51.100.10', null], ['ip', '203.0.113.21', now()->addDay()],
            ['email', 'demo-blocked@example.test', null], ['email', 'demo-expired-ban@example.test', now()->subDay()],
        ] as [$type, $value, $expires]) {
            Blacklist::firstOrCreate(['type' => $type, 'value' => $value], [
                'reason' => '[演示] 黑名单列表展示用的保留地址', 'source' => 'manual', 'expires_at' => $expires,
            ]);
        }
    }
}
