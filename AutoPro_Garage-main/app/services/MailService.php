<?php
declare(strict_types=1);

/**
 * MailService: Enterprise SMTP and notification delivery.
 * Supports native SMTP sockets with STARTTLS/SSL, environment variables,
 * and graceful fallback for local development environments.
 */
final class MailService
{
    public static function config(): array
    {
        $local = [];
        $localFile = APP_ROOT . '/app/config/local.php';
        if (is_file($localFile)) {
            $c = (array) require $localFile;
            if (isset($c['mail'])) {
                $local = (array) $c['mail'];
            }
        }

        $encryption = strtolower((string) (getenv('SMTP_ENCRYPTION') ?: ($local['encryption'] ?? 'tls')));
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'tls';
        }

        $fromEmail = (string) (getenv('SMTP_FROM_EMAIL') ?: ($local['from_email'] ?? ''));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = self::defaultSender();
        }

        return [
            'host'       => (string) (getenv('SMTP_HOST') ?: ($local['host'] ?? '')),
            'port'       => (int) (getenv('SMTP_PORT') ?: ($local['port'] ?? 587)),
            'user'       => (string) (getenv('SMTP_USERNAME') ?: ($local['user'] ?? '')),
            'pass'       => (string) (getenv('SMTP_PASSWORD') ?: ($local['pass'] ?? '')),
            'from_email' => $fromEmail,
            'from_name'  => (string) (getenv('SMTP_FROM_NAME') ?: ($local['from_name'] ?? 'Alpha Garage')),
            'encryption' => $encryption, // 'tls', 'ssl', or 'none'
        ];
    }

    /** Sender on the configured application domain; never the legacy AutoPro domain. */
    private static function defaultSender(): string
    {
        $appUrl = getenv('GMS_APP_URL');
        if ($appUrl === false) {
            $appUrl = (string) ($GLOBALS['config']['app_url'] ?? '');
        }
        $host = $appUrl !== '' ? parse_url($appUrl, PHP_URL_HOST) : null;
        if (is_string($host) && preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            return 'no-reply@' . strtolower($host);
        }
        return 'no-reply@localhost';
    }

    /**
     * Attempt delivery over SMTP, then native mail(). The local spool is a
     * development record only and is reported as a delivery failure.
     *
     * @return array{ok: bool, transport: string, message: string, error?: string, file?: string}
     */
    public static function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): array
    {
        $cfg = self::config();
        $error = '';

        // If SMTP credentials are fully provided, attempt SMTP socket delivery
        if (!empty($cfg['host']) && !empty($cfg['user']) && !empty($cfg['pass'])) {
            try {
                self::sendViaSmtp($cfg, $toEmail, $toName, $subject, $htmlBody, $textBody);
                return ['ok' => true, 'transport' => 'smtp', 'message' => 'Email sent successfully via SMTP.'];
            } catch (Throwable $e) {
                $error = $e->getMessage();
                app_log('SMTP delivery error: ' . $error);
            }
        }

        // Try native PHP mail() if configured on server
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . self::formatAddress($cfg['from_email'], $cfg['from_name']),
            'Reply-To: ' . $cfg['from_email'],
            'X-Mailer: Alpha-Garage-Mailer',
        ];

        if (!empty(ini_get('sendmail_path')) || !empty(ini_get('SMTP'))) {
            $sent = @mail($toEmail, $subject, $htmlBody, implode("\r\n", $headers));
            if ($sent) {
                return ['ok' => true, 'transport' => 'native', 'message' => 'Email sent via native mail().'];
            }
            if ($error === '') {
                $error = 'Native mail() reported a delivery failure.';
            }
            app_log('Native mail() delivery failed for ' . $toEmail . '.');
        }

        // Development spool: writes a local copy only, never a delivered message.
        $spoolDir = storage_path('mail_spool');
        if (!is_dir($spoolDir)) {
            @mkdir($spoolDir, 0775, true);
        }
        $filename = $spoolDir . '/mail_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.html';
        $spooled = @file_put_contents($filename, "<!-- TO: {$toEmail} ({$toName}) | SUBJECT: {$subject} -->\n" . $htmlBody) !== false;
        if (!$spooled) {
            $filename = '';
        }

        if ($error === '') {
            $error = 'No SMTP transport is configured.';
        }
        app_log('Email to ' . $toEmail . ' was not delivered: ' . $error . ($spooled ? ' (spooled locally for inspection)' : ' (spool write failed)'));

        return [
            'ok'        => false,
            'transport' => 'dev_spool',
            'file'      => $filename,
            'error'     => $error,
            'message'   => 'Email was NOT delivered. ' . $error . ($spooled ? ' A local copy was written to storage/mail_spool.' : ''),
        ];
    }

    /**
     * Format a luxury HTML email for password recovery.
     */
    public static function sendPasswordReset(string $toEmail, string $toName, string $resetUrl): array
    {
        $garageName = Setting::get('garage_name', 'Alpha Garage');
        $subject = 'Reset Your Password — ' . $garageName;

        // The address and link can contain user-controlled characters; escape for HTML.
        $safeGarage = e($garageName);
        $safeName   = e($toName);
        $safeUrl    = e($resetUrl);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { margin: 0; padding: 0; background-color: #0A1A14; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #F5F2E9; }
  .wrapper { width: 100%; max-width: 600px; margin: 40px auto; background-color: #10291F; border: 1px solid rgba(212, 175, 55, 0.3); border-radius: 12px; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.5); }
  .header { background: linear-gradient(135deg, #10291F 0%, #183B2B 100%); padding: 36px 40px; text-align: center; border-bottom: 2px solid #D4AF37; }
  .brand { font-size: 22px; font-weight: 700; color: #D4AF37; letter-spacing: 2px; text-transform: uppercase; margin: 0; }
  .tagline { font-size: 13px; color: #A7B0AA; margin-top: 6px; letter-spacing: 1px; }
  .content { padding: 40px; font-size: 15px; line-height: 1.65; color: #F5F2E9; }
  .h1 { font-size: 20px; color: #FFFFFF; font-weight: 600; margin-top: 0; margin-bottom: 16px; }
  .btn-container { text-align: center; margin: 32px 0; }
  .btn { display: inline-block; background: linear-gradient(135deg, #D4AF37 0%, #E8CD83 100%); color: #10291F !important; font-weight: 700; font-size: 15px; padding: 14px 32px; border-radius: 8px; text-decoration: none; text-transform: uppercase; letter-spacing: 1px; box-shadow: 0 8px 20px rgba(212, 175, 55, 0.3); }
  .btn:hover { background: #D4AF37; }
  .url-box { background: rgba(0,0,0,0.3); border: 1px solid rgba(212, 175, 55, 0.15); border-radius: 6px; padding: 12px 16px; font-family: monospace; font-size: 12px; color: #E8CD83; word-break: break-all; margin: 20px 0; }
  .note { font-size: 13px; color: #A7B0AA; margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.08); }
  .footer { background: #0A1A14; padding: 24px 40px; text-align: center; font-size: 12px; color: #A7B0AA; border-top: 1px solid rgba(212, 175, 55, 0.15); }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <div class="brand">{$safeGarage}</div>
    <div class="tagline">EXCELLENCE IN EVERY DETAIL &middot; LUXURY AUTOMOTIVE SERVICE</div>
  </div>
  <div class="content">
    <h2 class="h1">Password Recovery Request</h2>
    <p>Dear {$safeName},</p>
    <p>We received a request to reset the password for your account. Click the secure link below to proceed:</p>
    
    <div class="btn-container">
      <a href="{$safeUrl}" class="btn" target="_blank">Reset Password</a>
    </div>

    <p style="font-size: 13px; color: #A7B0AA;">If the button above does not open, copy and paste this URL into your browser:</p>
    <div class="url-box">{$safeUrl}</div>

    <div class="note">
      <strong>Important Security Notice:</strong>
      <ul style="margin: 8px 0 0 16px; padding: 0;">
        <li>This recovery link expires in <strong>30 minutes</strong>.</li>
        <li>This link is single-use and will be invalidated once consumed.</li>
        <li>If you did not initiate this request, you can safely ignore this email; your password remains unchanged.</li>
      </ul>
    </div>
  </div>
  <div class="footer">
    &copy; {$safeGarage} &middot; Highly Secure Automotive Management System.
  </div>
</div>
</body>
</html>
HTML;

        $text = "Password Recovery Request for {$toName}\n\n"
              . "Please use the following secure link to reset your password:\n"
              . "{$resetUrl}\n\n"
              . "This link expires in 30 minutes. If you did not request this, please ignore this message.";

        return self::send($toEmail, $toName, $subject, $html, $text);
    }

    private static function formatAddress(string $email, string $name): string
    {
        return empty($name) ? $email : sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($name), $email);
    }

    /**
     * Pure native PHP socket SMTP client with TLS support.
     */
    private static function sendViaSmtp(array $cfg, string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): void
    {
        $host = $cfg['host'];
        $port = (int) $cfg['port'];
        $timeout = 15;

        $target = ($cfg['encryption'] === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            throw new RuntimeException("Could not connect to SMTP host {$host}: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $timeout);

        $read = function () use ($socket): string {
            $response = '';
            while ($line = fgets($socket, 515)) {
                $response .= $line;
                if (substr($line, 3, 1) === ' ') {
                    break;
                }
            }
            return $response;
        };

        $write = function (string $cmd) use ($socket): void {
            fwrite($socket, $cmd . "\r\n");
        };

        $expect = function (string $code) use ($read): string {
            $resp = $read();
            if (!str_starts_with($resp, $code)) {
                throw new RuntimeException("SMTP error. Expected {$code}, got: " . trim($resp));
            }
            return $resp;
        };

        $expect('220');
        $write('EHLO ' . gethostname());
        $ehlo = $expect('250');

        if ($cfg['encryption'] === 'tls' && str_contains($ehlo, 'STARTTLS')) {
            $write('STARTTLS');
            $expect('220');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Failed to establish TLS encryption with SMTP server.');
            }
            $write('EHLO ' . gethostname());
            $expect('250');
        }

        if (!empty($cfg['user'])) {
            $write('AUTH LOGIN');
            $expect('334');
            $write(base64_encode($cfg['user']));
            $expect('334');
            $write(base64_encode($cfg['pass']));
            $expect('235');
        }

        $write('MAIL FROM:<' . $cfg['from_email'] . '>');
        $expect('250');
        $write('RCPT TO:<' . $toEmail . '>');
        $expect('250');
        $write('DATA');
        $expect('354');

        $boundary = '=_AlphaGarage_' . md5(uniqid((string) mt_rand(), true));
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        $data = [
            'Date: ' . date('r'),
            'To: ' . self::formatAddress($toEmail, $toName),
            'From: ' . self::formatAddress($cfg['from_email'], $cfg['from_name']),
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($textBody)),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($htmlBody)),
            '--' . $boundary . '--',
            '.',
        ];

        $write(implode("\r\n", $data));
        $expect('250');
        $write('QUIT');
        fclose($socket);
    }
}
