<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Language
{
    private const FALLBACK = [
        'id' => 0,
        'code' => 'ru',
        'name' => 'Русский',
        'short_name' => 'Рус',
        'is_default' => 1,
        'is_active' => 1,
        'sort_order' => 0,
    ];

    private static ?array $activeCache = null;
    private static ?array $defaultCache = null;

    /**
     * @return array<int, array<string, mixed>> все языки (для админки)
     */
    public static function all(): array
    {
        if (!Database::isConnected()) {
            return [self::FALLBACK];
        }

        $stmt = Database::pdo()->query('SELECT * FROM languages ORDER BY sort_order ASC, id ASC');

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>> только активные (для сайта)
     */
    public static function active(): array
    {
        if (!Database::isConnected()) {
            return [self::FALLBACK];
        }

        if (self::$activeCache === null) {
            $stmt = Database::pdo()->query(
                'SELECT * FROM languages WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
            );
            self::$activeCache = $stmt->fetchAll();
        }

        return self::$activeCache;
    }

    public static function default(): array
    {
        if (!Database::isConnected()) {
            return self::FALLBACK;
        }

        if (self::$defaultCache === null) {
            $stmt = Database::pdo()->query('SELECT * FROM languages WHERE is_default = 1 LIMIT 1');
            $row = $stmt->fetch();
            if (!$row) {
                $row = self::FALLBACK;
            }
            self::$defaultCache = $row;
        }

        return self::$defaultCache;
    }

    /**
     * Короткое название языка для компактного переключателя.
     *
     * Пусто — берём первые буквы названия, а не код: «Рус» читается как язык,
     * «RU» — как технический идентификатор.
     *
     * @param array<string, mixed> $language
     */
    public static function shortLabel(array $language): string
    {
        $short = trim((string) ($language['short_name'] ?? ''));
        if ($short !== '') {
            return $short;
        }

        $name = trim((string) ($language['name'] ?? ''));

        return $name !== ''
            ? mb_convert_case(mb_substr($name, 0, 3), MB_CASE_TITLE, 'UTF-8')
            : strtoupper((string) ($language['code'] ?? ''));
    }

    /** @param array<string, mixed> $data */
    private static function shortNameFor(array $data): string
    {
        $short = trim((string) ($data['short_name'] ?? ''));

        return mb_substr($short, 0, 16);
    }

    public static function defaultCode(): string
    {
        return (string) self::default()['code'];
    }

    public static function activeCodes(): array
    {
        return array_map(static fn (array $l) => (string) $l['code'], self::active());
    }

    public static function isActive(string $code): bool
    {
        return in_array($code, self::activeCodes(), true);
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM languages WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function codeExists(string $code, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM languages WHERE code = :code';
        $params = [':code' => $code];
        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
            $params[':id'] = $excludeId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function create(array $data): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if (!empty($data['is_default'])) {
                $pdo->exec('UPDATE languages SET is_default = 0');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO languages (code, name, short_name, is_default, is_active, sort_order, created_at)
                 VALUES (:code, :name, :short_name, :is_default, :is_active, :sort_order, NOW())'
            );
            $stmt->execute([
                ':code' => $data['code'],
                ':name' => $data['name'],
                ':short_name' => self::shortNameFor($data),
                ':is_default' => !empty($data['is_default']) ? 1 : 0,
                ':is_active' => !empty($data['is_active']) ? 1 : 0,
                ':sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);
            $id = (int) $pdo->lastInsertId();
            self::ensureOneDefault($pdo);
            $pdo->commit();
            self::flush();

            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function update(int $id, array $data): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if (!empty($data['is_default'])) {
                $pdo->exec('UPDATE languages SET is_default = 0');
            }
            $stmt = $pdo->prepare(
                'UPDATE languages SET code = :code, name = :name, short_name = :short_name,
                 is_default = :is_default, is_active = :is_active, sort_order = :sort_order WHERE id = :id'
            );
            $stmt->execute([
                ':code' => $data['code'],
                ':name' => $data['name'],
                ':short_name' => self::shortNameFor($data),
                ':is_default' => !empty($data['is_default']) ? 1 : 0,
                ':is_active' => !empty($data['is_active']) ? 1 : 0,
                ':sort_order' => (int) ($data['sort_order'] ?? 0),
                ':id' => $id,
            ]);
            self::ensureOneDefault($pdo);
            $pdo->commit();
            self::flush();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function delete(int $id): void
    {
        $lang = self::findById($id);
        if (!$lang || (int) $lang['is_default'] === 1) {
            // Язык по умолчанию удалять нельзя.
            return;
        }
        $stmt = Database::pdo()->prepare('DELETE FROM languages WHERE id = :id');
        $stmt->execute([':id' => $id]);
        self::flush();
    }

    private static function ensureOneDefault(\PDO $pdo): void
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM languages WHERE is_default = 1')->fetchColumn();
        if ($count === 0) {
            $pdo->exec('UPDATE languages SET is_default = 1, is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1');
        }
    }

    /** Сбрасывает кэш языков в текущем процессе (после правки списка языков). */
    public static function flush(): void
    {
        self::$activeCache = null;
        self::$defaultCache = null;
    }
}
