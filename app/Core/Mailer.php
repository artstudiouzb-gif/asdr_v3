<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Нативный Mailer на чистом PHP без сторонних библиотек.
 * Поддерживает:
 *  1. SMTP сокет-клиент (Яндекс, Google, Mail.ru и др.).
 *  2. Автоматический фолбэк на встроенную функцию mail() хостинга без каких-либо настроек!
 */
final class Mailer
{
    /** @var resource|null */
    private $socket = null;
    private array $config;
    private ?string $lastError = null;

    public function __construct(?array $config = null)
    {
        if ($config !== null) {
            $this->config = $config;
        } else {
            $sysConfig = (array) Config::get('mail', []);
            $host = trim((string) ($sysConfig['host'] ?? ''));
            if ($host === '') {
                $host = trim((string) \App\Models\Setting::get('smtp_host', ''));
            }
            if ($host !== '') {
                $this->config = [
                    'host' => $host,
                    'port' => (int) ($sysConfig['port'] ?: \App\Models\Setting::get('smtp_port', '587')),
                    'encryption' => (string) ($sysConfig['encryption'] ?: \App\Models\Setting::get('smtp_encryption', 'tls')),
                    'username' => (string) ($sysConfig['username'] ?: \App\Models\Setting::get('smtp_username', '')),
                    'password' => (string) ($sysConfig['password'] ?: \App\Models\Setting::get('smtp_password', '')),
                    'from_email' => (string) ($sysConfig['from_email'] ?: \App\Models\Setting::get('smtp_from_email', '')),
                    'from_name' => (string) ($sysConfig['from_name'] ?: \App\Models\Setting::get('smtp_from_name', 'ASDR CMS')),
                    'timeout' => (int) ($sysConfig['timeout'] ?: 15),
                ];
            } else {
                $this->config = $sysConfig;
            }
        }
    }

