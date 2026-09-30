<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Проверка ссылок в контенте.
 *
 * Журнал 404 видит битую ссылку только после того, как по ней пришёл
 * посетитель, а ссылка из текста на удалённую страницу, файл или чужой сайт,
 * который закрылся, живёт в контенте годами. Здесь ссылки собираются из тех
 * же таблиц, что и «где используется файл» (`ContentLocator`), и каждая
 * битая ведёт к записи, где её чинить.
 *
 * Внутренние ссылки проверяются без сети, где это возможно: файл загрузки —
 * на диске, защищённый файл — в медиатеке; остальные — запросом к своему
 * сайту. Внешние — зондом `Http::probeSafeRemote` (публичный хост, закреплённый
 * IP, тело не качается) и только из воркера: сотня чужих серверов с таймаутом
 * — это минуты, а веб-запрос столько не живёт.
 */
final class LinkChecker
{
    public const STATE_OK = 'ok';
    public const STATE_BROKEN = 'broken';
    public const STATE_UNREACHABLE = 'unreachable';
    public const STATE_BLOCKED = 'blocked';
    public const STATE_UNCHECKED = 'unchecked';

    /**
     * Чужой сервер, не ответивший один раз, ещё не битый: сбой на минуту
     * бывает у всех. Битым называется только после стольких отказов подряд.
     */
    public const UNREACHABLE_STREAK = 2;

    /** Живую внешнюю ссылку перепроверяем раз в неделю. */
    private const RECHECK_OK = 7 * 86400;

    /** Отказавшую — не чаще раза в сутки, чтобы ручной повтор не долбил чужой сервер. */
    private const RECHECK_FAILED = 20 * 3600;

    /** Схемы, которые ссылками на страницы не являются. */
    private const SKIP_SCHEMES = ['mailto', 'tel', 'sms', 'javascript', 'data', 'tg', 'viber', 'whatsapp', 'skype', 'file', 'ftp'];

    /**
     * Все ссылки контента: адрес => строки, где он стоит.
     *
     * @return array<string, list<array{0: string, 1: array<string, mixed>}>>
     */
    public static function collect(): array
    {
        $links = [];
        foreach (ContentLocator::columns() as $table => $columns) {
            $select = array_map(static fn (string $c): string => '`' . $c . '`', $columns);
            $locator = ContentLocator::locatorColumns($table);
            foreach ($locator as $extra) {
                if (!in_array($extra, $columns, true)) {
                    $select[] = '`' . $extra . '`';
                }
            }
            $stmt = Database::pdo()->query('SELECT ' . implode(', ', array_unique($select)) . ' FROM `' . $table . '`');
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $found = [];
                foreach ($columns as $column) {
                    if (in_array($column, $locator, true)) {
                        continue;
                    }
                    $value = $row[$column] ?? null;
                    if (is_string($value) && $value !== '') {
                        foreach (self::extractLinks($value) as $url) {
                            $found[$url] = true;
                        }
                    }
                }
                if ($found === []) {
                    continue;
                }
                $slim = array_intersect_key($row, array_flip($locator));
                foreach (array_keys($found) as $url) {
                    $links[(string) $url][] = [$table, $slim];
                }
            }
        }

