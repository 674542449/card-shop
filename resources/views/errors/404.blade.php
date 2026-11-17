<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, follow">
    <title>页面不存在（404）</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f6f9;
            --surface: #ffffff;
            --ink: #1e2532;
            --muted: #5b6577;
            --accent: #2f5fd0;
            --btn-sec: #edf0f5;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #131720;
                --surface: #1b212c;
                --ink: #e6eaf2;
                --muted: #9aa4b6;
                --accent: #3b6be0;
                --btn-sec: #262e3d;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: system-ui, -apple-system, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background: var(--bg);
            color: var(--ink);
        }
        main {
            width: 100%;
            max-width: 460px;
            background: var(--surface);
            border-radius: 12px;
            padding: 32px 24px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.10);
        }
        .code {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            background: rgba(8, 102, 255, 0.12);
            color: var(--accent);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 12px;
        }
        h1 {
            margin: 0 0 10px;
            font-size: 22px;
            line-height: 1.35;
        }
        p {
            margin: 0 0 24px;
            font-size: 14px;
            line-height: 1.6;
            color: var(--muted);
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
        }
        a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 18px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
        }
        .primary {
            background: var(--accent);
            color: #ffffff;
        }
        .secondary {
            background: var(--btn-sec);
            color: var(--ink);
        }
    </style>
</head>
<body>
    <main>
        <span class="code">HTTP 404</span>
        <h1>您访问的页面不存在或已下架</h1>
        <p>该链接可能已变更或对应商品已调整，请返回商品商城浏览最新在售区域与教程指南。</p>
        <nav class="actions" aria-label="错误页导航">
            <a href="/" class="primary">返回商品商城</a>
            <a href="/order/query" class="secondary">订单查询</a>
            <a href="/articles" class="secondary">帮助中心</a>
        </nav>
    </main>
</body>
</html>
