<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Support\Seo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    private array $baseMailConfig;
    private bool $configuredSmtp = false;

    public function __construct()
    {
        $this->baseMailConfig = config('mail');
    }

    /**
     * Point the mailer at the SMTP server configured in the admin.
     *
     * Mail settings used to live only in .env, which meant the one thing an operator
     * most needs to change after install — where the card secrets are sent from —
     * required SSH, a file edit and a container restart. They are ordinary settings
     * rows now, and this applies them over the config just before a send.
     *
     * Falls through to whatever .env provides when no host is configured, so an
     * install that already had working .env mail keeps working untouched.
     */
    private function configureMailer(): void
    {
        $host = trim((string) setting('mail_host', ''));

        if ($host === '') {
            // A durable worker can see the host cleared after a previous send.
            // Restore the environment configuration instead of retaining old credentials.
            if ($this->configuredSmtp) {
                config(['mail' => $this->baseMailConfig]);
                Mail::purge('smtp');
                $this->configuredSmtp = false;
            }
            return;
        }

        // `scheme` rather than `encryption`. Laravel derives the scheme from
        // encryption only for the exact string 'tls', so 'ssl' silently produced a
        // plaintext connection on port 465 — the port that requires implicit TLS.
        // Setting the scheme directly makes the operator's choice mean what it says.
        $encryption = (string) setting('mail_encryption', 'ssl');
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.invalid_configuration' => false,
            'mail.mailers.smtp.scheme' => $scheme,
            'mail.mailers.smtp.auto_tls' => $encryption === 'tls',
            'mail.mailers.smtp.require_tls' => in_array($encryption, ['ssl', 'tls'], true),
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => (int) setting('mail_port', 465),
            'mail.mailers.smtp.username' => (string) setting('mail_username', ''),
            'mail.mailers.smtp.password' => (string) setting('mail_password', ''),
            'mail.mailers.smtp.timeout' => 15,
            'mail.from.address' => (string) setting('mail_from_address', ''),
            'mail.from.name' => (string) setting('mail_from_name', setting('site_name', 'CardShop')),
        ]);

        // The manager caches a resolved mailer per name, built from the config as it
        // was at first use. Without this, changing the settings would not take effect
        // until the PHP worker was recycled — which looks exactly like "saving the
        // form does nothing".
        Mail::purge('smtp');
        $this->configuredSmtp = true;
    }

    public static function isEmailEnabled(): bool
    {
        $raw = setting('mail_enabled');
        if ($raw === null || $raw === '') {
            return true;
        }

        return self::isTruthySetting('mail_enabled');
    }

    public function emailEnabled(): bool
    {
        return self::isEmailEnabled();
    }

    /**
     * Send a test message to prove the SMTP settings work.
     *
     * The whole delivery path is failure-tolerant by design — a send that throws is
     * logged and swallowed so a dead mail server cannot break a sale. That is right,
     * but it leaves the operator no way to find out their settings are wrong except
     * by a buyer complaining. Report a safe error category: SMTP exception text can
     * include AUTH credentials, a server URL with userinfo, or the delivery body.
     * Returning it or writing it to a log can disclose passwords and card secrets.
     *
     * @return array{ok: bool, message: string}
     */
    public function sendTestEmail(string $to): array
    {
        if (!$this->emailEnabled()) {
            return ['ok' => false, 'message' => 'SMTP 邮件投递服务已关闭，请先开启并保存后再发送测试邮件。'];
        }

        try {
            $this->configureMailer();

            $siteName = (string) setting('site_name', '卡密商城');
            $sentAt = now()->format('Y-m-d H:i:s');

            Mail::html(
                '<p>这是一封测试邮件。</p><p>如果你收到了它，说明 SMTP 设置正确，'
                . '订单卡密可以正常发送。</p><p>站点：' . e($siteName) . '<br>时间：' . e($sentAt) . '</p>',
                fn ($message) => $message->to($to)->subject($siteName . ' - SMTP 测试邮件')
            );

            return ['ok' => true, 'message' => "测试邮件已发送至 {$to}，请查收（也看一下垃圾邮件箱）。"];
        } catch (\Throwable $e) {
            $message = $this->safeMailFailure($e);
            Log::warning('SMTP test failed', ['to' => $to, 'error_type' => get_class($e), 'error' => $message]);

            return ['ok' => false, 'message' => $message];
        }
    }

    /**
     * Send order completion email with card details to the buyer.
     */
    /**
     * @return bool Whether the mail was handed to the transport without error.
     *
     * It returns a value because the caller has to know. This used to be `void` with
     * a catch-all that only wrote a log line, so 补发卡密 in the admin reported
     * "卡密已重新发送" whether or not anything was sent — and the shipped .env.example
     * has MAIL_HOST and MAIL_FROM_ADDRESS empty, which throws before the transport is
     * even reached. The one tool for repairing a failed delivery was as blind as the
     * failure it existed to repair.
     */
    public function sendOrderEmail(Order $order): bool
    {
        if (!$this->emailEnabled()) {
            return false;
        }

        try {
            $this->configureMailer();

            $order->loadMissing('product');

            // The seeder and the admin Settings screen both use `email_template_body`;
            // `email_template` is kept only as a fallback for older installs.
            $template = (string) setting(
                'email_template_body',
                setting('email_template', $this->defaultEmailTemplate())
            );
            $siteName = (string) setting('site_name', '卡密商城');

            $cards = $order->deliveryCards()->pluck('content')->implode("\n");

            $amount = bcadd((string) $order->total_amount, '0', 2);

            // {{total_amount}} is the name used by the seeded template, {{amount}} by the
            // built-in default. Support both so either template renders correctly.
            $values = [
                '{{site_name}}' => $siteName,
                '{{order_no}}' => $order->order_no,
                '{{product_name}}' => $order->displayName(),
                '{{quantity}}' => (string) $order->quantity,
                '{{amount}}' => $amount,
                '{{total_amount}}' => $amount,
                '{{cards}}' => $cards,
            ];

            // {{cards}} was substituted raw. Card content is arbitrary operator-uploaded
            // text: one containing < or & renders as broken markup inside the <pre>, so
            // the buyer receives a card secret that is silently truncated or mangled —
            // and a card containing a tag injects it into the email body.
            $body = strtr($template, array_map(fn ($v) => e($v), $values));

            // Mail::html sends text/html, and the SEEDED template is pure plain text
            // whose entire structure is newlines — HTML collapses every one of them,
            // so on a fresh install the buyer's card secrets arrived as a single
            // run-on paragraph. The built-in fallback template below wraps {{cards}}
            // in <pre> and needs no help, hence the test for markup rather than a
            // blanket nl2br.
            if (!preg_match('/<[a-z][^>]*>/i', $template)) {
                $body = nl2br($body);
            }

            // The subject is a mail header, not HTML. Escaping it turned a site name
            // with an & into "A&amp;B" in the buyer's inbox, so it gets the raw values.
            $subject = strtr(
                (string) setting('email_template_subject', '{{site_name}} - 订单 {{order_no}} 卡密信息'),
                $values
            );

            Mail::html($body, function ($message) use ($order, $subject) {
                $message->to($order->email)->subject($subject);
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send order email', [
                'order_no' => $order->order_no,
                'email' => $order->email,
                'error_type' => get_class($e),
                'error' => $this->safeMailFailure($e),
            ]);

            return false;
        }
    }

    /** Refund mail contains only buyer-facing fields, never internal notes or card contents. */
    public function sendRefundEmail(Order $order, array $refund): bool
    {
        if (!$this->emailEnabled()) {
            return false;
        }

        try {
            $this->configureMailer();
            $siteName = (string) setting('site_name', '卡密商城');
            $label = ['requested' => '申请已提交，待审核', 'approved' => '已批准，待退款', 'completed' => '已退款', 'rejected' => '申请已拒绝'][$refund['status']] ?? '状态已更新';
            $body = '<h2>'.e($siteName).' - 退款进度</h2><p>订单：'.e($order->order_no).'</p><p>退款金额：¥'.e($refund['amount']).'</p><p>处理结果：'.e($label).'</p>';
            if (! empty($refund['customer_note'])) {
                $body .= '<p>给您的说明：<br>'.nl2br(e($refund['customer_note'])).'</p>';
            }
            if (! empty($refund['completed_at'])) {
                $body .= '<p>退款完成时间：'.e($refund['completed_at']).'</p>';
            }
            $body .= '<p><a href="'.e(\App\Support\Seo::url('/order/query')).'">查询订单与退款记录</a></p>';
            Mail::html($body, fn ($message) => $message->to($order->email)->subject($siteName.' - 订单 '.$order->order_no.' 退款进度'));
            return true;
        } catch (\Throwable $exception) {
            Log::warning('Refund email failed', ['order_no' => $order->order_no, 'error_type' => $exception::class]);
            return false;
        }
    }

    /** Send a Telegram notification message. */
    public function telegramConfigured(): bool
    {
        return self::isTruthySetting('telegram_enabled')
            && self::isValidBotToken(trim((string) setting('telegram_bot_token', '')))
            && trim((string) setting('telegram_chat_id', '')) !== '';
    }

    public static function isValidBotToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9:_-]{6,200}$/D', $token) === 1;
    }

    /**
     * Parse a single Telegram target destination:
     * - Private DM / Group / Supergroup / Channel numeric ID: 123456789, -987654321, -1001234567890
     * - Public Channel / Group username or link: @channel_name, https://t.me/channel_name
     * - Private t.me/c/ link: https://t.me/c/1234567890 -> -1001234567890
     * - Optional forum topic thread suffix: -1001234567890#12 or -1001234567890:12
     *
     * @return ?array{key: string, chat_id: string, message_thread_id: ?int, display: string}
     */
    public static function parseTelegramTargetEntry(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        $base = $trimmed;
        $threadId = null;
        if (preg_match('/^(.*[^:#\s])[:#](\d{1,12})$/D', $trimmed, $threadMatch)) {
            $base = trim($threadMatch[1]);
            $threadId = (int) $threadMatch[2];
            if ($threadId < 1) {
                return null;
            }
        }

        $chatId = null;
        if (preg_match('#^(?:https?://)?t\.me/c/(\d{5,18})/?$#i', $base, $privateLinkMatch)) {
            $chatId = '-100' . $privateLinkMatch[1];
        } elseif (preg_match('#^(?:https?://)?t\.me/([A-Za-z][A-Za-z0-9_]{3,63})/?$#i', $base, $publicLinkMatch)) {
            $chatId = '@' . $publicLinkMatch[1];
        } elseif (preg_match('/^-?\d{1,20}$/D', $base) && $base !== '0' && $base !== '-0') {
            $chatId = $base;
        } elseif (preg_match('/^@[A-Za-z][A-Za-z0-9_]{3,63}$/D', $base)) {
            $chatId = $base;
        }

        if ($chatId === null) {
            return null;
        }

        $key = $threadId !== null ? "{$chatId}#{$threadId}" : $chatId;
        $display = $threadId !== null ? "{$chatId}（话题 #{$threadId}）" : $chatId;

        return [
            'key' => $key,
            'chat_id' => $chatId,
            'message_thread_id' => $threadId,
            'display' => $display,
        ];
    }

    /**
     * Split and deduplicate comma/semicolon/newline-separated Telegram targets.
     *
     * @return list<array{key: string, chat_id: string, message_thread_id: ?int, display: string}>
     */
    public static function parseTelegramTargets(?string $raw): array
    {
        $entries = preg_split('/[\r\n,，;；]+/u', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parsed = [];
        foreach ($entries as $entry) {
            $item = self::parseTelegramTargetEntry($entry);
            if ($item !== null && !isset($parsed[$item['key']])) {
                $parsed[$item['key']] = $item;
            }
        }

        return array_values($parsed);
    }

    /**
     * Returns the first invalid entry in a raw target list, or null when all entries are valid.
     */
    public static function firstInvalidTelegramTarget(?string $raw, bool $singleOnly = false): ?string
    {
        $entries = preg_split('/[\r\n,，;；]+/u', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($entries === []) {
            return null;
        }
        if ($singleOnly && count($entries) > 1) {
            return $entries[1];
        }
        foreach ($entries as $entry) {
            if (self::parseTelegramTargetEntry($entry) === null) {
                return trim($entry);
            }
        }

        return null;
    }

    public function telegramBroadcastBotToken(): string
    {
        $dedicated = trim((string) setting('telegram_broadcast_bot_token', ''));
        if ($dedicated !== '') {
            return self::isValidBotToken($dedicated) ? $dedicated : '';
        }

        $primary = trim((string) setting('telegram_bot_token', ''));

        return self::isValidBotToken($primary) ? $primary : '';
    }

    /**
     * Resolve configured destinations for a target kind ('bot' | 'group' | 'channel').
     *
     * @return list<array{key: string, chat_id: string, message_thread_id: ?int, display: string}>
     */
    public function targetsForKind(string $kind): array
    {
        return match ($kind) {
            'bot' => self::isTruthySetting('telegram_target_bot_enabled')
                ? self::parseTelegramTargets(
                    trim((string) setting('telegram_target_bot_chat_ids', '')) !== ''
                        ? (string) setting('telegram_target_bot_chat_ids', '')
                        : (string) setting('telegram_chat_id', '')
                )
                : [],
            'group' => self::isTruthySetting('telegram_target_group_enabled')
                ? self::parseTelegramTargets((string) setting('telegram_target_group_ids', ''))
                : [],
            'channel' => self::isTruthySetting('telegram_target_channel_enabled')
                ? self::parseTelegramTargets((string) setting('telegram_target_channel_ids', ''))
                : [],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public function enabledBroadcastTargetKinds(): array
    {
        if ($this->telegramBroadcastBotToken() === '') {
            return [];
        }

        $kinds = [];
        foreach (['bot', 'group', 'channel'] as $kind) {
            if ($this->targetsForKind($kind) !== []) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    public function productSoldNotificationConfigured(): bool
    {
        return self::isTruthySetting('telegram_notify_product_sold')
            && $this->enabledBroadcastTargetKinds() !== [];
    }

    public function productListedNotificationConfigured(): bool
    {
        return self::isTruthySetting('telegram_notify_product_listed')
            && $this->enabledBroadcastTargetKinds() !== [];
    }

    public function isBroadcastKindActive(string $type, string $targetKind): bool
    {
        $eventEnabled = match ($type) {
            'product_sold' => self::isTruthySetting('telegram_notify_product_sold'),
            'product_listed' => self::isTruthySetting('telegram_notify_product_listed'),
            default => false,
        };

        return $eventEnabled
            && $this->telegramBroadcastBotToken() !== ''
            && $this->targetsForKind($targetKind) !== [];
    }

    public function sendTelegramNotification(string $message): bool
    {
        if (!self::isTruthySetting('telegram_enabled')) {
            return false;
        }

        $token = trim((string) setting('telegram_bot_token', ''));
        $chatId = trim((string) setting('telegram_chat_id', ''));

        if ($token === '' || $chatId === '' || !self::isValidBotToken($token)) {
            return false;
        }

        $parsed = self::parseTelegramTargetEntry($chatId);
        $body = [
            'chat_id' => $parsed['chat_id'] ?? $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
        ];
        if ($parsed !== null && $parsed['message_thread_id'] !== null) {
            $body['message_thread_id'] = $parsed['message_thread_id'];
        }

        try {
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", $body);

            if (!$response->successful()) {
                Log::warning('Telegram notification failed', [
                    'status' => $response->status(),
                    // A remote response is untrusted and can echo the token or
                    // notification text. Status is enough to diagnose a rejection.
                ]);
            }
            return $response->successful() && $response->json('ok') === true;
        } catch (\Throwable $e) {
            Log::error('Telegram notification exception', [
                'error_type' => get_class($e),
            ]);
            return false;
        }
    }

    /**
     * Deliver a product_sold or product_listed broadcast notification to all targets in a kind.
     *
     * @return array{ok: bool, skipped: bool, sent_targets: list<string>, error: ?string}
     */
    public function deliverBroadcastNotification(
        string $type,
        string $targetKind,
        ?Order $order,
        ?Product $product,
        array $payload = [],
    ): array {
        $alreadySent = array_values(array_filter((array) ($payload['sent_targets'] ?? []), 'is_string'));

        if (!$this->isBroadcastKindActive($type, $targetKind)) {
            return ['ok' => true, 'skipped' => true, 'sent_targets' => $alreadySent, 'error' => null];
        }

        $message = match ($type) {
            'product_sold' => $this->buildProductSoldMessage($order, $product, $targetKind, $payload),
            'product_listed' => $product ? $this->buildProductListedMessage($product, $payload) : null,
            default => null,
        };

        if ($message === null) {
            return ['ok' => true, 'skipped' => true, 'sent_targets' => $alreadySent, 'error' => null];
        }

        $token = $this->telegramBroadcastBotToken();
        $targets = $this->targetsForKind($targetKind);
        $firstFailureStatus = null;
        $failed = false;

        foreach ($targets as $target) {
            if (in_array($target['key'], $alreadySent, true)) {
                continue;
            }
            $result = $this->postTelegramTarget($token, $target, $message);
            if ($result['ok']) {
                $alreadySent[] = $target['key'];
            } else {
                $failed = true;
                $firstFailureStatus ??= $result['status'];
            }
        }

        $sentTargets = array_values(array_unique($alreadySent));

        if ($failed) {
            return [
                'ok' => false,
                'skipped' => false,
                'sent_targets' => $sentTargets,
                'error' => $this->safeTelegramFailure($targetKind, $firstFailureStatus),
            ];
        }

        return ['ok' => true, 'skipped' => false, 'sent_targets' => $sentTargets, 'error' => null];
    }

    /**
     * Send a live test push from Admin Settings to verify Bot Token & target permissions.
     *
     * @return array{ok: bool, message: string}
     */
    public function sendTestTelegram(string $scope = 'auto', ?string $customTarget = null): array
    {
        $siteName = (string) setting('site_name', '卡密商城');
        $sentAt = now()->format('Y-m-d H:i:s');
        $customTarget = trim((string) $customTarget);

        if ($customTarget !== '') {
            $parsed = self::parseTelegramTargetEntry($customTarget);
            if ($parsed === null) {
                return [
                    'ok' => false,
                    'message' => '测试目标格式不正确，请填写数字 Chat ID（如 123456789、-1001234567890#话题ID）或 @频道用户名。',
                ];
            }
            $token = $scope === 'admin'
                ? (self::isValidBotToken(trim((string) setting('telegram_bot_token', ''))) ? trim((string) setting('telegram_bot_token', '')) : '')
                : $this->telegramBroadcastBotToken();
            if ($token === '') {
                return ['ok' => false, 'message' => '请先填写有效的 Telegram 机器人 Token（Bot Token）。'];
            }

            $text = "✅ <b>Telegram 连通性测试成功</b>\n\n"
                . "🏪 站点：<b>" . e($siteName) . "</b>\n"
                . "🎯 目标：<code>" . e($parsed['display']) . "</code>\n"
                . "🕒 时间：" . e($sentAt) . "\n\n"
                . "机器人已可正常向此会话发送商品售出与上架通知。";

            $res = $this->postTelegramTarget($token, $parsed, $text);
            if ($res['ok']) {
                return ['ok' => true, 'message' => "测试消息已成功发送至 {$parsed['display']}，请在 Telegram 中查收。"];
            }

            return ['ok' => false, 'message' => $this->safeTelegramFailure('custom', $res['status'], $parsed['display'])];
        }

        /** @var list<array{kind: string, label: string, token: string, target: array{key: string, chat_id: string, message_thread_id: ?int, display: string}}> $plan */
        $plan = [];
        $seen = [];
        $pushPlan = function (string $kind, string $label, string $token, array $target) use (&$plan, &$seen): void {
            $dedupe = $token . '|' . $target['key'];
            if (isset($seen[$dedupe])) {
                return;
            }
            $seen[$dedupe] = true;
            $plan[] = ['kind' => $kind, 'label' => $label, 'token' => $token, 'target' => $target];
        };

        $primaryToken = trim((string) setting('telegram_bot_token', ''));
        $primaryToken = self::isValidBotToken($primaryToken) ? $primaryToken : '';
        $broadcastToken = $this->telegramBroadcastBotToken();

        if (in_array($scope, ['auto', 'admin'], true) && self::isTruthySetting('telegram_enabled')) {
            $adminChat = self::parseTelegramTargetEntry((string) setting('telegram_chat_id', ''));
            if ($primaryToken !== '' && $adminChat !== null) {
                $pushPlan('admin', '站长告警会话', $primaryToken, $adminChat);
            }
        }

        $kindLabels = [
            'bot' => '机器人私聊',
            'group' => 'Telegram 群组',
            'channel' => 'Telegram 频道',
        ];
        foreach ($kindLabels as $kind => $label) {
            if (!in_array($scope, ['auto', 'broadcast', $kind], true)) {
                continue;
            }
            if ($broadcastToken === '') {
                continue;
            }
            foreach ($this->targetsForKind($kind) as $target) {
                $pushPlan($kind, $label, $broadcastToken, $target);
            }
        }

        if ($plan === []) {
            if ($primaryToken === '' && $broadcastToken === '') {
                return ['ok' => false, 'message' => '请先填写有效的 Telegram 机器人 Token（Bot Token）。'];
            }

            return [
                'ok' => false,
                'message' => '当前没有已启用且填写了目标 ID 的推送通道。请先开启需要推送的目标（机器人私聊 / 群组 / 频道）并填写会话 ID，或在测试框中输入临时目标。',
            ];
        }

        $succeeded = 0;
        $errors = [];
        foreach ($plan as $item) {
            $text = "✅ <b>Telegram 通知连通性测试成功</b>\n\n"
                . "🏪 站点：<b>" . e($siteName) . "</b>\n"
                . "📡 通道：" . e($item['label']) . "（<code>" . e($item['target']['display']) . "</code>）\n"
                . "🕒 时间：" . e($sentAt) . "\n\n"
                . "当前机器人配置正常，商品售出与上架通知将自动推送至此会话。";

            $res = $this->postTelegramTarget($item['token'], $item['target'], $text);
            if ($res['ok']) {
                $succeeded++;
            } else {
                $errors[] = $this->safeTelegramFailure($item['kind'], $res['status'], "{$item['label']} {$item['target']['display']}");
            }
        }

        if ($errors !== []) {
            $prefix = $succeeded > 0 ? "部分目标发送成功（成功 {$succeeded} 个，失败 " . count($errors) . " 个）：" : '';

            return ['ok' => false, 'message' => $prefix . implode('；', $errors)];
        }

        return ['ok' => true, 'message' => "测试通知已全部发送成功（共 {$succeeded} 个目标会话），请在 Telegram 中查收。"];
    }

    /**
     * Send Telegram notification for a new paid order.
     */
    public function notifyNewOrder(Order $order): bool
    {
        $order->loadMissing('product');

        $method = $this->paymentMethodLabel((string) $order->payment_method);

        // Escaped because sendTelegramNotification() posts with parse_mode=HTML.
        // A product named "<VIP>" or an email containing < made Telegram reject the
        // whole message with a 400, so the operator got no notification at all for
        // exactly those sales — a silent gap, since the send failure only logs.
        $name = e($order->displayName());
        $email = e($order->email);

        $message = "<b>新订单通知</b>\n\n"
            . "订单号: <code>" . e($order->order_no) . "</code>\n"
            . "商品: {$name}\n"
            . "数量: {$order->quantity}\n"
            . "金额: ¥{$order->total_amount}\n"
            . "支付方式: " . e($method) . "\n"
            . "邮箱: {$email}";

        return $this->sendTelegramNotification($message);
    }

    public function buildProductSoldMessage(?Order $order, ?Product $product, string $targetKind, array $payload = []): ?string
    {
        if ($order) {
            $order->loadMissing(['product.category']);
            $product ??= $order->product;
        } elseif ($product) {
            $product->loadMissing('category');
        }

        $productName = $order ? $order->displayName() : (string) ($product?->name ?? ($payload['product_name'] ?? ''));
        if (trim($productName) === '') {
            return null;
        }

        $categoryName = (string) ($product?->category?->name ?? ($payload['category_name'] ?? ''));
        $quantity = $order ? (int) $order->quantity : max(1, (int) ($payload['quantity'] ?? 1));
        $amount = $order
            ? bcadd((string) $order->total_amount, '0', 2)
            : bcadd((string) ($payload['amount'] ?? $product?->price ?? '0'), '0', 2);

        $lines = [
            "🎉 <b>商品售出通知</b>",
            '',
            '📦 商品：<b>' . e($productName) . '</b>',
        ];

        if ($categoryName !== '') {
            $lines[] = '🏷 分类：' . e($categoryName);
        }

        $lines[] = "🔢 售出数量：{$quantity} 件";
        $lines[] = '💰 成交金额：¥' . e($amount);

        // Only private bot DMs see internal order number / payment channel;
        // public groups and channels NEVER receive buyer email or order identifiers.
        if ($targetKind === 'bot') {
            if ($order) {
                $lines[] = '🧾 订单号：<code>' . e($order->order_no) . '</code>';
                $lines[] = '💳 支付方式：' . e($this->paymentMethodLabel((string) $order->payment_method));
            } elseif (($payload['source'] ?? '') === 'offline_card') {
                $lines[] = '💳 销售方式：后台线下登记售出';
            }
        }

        if ($product) {
            $remaining = $product->cards()->where('status', 'unsold')->count();
            $isListed = $product->is_active && (!$product->category || $product->category->is_active);
            $lines[] = $remaining > 0
                ? "📊 剩余库存：{$remaining} 件"
                : '📊 剩余库存：已售罄';

            if ($isListed && $product->slug) {
                $url = e(Seo::url('/product/' . $product->slug));
                $lines[] = $remaining > 0
                    ? "🛒 立即购买：<a href=\"{$url}\">点击前往选购</a>"
                    : "🔗 商品页面：<a href=\"{$url}\">点击查看详情</a>";
            }
        }

        return implode("\n", $lines);
    }

    public function buildProductListedMessage(Product $product, array $payload = []): string
    {
        $product->loadMissing(['category', 'wholesalePrices']);

        $event = (string) ($payload['event'] ?? 'listed');
        $addedCount = max(0, (int) ($payload['added_count'] ?? 0));
        $stock = $product->cards()->where('status', 'unsold')->count();

        $headline = match ($event) {
            'created' => '🚀 <b>新商品上架通知</b>',
            'restocked' => '📦 <b>商品补货上架通知</b>',
            default => '🔔 <b>商品上架通知</b>',
        };

        $lines = [
            $headline,
            '',
            '📦 商品：<b>' . e($product->name) . '</b>',
        ];

        if ($product->category) {
            $lines[] = '🏷 分类：' . e($product->category->name);
        }

        $price = bcadd((string) $product->price, '0', 2);
        $lines[] = '💰 商品售价：¥' . e($price);

        if ($product->wholesalePrices->isNotEmpty()) {
            $tiers = $product->wholesalePrices
                ->sortBy('min_quantity')
                ->map(fn ($tier) => (int) $tier->min_quantity . '件起 ¥' . e(bcadd((string) $tier->price, '0', 2)))
                ->implode(' / ');
            $lines[] = '💎 批发优惠：' . $tiers;
        }

        if ($event === 'restocked' && $addedCount > 0) {
            $lines[] = "📊 库存动态：本次补货 +{$addedCount} 件（当前可售 {$stock} 件）";
        } else {
            $lines[] = $stock > 0
                ? "📊 可售库存：{$stock} 件"
                : '📊 可售库存：0 件（即将补货开售）';
        }

        $excerpt = Seo::excerpt($product->description, 80);
        if ($excerpt !== '') {
            $lines[] = '📝 商品简介：' . e($excerpt);
        }

        if ($product->slug) {
            $url = e(Seo::url('/product/' . $product->slug));
            $lines[] = "🛒 立即购买：<a href=\"{$url}\">点击前往选购</a>";
        }

        return implode("\n", $lines);
    }

    private static function isTruthySetting(string $key): bool
    {
        return in_array((string) setting($key, '0'), ['1', 'true'], true);
    }

    private function paymentMethodLabel(string $method): string
    {
        return [
            'alipay' => '支付宝',
            'wechat' => '微信支付',
            'usdt_trc20' => 'USDT (TRC20)',
            'usdt_bep20' => 'USDT (BEP20)',
            'usdt_polygon' => 'USDT (Polygon)',
            'manual' => '人工确认',
        ][$method] ?? $method;
    }

    /**
     * @param array{key: string, chat_id: string, message_thread_id: ?int, display: string} $target
     * @return array{ok: bool, status: ?int}
     */
    private function postTelegramTarget(string $token, array $target, string $message): array
    {
        $body = [
            'chat_id' => $target['chat_id'],
            'text' => $message,
            'parse_mode' => 'HTML',
        ];
        if ($target['message_thread_id'] !== null) {
            $body['message_thread_id'] = $target['message_thread_id'];
        }

        try {
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", $body);

            $ok = $response->successful() && $response->json('ok') === true;
            if (!$ok) {
                Log::warning('Telegram target delivery failed', [
                    'status' => $response->status(),
                    'has_thread' => $target['message_thread_id'] !== null,
                ]);
            }

            return ['ok' => $ok, 'status' => $response->status()];
        } catch (\Throwable $e) {
            Log::error('Telegram target delivery exception', [
                'error_type' => get_class($e),
            ]);

            return ['ok' => false, 'status' => null];
        }
    }

    private function safeTelegramFailure(string $kind, ?int $status, ?string $targetLabel = null): string
    {
        $prefix = $targetLabel !== null
            ? "Telegram 发送失败（{$targetLabel}）："
            : match ($kind) {
                'bot' => 'Telegram 机器人私聊通知发送失败：',
                'group' => 'Telegram 群组通知发送失败：',
                'channel' => 'Telegram 频道通知发送失败：',
                default => 'Telegram 通知发送失败：',
            };

        return $prefix . match ($status) {
            401 => 'Bot Token 无效或已被吊销，请核对 @BotFather 提供的 Token。',
            403 => '机器人无发言权限，请确认接收人已向机器人发送 /start，或已将机器人设为群组/频道管理员并授予发布消息权限。',
            400, 404 => '目标会话 ID、话题 ID 或 @用户名无效，或机器人尚未加入目标群组/频道。',
            429 => '触发 Telegram 接口速率限制，请稍后重试。',
            null => '无法连接 api.telegram.org，请检查服务器国际网络连通性。',
            default => "上游接口返回异常（HTTP {$status}），请检查机器人配置与会话权限。",
        };
    }


    /**
     * Default email template when none is configured.
     */
    private function defaultEmailTemplate(): string
    {
        return <<<'HTML'
<h2>{{site_name}} - 订单发货通知</h2>
<p>您的订单 <strong>{{order_no}}</strong> 已完成支付，以下是您购买的卡密信息：</p>
<p><strong>商品：</strong>{{product_name}}</p>
<p><strong>数量：</strong>{{quantity}}</p>
<p><strong>支付金额：</strong>¥{{amount}}</p>
<hr>
<p><strong>卡密内容：</strong></p>
<pre>{{cards}}</pre>
<hr>
<p>请妥善保管您的卡密信息。如有问题请联系客服。</p>
HTML;
    }

    /** Classify failures without ever publishing transport-supplied text. */
    private function safeMailFailure(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'authentication') || preg_match('/\b(534|535)\b/', $message)) {
            return '发送失败：SMTP 认证失败，请检查账户、密码或授权码。';
        }
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return '发送失败：SMTP 连接超时，请检查服务器地址、端口和网络。';
        }
        if (str_contains($message, 'connection refused') || str_contains($message, 'could not be established') || str_contains($message, 'getaddrinfo')) {
            return '发送失败：无法连接 SMTP 服务器，请检查地址、端口和网络。';
        }
        if (str_contains($message, 'certificate') || str_contains($message, 'tls') || str_contains($message, 'ssl')) {
            return '发送失败：SMTP 加密连接失败，请检查加密方式及证书。';
        }

        return '发送失败：邮件服务未能受理邮件，请检查 SMTP 配置和收件地址。';
    }
}