        return $links;
    }

    /**
     * Ссылки из одного значения колонки: атрибуты href/src в HTML, строки
     * JSON, похожие на адрес, и значение целиком, если оно само адрес (пункт
     * меню, цель редиректа, путь обложки).
     *
     * @return list<string>
     */
    public static function extractLinks(string $value): array
    {
        $text = str_replace(['\\/', '\\u0026', '\\"', '&amp;'], ['/', '&', '"', '&'], $value);
        $candidates = [];
        $trimmed = trim($text);
        if (preg_match('~^(?:/|https?://)\S*$~i', $trimmed) === 1) {
            $candidates[] = $trimmed;
        }
        if (preg_match_all('~\b(?:href|src)\s*=\s*(["\'])(.*?)\1~is', $text, $m)) {
            array_push($candidates, ...$m[2]);
        }
        if (preg_match_all('~"((?:/|https?://)[^"\s<>]*)"~i', $text, $m)) {
            array_push($candidates, ...$m[1]);
        }

        $out = [];
        foreach ($candidates as $candidate) {
            $url = self::normalize((string) $candidate);
            if ($url !== null) {
                $out[$url] = true;
            }
        }

        return array_map('strval', array_keys($out));
    }

    /**
     * Адрес в едином виде или null, если это не ссылка на страницу: якорь,
     * почта, телефон, шаблон с подстановкой, относительный путь без «/».
     * Абсолютный адрес своего сайта становится путём — иначе одна и та же
     * страница проверялась бы дважды.
     */
    public static function normalize(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || $url[0] === '#' || strlen($url) > 2000
            || preg_match('~[\s<>{}|\\\\^`]~', $url) === 1) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $url, $m) === 1) {
            $scheme = strtolower($m[1]);
            if (in_array($scheme, self::SKIP_SCHEMES, true) || !in_array($scheme, ['http', 'https'], true)) {
                return null;
            }
        } elseif ($url[0] !== '/') {
            return null;
        }

        $url = (string) preg_replace('/#.*$/', '', $url);
        if (preg_match('~^https?://~i', $url) === 1) {
            $parts = parse_url($url);
            if (!is_array($parts) || empty($parts['host'])) {
                return null;
            }
            if (self::isOwnHost((string) $parts['host'])) {
                $url = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            }
        }

        return $url === '' ? null : $url;
    }

    public static function isInternal(string $url): bool
    {
        return str_starts_with($url, '/');
    }

    /**
     * Проверка одного адреса.
     *
     * @return array{state: string, status: int, error: string}
     */
    public static function check(string $url): array
    {
        return self::isInternal($url) ? self::checkInternal($url) : self::checkExternal($url);
    }

    /**
     * Проход: сверить список адресов с контентом и проверить, сколько успеем.
     * Первыми идут непроверенные, потом отказавшие, потом давно проверенные.
     *
     * @return array{total: int, checked: int, broken: int, unreachable: int, blocked: int, remaining: int}
     */
    public static function run(bool $withExternal, int $budgetSeconds): array
    {
        $started = microtime(true);
        $links = self::collect();
        self::sync(array_keys($links));

        $checked = 0;
        $remaining = 0;
        foreach (self::queue() as $row) {
            $url = (string) $row['url'];
            $internal = (int) $row['is_internal'] === 1;
            if (!$internal && !self::externalDue($row, $withExternal)) {
                continue;
            }
            if (microtime(true) - $started > $budgetSeconds) {
                $remaining++;
                continue;
            }
            self::save($row, self::check($url));
            $checked++;
        }

        $counts = self::counts();

        return [
            'total' => count($links),
            'checked' => $checked,
            'broken' => $counts[self::STATE_BROKEN],
            'unreachable' => $counts[self::STATE_UNREACHABLE],
            'blocked' => $counts[self::STATE_BLOCKED],
            'remaining' => $remaining,
        ];
    }

    /**
     * Число адресов по состояниям. «Не отвечает» считается только после
     * нескольких отказов подряд.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        $out = array_fill_keys([self::STATE_OK, self::STATE_BROKEN, self::STATE_UNREACHABLE, self::STATE_BLOCKED, self::STATE_UNCHECKED], 0);
        $stmt = Database::pdo()->prepare('SELECT state, COUNT(*) AS n FROM link_checks
            WHERE state <> :u OR fail_streak >= :streak GROUP BY state');
        $stmt->execute([':u' => self::STATE_UNREACHABLE, ':streak' => self::UNREACHABLE_STREAK]);
        foreach (Database::rows($stmt) as $row) {
            $out[(string) $row['state']] = (int) $row['n'];
        }
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM link_checks WHERE state = :u AND fail_streak < :streak');
        $stmt->execute([':u' => self::STATE_UNREACHABLE, ':streak' => self::UNREACHABLE_STREAK]);
        $out[self::STATE_UNCHECKED] += (int) $stmt->fetchColumn();

        return $out;
    }

    /**
     * Проблемные адреса с местами, где они стоят.
     *
     * @return list<array{url: string, state: string, status: int, error: string, checked_at: string, first_failed_at: string, places: list<array{label: string, url: string, trashed: bool, table: string}>}>
     */
    public static function problems(): array
    {
        $stmt = Database::pdo()->prepare('SELECT url, state, status, error, checked_at, first_failed_at FROM link_checks
            WHERE state IN (:b, :k) OR (state = :u AND fail_streak >= :streak)
            ORDER BY FIELD(state, :b2, :u2, :k2), is_internal DESC, url');
        $stmt->execute([
            ':b' => self::STATE_BROKEN, ':k' => self::STATE_BLOCKED, ':u' => self::STATE_UNREACHABLE,
            ':streak' => self::UNREACHABLE_STREAK,
            ':b2' => self::STATE_BROKEN, ':u2' => self::STATE_UNREACHABLE, ':k2' => self::STATE_BLOCKED,
        ]);
        $rows = Database::rows($stmt);
        if ($rows === []) {
            return [];
        }

        $links = self::collect();
        $out = [];
        foreach ($rows as $row) {
            $url = (string) $row['url'];
            $out[] = [
                'url' => $url,
                'state' => (string) $row['state'],
                'status' => (int) $row['status'],
                'error' => (string) ($row['error'] ?? ''),
                'checked_at' => (string) ($row['checked_at'] ?? ''),
                'first_failed_at' => (string) ($row['first_failed_at'] ?? ''),
                'places' => ContentLocator::describe($links[$url] ?? []),
            ];
        }

        return $out;
    }

    /** Когда проход был в последний раз (любой адрес), или null. */
    public static function lastCheckedAt(): ?string
    {
        $value = Database::pdo()->query('SELECT MAX(checked_at) FROM link_checks')->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array{state: string, status: int, error: string} */
    private static function checkInternal(string $url): array
    {
        $uploads = rtrim((string) Config::get('paths.public_uploads_url', '/uploads/public'), '/') . '/';
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        if (str_starts_with($path, $uploads)) {
            return Media::localUploadPath($path) !== null
                ? ['state' => self::STATE_OK, 'status' => 200, 'error' => '']
                : ['state' => self::STATE_BROKEN, 'status' => 404, 'error' => 'Файла нет на диске'];
        }
        if ($path === '/download.php') {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $id = (int) ($query['file_id'] ?? 0);
            $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM files WHERE id = ?');
            $stmt->execute([$id]);

            return (int) $stmt->fetchColumn() > 0
                ? ['state' => self::STATE_OK, 'status' => 200, 'error' => '']
                : ['state' => self::STATE_BROKEN, 'status' => 404, 'error' => 'Файл удалён из медиатеки'];
        }

        $base = rtrim((string) Config::get('app.url', ''), '/');
        if (preg_match('~^https?://~i', $base) !== 1) {
            return ['state' => self::STATE_UNCHECKED, 'status' => 0, 'error' => 'Не задан адрес сайта (app.url)'];
        }
        $res = Http::get($base . $url, ['User-Agent: Mozilla/5.0 (compatible; LinkCheck)'], 10);

        return self::classify($res['status'], $res['error'], true);
    }

    /** @return array{state: string, status: int, error: string} */
    private static function checkExternal(string $url): array
    {
        $res = Http::probeSafeRemote($url, 8);
        if ($res['status'] === 0 && $res['error'] === 'unsafe remote URL') {
            return ['state' => self::STATE_UNREACHABLE, 'status' => 0, 'error' => 'Адрес не находится в DNS или ведёт во внутреннюю сеть'];
        }

        return self::classify($res['status'], $res['error'], false);
    }

    /** @return array{state: string, status: int, error: string} */
    private static function classify(int $status, string $error, bool $internal): array
    {
        if ($status >= 200 && $status < 400) {
            return ['state' => self::STATE_OK, 'status' => $status, 'error' => ''];
        }
        if ($status === 404 || $status === 410) {
            return ['state' => self::STATE_BROKEN, 'status' => $status, 'error' => $status === 410 ? 'Страница удалена' : 'Страница не найдена'];
        }
        if (in_array($status, [401, 403, 405, 429, 999], true)) {
            return ['state' => $internal ? self::STATE_OK : self::STATE_BLOCKED, 'status' => $status, 'error' => $internal ? '' : 'Сайт не пускает роботов — проверьте вручную'];
        }

        return [
            'state' => self::STATE_UNREACHABLE,
            'status' => $status,
            'error' => $status > 0 ? 'Сервер ответил ошибкой ' . $status : ($error !== '' ? mb_substr($error, 0, 200) : 'Нет ответа'),
        ];
    }

    /**
     * Сверка таблицы с контентом: новые адреса добавить, исчезнувшие удалить.
     *
     * @param list<string> $urls
     */
    private static function sync(array $urls): void
    {
        $pdo = Database::pdo();
        $current = [];
        foreach ($urls as $url) {
            $current[sha1($url)] = $url;
        }
        $known = [];
        foreach ($pdo->query('SELECT id, url_hash FROM link_checks')->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $hash) {
            $known[(string) $hash] = (int) $id;
        }

        $insert = $pdo->prepare('INSERT INTO link_checks (url_hash, url, is_internal) VALUES (?, ?, ?)');
        foreach (array_diff_key($current, $known) as $hash => $url) {
            $insert->execute([$hash, $url, self::isInternal($url) ? 1 : 0]);
        }
        $gone = array_values(array_diff_key($known, $current));
        foreach (array_chunk($gone, 500) as $chunk) {
            $pdo->prepare('DELETE FROM link_checks WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')')->execute($chunk);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function queue(): array
    {
        return Database::rows(Database::pdo()->query("SELECT id, url, is_internal, state, fail_streak, checked_at,
                TIMESTAMPDIFF(SECOND, checked_at, NOW()) AS age
            FROM link_checks
            ORDER BY checked_at IS NOT NULL, state = 'ok', checked_at, id"));
    }

    /** @param array<string, mixed> $row */
    private static function externalDue(array $row, bool $withExternal): bool
    {
        if (!$withExternal) {
            return false;
        }
        // Возраст считает база: время PHP и время MySQL бывают в разных поясах.
        if ($row['age'] === null) {
            return true;
        }
        $age = (int) $row['age'];

        return $age >= ((string) $row['state'] === self::STATE_OK ? self::RECHECK_OK : self::RECHECK_FAILED);
    }

    /**
     * @param array<string, mixed> $row
     * @param array{state: string, status: int, error: string} $result
     */
    private static function save(array $row, array $result): void
    {
        $failed = in_array($result['state'], [self::STATE_BROKEN, self::STATE_UNREACHABLE], true);
        $streak = $failed ? (int) $row['fail_streak'] + 1 : 0;

        Database::pdo()->prepare('UPDATE link_checks SET state = ?, status = ?, error = ?, fail_streak = ?,
                first_failed_at = ' . ($failed ? 'COALESCE(first_failed_at, NOW())' : 'NULL') . ', checked_at = NOW() WHERE id = ?')
            ->execute([
                $result['state'], $result['status'], $result['error'] !== '' ? mb_substr($result['error'], 0, 255) : null,
                $streak, (int) $row['id'],
            ]);
    }

    private static function isOwnHost(string $host): bool
    {
        $own = strtolower((string) parse_url((string) Config::get('app.url', ''), PHP_URL_HOST));
        if ($own === '') {
            return false;
        }
        $host = strtolower($host);
        $strip = static fn (string $h): string => str_starts_with($h, 'www.') ? substr($h, 4) : $h;

        return $strip($host) === $strip($own);
    }
}
