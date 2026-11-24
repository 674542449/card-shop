@extends(theme_view_path('layout'))

@section('title', '订单查询 - ' . setting('site_name', 'CardShop'))

@section('content')
    <div class="ws-console is-narrow">
        <div class="ws-console-card">
            <div class="ws-panel-head">
                <div>
                    <h1 style="margin:0;font-size:17px;font-weight:800;">订单查询与卡密提取</h1>
                    <span style="display:block;margin-top:2px;">请输入下单时填写的接收邮箱与查询密码以验证订单归属</span>
                </div>
            </div>

            <div class="ws-panel-body">
                <form action="/order/query" method="POST" class="ws-form" autocomplete="off" data-guard>
                    @csrf
                    <div class="ws-hp-field" aria-hidden="true">
                        <label for="query-hp-website">请勿填写此项</label>
                        <input type="text" id="query-hp-website" name="website" value="" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="ws-field">
                        <label class="ws-label" for="query-email">
                            <span>下单邮箱</span>
                            <small>必填</small>
                        </label>
                        <input type="email" id="query-email" name="email"
                               class="ws-input @error('email') is-invalid @enderror"
                               placeholder="请输入下单时填写的接收邮箱"
                               value="{{ old('email') }}" maxlength="200"
                               autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false"
                               data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other" required>
                        @error('email')<span class="ws-field-error" role="alert">{{ $message }}</span>@enderror
                    </div>

                    <div class="ws-field">
                        <label class="ws-label" for="query-password">
                            <span>查询密码</span>
                            <small>必填</small>
                        </label>
                        <div class="ws-secret-wrap">
                            <input type="text" id="query-password" name="query_password"
                                   class="ws-input is-secret-masked @error('query_password') is-invalid @enderror"
                                   placeholder="请输入下单时设置的查询密码"
                                   maxlength="50"
                                   autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false"
                                   data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other"
                                   data-clear-on-restore required>
                            <button type="button" class="ws-secret-toggle" data-secret-toggle="query-password" aria-pressed="false" aria-label="显示查询密码">显示</button>
                        </div>
                        @error('query_password')<span class="ws-field-error" role="alert">{{ $message }}</span>@enderror
                    </div>

                    <div class="ws-field">
                        <label class="ws-label" for="query-order-no">
                            <span>订单编号</span>
                            <small>选填，输入订单编号可精确检索</small>
                        </label>
                        <input type="text" id="query-order-no" name="order_no"
                               class="ws-input @error('order_no') is-invalid @enderror"
                               placeholder="请输入订单编号（选填）"
                               value="{{ old('order_no', $lookupOrderNo ?? '') }}" maxlength="30"
                               autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false"
                               data-lpignore="true" data-1p-ignore="true" data-bwignore="true" data-form-type="other">
                        @error('order_no')<span class="ws-field-error" role="alert">{{ $message }}</span>@enderror
                    </div>

                    @if(turnstile_site_key())
                    <div class="cf-turnstile" data-sitekey="{{ turnstile_site_key() }}"></div>
                    @error('turnstile')<span class="ws-field-error" role="alert">{{ $message }}</span>@enderror
                    @endif

                    <button type="submit" class="ws-btn ws-btn-primary ws-btn-lg" style="width:100%;">验证并查询订单 &rarr;</button>
                </form>
            </div>
        </div>

        <div class="ws-panel-card">
            <div class="ws-panel-body" style="padding:16px 20px;font-size:12.5px;color:var(--ws-muted);">
                <strong style="color:var(--ws-ink-2);">安全说明：</strong>
                系统仅在验证下单邮箱与查询密码一致后开放卡密读取与文件导出权限。在公共设备上完成操作后，请及时关闭当前页面。
            </div>
        </div>
    </div>
@endsection
