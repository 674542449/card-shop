@if(!empty($contactQr))
<dialog id="contact-qr-dialog" class="cs-qr-dialog" closedby="any" aria-labelledby="contact-qr-dialog-title">
    <div class="cs-qr-dialog-surface">
        <div class="cs-qr-dialog-head">
            <h2 id="contact-qr-dialog-title" class="cs-qr-dialog-title">客服二维码</h2>
            <form method="dialog" class="cs-qr-dialog-close-form">
                <button type="submit" class="cs-qr-dialog-close" aria-label="关闭二维码放大窗口" autofocus>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M18 6 6 18M6 6l12 12"/>
                    </svg>
                </button>
            </form>
        </div>
        <div class="cs-qr-dialog-body">
            <div class="cs-qr-dialog-frame">
                <img src="{{ $contactQr }}" alt="客服联系二维码（放大视图）" class="cs-qr-dialog-img" loading="lazy" decoding="async">
            </div>
            <p class="cs-qr-dialog-caption">{{ !empty($contactText) ? $contactText : '请使用手机扫描上方二维码联系在线客服' }}</p>
            @if(!empty($contactUrl))
            <a href="{{ $contactUrl }}" class="cs-qr-dialog-link" target="_blank" rel="noopener">直接前往在线客服 ↗</a>
            @endif
        </div>
        <form method="dialog" class="cs-qr-dialog-footer">
            <button type="submit" class="cs-qr-dialog-dismiss">关闭</button>
        </form>
    </div>
</dialog>
@endif
