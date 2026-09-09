<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\Database;
use App\Core\Heartbeat;
use App\Core\Logger;

/**
 * Health-check для мониторинга (задача 59). Публично отдаёт только общий
 * статус и HTTP-код. Подробности о БД, storage, воркерах и релизе доступны
 * только с сервисным токеном из переменной окружения HEALTH_CHECK_TOKEN.
 */
final class HealthController
{
    public function index(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');

        [$providedToken, $authAttempted] = self::providedToken();
        $expectedToken = trim((string) getenv('HEALTH_CHECK_TOKEN'));
        $detailed = $expectedToken !== ''
            && $providedToken !== ''
            && hash_equals($expectedToken, $providedToken);

        if ($authAttempted && !$detailed) {
            http_response_code(403);
            header('WWW-Authenticate: Bearer realm="health"');
            echo json_encode(['status' => 'forbidden'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $checks = ['db' => false, 'storage' => false];

        try {
            Database::pdo()->query('SELECT 1');
            $checks['db'] = true;
        } catch (\Throwable $e) {
            Logger::critical('Health-check: БД недоступна — ' . $e->getMessage());
        }

        $logDir = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3)) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $checks['storage'] = is_dir($logDir) && is_writable($logDir);
        if (!$checks['storage']) {
            Logger::warning('Health-check: каталог storage/logs недоступен для записи.');
        }

        // Живость фоновых воркеров (группа 2.1). «Залипшим» считается воркер,
        // который запускался хотя бы раз, но давно молчит (cron отвалился).
        $workers = Heartbeat::status();
        $stale = [];
        foreach ($workers as $name => $w) {
            if ($w['stale']) {
                $stale[] = $name;
            }
        }
        if ($stale !== []) {
            // TelegramNotifier throttлит одинаковые алерты по сигнатуре, поэтому
            // частые /health-опросы не спамят.
            Logger::critical('Health-check: воркер(ы) не запускались вовремя — ' . implode(', ', $stale), [
                'stale_workers' => $stale,
            ]);
        }

        // Инфраструктурный статус (db+storage) определяет HTTP-код, чтобы не
        // «флапать» 503 на установках, где часть воркеров осознанно не настроена.
        $ok = $checks['db'] && $checks['storage'];
        $status = $ok ? ($stale === [] ? 'ok' : 'degraded') : 'down';
        http_response_code($ok ? 200 : 503);

        /** @var array<string,mixed> $payload */
        $payload = ['status' => $status];
        if ($detailed) {
            $payload['checks'] = $checks;
            $payload['workers'] = $workers;
            $payload['release'] = \App\Core\Release::id();
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Принимает токен только из заголовка Authorization: Bearer ... или
     * X-Health-Token. Query-параметры намеренно не используются, чтобы секрет
     * не попадал в access-логи, историю браузера и URL мониторинга.
     *
     * @return array{0:string,1:bool} [token, была ли попытка авторизации]
     */
    private static function providedToken(): array
    {
        $authorization = trim((string) (
            $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? ''
        ));
        $headerToken = trim((string) ($_SERVER['HTTP_X_HEALTH_TOKEN'] ?? ''));
        $attempted = $authorization !== '' || $headerToken !== '';

        $token = $headerToken;
        if ($authorization !== '') {
            if (preg_match('/^Bearer[ \t]+([^\s]+)$/i', $authorization, $matches) !== 1) {
                return ['', true];
            }
            $token = (string) $matches[1];
        }

        if ($token === '' || preg_match('/[\x00-\x1F\x7F]/', $token) === 1) {
            return ['', $attempted];
        }

        return [$token, $attempted];
    }
}
