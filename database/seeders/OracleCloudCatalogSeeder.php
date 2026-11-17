<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Card;
use App\Models\Category;
use App\Models\OperationLog;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Optional local catalog preset; never called by DatabaseSeeder or container boot. */
class OracleCloudCatalogSeeder extends Seeder
{
    public const UNIFIED_PRODUCT_IMAGE = '/oci-assets/oracle-cloud.webp';

    public function run(): void
    {
        $connection = config('database.default');
        $host = config("database.connections.{$connection}.host");
        if (! app()->environment(['local', 'testing'])
            || ($connection === 'pgsql' && ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true))) {
            throw new \RuntimeException('OracleCloudCatalogSeeder resets catalog and orders; run only in local development environments.');
        }

        $regions = self::regions();
        self::generateAssets();

        $summary = DB::transaction(function () use ($regions) {
            // 1. Cleanly clear existing orders, cards, products, categories & old articles
            DB::table('order_card_replacement_items')->delete();
            DB::table('order_card_replacements')->delete();
            DB::table('order_refunds')->delete();
            DB::table('payment_receipts')->delete();
            DB::table('payment_attempts')->delete();
            DB::table('payment_reconciliation_jobs')->delete();
            DB::table('notification_deliveries')->delete();
            DB::table('web_checkout_requests')->delete();
            DB::table('api_order_requests')->delete();
            DB::table('orders')->delete();
            DB::table('coupons')->where('code', 'like', 'DEMO-%')->delete();
            DB::table('coupons')->whereNotNull('product_id')->update(['product_id' => null]);

            DB::table('cards')->delete();
            DB::table('product_wholesale_prices')->delete();
            DB::table('products')->delete();
            DB::table('categories')->delete();
            DB::table('articles')->delete();
            DB::table('article_categories')->delete();
            DB::table('seo_deliveries')->delete();

            // 2. Create 6 independent regional categories (亚太 / 北美 / 欧洲 / 中东 / 非洲 / 拉美)
            $categoryDefs = [
                'apac' => [
                    'name' => '亚太区',
                    'slug' => 'oci-apac',
                    'description' => '甲骨文云（Oracle Cloud）亚太区域账号（东京、大阪、首尔、春川、新加坡、古来、巴淡岛、悉尼、墨尔本、孟买、海得拉巴），高质量谷歌原邮交付，涵盖升级号与普通号。',
                    'image' => '/oci-assets/cat-apac.svg',
                    'sort_order' => 10,
                ],
                'na' => [
                    'name' => '北美区',
                    'slug' => 'oci-na',
                    'description' => '甲骨文云（Oracle Cloud）北美区域账号（圣何塞、凤凰城、阿什本、芝加哥、多伦多、蒙特利尔、克雷塔罗、蒙特雷），高质量谷歌原邮交付，涵盖升级号与普通号。',
                    'image' => '/oci-assets/cat-na.svg',
                    'sort_order' => 20,
                ],
                'eu' => [
                    'name' => '欧洲区',
                    'slug' => 'oci-eu',
                    'description' => '甲骨文云（Oracle Cloud）欧洲区域账号（法兰克福、伦敦、纽波特、阿姆斯特丹、巴黎、马赛、苏黎世、斯德哥尔摩、米兰、都灵、马德里等 13 区），高质量谷歌原邮交付。',
                    'image' => '/oci-assets/cat-eu.svg',
                    'sort_order' => 30,
                ],
                'me' => [
                    'name' => '中东区',
                    'slug' => 'oci-me',
                    'description' => '甲骨文云（Oracle Cloud）中东区域独立分类（阿联酋迪拜、阿布扎比、沙特利雅得、吉达、以色列耶路撒冷），高质量谷歌原邮交付，涵盖升级号与普通号。',
                    'image' => '/oci-assets/cat-me.svg',
                    'sort_order' => 40,
                ],
                'af' => [
                    'name' => '非洲区',
                    'slug' => 'oci-af',
                    'description' => '甲骨文云（Oracle Cloud）非洲区域独立分类（南非约翰内斯堡、摩洛哥卡萨布兰卡），稀缺非洲原生云节点，高质量谷歌原邮交付，涵盖升级号与普通号。',
                    'image' => '/oci-assets/cat-af.svg',
                    'sort_order' => 50,
                ],
                'latam' => [
                    'name' => '拉美区',
                    'slug' => 'oci-latam',
                    'description' => '甲骨文云（Oracle Cloud）拉丁美洲区域独立分类（巴西圣保罗、维涅杜、智利圣地亚哥、瓦尔帕莱索、哥伦比亚波哥大），高质量谷歌原邮交付，涵盖升级号与普通号。',
                    'image' => '/oci-assets/cat-latam.svg',
                    'sort_order' => 60,
                ],
            ];

            $categories = [];
            foreach ($categoryDefs as $key => $def) {
                $categories[$key] = Category::create([
                    ...$def,
                    'is_active' => true,
                ]);
            }

            $unifiedImg = self::UNIFIED_PRODUCT_IMAGE;

            // 3. Unified product introduction image across ALL products + clear tier specification copy
            $paygDescription = '<p><img src="' . $unifiedImg . '" alt="Oracle Cloud 甲骨文云官方云基础设施账号" width="960" height="600"></p>'
                . '<h3>一、升级号（按量付费 PAYG · 高质量谷歌原邮）说明</h3>'
                . '<ul>'
                . '<li><strong>账号规格：</strong>Oracle Cloud（甲骨文云）已完成官方 Pay As You Go（按量付费）商业升级的独立主区域租户账号，配套<strong>高质量谷歌（Gmail）初始注册原邮</strong>一并交付。</li>'
                . '<li><strong>商业池秒开容量：</strong>直接调用商业级宿主资源池，在东京、首尔、新加坡、悉尼、圣何塞等热门区域告别 <code>Out of host capacity</code> 容量报错，支持秒开 <code>4 OCPU + 24 GB</code> ARM 实例。</li>'
                . '<li><strong>完整保留永久免费额度：</strong>升级号同样享有全部 <strong>Always Free 永久免费配额</strong>（4C24G ARM、2 台 AMD Micro、200 GB 块存储、10 TB/月流量）；在免费配额内使用月账单恒为 <strong>$0</strong>。</li>'
                . '<li><strong>发货卡密格式：</strong><code>租户名称(Tenancy)----谷歌原邮账号----甲骨文控制台密码----谷歌邮箱密码及辅助邮箱----2FA二验密钥</code></li>'
                . '</ul>'
                . '<h3>二、登录核验与安全建议</h3>'
                . '<ol>'
                . '<li>打开 <code>https://cloud.oracle.com</code>，先输入卡密中的<strong>租户名称（Cloud Account Name）</strong>进入对应区域登录页。</li>'
                . '<li>使用谷歌原邮、控制台密码及 2FA 密钥完成首次登录核验，并建议在 <strong>Budgets（预算）</strong> 中设置月度 <code>$1</code> 邮件提醒。</li>'
                . '<li>核验无误后请及时修改甲骨文控制台密码、谷歌原邮密码并换绑您个人的 Authenticator 验证器。</li>'
                . '</ol>';

            $freeDescription = '<p><img src="' . $unifiedImg . '" alt="Oracle Cloud 甲骨文云官方云基础设施账号" width="960" height="600"></p>'
                . '<h3>一、普通号（永久免费 Always Free · 高质量谷歌原邮）说明</h3>'
                . '<ul>'
                . '<li><strong>账号规格：</strong>Oracle Cloud（甲骨文云）永久免费层（Always Free）独立主区域租户账号，配套<strong>高质量谷歌（Gmail）初始注册原邮</strong>一并交付。</li>'
                . '<li><strong>永久免费额度：</strong>支持最高 <code>4 OCPU + 24 GB 内存</code> Ampere A1 ARM 弹性实例，以及 <code>2 台 AMD Micro</code> 永久免费虚拟机，永不自动扣费。</li>'
                . '<li><strong>存储与网络：</strong>合计 <code>200 GB</code> 块存储卷配额 + 每月 <code>10 TB</code> 公网出站流量 + 独立公网 IPv4 / IPv6 地址。</li>'
                . '<li><strong>发货卡密格式：</strong><code>租户名称(Tenancy)----谷歌原邮账号----甲骨文控制台密码----谷歌邮箱密码及辅助邮箱----2FA二验密钥</code></li>'
                . '</ul>'
                . '<h3>二、登录核验与安全建议</h3>'
                . '<ol>'
                . '<li>打开 <code>https://cloud.oracle.com</code>，输入卡密中的<strong>租户名称（Cloud Account Name）</strong>进入对应主区域登录页。</li>'
                . '<li>使用谷歌原邮、控制台密码及 TOTP 二验密钥完成首次登录核验。</li>'
                . '<li>核验无误后请及时修改甲骨文控制台密码、谷歌邮箱密码并重新绑定您个人的身份验证器（Google / Microsoft Authenticator）。</li>'
                . '</ol>';

            // 4. Create 90 regional products (45 regions × [升级, 普通]) + wholesale tiers + encrypted unsold stock cards
            $now = now();
            $createdProducts = 0;
            $createdCards = 0;
            $cardBuffer = [];

            foreach ($regions as $index => $region) {
                $rank = $index + 1;
                $category = $categories[$region['cat_key']];
                $city = $region['city'];
                $fullZh = $region['zh'];

                // (A) 升级号: "{城市}升级 - 高质量谷歌" (e.g. "悉尼升级 - 高质量谷歌")
                $paygTitle = "{$city}升级 - 高质量谷歌";
                $paygBase = (float) $region['payg_price'];
                $paygProduct = Product::create([
                    'category_id' => $category->id,
                    'name' => $paygTitle,
                    'slug' => 'oci-payg-' . $region['code'],
                    'description' => $paygDescription,
                    'image' => $unifiedImg,
                    'price' => number_format($paygBase, 2, '.', ''),
                    'min_quantity' => 1,
                    'max_quantity' => 10,
                    'low_stock_threshold' => 3,
                    'sort_order' => $rank * 2 - 1,
                    'is_active' => true,
                    'seo_title' => "{$paygTitle} | 甲骨文云 {$fullZh} ({$region['code']}) PAYG 升级号",
                    'seo_keywords' => "{$city}升级,高质量谷歌,甲骨文升级号,甲骨文按量付费号,Oracle Cloud,{$category->name},{$fullZh},{$region['code']}",
                    'seo_description' => "{$paygTitle}：Oracle Cloud 甲骨文云 {$category->name} · {$fullZh}（{$region['en']} / {$region['code']}）按量付费 PAYG 升级号，高质量谷歌原邮交付，商业池秒开 ARM 且含 4C24G 永久免费额度。",
                ]);
                $paygProduct->wholesalePrices()->createMany([
                    ['min_quantity' => 3, 'price' => number_format(round($paygBase * 0.95, 2), 2, '.', '')],
                    ['min_quantity' => 5, 'price' => number_format(round($paygBase * 0.90, 2), 2, '.', '')],
                ]);
                $createdProducts++;

                $paygStock = $region['payg_stock'];
                for ($n = 1; $n <= $paygStock; $n++) {
                    $secret = sprintf(
                        'Tenancy: oci-payg-%s-%03d ---- 谷歌原邮: oci.%s.upg%03d@gmail.example.test ---- 控制台密码: OciPayg#%s!%03d ---- 谷歌邮箱密码: GMail#%03dUpg ---- 2FA密钥: KBSWY3DPEHPK3PY%d',
                        $region['code'],
                        $n,
                        strtolower($region['iata']),
                        $n,
                        strtoupper($region['iata']),
                        $n,
                        $n,
                        $n % 9
                    );
                    $cardBuffer[] = Card::storageAttributes([
                        'product_id' => $paygProduct->id,
                        'order_id' => null,
                        'content' => $secret,
                        'status' => 'unsold',
                        'locked_at' => null,
                        'sold_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $createdCards++;
                    if (count($cardBuffer) >= 400) {
                        DB::table('cards')->insert($cardBuffer);
                        $cardBuffer = [];
                    }
                }

                // (B) 普通号: "{城市}普通 - 高质量谷歌" (e.g. "悉尼普通 - 高质量谷歌")
                $freeTitle = "{$city}普通 - 高质量谷歌";
                $freeBase = (float) $region['free_price'];
                $freeProduct = Product::create([
                    'category_id' => $category->id,
                    'name' => $freeTitle,
                    'slug' => 'oci-free-' . $region['code'],
                    'description' => $freeDescription,
                    'image' => $unifiedImg,
                    'price' => number_format($freeBase, 2, '.', ''),
                    'min_quantity' => 1,
                    'max_quantity' => 10,
                    'low_stock_threshold' => 3,
                    'sort_order' => $rank * 2,
                    'is_active' => true,
                    'seo_title' => "{$freeTitle} | 甲骨文云 {$fullZh} ({$region['code']}) 永久免费号",
                    'seo_keywords' => "{$city}普通,高质量谷歌,甲骨文免费号,甲骨文普通号,Oracle Cloud,{$category->name},{$fullZh},{$region['code']}",
                    'seo_description' => "{$freeTitle}：Oracle Cloud 甲骨文云 {$category->name} · {$fullZh}（{$region['en']} / {$region['code']}）永久免费普通号，高质量谷歌原邮交付，支持 4C24G ARM 与 AMD 免费额度，24/7 自动发货。",
                ]);
                $freeProduct->wholesalePrices()->createMany([
                    ['min_quantity' => 3, 'price' => number_format(round($freeBase * 0.95, 2), 2, '.', '')],
                    ['min_quantity' => 5, 'price' => number_format(round($freeBase * 0.90, 2), 2, '.', '')],
                ]);
                $createdProducts++;

                $freeStock = $region['free_stock'];
                for ($n = 1; $n <= $freeStock; $n++) {
                    $secret = sprintf(
                        'Tenancy: oci-free-%s-%03d ---- 谷歌原邮: oci.%s.std%03d@gmail.example.test ---- 控制台密码: OciFree#%s!%03d ---- 谷歌邮箱密码: GMail#%03dStd ---- 2FA密钥: JBSWY3DPEHPK3PX%d',
                        $region['code'],
                        $n,
                        strtolower($region['iata']),
                        $n,
                        strtoupper($region['iata']),
                        $n,
                        $n,
                        $n % 9
                    );
                    $cardBuffer[] = Card::storageAttributes([
                        'product_id' => $freeProduct->id,
                        'order_id' => null,
                        'content' => $secret,
                        'status' => 'unsold',
                        'locked_at' => null,
                        'sold_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $createdCards++;
                    if (count($cardBuffer) >= 400) {
                        DB::table('cards')->insert($cardBuffer);
                        $cardBuffer = [];
                    }
                }
            }

            if ($cardBuffer !== []) {
                DB::table('cards')->insert($cardBuffer);
            }

            // 5. Update help-center articles to match the 6 categories, new naming convention & unified image
            $guideCat = ArticleCategory::create([
                'name' => '甲骨文云使用指南',
                'slug' => 'oci-guides',
                'sort_order' => 10,
            ]);

            $articles = [
                [
                    'title' => '甲骨文「升级号」与「普通号」（高质量谷歌原邮）区别详解',
                    'slug' => 'oci-free-vs-payg',
                    'cover_image' => '/oci-assets/article-free-vs-payg.svg',
                    'content' => '<p><img src="' . $unifiedImg . '" alt="Oracle Cloud 甲骨文云官方基础设施展示图" width="960" height="600"></p>'
                        . '<h2>核心对比：升级号（PAYG） vs 普通号（Always Free）</h2>'
                        . '<p>全站商品均采用<strong>高质量谷歌（Gmail）初始注册原邮</strong>交付，两类账号均享有相同的甲骨文官方 <strong>Always Free 永久免费资源额度</strong>（最高 4 OCPU + 24 GB ARM 实例、2 台 AMD Micro 实例、200 GB 块存储、每月 10 TB 出站流量）。核心差异在于宿主资源池优先级：</p>'
                        . '<ul>'
                        . '<li><strong>升级号（如「悉尼升级 - 高质量谷歌」）：</strong>已完成官方 Pay As You Go 商业账户升级，共享商业宿主池容量，在东京、首尔、新加坡、悉尼、圣何塞等热门区域可随时秒开 ARM 实例；只要控制在免费额度内同样 0 费用。</li>'
                        . '<li><strong>普通号（如「悉尼普通 - 高质量谷歌」）：</strong>标准永久免费层账号，适合预算敏感、在余量充足区域部署轻量节点，成本恒定为 0，永远不会产生超额账单。</li>'
                        . '</ul>',
                ],
                [
                    'title' => '收到甲骨文账号后：谷歌原邮核验、改密与更换 2FA 二验教程',
                    'slug' => 'oci-first-login-security',
                    'cover_image' => '/oci-assets/article-login-2fa.svg',
                    'content' => '<p><img src="' . $unifiedImg . '" alt="Oracle Cloud 甲骨文云安全接管指南" width="960" height="600"></p>'
                        . '<h2>安全接管四步走</h2>'
                        . '<ol>'
                        . '<li>访问甲骨文云官方入口 <code>https://cloud.oracle.com</code>，输入卡密第一段的<strong>租户名称（Tenancy Name）</strong>点击下一步。</li>'
                        . '<li>输入配套谷歌原邮账号、控制台密码，并用二验密钥生成 6 位动态验证码完成登录。</li>'
                        . '<li>进入右上角 <strong>Profile &rarr; My profile &rarr; Security</strong>，先更换为您手机上的 Authenticator 验证器应用。</li>'
                        . '<li>同步修改甲骨文控制台登录密码及配套谷歌原邮密码/辅助邮箱，确保账号由您个人独享控制。</li>'
                        . '</ol>',
                ],
                [
                    'title' => '甲骨文全球 45 个区域怎么选？亚太、北美、欧洲、中东、非洲与拉美六大区指南',
                    'slug' => 'oci-region-selection-guide',
                    'cover_image' => '/oci-assets/article-region-guide.svg',
                    'content' => '<h2>六大独立区域板块选区建议</h2>'
                        . '<ul>'
                        . '<li><strong>亚太区（12 区）：</strong>东京、大阪、首尔、春川、新加坡、悉尼等物理距离近、延迟低，是日常建站与亚太业务首选。</li>'
                        . '<li><strong>北美区（8 区）：</strong>圣何塞、凤凰城美西国际带宽充沛，阿什本、芝加哥、多伦多、蒙特利尔与墨西哥节点适合北美全境覆盖。</li>'
                        . '<li><strong>欧洲区（13 区）：</strong>法兰克福、伦敦、阿姆斯特丹、巴黎、苏黎世、马德里等欧洲核心交换枢纽，跨境吞吐稳定。</li>'
                        . '<li><strong>中东区（5 区）：</strong>迪拜、阿布扎比、利雅得、吉达、耶路撒冷，适合中东本地化业务与海湾低延迟互联。</li>'
                        . '<li><strong>非洲区（2 区）：</strong>南非约翰内斯堡、摩洛哥卡萨布兰卡，稀缺非洲本土原生云节点。</li>'
                        . '<li><strong>拉美区（5 区）：</strong>巴西圣保罗、维涅杜、智利圣地亚哥、瓦尔帕莱索、哥伦比亚波哥大，覆盖南美主要经济中心。</li>'
                        . '</ul>',
                ],
                [
                    'title' => '升级号（PAYG）如何在永久免费额度内 0 账单稳定使用',
                    'slug' => 'oci-payg-budget-protection',
                    'cover_image' => '/oci-assets/article-payg-budget.svg',
                    'content' => '<p><img src="' . $unifiedImg . '" alt="Oracle Cloud 甲骨文云升级号零账单配额说明" width="960" height="600"></p>'
                        . '<h2>认准 Always Free 永久免费边界</h2>'
                        . '<ul>'
                        . '<li><strong>ARM 实例（VM.Standard.A1.Flex）：</strong>所有实例合计不超过 <code>4 OCPU</code> 与 <code>24 GB 内存</code>（可开 1 台 4C24G 或 2 台 2C12G 或 4 台 1C6G）。</li>'
                        . '<li><strong>AMD 实例（VM.Standard.E2.1.Micro）：</strong>最多 <code>2 台</code> 免费微型实例。</li>'
                        . '<li><strong>块存储（Boot + Block Volumes）：</strong>账号下全部实例引导卷与附加块存储总和不超过 <code>200 GB</code>。</li>'
                        . '<li><strong>预算提醒（Budgets）：</strong>在控制台设置月度 <code>$1</code> 阈值邮件通知，一旦误开收费规格可第一时间收到提醒。</li>'
                        . '</ul>',
                ],
            ];

            foreach ($articles as $idx => $art) {
                Article::create([
                    'article_category_id' => $guideCat->id,
                    'title' => $art['title'],
                    'slug' => $art['slug'],
                    'cover_image' => $art['cover_image'],
                    'content' => $art['content'],
                    'is_published' => true,
                    'views' => 260 - $idx * 35,
                ]);
            }

            // 6. Update storefront announcement & SEO settings to match the 6 regional categories & naming
            Setting::set('seo_default_title', '甲骨文云账号商城 - 亚太/北美/欧洲/中东/非洲/拉美 升级号与普通号（高质量谷歌）自动发货 | CardShop', 'seo');
            Setting::set('seo_default_description', '提供甲骨文云（Oracle Cloud / OCI）全球 45 个主区域独立账号，按亚太区、北美区、欧洲区、中东区、非洲区、拉美区六大板块分类，涵盖升级号（PAYG）与普通号（Always Free），高质量谷歌原邮 24/7 自动发货。', 'seo');
            Setting::set('seo_default_keywords', '甲骨文云,Oracle Cloud,甲骨文升级号,甲骨文普通号,高质量谷歌,悉尼升级,东京升级,首尔升级,新加坡升级,4C24G ARM', 'seo');
            Setting::set('site_description', '甲骨文云（Oracle Cloud）全球 45 区域自助发卡商城 · 亚太、北美、欧洲、中东、非洲、拉美六大独立分类，高质量谷歌原邮升级号与普通号现货秒发。', 'site');
            Setting::set('site_announcement', '<p><strong>现货自动发货：</strong>全站按<strong>亚太区、北美区、欧洲区、中东区、非洲区、拉美区</strong>六大独立板块划分全球 45 个甲骨文区域，每区均提供<strong>升级号（PAYG）</strong>与<strong>普通号（Always Free）</strong>，统一配套<strong>高质量谷歌原邮</strong>交付。<a href="/articles/oci-free-vs-payg">查看升级号与普通号区别 &rarr;</a></p>', 'site');
            Setting::set('contact_url', '/articles/oci-first-login-security', 'site');
            Setting::set('contact_text', '甲骨文云自助商城 · 支持凭下单邮箱与查询密码 24 小时自助提卡与导出。', 'site');
            Setting::set('checkout_require_key', '1', 'security');
            Setting::set('order_max_pending_per_ip', '3', 'security');
            Setting::set('order_max_pending_per_email', '3', 'security');
            Setting::set('order_max_locked_percent', '80', 'security');

            $adminId = DB::table('admins')->min('id');
            if ($adminId) {
                OperationLog::create([
                    'admin_id' => $adminId,
                    'action' => '更新六大区域分类与统一甲骨文介绍图',
                    'target_type' => 'product',
                    'target_id' => null,
                    'detail' => "已拆分为 6 大独立分类（亚太/北美/欧洲/中东/非洲/拉美），更新 {$createdProducts} 款「升级/普通 - 高质量谷歌」商品并统一商品介绍图",
                    'ip' => '127.0.0.1',
                ]);
            }

            return [
                'categories' => count($categories),
                'regions' => count($regions),
                'products' => $createdProducts,
                'cards' => $createdCards,
                'articles' => count($articles),
            ];
        });

        $this->command?->info(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public static function generateAssets(): void
    {
        $assetDir = public_path('oci-assets');
        File::ensureDirectoryExists($assetDir);

        // Clean up obsolete per-region product SVGs & old merged category icon so only the unified Oracle Cloud image + 6 category icons remain
        foreach (File::glob($assetDir . '/oci-free-*.svg') as $oldFile) {
            File::delete($oldFile);
        }
        foreach (File::glob($assetDir . '/oci-payg-*.svg') as $oldFile) {
            File::delete($oldFile);
        }
        foreach (['cat-mea-latam.svg', 'category-free.svg', 'category-payg.svg', 'intro-free.svg', 'intro-payg.svg', 'delivery-flow.svg'] as $obsolete) {
            if (File::exists($assetDir . '/' . $obsolete)) {
                File::delete($assetDir . '/' . $obsolete);
            }
        }

        // 6 Independent Regional Category Icons (SVG)
        foreach (['apac', 'na', 'eu', 'me', 'af', 'latam'] as $catKey) {
            File::put($assetDir . '/cat-' . $catKey . '.svg', self::categoryIconSvg($catKey));
        }

        // Help Center Article Covers (SVG)
        File::put($assetDir . '/article-free-vs-payg.svg', self::articleCoverSvg('UPGRADE vs STANDARD', '升级号与普通号（高质量谷歌）对比', '4C24G ARM · 商业池容量 · 零账单边界', '#C74634', '#0F172A'));
        File::put($assetDir . '/article-login-2fa.svg', self::articleCoverSvg('GOOGLE MAIL & 2FA', '谷歌原邮核验、改密与二验换绑指南', 'Tenancy 租户登录 · Gmail 原邮 · 安全接管', '#991B1B', '#1E293B'));
        File::put($assetDir . '/article-region-guide.svg', self::articleCoverSvg('6 GLOBAL ZONES', '亚太 · 北美 · 欧洲 · 中东 · 非洲 · 拉美', '全球 45 个甲骨文主区域选区参考', '#B91C1C', '#172554'));
        File::put($assetDir . '/article-payg-budget.svg', self::articleCoverSvg('ZERO-BILL BUDGET', '升级号永久免费配额与预算保护设置', '200GB 存储 · 10TB 流量 · $1 预算邮件告警', '#D97706', '#0F172A'));
    }

    /**
     * 45 official Oracle Cloud commercial regions mapped to the 6 independent regional categories.
     */
    public static function regions(): array
    {
        return [
            // 一、亚太区（12 个区域）
            ['cat_key' => 'apac', 'city' => '东京', 'zh' => '日本东京', 'en' => 'Japan East (Tokyo)', 'code' => 'ap-tokyo-1', 'iata' => 'NRT', 'free_price' => '88.00', 'payg_price' => '268.00', 'free_stock' => 28, 'payg_stock' => 16],
            ['cat_key' => 'apac', 'city' => '大阪', 'zh' => '日本大阪', 'en' => 'Japan Central (Osaka)', 'code' => 'ap-osaka-1', 'iata' => 'KIX', 'free_price' => '85.00', 'payg_price' => '258.00', 'free_stock' => 24, 'payg_stock' => 14],
            ['cat_key' => 'apac', 'city' => '首尔', 'zh' => '韩国首尔', 'en' => 'South Korea Central (Seoul)', 'code' => 'ap-seoul-1', 'iata' => 'ICN', 'free_price' => '88.00', 'payg_price' => '268.00', 'free_stock' => 26, 'payg_stock' => 15],
            ['cat_key' => 'apac', 'city' => '春川', 'zh' => '韩国春川', 'en' => 'South Korea North (Chuncheon)', 'code' => 'ap-chuncheon-1', 'iata' => 'YNY', 'free_price' => '85.00', 'payg_price' => '258.00', 'free_stock' => 22, 'payg_stock' => 14],
            ['cat_key' => 'apac', 'city' => '新加坡', 'zh' => '新加坡', 'en' => 'Singapore (Singapore)', 'code' => 'ap-singapore-1', 'iata' => 'SIN', 'free_price' => '88.00', 'payg_price' => '268.00', 'free_stock' => 30, 'payg_stock' => 18],
            ['cat_key' => 'apac', 'city' => '新加坡西', 'zh' => '新加坡西', 'en' => 'Singapore West (Singapore)', 'code' => 'ap-singapore-2', 'iata' => 'XSP', 'free_price' => '85.00', 'payg_price' => '258.00', 'free_stock' => 20, 'payg_stock' => 12],
            ['cat_key' => 'apac', 'city' => '古来', 'zh' => '马来西亚古来', 'en' => 'Malaysia West 2 (Kulai)', 'code' => 'ap-kulai-2', 'iata' => 'JBP', 'free_price' => '75.00', 'payg_price' => '228.00', 'free_stock' => 18, 'payg_stock' => 11],
            ['cat_key' => 'apac', 'city' => '巴淡岛', 'zh' => '印尼巴淡岛', 'en' => 'Indonesia North (Batam)', 'code' => 'ap-batam-1', 'iata' => 'HSG', 'free_price' => '75.00', 'payg_price' => '228.00', 'free_stock' => 18, 'payg_stock' => 10],
            ['cat_key' => 'apac', 'city' => '悉尼', 'zh' => '澳大利亚悉尼', 'en' => 'Australia East (Sydney)', 'code' => 'ap-sydney-1', 'iata' => 'SYD', 'free_price' => '68.00', 'payg_price' => '218.00', 'free_stock' => 20, 'payg_stock' => 12],
            ['cat_key' => 'apac', 'city' => '墨尔本', 'zh' => '澳大利亚墨尔本', 'en' => 'Australia Southeast (Melbourne)', 'code' => 'ap-melbourne-1', 'iata' => 'MEL', 'free_price' => '68.00', 'payg_price' => '218.00', 'free_stock' => 18, 'payg_stock' => 10],
            ['cat_key' => 'apac', 'city' => '孟买', 'zh' => '印度孟买', 'en' => 'India West (Mumbai)', 'code' => 'ap-mumbai-1', 'iata' => 'BOM', 'free_price' => '65.00', 'payg_price' => '198.00', 'free_stock' => 16, 'payg_stock' => 10],
            ['cat_key' => 'apac', 'city' => '海得拉巴', 'zh' => '印度海得拉巴', 'en' => 'India South (Hyderabad)', 'code' => 'ap-hyderabad-1', 'iata' => 'HYD', 'free_price' => '65.00', 'payg_price' => '198.00', 'free_stock' => 16, 'payg_stock' => 10],

            // 二、北美区（8 个区域）
            ['cat_key' => 'na', 'city' => '圣何塞', 'zh' => '美国圣何塞', 'en' => 'US West (San Jose)', 'code' => 'us-sanjose-1', 'iata' => 'SJC', 'free_price' => '78.00', 'payg_price' => '238.00', 'free_stock' => 26, 'payg_stock' => 16],
            ['cat_key' => 'na', 'city' => '凤凰城', 'zh' => '美国凤凰城', 'en' => 'US West (Phoenix)', 'code' => 'us-phoenix-1', 'iata' => 'PHX', 'free_price' => '72.00', 'payg_price' => '228.00', 'free_stock' => 24, 'payg_stock' => 15],
            ['cat_key' => 'na', 'city' => '阿什本', 'zh' => '美国阿什本', 'en' => 'US East (Ashburn)', 'code' => 'us-ashburn-1', 'iata' => 'IAD', 'free_price' => '72.00', 'payg_price' => '228.00', 'free_stock' => 24, 'payg_stock' => 15],
            ['cat_key' => 'na', 'city' => '芝加哥', 'zh' => '美国芝加哥', 'en' => 'US Midwest (Chicago)', 'code' => 'us-chicago-1', 'iata' => 'ORD', 'free_price' => '72.00', 'payg_price' => '228.00', 'free_stock' => 20, 'payg_stock' => 12],
            ['cat_key' => 'na', 'city' => '多伦多', 'zh' => '加拿大多伦多', 'en' => 'Canada Southeast (Toronto)', 'code' => 'ca-toronto-1', 'iata' => 'YYZ', 'free_price' => '68.00', 'payg_price' => '208.00', 'free_stock' => 18, 'payg_stock' => 11],
            ['cat_key' => 'na', 'city' => '蒙特利尔', 'zh' => '加拿大蒙特利尔', 'en' => 'Canada Southeast (Montreal)', 'code' => 'ca-montreal-1', 'iata' => 'YUL', 'free_price' => '68.00', 'payg_price' => '208.00', 'free_stock' => 18, 'payg_stock' => 11],
            ['cat_key' => 'na', 'city' => '克雷塔罗', 'zh' => '墨西哥克雷塔罗', 'en' => 'Mexico Central (Queretaro)', 'code' => 'mx-queretaro-1', 'iata' => 'QRO', 'free_price' => '62.00', 'payg_price' => '198.00', 'free_stock' => 15, 'payg_stock' => 9],
            ['cat_key' => 'na', 'city' => '蒙特雷', 'zh' => '墨西哥蒙特雷', 'en' => 'Mexico Northeast (Monterrey)', 'code' => 'mx-monterrey-1', 'iata' => 'MTY', 'free_price' => '62.00', 'payg_price' => '198.00', 'free_stock' => 15, 'payg_stock' => 9],

            // 三、欧洲区（13 个区域）
            ['cat_key' => 'eu', 'city' => '法兰克福', 'zh' => '德国法兰克福', 'en' => 'Germany Central (Frankfurt)', 'code' => 'eu-frankfurt-1', 'iata' => 'FRA', 'free_price' => '72.00', 'payg_price' => '228.00', 'free_stock' => 25, 'payg_stock' => 15],
            ['cat_key' => 'eu', 'city' => '伦敦', 'zh' => '英国伦敦', 'en' => 'UK South (London)', 'code' => 'uk-london-1', 'iata' => 'LHR', 'free_price' => '72.00', 'payg_price' => '228.00', 'free_stock' => 22, 'payg_stock' => 14],
            ['cat_key' => 'eu', 'city' => '纽波特', 'zh' => '英国纽波特', 'en' => 'UK West (Newport)', 'code' => 'uk-cardiff-1', 'iata' => 'CWL', 'free_price' => '68.00', 'payg_price' => '208.00', 'free_stock' => 16, 'payg_stock' => 10],
            ['cat_key' => 'eu', 'city' => '阿姆斯特丹', 'zh' => '荷兰阿姆斯特丹', 'en' => 'Netherlands Northwest (Amsterdam)', 'code' => 'eu-amsterdam-1', 'iata' => 'AMS', 'free_price' => '68.00', 'payg_price' => '218.00', 'free_stock' => 20, 'payg_stock' => 12],
            ['cat_key' => 'eu', 'city' => '巴黎', 'zh' => '法国巴黎', 'en' => 'France Central (Paris)', 'code' => 'eu-paris-1', 'iata' => 'CDG', 'free_price' => '68.00', 'payg_price' => '218.00', 'free_stock' => 20, 'payg_stock' => 12],
            ['cat_key' => 'eu', 'city' => '马赛', 'zh' => '法国马赛', 'en' => 'France South (Marseille)', 'code' => 'eu-marseille-1', 'iata' => 'MRS', 'free_price' => '65.00', 'payg_price' => '208.00', 'free_stock' => 16, 'payg_stock' => 10],
            ['cat_key' => 'eu', 'city' => '苏黎世', 'zh' => '瑞士苏黎世', 'en' => 'Switzerland North (Zurich)', 'code' => 'eu-zurich-1', 'iata' => 'ZRH', 'free_price' => '68.00', 'payg_price' => '218.00', 'free_stock' => 18, 'payg_stock' => 11],
            ['cat_key' => 'eu', 'city' => '斯德哥尔摩', 'zh' => '瑞典斯德哥尔摩', 'en' => 'Sweden Central (Stockholm)', 'code' => 'eu-stockholm-1', 'iata' => 'ARN', 'free_price' => '65.00', 'payg_price' => '208.00', 'free_stock' => 16, 'payg_stock' => 10],
            ['cat_key' => 'eu', 'city' => '米兰', 'zh' => '意大利米兰', 'en' => 'Italy Northwest (Milan)', 'code' => 'eu-milan-1', 'iata' => 'LIN', 'free_price' => '65.00', 'payg_price' => '208.00', 'free_stock' => 16, 'payg_stock' => 10],
            ['cat_key' => 'eu', 'city' => '都灵', 'zh' => '意大利都灵', 'en' => 'Italy North (Turin)', 'code' => 'eu-turin-1', 'iata' => 'NRQ', 'free_price' => '65.00', 'payg_price' => '208.00', 'free_stock' => 15, 'payg_stock' => 9],
            ['cat_key' => 'eu', 'city' => '马德里', 'zh' => '西班牙马德里', 'en' => 'Spain Central (Madrid)', 'code' => 'eu-madrid-1', 'iata' => 'MAD', 'free_price' => '65.00', 'payg_price' => '208.00', 'free_stock' => 16, 'payg_stock' => 10],
            ['cat_key' => 'eu', 'city' => '马德里3区', 'zh' => '西班牙马德里3区', 'en' => 'Spain Central (Madrid 3)', 'code' => 'eu-madrid-3', 'iata' => 'ORF', 'free_price' => '65.00', 'payg_price' => '208.00', 'free_stock' => 14, 'payg_stock' => 9],
            ['cat_key' => 'eu', 'city' => '约瓦诺瓦茨', 'zh' => '塞尔维亚约瓦诺瓦茨', 'en' => 'Serbia Central (Jovanovac)', 'code' => 'eu-jovanovac-1', 'iata' => 'BEG', 'free_price' => '62.00', 'payg_price' => '198.00', 'free_stock' => 14, 'payg_stock' => 8],

            // 四、中东区（5 个区域）
            ['cat_key' => 'me', 'city' => '迪拜', 'zh' => '阿联酋迪拜', 'en' => 'UAE East (Dubai)', 'code' => 'me-dubai-1', 'iata' => 'DXB', 'free_price' => '68.00', 'payg_price' => '218.00', 'free_stock' => 18, 'payg_stock' => 11],
            ['cat_key' => 'me', 'city' => '阿布扎比', 'zh' => '阿联酋阿布扎比', 'en' => 'UAE Central (Abu Dhabi)', 'code' => 'me-abudhabi-1', 'iata' => 'AUH', 'free_price' => '65.00', 'payg_price' => '208.00', 'free_stock' => 16, 'payg_stock' => 10],
            ['cat_key' => 'me', 'city' => '利雅得', 'zh' => '沙特利雅得', 'en' => 'Saudi Arabia Central (Riyadh)', 'code' => 'me-riyadh-1', 'iata' => 'RUH', 'free_price' => '65.00', 'payg_price' => '208.00', 'free_stock' => 16, 'payg_stock' => 10],
            ['cat_key' => 'me', 'city' => '吉达', 'zh' => '沙特吉达', 'en' => 'Saudi Arabia West (Jeddah)', 'code' => 'me-jeddah-1', 'iata' => 'JED', 'free_price' => '65.00', 'payg_price' => '208.00', 'free_stock' => 15, 'payg_stock' => 9],
            ['cat_key' => 'me', 'city' => '耶路撒冷', 'zh' => '以色列耶路撒冷', 'en' => 'Israel Central (Jerusalem)', 'code' => 'il-jerusalem-1', 'iata' => 'MTZ', 'free_price' => '65.00', 'payg_price' => '208.00', 'free_stock' => 15, 'payg_stock' => 9],

            // 五、非洲区（2 个区域）
            ['cat_key' => 'af', 'city' => '约翰内斯堡', 'zh' => '南非约翰内斯堡', 'en' => 'South Africa Central (Johannesburg)', 'code' => 'af-johannesburg-1', 'iata' => 'JNB', 'free_price' => '62.00', 'payg_price' => '198.00', 'free_stock' => 15, 'payg_stock' => 9],
            ['cat_key' => 'af', 'city' => '卡萨布兰卡', 'zh' => '摩洛哥卡萨布兰卡', 'en' => 'Morocco West (Casablanca)', 'code' => 'af-casablanca-1', 'iata' => 'LEJ', 'free_price' => '62.00', 'payg_price' => '198.00', 'free_stock' => 14, 'payg_stock' => 8],

            // 六、拉美区（5 个区域）
            ['cat_key' => 'latam', 'city' => '圣保罗', 'zh' => '巴西圣保罗', 'en' => 'Brazil East (Sao Paulo)', 'code' => 'sa-saopaulo-1', 'iata' => 'GRU', 'free_price' => '62.00', 'payg_price' => '198.00', 'free_stock' => 18, 'payg_stock' => 11],
            ['cat_key' => 'latam', 'city' => '维涅杜', 'zh' => '巴西维涅杜', 'en' => 'Brazil Southeast (Vinhedo)', 'code' => 'sa-vinhedo-1', 'iata' => 'VCP', 'free_price' => '62.00', 'payg_price' => '198.00', 'free_stock' => 14, 'payg_stock' => 9],
            ['cat_key' => 'latam', 'city' => '圣地亚哥', 'zh' => '智利圣地亚哥', 'en' => 'Chile Central (Santiago)', 'code' => 'sa-santiago-1', 'iata' => 'SCL', 'free_price' => '62.00', 'payg_price' => '198.00', 'free_stock' => 15, 'payg_stock' => 9],
            ['cat_key' => 'latam', 'city' => '瓦尔帕莱索', 'zh' => '智利瓦尔帕莱索', 'en' => 'Chile West (Valparaiso)', 'code' => 'sa-valparaiso-1', 'iata' => 'VAP', 'free_price' => '62.00', 'payg_price' => '198.00', 'free_stock' => 14, 'payg_stock' => 8],
            ['cat_key' => 'latam', 'city' => '波哥大', 'zh' => '哥伦比亚波哥大', 'en' => 'Colombia Central (Bogota)', 'code' => 'sa-bogota-1', 'iata' => 'BOG', 'free_price' => '62.00', 'payg_price' => '198.00', 'free_stock' => 14, 'payg_stock' => 8],
        ];
    }

    /**
     * Luminous, cheerful soft-gradient category SVG icons (96×96) for the 6 regional entries.
     */
    private static function categoryIconSvg(string $catKey): string
    {
        [$gradTop, $gradBottom, $pillBg, $label] = match ($catKey) {
            'apac' => ['#FF6B57', '#DE3B2A', '#9E1F14', 'OCI · 亚太'],
            'na' => ['#3B82F6', '#1D63DC', '#153E90', 'OCI · 北美'],
            'eu' => ['#7C69EF', '#5340C8', '#33248F', 'OCI · 欧洲'],
            'me' => ['#F59E0B', '#D97706', '#92400E', 'OCI · 中东'],
            'af' => ['#10B981', '#059669', '#065F46', 'OCI · 非洲'],
            default => ['#EC4899', '#C81E68', '#831843', 'OCI · 拉美'],
        };

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96" fill="none">
  <defs>
    <linearGradient id="g-{$catKey}" x1="12" y1="6" x2="84" y2="90" gradientUnits="userSpaceOnUse">
      <stop offset="0%" stop-color="{$gradTop}"/>
      <stop offset="100%" stop-color="{$gradBottom}"/>
    </linearGradient>
  </defs>
  <rect width="96" height="96" rx="22" fill="url(#g-{$catKey})"/>
  <circle cx="76" cy="20" r="24" fill="#FFFFFF" fill-opacity="0.16"/>
  <path d="M26 50c-5.5 0-10-4.3-10-9.6 0-4.8 3.6-8.8 8.3-9.5C26.7 23.5 33.6 18 41.8 18c8.8 0 16.2 6.2 17.9 14.5 4.8.6 8.3 4.6 8.3 9.5 0 5.5-4.5 8-10 8H26Z" fill="#FFFFFF"/>
  <rect x="31.5" y="32" width="21" height="9.5" rx="4.75" stroke="{$gradBottom}" stroke-width="3"/>
  <rect x="13" y="60" width="70" height="22" rx="11" fill="{$pillBg}" fill-opacity="0.78"/>
  <text x="48" y="75" text-anchor="middle" fill="#FFFFFF" font-family="system-ui, -apple-system, 'Microsoft YaHei', sans-serif" font-size="13" font-weight="800" letter-spacing="0.4">{$label}</text>
</svg>
SVG;
    }

    private static function articleCoverSvg(string $badge, string $title, string $subtitle, string $accent, string $bg): string
    {
        $b = htmlspecialchars($badge, ENT_QUOTES, 'UTF-8');
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $s = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360" viewBox="0 0 640 360" fill="none">
  <rect width="640" height="360" fill="{$bg}"/>
  <circle cx="540" cy="80" r="140" fill="{$accent}" fill-opacity="0.22"/>
  <rect x="42" y="48" width="112" height="32" rx="7" fill="#C74634"/>
  <text x="98" y="70" text-anchor="middle" fill="#FFFFFF" font-family="Arial Black, system-ui, sans-serif" font-size="15" font-weight="900" letter-spacing="1.4">ORACLE</text>
  <rect x="164" y="48" width="210" height="32" rx="7" fill="#FFFFFF" fill-opacity="0.12"/>
  <text x="269" y="69" text-anchor="middle" fill="#F8FAFC" font-family="ui-monospace, Consolas, monospace" font-size="13.5" font-weight="700">{$b}</text>
  <text x="42" y="176" fill="#FFFFFF" font-family="system-ui, -apple-system, 'Microsoft YaHei', sans-serif" font-size="30" font-weight="900">{$t}</text>
  <text x="42" y="226" fill="#CBD5E1" font-family="system-ui, -apple-system, 'Microsoft YaHei', sans-serif" font-size="17" font-weight="600">{$s}</text>
  <rect x="42" y="274" width="220" height="40" rx="10" fill="{$accent}"/>
  <text x="152" y="300" text-anchor="middle" fill="#FFFFFF" font-family="system-ui, -apple-system, 'Microsoft YaHei', sans-serif" font-size="15" font-weight="800">Oracle Cloud 使用指南</text>
</svg>
SVG;
    }
}
