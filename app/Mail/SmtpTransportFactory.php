<?php

namespace App\Mail;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

/** Apply the SMTP policy to direct mailers and every composite child transport. */
class SmtpTransportFactory
{
    public static function create(array $config): EsmtpTransport
    {
        if ($config['invalid_configuration'] ?? false) {
            throw new \RuntimeException('SMTP configuration is invalid.');
        }
        $config += ['require_tls' => true];
        $scheme = $config['scheme'] ?? ((int) ($config['port'] ?? 0) === 465 ? 'smtps' : 'smtp');
        $transport = (new EsmtpTransportFactory)->create(new Dsn($scheme, $config['host'],
            $config['username'] ?? null, $config['password'] ?? null, $config['port'] ?? null, $config));
        $stream = $transport->getStream();
        if (isset($config['timeout'])) {
            $stream->setTimeout($config['timeout']);
        }
        $required = new RequiredTlsSmtpTransport($stream->getHost(), $stream->getPort(), $stream->isTLS());
        $required->setUsername($transport->getUsername())->setPassword($transport->getPassword())
            ->setLocalDomain($transport->getLocalDomain())->setAutoTls($transport->isAutoTls())->setRequireTls($transport->isTlsRequired());
        $required->getStream()->setTimeout($stream->getTimeout())->setStreamOptions($stream->getStreamOptions());
        if ($stream->getSourceIp() !== null) {
            $required->getStream()->setSourceIp($stream->getSourceIp());
        }
        if (isset($config['max_per_second'])) {
            $required->setMaxPerSecond((float) $config['max_per_second']);
        }
        if (isset($config['restart_threshold'])) {
            $required->setRestartThreshold((int) $config['restart_threshold'], (int) ($config['restart_threshold_sleep'] ?? 0));
        }
        if (isset($config['ping_threshold'])) {
            $required->setPingThreshold((int) $config['ping_threshold']);
        }

        return $required;
    }
}
