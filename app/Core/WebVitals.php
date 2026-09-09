<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Core Web Vitals с реальных посетителей.
 *
 * Лабораторные замеры не показывают INP вовсе, а LCP на дев-сервере упирается
 * в сам сервер. Поле — единственный источник правды по отзывчивости, поэтому
 * страницы отправляют сюда свои метрики.
 *
 * Персональных данных не храним: ни адреса страницы, ни IP, ни User-Agent.
 * Только метрика, значение и три крупных среза. Этого хватает для 75-го
 * перцентиля — величины, по которой Google оценивает сайт.
 */
final class WebVitals
{
    /** Метрики, которые принимаем. Остальное молча отбрасываем. */
    public const METRICS = ['LCP', 'INP', 'CLS', 'TTFB', 'FCP'];

    /** Пороги «хорошо / нужно улучшить» — по документации web.dev. */
    public const THRESHOLDS = [
        'LCP' => [2500.0, 4000.0],
        'INP' => [200.0, 500.0],
        'CLS' => [0.1, 0.25],
        'TTFB' => [800.0, 1800.0],
        'FCP' => [1800.0, 3000.0],
    ];

    /** Сколько дней держим сырые замеры. */
    private const RETENTION_DAYS = 60;

    /** За один запрос принимаем не больше метрик, чем их существует. */
    private const MAX_METRICS_PER_REQUEST = 10;

    public static function enabled(): bool
    {
        return Setting::get('perf_vitals_enabled', '0') === '1';
    }

    /** Доля посетителей, с которых собираем: 0.01–1. */
    public static function sampleRate(): float
    {
        $raw = (float) Setting::get('perf_vitals_sample', '1');

        return max(0.01, min(1.0, $raw > 0 ? $raw : 1.0));
    }

    /**
     * Крупный тип страницы вместо её адреса: по нему видно, где именно
     * проседает, и при этом не собирается история просмотров посетителя.
     */
    public static function pageKind(string $template): string
    {
        return match (true) {
            $template === 'site/page' => 'page',
            $template === 'site/news_show' => 'news',
            $template === 'site/news_index' => 'news_list',
            $template === 'site/project_show', $template === 'site/projects_index' => 'projects',
            $template === 'site/album', $template === 'site/albums' => 'albums',
            $template === 'site/search' => 'search',
            $template === 'site/calendar' => 'calendar',
            str_starts_with($template, 'site/content') => 'content',
            default => 'other',
        };
    }

    /**
     * Записывает пачку метрик одного посещения.
     *
     * @param array<int, array<string, mixed>> $metrics
     * @return int сколько строк принято
     */
    public static function store(array $metrics, string $pageKind, string $device, string $lang): int
    {
        if (!self::enabled() || !Database::isConnected()) {
            return 0;
        }

        $pageKind = preg_match('/^[a-z_]{1,24}$/', $pageKind) === 1 ? $pageKind : 'other';
        $device = $device === 'mobile' ? 'mobile' : 'desktop';
        $lang = preg_match('/^[a-z-]{0,8}$/', $lang) === 1 ? $lang : '';

        $rows = [];
        foreach (array_slice($metrics, 0, self::MAX_METRICS_PER_REQUEST) as $metric) {
            $name = is_array($metric) ? (string) ($metric['metric'] ?? '') : '';
            if (!in_array($name, self::METRICS, true)) {
                continue;
            }
            $value = $metric['value'] ?? null;
            if (!is_numeric($value)) {
                continue;
            }
            $value = (float) $value;
            // Отрицательных метрик не бывает, а неправдоподобно больших не
            // ждём: браузер с битым таймером не должен портить перцентиль.
            if ($value < 0 || $value > 3_600_000) {
                continue;
            }
            $rows[] = [$name, $value, self::rate($name, $value), $pageKind, $device, $lang];
        }

        if ($rows === []) {
            return 0;
        }

        $sql = 'INSERT INTO web_vitals (metric, value, rating, page_kind, device, lang, created_at) VALUES '
            . implode(', ', array_fill(0, count($rows), '(?, ?, ?, ?, ?, ?, NOW())'));
        $statement = Database::pdo()->prepare($sql);
        $statement->execute(array_merge(...$rows));

        return count($rows);
    }

    /** Оценка по порогам web.dev — считаем на сервере, клиенту не доверяем. */
    public static function rate(string $metric, float $value): string
    {
        [$good, $poor] = self::THRESHOLDS[$metric] ?? [0.0, 0.0];
        if ($good === 0.0) {
            return '';
        }

        return match (true) {
            $value <= $good => 'good',
            $value <= $poor => 'needs-improvement',
            default => 'poor',
        };
    }

    /**
     * 75-й перцентиль по каждой метрике за период — та самая величина, по
     * которой сайт оценивают. Среднее здесь бесполезно: его вытягивают
     * быстрые заходы с горячим кешем.
     *
     * @return array<string, array{p75: float, count: int, rating: string}>
     */
    public static function summary(int $days = 28, string $device = '', string $pageKind = ''): array
    {
        if (!Database::isConnected()) {
            return [];
        }

        $where = ['created_at >= (NOW() - INTERVAL ? DAY)'];
        $params = [max(1, min(365, $days))];
        if ($device !== '') {
            $where[] = 'device = ?';
            $params[] = $device;
        }
        if ($pageKind !== '') {
            $where[] = 'page_kind = ?';
            $params[] = $pageKind;
        }

        $out = [];
        foreach (self::METRICS as $metric) {
            $statement = Database::pdo()->prepare(
                'SELECT value FROM web_vitals WHERE metric = ? AND ' . implode(' AND ', $where)
                . ' ORDER BY value ASC'
            );
            $statement->execute(array_merge([$metric], $params));
            $values = array_map('floatval', $statement->fetchAll(\PDO::FETCH_COLUMN));
            if ($values === []) {
                continue;
            }
            // Перцентиль по ближайшему рангу: на малых выборках он честнее
            // интерполяции, которая рисует значение, которого никто не видел.
            $index = (int) ceil(0.75 * count($values)) - 1;
            $p75 = $values[max(0, min(count($values) - 1, $index))];
            $out[$metric] = [
                'p75' => $p75,
                'count' => count($values),
                'rating' => self::rate($metric, $p75),
            ];
        }

        return $out;
    }

    /** Чистка старых замеров: таблица не должна расти бесконечно. */
    public static function prune(): int
    {
        if (!Database::isConnected()) {
            return 0;
        }
        $statement = Database::pdo()->prepare(
            'DELETE FROM web_vitals WHERE created_at < (NOW() - INTERVAL ? DAY)'
        );
        $statement->execute([self::RETENTION_DAYS]);

        return $statement->rowCount();
    }
}
