<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- 后台的挂载路径。前端在运行时读它，作为 React Router 的 basename 和 API 前缀，
         所以改 ADMIN_PATH 不需要重新构建 SPA —— 同一份构建产物适用于任何路径。 --}}
    <meta name="admin-base" content="/{{ admin_path() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ setting('site_name', 'CardShop') }} - 管理后台</title>
    @php
        // Resolve the built SPA entry. Prefer the Vite manifest; fall back to globbing
        // the assets directory so a manifest-less build still boots instead of showing
        // the "not built" page.
        $dir = public_path('admin-assets');
        $jsFiles = [];
        $cssFiles = [];

        $manifestPath = collect([
            $dir . '/.vite/manifest.json',
            $dir . '/manifest.json',
        ])->first(fn ($p) => is_file($p));

        if ($manifestPath) {
            $manifest = json_decode(file_get_contents($manifestPath), true) ?: [];
            $entry = collect($manifest)->first(fn ($e) => ($e['isEntry'] ?? false))
                ?? ($manifest['index.html'] ?? null);

            if ($entry && !empty($entry['file'])) {
                $jsFiles[] = $entry['file'];
                $cssFiles = $entry['css'] ?? [];
            }
        }

        if (empty($jsFiles) && is_dir($dir . '/assets')) {
            foreach (glob($dir . '/assets/*.js') as $p) {
                $jsFiles[] = 'assets/' . basename($p);
            }
            foreach (glob($dir . '/assets/*.css') as $p) {
                $cssFiles[] = 'assets/' . basename($p);
            }
        }

        $built = !empty($jsFiles);
    @endphp

    @foreach($cssFiles as $css)
    <link rel="stylesheet" href="{{ asset('admin-assets/' . $css) }}">
    @endforeach
</head>
<body>
    <div id="root"></div>

    @if($built)
        @foreach($jsFiles as $js)
        <script type="module" src="{{ asset('admin-assets/' . $js) }}"></script>
        @endforeach
    @else
    <div style="max-width:640px;margin:80px auto;padding:0 20px;font-family:system-ui,sans-serif;color:#444;">
        <h2 style="color:#c00;">管理后台资源缺失</h2>
        <p>服务器上没有找到 <code>public/admin-assets/</code> 里的构建产物。</p>
        <p>正常情况下这些文件已经随代码仓库一起提交，请先执行：</p>
        <pre style="background:#f5f5f5;padding:15px;border-radius:4px;overflow:auto;">git pull</pre>
        <p>如果仍然缺失，在一台内存充足的机器上构建后提交：</p>
        <pre style="background:#f5f5f5;padding:15px;border-radius:4px;overflow:auto;">cd admin-frontend
npm install
npm run build
git add ../public/admin-assets && git commit -m "build admin" && git push</pre>
    </div>
    @endif
</body>
</html>
