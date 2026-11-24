<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

if (!function_exists('settings_all')) {
    /**
     * Load every stored setting as a key => value map; credentials remain sealed.
     *
     * The whole table is cached under one key rather than one key per setting. That
     * keeps a page render to a single lookup, and — critically — it means the caller's
     * $default is never written into the cache, so two callers asking for the same key
     * with different fallbacks can no longer poison each other.
     *
     * Cache/DB outages may degrade to an empty map at bootstrap. Invalid secret
     * storage fails closed; setting() decrypts one selected credential on use.
     *
     * @return array<string, string|null>
     */
    function settings_all(): array
    {
        $memo = settings_memo();

        if ($memo !== null) {
            return $memo;
        }

        $load = fn (): array => \App\Security\SecretSettings::cacheMap(DB::table('settings')->pluck('value', 'key')->all());

        try {
            $memo = Cache::remember('settings:stored:v1', 3600, $load);
        } catch (\Throwable $e) {
            if ($e instanceof \App\Exceptions\SecretStorageException) { throw $e; }
            // Cache backend unavailable — read straight through to the database.
            try {
                $memo = $load();
            } catch (\Throwable $e) {
                if ($e instanceof \App\Exceptions\SecretStorageException) { throw $e; }
                // Database unavailable or not migrated yet.
                $memo = [];
            }
        }

        settings_memo($memo);

        return $memo;
    }
}

if (!function_exists('settings_memo')) {
    /**
     * The per-request copy of the settings map.
     *
     * It lives in its own function purely so settings_forget() can reach it. As a
     * `static` inside settings_all() it was unreachable, which made settings_forget()
     * a half-truth: it dropped the shared cache but left this copy in place, so any
     * setting read after a write in the SAME request still returned the old value.
     * Harmless under PHP-FPM, where statics die with the request — but the function
     * is documented as "call after any write", and it should mean it.
     *
     * @param array<string, string|null>|null $set   Store this map.
     * @param bool                            $clear Forget the stored map.
     * @return array<string, string|null>|null       Null when nothing is stored.
     */
    function settings_memo(?array $set = null, bool $clear = false): ?array
    {
        static $memo = null;

        if ($clear) {
            $memo = null;
        } elseif ($set !== null) {
            $memo = $set;
        }

        return $memo;
    }
}

if (!function_exists('setting')) {
    /**
     * Get a setting value, falling back to $default when unset or blank.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function setting(string $key, mixed $default = null): mixed
    {
        $value = \App\Security\SecretSettings::decode($key, settings_all()[$key] ?? null);

        return ($value === null || $value === '') ? $default : $value;
    }
}

if (!function_exists('asset_versioned')) {
    /**
     * asset() with a cache-busting version derived from the file's mtime.
     *
     * nginx serves everything under public/ with `Cache-Control: public, immutable`
     * and a 30-day expiry. Without a version in the URL that is a trap rather than an
     * optimisation: a returning visitor keeps the stylesheet they cached weeks ago and
     * never sees a deploy. Changing the file changes its mtime, which changes the URL,
     * which is exactly the condition `immutable` is safe under.
     */
    function asset_versioned(string $path): string
    {
        static $stamps = [];

        if (!array_key_exists($path, $stamps)) {
            $full = public_path($path);
            $stamps[$path] = is_file($full) ? (string) filemtime($full) : null;
        }

        $url = asset($path);

        return $stamps[$path] ? $url . '?v=' . $stamps[$path] : $url;
    }
}

if (!function_exists('settings_forget')) {
    /**
     * Drop the cached settings map. Call after any write to the settings table.
     */
    function settings_forget(): void
    {
        // Both copies, or the name is a lie: the shared cache AND this request's own.
        settings_memo(clear: true);

        try {
            Cache::forget('settings:map');
            Cache::forget('settings:stored:v1');
        } catch (\Throwable $e) {
            // Nothing to do — a cache we cannot reach is already effectively cleared.
        }
    }
}

if (!function_exists('format_price')) {
    /**
     * Format a price value as Chinese Yuan.
     *
     * @param float $price
     * @return string
     */
    function format_price(float $price): string
    {
        return '¥' . number_format($price, 2, '.', '');
    }
}

