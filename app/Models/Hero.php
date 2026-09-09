<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Cache;
use App\Core\Database;
use App\Core\Hero\HeroSettings;

/**
 * Обложка (Hero) — отдельный тип контента: запись, у которой есть общие
 * настройки и набор слайдов (`hero_slides`).
 *
 * Блок страницы содержимого не хранит: он ссылается на обложку по id, поэтому
 * одна и та же обложка выводится на нескольких страницах и правится в одном
 * месте. Публикация у обложки своя (черновик / опубликована / по расписанию) —
 * это позволяет собрать обложку к празднику заранее и не выкладывать её руками
 * в нужный день.
 */
final class Hero
{
    public const STATUSES = ['draft', 'published', 'scheduled'];

    /**
     * Все обложки для админки (без удалённых).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return Database::pdo()->query(
            'SELECT * FROM heroes WHERE deleted_at IS NULL
             ORDER BY priority DESC, name ASC, id ASC'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM heroes WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Обложка для вывода на сайте: найдётся только если окно публикации открыто.
     * Пустой ответ — не ошибка: блок с невидимой обложкой просто ничего не
     * рисует, а не показывает пустую чёрную полосу.
     *
     * @return array<string, mixed>|null
     */
    public static function findVisible(int $id, ?int $now = null): ?array
    {
        $row = self::find($id);

        return $row !== null && self::isVisible($row, $now) ? $row : null;
    }

    /**
     * Видна ли обложка прямо сейчас.
     *
     * `scheduled` без дат ведёт себя как черновик: редактор выбрал расписание,
     * но не заполнил его — показывать «всегда» было бы обратным тому, что он
     * просил.
     *
     * @param array<string, mixed> $row
     */
    public static function isVisible(array $row, ?int $now = null): bool
    {
        $status = (string) ($row['status'] ?? 'draft');
        if ($status === 'published') {
            return true;
        }
        if ($status !== 'scheduled') {
            return false;
        }

        $now ??= time();
        $from = self::timestamp($row['published_from'] ?? null);
        $to = self::timestamp($row['published_to'] ?? null);
        if ($from === null && $to === null) {
            return false;
        }

        return ($from === null || $now >= $from) && ($to === null || $now < $to);
    }

    /**
     * Ближайшая граница расписания в будущем — по ней PageController
     * пересобирает кэш страницы. Без этого обложка «до 30 июля» висела бы и
     * 31-го: кэш общий для всех посетителей и сам по себе не протухает.
     *
     * @param array<string, mixed> $row
     */
    public static function boundary(array $row, ?int $now = null): ?int
    {
        if ((string) ($row['status'] ?? '') !== 'scheduled') {
            return null;
        }

        $now ??= time();
        $next = null;
        foreach ([self::timestamp($row['published_from'] ?? null), self::timestamp($row['published_to'] ?? null)] as $point) {
            if ($point !== null && $point > $now && ($next === null || $point < $next)) {
                $next = $point;
            }
        }

        return $next;
    }

    /**
     * Общие настройки обложки, дополненные значениями по умолчанию.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function settings(array $row): array
    {
        $raw = json_decode((string) ($row['settings'] ?? ''), true);

        return HeroSettings::withDefaults(is_array($raw) ? $raw : []);
    }

    public static function create(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO heroes (name, status, settings) VALUES (:n, :st, :s)'
        );
        $stmt->execute([
            ':n' => mb_substr($name, 0, 190),
            ':st' => 'draft',
            ':s' => json_encode(HeroSettings::defaults(), JSON_UNESCAPED_UNICODE),
        ]);
        // id читаем до сброса кэша: bustPageCache() ходит в settings и обнуляет
        // last insert id на холодном кэше (см. CLAUDE.md, «Грабли»).
        $id = (int) Database::pdo()->lastInsertId();
        self::bustCache();

        return $id;
    }

    /**
     * @param array<string, mixed> $settings уже нормализованные настройки
     */
    public static function update(
        int $id,
        string $name,
        string $status,
        ?string $from,
        ?string $to,
        int $priority,
        array $settings,
        string $preset
    ): void {
        $name = trim($name);
        if ($name === '') {
            return;
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE heroes SET name = :n, status = :st, published_from = :f, published_to = :t,
                    priority = :p, preset = :pr, settings = :s
             WHERE id = :id'
        );
        $stmt->execute([
            ':n' => mb_substr($name, 0, 190),
            ':st' => in_array($status, self::STATUSES, true) ? $status : 'draft',
            ':f' => self::datetimeOrNull($from),
            ':t' => self::datetimeOrNull($to),
            ':p' => max(-999, min(999, $priority)),
            ':pr' => mb_substr(trim($preset), 0, 32),
            ':s' => json_encode($settings, JSON_UNESCAPED_UNICODE),
            ':id' => $id,
        ]);
        self::bustCache();
    }

