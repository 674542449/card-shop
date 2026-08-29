<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
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
            'mail.mailers.smtp.scheme' => $scheme,
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
    }

    /**
     * Send a test message to prove the SMTP settings work.
     *
     * The whole delivery path is failure-tolerant by design — a send that throws is
     * logged and swallowed so a dead mail server cannot break a sale. That is right,
     * but it leaves the operator no way to find out their settings are wrong except
     * by a buyer complaining. This is that way: it reports the transport's own error
     * message verbatim, because "Connection refused" and "535 authentication failed"
     * need completely different fixes.
     *
     * @return array{ok: bool, message: string}
     */
    public function sendTestEmail(string $to): array
    {
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
            Log::warning('SMTP test failed', ['to' => $to, 'error' => $e->getMessage()]);

            return ['ok' => false, 'message' => '发送失败：' . $e->getMessage()];
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
        try {
            $this->configureMailer();

            $order->loadMissing(['product', 'cards']);

            // The seeder and the admin Settings screen both use `email_template_body`;
            // `email_template` is kept only as a fallback for older installs.
            $template = (string) setting(
                'email_template_body',
                setting('email_template', $this->defaultEmailTemplate())
            );
            $siteName = (string) setting('site_name', '卡密商城');

            $cards = $order->cards->pluck('content')->implode("\n");

            $amount = number_format((float) $order->total_amount, 2, '.', '');

            // {{total_amount}} is the name used by the seeded template, {{amount}} by the
            // built-in default. Support both so either template renders correctly.
            $values = [
                '{{site_name}}' => $siteName,
                '{{order_no}}' => $order->order_no,
                '{{product_name}}' => $order->product->name ?? '',
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
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send a Telegram notification message.
     */
    public function sendTelegramNotification(string $message): void
    {
        $enabled = setting('telegram_enabled', '0');
        if (!$enabled || $enabled === '0' || $enabled === 'false') {
            return;
        }

        $token = (string) setting('telegram_bot_token', '');
        $chatId = (string) setting('telegram_chat_id', '');

        if (empty($token) || empty($chatId)) {
            return;
        }

        try {
            $response = Http::timeout(10)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                ]);

            if (!$response->successful()) {
                Log::warning('Telegram notification failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram notification exception', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send Telegram notification for a new paid order.
     */
    public function notifyNewOrder(Order $order): void
    {
        $order->loadMissing('product');

        $paymentLabels = [
            'alipay' => '支付宝',
            'wechat' => '微信支付',
            'usdt_trc20' => 'USDT (TRC20)',
            'usdt_bep20' => 'USDT (BEP20)',
            'usdt_polygon' => 'USDT (Polygon)',
        ];

        $method = $paymentLabels[$order->payment_method] ?? $order->payment_method;

        // Escaped because sendTelegramNotification() posts with parse_mode=HTML.
        // A product named "<VIP>" or an email containing < made Telegram reject the
        // whole message with a 400, so the operator got no notification at all for
        // exactly those sales — a silent gap, since the send failure only logs.
        $name = e($order->product->name ?? '');
        $email = e($order->email);

        $message = "<b>新订单通知</b>\n\n"
            . "订单号: <code>" . e($order->order_no) . "</code>\n"
            . "商品: {$name}\n"
            . "数量: {$order->quantity}\n"
            . "金额: ¥{$order->total_amount}\n"
            . "支付方式: " . e($method) . "\n"
            . "邮箱: {$email}";

        $this->sendTelegramNotification($message);
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
}