if (!function_exists('generate_order_no')) {
    /**
     * Generate a unique order number.
     *
     * Format: YYYYMMDDHHmmss + 10 random digits (24 chars, fits orders.order_no(30)
     * and every gateway's out_trade_no length limit).
     * Example: 202608281430526048172093
     *
     * 随机段从 5 位（random_int 0–99999，约 17 位熵）加到 10 位（约 33 位熵）。
     * 时间戳前缀是完全可预测的，5 位随机意味着「知道下单大致时刻」就只需 ≤10 万次
     * 猜测即可命中一个真实订单号——而订单号一旦被猜到/泄露，配合 /order/pay 公开的
     * 商品金额与支付链接就有了攻击面。加到 10 位后，按秒暴力猜测在算力上不再可行。
     * 这是纵深防御：卡密的真正授权仍由会话所有权和 /order/query 的密码把关，订单号
     * 不可预测只是把「先拿到一个有效订单号」这一步的成本抬高。
     *
     * random_int 是密码学安全的 CSPRNG，不用 rand()/mt_rand()。
     *
     * @return string
     */
    function generate_order_no(): string
    {
        return date('YmdHis') . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('admin_path')) {
    /**
     * The URL segment the admin console is served from, without slashes.
     *
     * Uses an explicit valid ADMIN_PATH from the environment when set; otherwise
     * resolves and persists a cryptographically random 24-character high-strength
     * path generated once at installation time (storage/app/private/admin-path),
     * so /admin is never implicitly exposed.
     *
     * @return string
     */
    function admin_path(): string
    {
        return \App\Support\AdminPath::resolve();
    }
}

if (!function_exists('turnstile_site_key')) {
    /**
     * Return the configured Cloudflare Turnstile site key for storefront widgets only
     * when Turnstile protection is enabled (default '1' for backwards compatibility).
     */
    function turnstile_site_key(): string
    {
        if (setting('turnstile_enabled', '1') !== '1') {
            return '';
        }

        return trim((string) setting('turnstile_site_key', ''));
    }
}

if (!function_exists('themes_available')) {
    /**
     * 磁盘上装了哪些前台模板。
     *
     * 目录即模板：resources/views/templates/<名字>/ 存在就算一套。加模板不需要改任何
     * 代码，也不需要注册——这样别人（或以后的你）只要照着 default 拷一份改就行。
     *
     * @return array<int, string>
     */
    function themes_available(): array
    {
        static $list = null;

        if ($list !== null) {
            return $list;
        }

        $dirs = glob(resource_path('views/templates/*'), GLOB_ONLYDIR) ?: [];
        $list = array_values(array_filter(array_map('basename', $dirs), function (string $n): bool {
            // 只认这个字符集：模板名会直接拼进视图路径，放行点号就等于放行 ../
            return (bool) preg_match('/^[a-z0-9_-]{1,32}$/', $n);
        }));

        sort($list);

        return $list;
    }
}

if (!function_exists('theme')) {
    /**
     * 当前生效的前台模板名。
     *
     * 值来自后台设置 site_theme，但**必须**在磁盘上真实存在才采用。设置表是可写的，
     * 而这个值会拼进视图路径——不校验就等于把视图路径的一部分交给数据库内容决定。
     */
    function theme(): string
    {
        $want = (string) setting('site_theme', 'default');

        return in_array($want, themes_available(), true) ? $want : 'default';
    }
}

if (!function_exists('theme_view_path')) {
    /**
     * 把 'home' / 'partials.product-row' 解析成实际的视图路径。
     *
     * 当前模板缺这个视图时回落到 default。这样一套新模板只需要覆盖它想改的页面，
     * 剩下的自动用 default 的——否则每加一个模板都要把 17 个文件全抄一遍，
     * 而抄漏一个的表现是白屏，不是提示。
     */
    function theme_view_path(string $name): string
    {
        $candidate = 'templates.' . theme() . '.' . $name;

        return view()->exists($candidate) ? $candidate : 'templates.default.' . $name;
    }
}

if (!function_exists('theme_view')) {
    /**
     * 前台控制器统一用它取视图，替代 view('front.xxx')。
     *
     * @param  array<string, mixed>  $data
     */
    function theme_view(string $name, array $data = []): \Illuminate\Contracts\View\View
    {
        return view(theme_view_path($name), $data);
    }
}

if (!function_exists('theme_asset')) {
    /**
     * 模板自己的静态资源，带 mtime 版本号。
     *
     * 放在 public/themes/<模板名>/ 下。文件不存在时回落到 default 的同名文件，
     * 理由和视图回落一样：新模板可以只带自己改动的那部分。
     */
    function theme_asset(string $path): string
    {
        $rel = 'themes/' . theme() . '/' . ltrim($path, '/');

        if (!is_file(public_path($rel))) {
            $rel = 'themes/default/' . ltrim($path, '/');
        }

        return asset_versioned($rel);
    }
}
