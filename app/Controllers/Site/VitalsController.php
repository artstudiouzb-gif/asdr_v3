<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\FileRateLimiter;
use App\Core\WebVitals;

/**
 * Приём Core Web Vitals от браузеров посетителей.
 *
 * Ручка публичная и без CSRF: её вызывает sendBeacon при закрытии вкладки,
 * когда токен из формы взять неоткуда, а сессии у посетителя нет и заводить
 * её нельзя — это сломало бы кеширование публичных ответов.
 *
 * Отсюда и ограничения: принимаем только известные имена метрик, значения
 * приводим к числу и отбрасываем неправдоподобные, ответ всегда пустой.
 * Записать сюда что-то осмысленное для злоумышленника нельзя — только
 * испортить собственную статистику, и от этого стоит ограничитель частоты.
 */
final class VitalsController
{
    /** Больше замеров с одного адреса за минуту не ждём даже от вкладок. */
    private const LIMIT_PER_MINUTE = 30;

    public function store(): void
    {
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=UTF-8');

        if (!WebVitals::enabled()) {
            http_response_code(204);
            return;
        }

        // Ограничитель файловый, а не через таблицу попыток входа: та ведёт
        // журнал аутентификации, и метрикам там не место. IP используется
        // только как ключ и в базу не попадает.
        $identifier = 'vitals:' . sha1((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (FileRateLimiter::allow($identifier, self::LIMIT_PER_MINUTE, 60) === false) {
            http_response_code(429);
            return;
        }

        $raw = (string) file_get_contents('php://input');
        if ($raw === '' || strlen($raw) > 4096) {
            http_response_code(204);
            return;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['metrics'] ?? null)) {
            http_response_code(204);
            return;
        }

        WebVitals::store(
            $data['metrics'],
            (string) ($data['page'] ?? 'other'),
            (string) ($data['device'] ?? 'desktop'),
            (string) ($data['lang'] ?? '')
        );

        http_response_code(204);
    }
}
