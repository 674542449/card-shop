<?php

namespace App\Http\Middleware;

use App\Models\Blacklist;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 扫描器蜜罐：一旦有人来探常见的敏感路径（/.env、/wp-login.php、/phpmyadmin …），
 * 立刻把这个 IP 拉黑，之后它的所有请求都会被 CheckBlacklist 挡成 403。
 *
 * 为什么这样设计——每一条都是防「误伤真人」的：
 *
 *  - **只封公网真实 IP**。$request->ip() 已经是真实客户端地址（nginx 用
 *    set_real_ip_from + CF-Connecting-IP 改写过 $remote_addr，TRUSTED_PROXIES 留空），
 *    所以攻击者伪造 X-Forwarded-For 骗不了它，也就无法借蜜罐去封别人的 IP。
 *    默认跳过私网/保留地址（内网健康检查、负载均衡自己），只封公网。
 *  - **命中的是「已知恶意」路径白名单，不是「非法路径」黑名单**。列表里全是真实用户和
 *    正经爬虫永远不会碰的路径；正常的 /、/product、/order、/articles、/api、后台路径、
 *    /sitemap.xml、/.well-known 一律不匹配，且额外硬跳过当前后台路径。
 *  - **封禁可过期**（honeypot_ban_minutes，默认 7 天）。万一真的误伤（例如攻击者用
 *    <img src="/.env"> 骗某个访客的浏览器去请求），代价是「几天」而不是「永久」；
 *    还能配 honeypot_whitelist 永久放行。
 *  - **可一键关闭**（honeypot_enabled=0）。
 *
 * 这是全局中间件（bootstrap/app.php 里 prepend），所以连没有匹配路由的 404 探测也能
 * 在进路由之前就被它拦下。命中即返回一个普通 404，和真正的「路径不存在」无从区分，
 * 不给扫描器任何「这里有蜜罐」的信号。
 */
class TrapScanners
{
    /**
     * 已知恶意探测路径（大小写不敏感，匹配带前导斜杠的完整路径）。
     * 只放真实用户/正经爬虫不会碰的，宁可漏封也不误封。
     */
    private const PATTERNS = [
        // 环境变量 / 密钥 / 版本库
        '#/\.env(\.[a-z]+)?(/|$)#i',
        '#/\.git(/|$)#i',
        '#/\.svn(/|$)#i',
        '#/\.hg(/|$)#i',
        '#/\.aws(/|$)#i',
        '#/\.ssh(/|$)#i',
        '#/\.DS_Store$#i',
        '#/(wp-config|configuration|config|settings|database|secrets?)\.(php|inc|bak|old|save|txt|ya?ml|json)(/|$)#i',

        // WordPress（本站不是 WP，任何 wp-* 都是探测）
        '#/wp-(admin|login|content|includes|json|cron)#i',
        '#/xmlrpc\.php#i',
        '#/wlwmanifest\.xml#i',

        // 数据库管理面板
        '#/(phpmyadmin|phpMyAdmin|mysqladmin|myadmin|pma|dbadmin|adminer)(/|\.php|$)#i',

        // 其它后台 / 探测面板（注意：不含裸 admin，那可能是本站后台路径）
        '#/(administrator|admin\.php|admin/config|manager/html|jmx-console|web-console)#i',
        '#/(telescope|_ignition|_profiler|actuator|solr|struts|jenkins|grafana|kibana)(/|$)#i',
        '#/(phpinfo|info|test|shell|cmd|eval|c99|r57|wso|alfa|webshell|backdoor)\.(php|asp|aspx|jsp)#i',

        // 著名 RCE 探针
        '#/vendor/phpunit#i',
        '#eval-stdin\.php#i',
        '#/cgi-bin/#i',
        '#/(think|thinkphp)/#i',

        // 打包好的源码 / 备份 / 数据库导出躺在根目录
        '#/[a-z0-9_.-]+\.(sql|sql\.gz|sql\.zip|tar\.gz|tgz|tar|rar|7z|bak)(/|$)#i',
        '#/(backup|backups|dump|db|database|www|web|htdocs|public_html|wwwroot|site|old|new|temp|test)\.(zip|rar|tar|tar\.gz|tgz|7z|gz)(/|$)#i',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (setting('honeypot_enabled', '1') !== '1') {
            return $next($request);
        }

        // path() 去掉查询串和前导斜杠；补回斜杠让 PATTERNS 里的 /前缀 能匹配。
        $path = '/' . $request->path();

        if (!$this->looksLikeProbe($path)) {
            return $next($request);
        }

        $ip = $request->ip();

        if ($this->bannable($ip)) {
            // 同一个扫描器一分钟内会连打几十条，没必要每条都写库。用一个短 TTL 的
            // 缓存标记去重：第一条封禁并写日志，后续的直接 404。
            $firstHit = true;
            try {
                $firstHit = Cache::add('honeypot_seen:' . md5($ip), 1, 120);
            } catch (\Throwable $e) {
                // 缓存不可用就退化成「每次都封」——banIp 是幂等的 updateOrCreate，
                // 顶多多几次写库，不影响正确性。
            }

            if ($firstHit) {
                Blacklist::banIp(
                    $ip,
                    '蜜罐命中: ' . mb_substr($path, 0, 120),
                    (int) setting('honeypot_ban_minutes', 10080),
                );

                Log::warning('Honeypot trapped scanner', [
                    'ip' => $ip,
                    'path' => mb_substr($path, 0, 120),
                    'method' => $request->method(),
                    'ua' => mb_substr((string) $request->userAgent(), 0, 160),
                ]);
            }
        }

        // 一个再普通不过的 404：和「路径本就不存在」无从区分。
        abort(404);
    }

    /**
     * 路径是否命中已知恶意探测。先硬跳过当前后台路径，绝不误伤后台。
     */
    private function looksLikeProbe(string $path): bool
    {
        // 后台路径由 ADMIN_PATH 决定，可能恰好含敏感词，绝不能被蜜罐扫到。
        $adminPrefix = '/' . admin_path();
        if ($path === $adminPrefix || str_starts_with($path, $adminPrefix . '/')) {
            return false;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * 这个 IP 能不能封。
     */
    private function bannable(string $ip): bool
    {
        // 白名单：永久放行（自己的办公网、监控等）。
        $whitelist = array_filter(array_map('trim', explode(',', (string) setting('honeypot_whitelist', ''))));
        if (in_array($ip, $whitelist, true)) {
            return false;
        }

        // 默认只封公网地址：私网 / 环回 / 保留地址多半是内网探测或健康检查，
        // 封了反而影响自己。需要连内网一起封时把 honeypot_skip_reserved_ips 设为 0。
        if (setting('honeypot_skip_reserved_ips', '1') === '1') {
            $isPublic = filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );

            if ($isPublic === false) {
                return false;
            }
        }

        return true;
    }
}
