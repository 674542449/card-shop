<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    /**
     * Send order completion email with card details to the buyer.
     */
    public function sendOrderEmail(Order $order): void
    {
        try {
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
            $placeholders = [
                '{{site_name}}' => e($siteName),
                '{{order_no}}' => e($order->order_no),
                '{{product_name}}' => e($order->product->name ?? ''),
                '{{quantity}}' => (string) $order->quantity,
                '{{amount}}' => $amount,
                '{{total_amount}}' => $amount,
                '{{cards}}' => $cards,
            ];

            $body = strtr($template, $placeholders);

            $subject = strtr(
                (string) setting('email_template_subject', '{{site_name}} - 订单 {{order_no}} 卡密信息'),
                $placeholders
            );

            Mail::html($body, function ($message) use ($order, $subject) {
                $message->to($order->email)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::error('Failed to send order email', [
                'order_no' => $order->order_no,
                'email' => $order->email,
                'error' => $e->getMessage(),
            ]);
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

        $message = "<b>新订单通知</b>\n\n"
            . "订单号: <code>{$order->order_no}</code>\n"
            . "商品: {$order->product->name}\n"
            . "数量: {$order->quantity}\n"
            . "金额: ¥{$order->total_amount}\n"
            . "支付方式: {$method}\n"
            . "邮箱: {$order->email}";

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