    /** Сохранение только настроек (применение пресета). @param array<string, mixed> $settings */
    public static function saveSettings(int $id, array $settings, string $preset): void
    {
        $stmt = Database::pdo()->prepare('UPDATE heroes SET settings = :s, preset = :p WHERE id = :id');
        $stmt->execute([
            ':s' => json_encode($settings, JSON_UNESCAPED_UNICODE),
            ':p' => mb_substr(trim($preset), 0, 32),
            ':id' => $id,
        ]);
        self::bustCache();
    }

    /**
     * Мягкое удаление: блоки, которые ссылались на обложку, перестают её
     * показывать, но восстановить запись можно — слайды остаются на месте.
     */
    public static function delete(int $id): void
    {
        Database::pdo()
            ->prepare('UPDATE heroes SET deleted_at = NOW() WHERE id = :id')
            ->execute([':id' => $id]);
        self::bustCache();
    }

    /** Полная копия обложки вместе со слайдами и их переводами. */
    public static function duplicate(int $id): ?int
    {
        $row = self::find($id);
        if ($row === null) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO heroes (name, status, published_from, published_to, priority, preset, settings)
             VALUES (:n, :st, :f, :t, :p, :pr, :s)'
        );
        $stmt->execute([
            ':n' => mb_substr(trim((string) $row['name']) . ' (копия)', 0, 190),
            // Копия всегда черновик: дубль опубликованной обложки не должен
            // попасть на сайт раньше, чем редактор её проверит.
            ':st' => 'draft',
            ':f' => $row['published_from'],
            ':t' => $row['published_to'],
            ':p' => (int) $row['priority'],
            ':pr' => (string) $row['preset'],
            ':s' => (string) $row['settings'],
        ]);
        $newId = (int) Database::pdo()->lastInsertId();
        foreach (HeroSlide::forHero($id) as $slide) {
            HeroSlide::copyTo($newId, (int) $slide['id']);
        }
        self::bustCache();

        return $newId;
    }

    /**
     * Сколько слайдов у каждой обложки (для списка в админке).
     *
     * @return array<int, array{total: int, active: int}>
     */
    public static function slideCounts(): array
    {
        $rows = Database::pdo()->query(
            'SELECT hero_id, COUNT(*) AS total, SUM(is_active = 1) AS active
             FROM hero_slides GROUP BY hero_id'
        )->fetchAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['hero_id']] = [
                'total' => (int) $row['total'],
                'active' => (int) $row['active'],
            ];
        }

        return $counts;
    }

    /**
     * Где обложка используется: id блоков и названия их страниц. Нужно перед
     * удалением — «удалить обложку, которая стоит на главной» должно быть
     * осознанным действием, а не сюрпризом.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function usage(int $id): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT b.id AS block_id, b.page_id, b.data, p.title, p.slug, p.lang
             FROM blocks b
             LEFT JOIN pages p ON p.id = b.page_id
             WHERE b.type = 'hero' AND b.data LIKE :needle
             ORDER BY p.title ASC, b.id ASC"
        );
        $stmt->execute([':needle' => '%"hero_id":' . $id . '%']);

        // LIKE даёт ложные срабатывания (hero_id:1 против hero_id:12) —
        // отсеиваем их разбором уже прочитанных данных, без второго запроса.
        return array_values(array_filter(
            $stmt->fetchAll(),
            static function (array $row) use ($id): bool {
                $data = json_decode((string) ($row['data'] ?? ''), true);

                return is_array($data) && (int) ($data['hero_id'] ?? 0) === $id;
            }
        ));
    }

    private static function timestamp(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        $ts = strtotime($value);

        return $ts === false ? null : $ts;
    }

    private static function datetimeOrNull(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        // Поле формы datetime-local отдаёт «2026-08-14T10:00»; MySQL ждёт пробел.
        $ts = strtotime(str_replace('T', ' ', $value));

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    private static function bustCache(): void
    {
        Cache::forgetPrefix('page:');
    }
}
