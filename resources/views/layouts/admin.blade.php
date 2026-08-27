<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '管理后台') - {{ setting('site_name', '发卡平台') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/css/admin.css">
</head>
<body>

    <!-- 侧边栏 -->
    <div class="admin-sidebar" id="adminSidebar">
        <div class="sidebar-header">
            <a href="/admin" class="sidebar-brand">{{ setting('site_name', '发卡平台') }}</a>
        </div>
        <nav class="sidebar-nav">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('admin') && !request()->is('admin/*') ? 'active' : '' }}" href="/admin">
                        <i class="bi bi-speedometer2"></i>
                        <span>仪表盘</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('admin/categories*') ? 'active' : '' }}" href="/admin/categories">
                        <i class="bi bi-folder"></i>
                        <span>商品分类</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('admin/products*') ? 'active' : '' }}" href="/admin/products">
                        <i class="bi bi-box"></i>
                        <span>商品管理</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('admin/orders*') ? 'active' : '' }}" href="/admin/orders">
                        <i class="bi bi-receipt"></i>
                        <span>订单管理</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('admin/article-categories*') ? 'active' : '' }}" href="/admin/article-categories">
                        <i class="bi bi-tag"></i>
                        <span>文章分类</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('admin/articles*') ? 'active' : '' }}" href="/admin/articles">
                        <i class="bi bi-file-text"></i>
                        <span>文章管理</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('admin/coupons*') ? 'active' : '' }}" href="/admin/coupons">
                        <i class="bi bi-ticket-perforated"></i>
                        <span>优惠码</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('admin/blacklists*') ? 'active' : '' }}" href="/admin/blacklists">
                        <i class="bi bi-shield-x"></i>
                        <span>黑名单</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('admin/logs*') ? 'active' : '' }}" href="/admin/logs">
                        <i class="bi bi-clock-history"></i>
                        <span>操作日志</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ request()->is('admin/settings*') ? 'active' : '' }}" href="/admin/settings">
                        <i class="bi bi-gear"></i>
                        <span>系统设置</span>
                    </a>
                </li>
            </ul>
        </nav>
    </div>

    <!-- 移动端侧边栏遮罩 -->
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <!-- 主内容区 -->
    <div class="admin-main">
        <!-- 顶部导航栏 -->
        <header class="admin-header">
            <div class="d-flex align-items-center">
                <button class="btn btn-link text-dark d-lg-none me-2" id="sidebarToggle" type="button">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <nav aria-label="breadcrumb" class="flex-grow-1">
                    @yield('breadcrumb')
                </nav>
            </div>
            <div class="d-flex align-items-center">
                <span class="text-muted me-3">
                    <i class="bi bi-person-circle me-1"></i>{{ session('admin_username', '管理员') }}
                </span>
                <form action="/admin/logout" method="POST" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-box-arrow-right me-1"></i>退出
                    </button>
                </form>
            </div>
        </header>

        <!-- 提示消息 -->
        @if(session('success'))
            <div class="container-fluid px-4 mt-3">
                <div class="alert alert-success alert-dismissible fade show auto-dismiss" role="alert">
                    <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
                </div>
            </div>
        @endif

        @if(session('error'))
            <div class="container-fluid px-4 mt-3">
                <div class="alert alert-danger alert-dismissible fade show auto-dismiss" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
                </div>
            </div>
        @endif

        <!-- 页面内容 -->
        <div class="admin-content">
            @yield('content')
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/admin.js"></script>
    @stack('scripts')
</body>
</html>
