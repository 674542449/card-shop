<?php

namespace App\Mail;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\RawMessage;

/** Do not let a legacy HELO fallback bypass a required TLS connection. */
class RequiredTlsSmtpTransport extends EsmtpTransport
{
    private bool $startTlsAccepted = false;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            return parent::send($message, $envelope);
        } catch (TransportExceptionInterface $exception) {
            // Composite transports log child exceptions before NotificationService
            // can classify them. Drop server-supplied text and SMTP debug buffers,
            // which may contain credentials or an echoed delivery body.
            $detail = strtolower($exception->getMessage());
            $category = match (true) {
                str_contains($detail, 'authentication'), (bool) preg_match('/\b(534|535)\b/', $detail) => 'authentication failed',
                str_contains($detail, 'timeout'), str_contains($detail, 'timed out') => 'connection timed out',
                str_contains($detail, 'tls'), str_contains($detail, 'ssl'), str_contains($detail, 'certificate') => 'TLS connection failed',
                str_contains($detail, 'connection refused'), str_contains($detail, 'could not be established'), str_contains($detail, 'getaddrinfo') => 'connection could not be established',
                default => 'delivery rejected',
            };
            throw new TransportException('SMTP '.$category.'.');
        }
    }

    public function executeCommand(string $command, array $codes): string
    {
        $greeting = str_starts_with($command, 'HELO ');
        if ($greeting) {
            // SmtpTransport uses this greeting for every new connection. Its
            // ESMTP implementation upgrades it to EHLO and may fall back to HELO.
            $this->startTlsAccepted = false;
        }
        $response = parent::executeCommand($command, $codes);
        if ($command === "STARTTLS\r\n") {
            $this->startTlsAccepted = true;
        }
        // Returning from the outer greeting after a STARTTLS command means the
        // parent completed TLS negotiation and the second EHLO successfully; a
        // failed crypto handshake throws before this point. SocketStream::isTLS()
        // reports implicit TLS configuration, not a successful STARTTLS upgrade.
        if ($greeting && $this->isTlsRequired() && !$this->getStream()->isTLS() && !$this->startTlsAccepted) {
            throw new TransportException('SMTP TLS connection is required.');
        }

        return $response;
    }
}
