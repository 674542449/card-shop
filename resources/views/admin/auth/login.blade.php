<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员登录 - {{ setting('site_name', '发卡平台') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/css/admin.css">
    <style>
        body {
            background-color: #f0f2f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>

    <div class="login-container" style="width: 100%; max-width: 400px; padding: 0 15px;">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <h4 class="fw-bold text-primary mb-1">{{ setting('site_name', '发卡平台') }}</h4>
                    <p class="text-muted small">管理后台登录</p>
                </div>

                @if($errors->any())
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <ul class="mb-0 list-unstyled">
                            @foreach($errors->all() as $error)
                                <li><i class="bi bi-exclamation-circle me-1"></i>{{ $error }}</li>
                            @endforeach
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
                    </div>
                @endif

                @if(session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-circle me-1"></i>{{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
                    </div>
                @endif

                <form action="/admin/login" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label for="username" class="form-label">用户名</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control" id="username" name="username" value="{{ old('username') }}" required autofocus placeholder="请输入用户名">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">密码</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="password" name="password" required placeholder="请输入密码">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-box-arrow-in-right me-1"></i>登录
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