    /**
     * Всегда возвращает true, т.к. при отсутствии SMTP используется нативная mail() хостинга.
     */
    public static function isConfigured(): bool
    {
        return true;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function send(string $toEmail, string $subject, string $body, ?string $toName = null): bool
    {
        $this->lastError = null;

        if (self::hasControlChars($toEmail) || ($toName !== null && self::hasControlChars($toName))) {
            $this->lastError = 'Недопустимые символы в адресе получателя';
            Logger::security('Mailer: отклонён адрес с управляющими символами', [
                'email' => preg_replace('/[\r\n\x00]+/', '⏎', $toEmail),
            ]);
            return false;
        }

        $host = trim((string) ($this->config['host'] ?? ''));
        $fromEmail = !empty($this->config['from_email'])
            ? $this->config['from_email']
            : (!empty($this->config['username']) ? $this->config['username'] : 'no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $fromName = !empty($this->config['from_name']) ? $this->config['from_name'] : 'ASDR CMS';

        // Если настроен SMTP — отправляем через прямые сокеты
        if ($host !== '') {
            try {
                $this->connect();
                $this->ehlo();

                if (($this->config['encryption'] ?? '') === 'tls') {
                    $this->startTls();
                    $this->ehlo();
                }

                if (!empty($this->config['username'])) {
                    $this->authenticate();
                }

                $realFrom = $this->config['from_email'] ?: $this->config['username'];
                $this->command('MAIL FROM:<' . $realFrom . '>', [250]);
                $this->command('RCPT TO:<' . $toEmail . '>', [250, 251]);
                $this->command('DATA', [354]);

                $message = $this->buildMessage($realFrom, $fromName, $toEmail, $toName, $subject, $body);
                $this->write($message . "\r\n.");
                $this->expect([250]);
                $this->command('QUIT', [221]);

                return true;
            } catch (\Throwable $e) {
                $smtpErr = $e->getMessage();
                $this->lastError = $smtpErr;
                Logger::error('SMTP send failed: ' . $e->getMessage() . '. Отправка через нативный mail().');
                // Резервный запуск через встроенную функцию mail()
                $ok = $this->sendNativeMail($fromEmail, $fromName, $toEmail, $toName, $subject, $body);
                if (!$ok && $smtpErr !== '') {
                    $this->lastError = $smtpErr;
                }
                return $ok;
            } finally {
                $this->close();
            }
        }

        // Если SMTP не задан — автоматически отправляем без настроек через системную функцию mail()
        return $this->sendNativeMail($fromEmail, $fromName, $toEmail, $toName, $subject, $body);
    }

    /**
     * Отправка писем через встроенный mail() хостинга без указания SMTP серверных настроек.
     */
    private function sendNativeMail(string $fromEmail, string $fromName, string $toEmail, ?string $toName, string $subject, string $body): bool
    {
        if (!function_exists('mail')) {
            $this->lastError = 'Нативная функция mail() отключена на сервере (disable_functions в php.ini).';
            return false;
        }

        try {
            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $fromHeader = $this->formatAddress($fromName, $fromEmail);

            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                'From: ' . $fromHeader,
                'Reply-To: ' . $fromHeader,
                'X-Mailer: ASDR CMS Mailer',
            ];

            $encodedBody = chunk_split(base64_encode($body));

            // Вызов нативной функции mail() PHP с перехватом возможных предупреждений и ошибок
            $ok = @mail($toEmail, $encodedSubject, $encodedBody, implode("\r\n", $headers));
            if (!$ok) {
                $this->lastError = 'Нативная функция mail() сервера вернула false.';
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = 'Ошибка вызова mail(): ' . $e->getMessage();
            return false;
        }
    }

    private function connect(): void
    {
        $host = $this->config['host'];
        $port = (int) ($this->config['port'] ?? 587);
        $encryption = $this->config['encryption'] ?? 'tls';
        $timeout = (int) ($this->config['timeout'] ?? 15);

        $prefix = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $remote = $prefix . $host . ':' . $port;

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            throw new \RuntimeException(sprintf('Не удалось подключиться к SMTP %s:%d: %s (%d)', $host, $port, $errstr, $errno));
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $timeout);
        $this->expect([220]);
    }

    private function ehlo(): void
    {
        $domain = $_SERVER['SERVER_NAME'] ?? 'localhost';
        if (!preg_match('/^[a-zA-Z0-9.-]+$/', $domain)) {
            $domain = 'localhost';
        }
        $this->command('EHLO ' . $domain, [250]);
    }

    private function startTls(): void
    {
        $this->command('STARTTLS', [220]);

        $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        if (!@stream_socket_enable_crypto($this->socket, true, $cryptoMethod)) {
            throw new \RuntimeException('Ошибка включения TLS шифрования');
        }
    }

    private function authenticate(): void
    {
        $user = $this->config['username'];
        $pass = $this->config['password'];

        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($user), [334]);
        $this->command(base64_encode($pass), [235]);
    }

    private function buildMessage(string $fromEmail, string $fromName, string $toEmail, ?string $toName, string $subject, string $body): string
    {
        $headers = [];
        $headers[] = 'From: ' . $this->formatAddress($fromName, $fromEmail);
        $headers[] = 'To: ' . $this->formatAddress($toName, $toEmail);
        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers[] = 'Date: ' . date(DATE_RFC2822);
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';

        $encodedBody = chunk_split(base64_encode($body));

        return implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;
    }

    private function formatAddress(?string $name, string $email): string
    {
        if (empty($name)) {
            return $email;
        }
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }

    private function command(string $cmd, array $expectedCodes): string
    {
        $this->write($cmd);
        return $this->expect($expectedCodes);
    }

    private function write(string $data): void
    {
        if ($this->socket === null) {
            throw new \RuntimeException('SMTP сокет закрыт.');
        }

        $result = @fwrite($this->socket, $data . "\r\n");
        if ($result === false) {
            throw new \RuntimeException('Не удалось записать данные в SMTP сокет.');
        }
    }

    private function expect(array $expectedCodes): string
    {
        if ($this->socket === null) {
            throw new \RuntimeException('SMTP сокет закрыт.');
        }

        $response = '';
        while ($line = fgets($this->socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        if ($response === '') {
            throw new \RuntimeException('Пустой ответ от SMTP сервера.');
        }

        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new \RuntimeException(sprintf('Ошибка SMTP (код %d): %s', $code, trim($response)));
        }

        return $response;
    }

    private function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private static function hasControlChars(string $str): bool
    {
        return (bool) preg_match('/[\r\n\x00]/', $str);
    }
}
