<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ setting('site_name', 'CardShop') }} - 管理后台</title>
    @php
        $manifest = [];
        $manifestPath = public_path('admin-assets/.vite/manifest.json');
        if (file_exists($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true) ?? [];
        }
        $entry = $manifest['index.html'] ?? null;
    @endphp
    @if($entry)
        @if(isset($entry['css']))
            @foreach($entry['css'] as $css)
            <link rel="stylesheet" href="/admin-assets/{{ $css }}">
            @endforeach
        @endif
    @endif
</head>
<body>
    <div id="root"></div>
    @if($entry)
    <script type="module" src="/admin-assets/{{ $entry['file'] }}"></script>
    @else
    <div style="text-align:center;padding:100px 20px;font-family:sans-serif;color:#666;">
        <h2>管理后台尚未构建</h2>
        <p>请在服务器上运行以下命令构建管理后台：</p>
        <pre style="background:#f5f5f5;padding:15px;border-radius:4px;display:inline-block;text-align:left;">cd admin-frontend
npm install
npm run build</pre>
    </div>
    @endif
</body>
</html>
