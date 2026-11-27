<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Card;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OracleCloudCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionDemoSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_database_seeder_populates_oracle_cloud_demo_catalog_and_is_idempotent(): void
    {
        $this->assertTrue(OracleCloudCatalogSeeder::hasRequiredAssets());

        app(DatabaseSeeder::class)->run();

        $expectedCards = OracleCloudCatalogSeeder::expectedCardCount();

        $this->assertSame(6, Category::count());
        $this->assertSame(90, Product::count());
        $this->assertSame(180, DB::table('product_wholesale_prices')->count());
        $this->assertSame($expectedCards, Card::where('status', 'unsold')->count());
        $this->assertSame(1, ArticleCategory::count());
        $this->assertSame(4, Article::where('is_published', true)->count());
        $this->assertSame('1', Setting::where('key', OracleCloudCatalogSeeder::SEEDED_MARKER_KEY)->value('value'));
        $this->assertStringContainsString('甲骨文云', (string) Setting::get('site_description'));
        $this->assertStringContainsString('现货自动发货', (string) Setting::get('site_announcement'));

        // Verify seeded cards are encrypted at rest rather than stored in plaintext.
        $rawContent = (string) DB::table('cards')->value('content');
        $this->assertStringNotContainsString('Tenancy:', $rawContent);
        $this->assertStringContainsString('Tenancy:', (string) Card::firstOrFail()->content);

        // Verify storefront renders seeded catalog cleanly across key pages.
        $this->get('/')->assertOk()->assertSee('亚太区')->assertSee('东京升级 - 高质量谷歌');
        $this->get('/product/oci-payg-ap-tokyo-1')->assertOk()->assertSee('东京升级 - 高质量谷歌');
        $this->get('/articles/oci-free-vs-payg')->assertOk()->assertSee('升级号');

        // Re-running DatabaseSeeder must not duplicate records or overwrite customized settings.
        Setting::set('site_description', '自定义线上店铺简介', 'site');
        app(DatabaseSeeder::class)->run();

        $this->assertSame(6, Category::count());
        $this->assertSame(90, Product::count());
        $this->assertSame($expectedCards, Card::count());
        $this->assertSame(4, Article::count());
        $this->assertSame('自定义线上店铺简介', (string) Setting::get('site_description'));
    }

    public function test_clearing_demo_catalog_does_not_reseed_on_future_upgrades(): void
    {
        app(DatabaseSeeder::class)->run();
        $this->assertSame(90, Product::count());

        // Operator intentionally deletes all demo products, cards, categories & articles.
        Card::query()->delete();
        DB::table('product_wholesale_prices')->delete();
        Product::query()->delete();
        Category::query()->delete();
        Article::query()->delete();
        ArticleCategory::query()->delete();

        // Running DatabaseSeeder again during a future upgrade must not resurrect demo records.
        app(DatabaseSeeder::class)->run();

        $this->assertSame(0, Category::count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Card::count());
        $this->assertSame(0, Article::count());
    }

    public function test_existing_shop_with_business_data_is_never_touched_by_demo_seeder(): void
    {
        $category = Category::create([
            'name' => '真实业务分类',
            'slug' => 'real-prod-cat',
            'is_active' => true,
        ]);
        Product::create([
            'category_id' => $category->id,
            'name' => '真实线上商品',
            'slug' => 'real-prod-item',
            'price' => '99.00',
            'is_active' => true,
        ]);
        Setting::set('site_description', '真实业务站点简介', 'site');

        app(DatabaseSeeder::class)->run();

        $this->assertSame(1, Category::count());
        $this->assertSame(1, Product::count());
        $this->assertSame(0, Card::count());
        $this->assertSame(0, Article::count());
        $this->assertSame('真实业务站点简介', (string) Setting::get('site_description'));
        $this->assertNull(Setting::where('key', OracleCloudCatalogSeeder::SEEDED_MARKER_KEY)->value('value'));
    }
}
