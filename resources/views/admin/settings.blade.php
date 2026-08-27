@extends('layouts.admin')

@section('breadcrumb', '系统设置')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">系统设置</h4>
</div>

<ul class="nav nav-tabs" id="settingTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="site-tab" data-bs-toggle="tab" data-bs-target="#site" type="button" role="tab" aria-controls="site" aria-selected="true">站点设置</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="payment-tab" data-bs-toggle="tab" data-bs-target="#payment" type="button" role="tab" aria-controls="payment" aria-selected="false">支付设置</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="email-tab" data-bs-toggle="tab" data-bs-target="#email" type="button" role="tab" aria-controls="email" aria-selected="false">邮件设置</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="telegram-tab" data-bs-toggle="tab" data-bs-target="#telegram" type="button" role="tab" aria-controls="telegram" aria-selected="false">Telegram通知</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="seo-tab" data-bs-toggle="tab" data-bs-target="#seo" type="button" role="tab" aria-controls="seo" aria-selected="false">SEO设置</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab" aria-controls="security" aria-selected="false">安全设置</button>
    </li>
</ul>

<div class="tab-content mt-3" id="settingTabsContent">
    <!-- 站点设置 -->
    <div class="tab-pane fade show active" id="site" role="tabpanel" aria-labelledby="site-tab">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ url('/admin/settings') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="site_name" class="form-label">站点名称</label>
                        <input type="text" name="site_name" id="site_name" class="form-control" value="{{ $settings['site_name'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label for="site_logo" class="form-label">站点Logo URL</label>
                        <input type="text" name="site_logo" id="site_logo" class="form-control" value="{{ $settings['site_logo'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label for="site_description" class="form-label">站点描述</label>
                        <textarea name="site_description" id="site_description" class="form-control">{{ $settings['site_description'] ?? '' }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label for="site_announcement" class="form-label">站点公告</label>
                        <textarea name="site_announcement" id="site_announcement" class="form-control" rows="4">{{ $settings['site_announcement'] ?? '' }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">保存设置</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 支付设置 -->
    <div class="tab-pane fade" id="payment" role="tabpanel" aria-labelledby="payment-tab">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ url('/admin/settings') }}">
                    @csrf
                    <h6 class="mb-3">易支付 (Epay)</h6>
                    <div class="mb-3">
                        <label for="epay_api_url" class="form-label">API 地址</label>
                        <input type="text" name="epay_api_url" id="epay_api_url" class="form-control" value="{{ $settings['epay_api_url'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label for="epay_merchant_id" class="form-label">商户ID</label>
                        <input type="text" name="epay_merchant_id" id="epay_merchant_id" class="form-control" value="{{ $settings['epay_merchant_id'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label for="epay_merchant_key" class="form-label">商户密钥</label>
                        <input type="text" name="epay_merchant_key" id="epay_merchant_key" class="form-control" value="{{ $settings['epay_merchant_key'] ?? '' }}">
                    </div>
                    <hr>
                    <h6 class="mb-3">USDT (EpuSDT)</h6>
                    <div class="mb-3">
                        <label for="epusdt_api_url" class="form-label">API 地址</label>
                        <input type="text" name="epusdt_api_url" id="epusdt_api_url" class="form-control" value="{{ $settings['epusdt_api_url'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label for="epusdt_api_token" class="form-label">API Token</label>
                        <input type="text" name="epusdt_api_token" id="epusdt_api_token" class="form-control" value="{{ $settings['epusdt_api_token'] ?? '' }}">
                    </div>
                    <button type="submit" class="btn btn-primary">保存设置</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 邮件设置 -->
    <div class="tab-pane fade" id="email" role="tabpanel" aria-labelledby="email-tab">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ url('/admin/settings') }}">
                    @csrf
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>SMTP 配置请在 .env 文件中设置 (MAIL_HOST, MAIL_PORT 等)
                    </div>
                    <div class="mb-3">
                        <label for="email_template_subject" class="form-label">邮件模板主题</label>
                        <input type="text" name="email_template_subject" id="email_template_subject" class="form-control" value="{{ $settings['email_template_subject'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label for="email_template_body" class="form-label">邮件模板内容</label>
                        <textarea name="email_template_body" id="email_template_body" class="form-control" rows="8" placeholder="支持变量: {order_no}, {product_name}, {cards}">{{ $settings['email_template_body'] ?? '' }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">保存设置</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Telegram通知 -->
    <div class="tab-pane fade" id="telegram" role="tabpanel" aria-labelledby="telegram-tab">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ url('/admin/settings') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="telegram_bot_token" class="form-label">Bot Token</label>
                        <input type="text" name="telegram_bot_token" id="telegram_bot_token" class="form-control" value="{{ $settings['telegram_bot_token'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label for="telegram_chat_id" class="form-label">Chat ID</label>
                        <input type="text" name="telegram_chat_id" id="telegram_chat_id" class="form-control" value="{{ $settings['telegram_chat_id'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="telegram_enabled" id="telegram_enabled" value="1" {{ ($settings['telegram_enabled'] ?? '') ? 'checked' : '' }}>
                            <label class="form-check-label" for="telegram_enabled">启用通知</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">保存设置</button>
                </form>
            </div>
        </div>
    </div>

    <!-- SEO设置 -->
    <div class="tab-pane fade" id="seo" role="tabpanel" aria-labelledby="seo-tab">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ url('/admin/settings') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="seo_default_title" class="form-label">默认标题</label>
                        <input type="text" name="seo_default_title" id="seo_default_title" class="form-control" value="{{ $settings['seo_default_title'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label for="seo_default_description" class="form-label">默认描述</label>
                        <textarea name="seo_default_description" id="seo_default_description" class="form-control">{{ $settings['seo_default_description'] ?? '' }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label for="seo_default_keywords" class="form-label">默认关键词</label>
                        <input type="text" name="seo_default_keywords" id="seo_default_keywords" class="form-control" value="{{ $settings['seo_default_keywords'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label for="baidu_push_token" class="form-label">百度推送 Token</label>
                        <input type="text" name="baidu_push_token" id="baidu_push_token" class="form-control" value="{{ $settings['baidu_push_token'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label for="bing_indexnow_key" class="form-label">Bing IndexNow Key</label>
                        <input type="text" name="bing_indexnow_key" id="bing_indexnow_key" class="form-control" value="{{ $settings['bing_indexnow_key'] ?? '' }}">
                    </div>
                    <button type="submit" class="btn btn-primary">保存设置</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 安全设置 -->
    <div class="tab-pane fade" id="security" role="tabpanel" aria-labelledby="security-tab">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ url('/admin/settings') }}">
                    @csrf
                    <div class="mb-3">
                        <label for="turnstile_site_key" class="form-label">Turnstile Site Key</label>
                        <input type="text" name="turnstile_site_key" id="turnstile_site_key" class="form-control" value="{{ $settings['turnstile_site_key'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label for="turnstile_secret_key" class="form-label">Turnstile Secret Key</label>
                        <input type="text" name="turnstile_secret_key" id="turnstile_secret_key" class="form-control" value="{{ $settings['turnstile_secret_key'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label for="order_expire_minutes" class="form-label">订单过期时间（分钟）</label>
                        <input type="number" name="order_expire_minutes" id="order_expire_minutes" class="form-control" value="{{ $settings['order_expire_minutes'] ?? '30' }}">
                    </div>
                    <button type="submit" class="btn btn-primary">保存设置</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Tab hash persistence
    document.addEventListener('DOMContentLoaded', function () {
        var hash = window.location.hash;
        if (hash) {
            var tab = document.querySelector('#settingTabs button[data-bs-target="' + hash + '"]');
            if (tab) {
                new bootstrap.Tab(tab).show();
            }
        }
        document.querySelectorAll('#settingTabs button[data-bs-toggle="tab"]').forEach(function (tab) {
            tab.addEventListener('shown.bs.tab', function (e) {
                window.location.hash = e.target.getAttribute('data-bs-target');
            });
        });
    });
</script>
@endpush
