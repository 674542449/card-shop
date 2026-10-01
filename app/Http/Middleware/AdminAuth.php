<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $adminId = $request->session()->get('admin_id');

        if (!$adminId) {
            if ($request->expectsJson()) {
                return response()->json(['message' => '未登录'], 401);
            }
            return redirect('/' . admin_path() . '/login');
        }

        $admin = Admin::find($adminId);

        if (!$admin || !$admin->is_active) {
            $request->session()->forget('admin_id');
            if ($request->expectsJson()) {
                return response()->json(['message' => '未登录'], 401);
            }
            return redirect('/' . admin_path() . '/login');
        }

        // 把会话绑到当时的密码哈希上。
        //
        // 没有这一道，改密码是踢不掉任何人的：session()->regenerate() 只换当前请求
        // 自己的 session ID，存储里属于同一个管理员的其他会话一个字节都不碰。于是
        // 「发现异常 → 改密码」这个所有人都会做的补救动作，对已经被盗的 cookie 完全
        // 无效，而且 Laravel 每次请求都会续期，攻击者只要保持活动就永不掉线。
        //
        // 绑定之后，任何一处修改密码（后台的改密接口、admin:password 命令、直接改库）
        // 都会让所有旧会话在下一次请求时被拒——包括改密码的人自己那些别的设备。
        //
        // 会话里没有这个值时一律拒绝：这是本次修复之前建立的老会话，没法证明它是在
        // 当前密码下建立的。代价是升级后所有管理员要重新登录一次。
        if (!hash_equals(self::passwordFingerprint($admin->password), (string) $request->session()->get('admin_pw'))) {
            $request->session()->forget(['admin_id', 'admin_username', 'admin_pw']);
            if ($request->expectsJson()) {
                return response()->json(['message' => '登录状态已失效，请重新登录。'], 401);
            }
            return redirect('/' . admin_path() . '/login');
        }

        $request->attributes->set('admin', $admin);
        // Authorize the route Laravel matched, never the caller's raw path. The
        // router decodes percent-encoded segments, while Request::path() does not:
        // /%61dmins used to reach AdminController without matching the owner gate.
        $routeUri = $request->route()?->uri() ?? '';
        $prefix = 'api/'.admin_path().'/';
        $segment = str_starts_with($routeUri, $prefix)
            ? explode('/', substr($routeUri, strlen($prefix)))[0]
            : '';
        // Card routes nested under /products are still secret-inventory actions.
        // Catalog readers and product editors must not inherit permission to
        // extract every unsold card just because the URI starts with products.
        if (str_starts_with($request->route()?->getActionName() ?? '', \App\Http\Controllers\Api\Admin\CardController::class.'@')) {
            $segment = 'cards';
        }
        $areas = ['dashboard' => 'overview', 'categories' => 'catalog', 'products' => 'catalog', 'cards' => 'cards',
            'orders' => 'orders', 'refunds' => 'refunds', 'articles' => 'content', 'article-categories' => 'content',
            'coupons' => 'coupons', 'blacklists' => 'blacklists', 'logs' => 'logs', 'settings' => 'settings',
            'api-tokens' => 'tokens', 'notifications' => 'notifications', 'maintenance' => 'maintenance', 'seo-deliveries' => 'content'];
        // Self-service routes are available to every authenticated administrator.
        // New areas require an explicit policy before any staff account can use them.
        if (!isset($areas[$segment]) && !in_array($segment, ['admins', 'upload', 'me', 'logout', 'password'], true)) {
            return response()->json(['message' => '此后台接口尚未配置访问权限。'], 403);
        }
        if ($segment === 'admins' && $admin->role !== 'owner') { return response()->json(['message' => '仅店主管理员可以管理账户。'], 403); }
        if (isset($areas[$segment]) && !$admin->allows($areas[$segment], $request->isMethodSafe() ? 'read' : 'write')) {
            return response()->json(['message' => '当前账户没有此操作权限。'], 403);
        }
        if ($segment === 'upload' && !$admin->allows('catalog', 'write') && !$admin->allows('content', 'write') && !$admin->allows('settings', 'write')) {
            return response()->json(['message' => '当前账户没有上传权限。'], 403);
        }

        return $next($request);
    }

    /**
     * 存进会话的密码指纹。
     *
     * 存指纹而不是存哈希本身：会话内容在 file 驱动下就是磁盘上的明文文件，在 redis
     * 驱动下是一个可被 KEYS 扫到的键，没必要把 bcrypt 哈希再复制一份进去。
     */
    public static function passwordFingerprint(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }
}
