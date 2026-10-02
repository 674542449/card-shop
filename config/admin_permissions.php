<?php

// This definition is shared by route policies, account validation and the SPA.
return [
    'areas' => [
        'overview' => '概览', 'catalog' => '商品信息', 'cards' => '卡密（敏感内容）',
        'payments' => '人工收款确认', 'orders' => '订单', 'refunds' => '退款',
        'content' => '内容与推送', 'coupons' => '优惠券', 'blacklists' => '黑名单',
        'logs' => '审计日志', 'settings' => '系统设置', 'tokens' => 'API 令牌',
        'notifications' => '通知', 'maintenance' => '维护',
    ],
    'combinations' => [
        'self' => ['authenticated' => true],
        'accounts' => ['owner' => true],
        'upload' => ['any' => ['catalog:write', 'content:write', 'settings:write']],
        'orders.mark_paid' => ['all' => ['orders:write', 'payments:write']],
        'orders.replace_cards' => ['all' => ['orders:write', 'cards:write']],
        'orders.refund' => ['all' => ['orders:write', 'refunds:write']],
        'settings.test_email' => ['owner' => true, 'all' => ['settings:write']],
        'backups.read' => ['owner' => true, 'all' => ['maintenance:read']],
        'backups.write' => ['owner' => true, 'all' => ['maintenance:write']],
        'health.acknowledge' => ['all' => ['maintenance:write']],
    ],
    'pages' => [
        ['path' => '/', 'capability' => 'overview:read'],
        ['path' => '/account', 'capability' => 'self'],
        ['path' => '/admins', 'capability' => 'accounts'],
        ['path' => '/products/{id}/cards', 'capability' => 'cards:read'],
        ['path' => '/categories', 'capability' => 'catalog:read', 'children' => true],
        ['path' => '/products', 'capability' => 'catalog:read', 'children' => true],
        ['path' => '/orders', 'capability' => 'orders:read', 'children' => true],
        ['path' => '/refunds', 'capability' => 'refunds:read'],
        ['path' => '/articles', 'capability' => 'content:read', 'children' => true],
        ['path' => '/article-categories', 'capability' => 'content:read'],
        ['path' => '/coupons', 'capability' => 'coupons:read'],
        ['path' => '/blacklists', 'capability' => 'blacklists:read'],
        ['path' => '/logs', 'capability' => 'logs:read'],
        ['path' => '/settings', 'capability' => 'settings:read'],
        ['path' => '/api-tokens', 'capability' => 'tokens:read'],
        ['path' => '/notifications', 'capability' => 'notifications:read'],
        ['path' => '/operations', 'any' => ['maintenance:read', 'content:read']],
    ],
];
