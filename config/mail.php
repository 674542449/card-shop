<?php

// Resolve environment SMTP settings once. Laravel's built-in SMTP config does
// not interpret MAIL_ENCRYPTION, and parsing MAIL_URL again at send time could
// overwrite the required TLS flags or an administrator's newer SMTP settings.
$smtp = [
    'transport' => 'smtp',
    'host' => env('MAIL_HOST', '127.0.0.1'),
    'port' => env('MAIL_PORT', 2525),
    'username' => env('MAIL_USERNAME'),
    'password' => env('MAIL_PASSWORD'),
    'timeout' => 15,
    'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
];
$scheme = strtolower(trim((string) env('MAIL_SCHEME', '')));
$encryption = strtolower(trim((string) env('MAIL_ENCRYPTION', '')));
$mailUrl = trim((string) env('MAIL_URL', ''));
if ($mailUrl !== '') {
    try {
        $parsed = (new Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($mailUrl);
        $urlScheme = strtolower((string) ($parsed['driver'] ?? ''));
        if (!in_array($urlScheme, ['smtp', 'smtps'], true) || empty($parsed['host'])) {
            throw new InvalidArgumentException('Invalid SMTP URL.');
        }
        foreach (['host', 'port', 'username', 'password', 'local_domain', 'timeout'] as $key) {
            if (array_key_exists($key, $parsed)) { $smtp[$key] = $parsed[$key]; }
        }
        $scheme = $urlScheme;
        if (array_key_exists('encryption', $parsed)) { $encryption = strtolower((string) $parsed['encryption']); }
    } catch (Throwable) {
        // A bad mail URL must fail delivery without breaking storefront bootstrap.
        $smtp['invalid_configuration'] = true;
    }
}
if ($scheme === 'smtps') {
    $encryption = 'ssl';
} elseif (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
    $encryption = (int) $smtp['port'] === 465 ? 'ssl' : 'tls';
}
$smtp += [
    'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
    'encryption' => $encryption,
    'auto_tls' => $encryption === 'tls',
    'require_tls' => $encryption !== 'none',
    'url' => null,
];

return [
    'default' => env('MAIL_MAILER', 'smtp'),
    'mailers' => [
        'smtp' => $smtp,
        'ses' => ['transport' => 'ses'],
        'postmark' => ['transport' => 'postmark'],
        'resend' => ['transport' => 'resend'],
        'sendmail' => ['transport' => 'sendmail', 'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i')],
        'log' => ['transport' => 'log', 'channel' => env('MAIL_LOG_CHANNEL')],
        'array' => ['transport' => 'array'],
        // A log fallback would expose the card body and falsely mark it delivered.
        'failover' => ['transport' => 'failover', 'mailers' => ['smtp'], 'retry_after' => 60],
        'roundrobin' => ['transport' => 'roundrobin', 'mailers' => ['ses', 'postmark'], 'retry_after' => 60],
    ],
    'from' => ['address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'), 'name' => env('MAIL_FROM_NAME', 'Example')],
    'markdown' => [
        'theme' => env('MAIL_MARKDOWN_THEME', 'default'),
        'paths' => [resource_path('views/vendor/mail')],
        'extensions' => [],
    ],
];
