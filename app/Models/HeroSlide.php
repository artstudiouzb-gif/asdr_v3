<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Cache;
use App\Core\Database;
use App\Core\Hero\HeroSlideData;

/**
 * Слайд обложки.
 *
 * Порядок хранится числом (`sort_order`) и переписывается перетаскиванием в
 * админке; выборка на сайте идёт по нему же, поэтому «как в списке» и «как на
 * странице» — это один и тот же порядок, а не две настройки, которые
 * расходятся.
 */
final class HeroSlide
{
    /** Больше десяти слайдов посетитель всё равно не досмотрит. */
    public const MAX_SLIDES = 12;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forHero(int $heroId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM hero_slides WHERE hero_id = :id';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $stmt = Database::pdo()->prepare($sql . ' ORDER BY sort_order ASC, id ASC');
        $stmt->execute([':id' => $heroId]);

        return array_map(self::decode(...), $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM hero_slides WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ? self::decode($row) : null;
    }

    /**
     * Слайд с наложенным переводом. Пустое поле перевода — не «стереть текст»,
     * а «переводчик до него не дошёл»: подставляем основной язык, иначе на
     * узбекской версии главной оставался бы пустой заголовок.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $translation
     * @return array<string, mixed>
     */
    public static function applyTranslation(array $row, array $translation): array
    {
        $map = [
            'eyebrow' => 'eyebrow',
            'title' => 'title',
            'subtitle' => 'subtitle',
            'cta_text' => 'cta_text',
            'cta_url' => 'cta_url',
            'cta2_text' => 'cta2_text',
            'cta2_url' => 'cta2_url',
            'link_url' => 'link_url',
            'art_alt' => 'art_alt',
            'watermark' => 'watermark',
        ];
        foreach ($map as $column => $field) {
            $value = trim((string) ($translation[$column] ?? ''));
            if ($value !== '') {
                $row['data'][$field] = $value;
            }
        }

        return $row;
    }

    /**
     * Слайды обложки на нужном языке, только активные и попадающие в своё окно
     * показа — ровно то, что уходит в шаблон.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forDisplay(int $heroId, ?string $lang = null, ?int $now = null): array
    {
        $slides = self::forHero($heroId, true);
        if ($slides === []) {
            return [];
        }

        if ($lang !== null && $lang !== '' && $lang !== Language::defaultCode()) {
            $translations = HeroSlideTranslation::forSlides(
                array_map(static fn (array $s): int => (int) $s['id'], $slides),
                $lang
            );
            $slides = array_map(
                static fn (array $s): array => self::applyTranslation($s, $translations[(int) $s['id']] ?? []),
                $slides
            );
        }

        $now ??= time();

        return array_values(array_filter(
            $slides,
            static fn (array $s): bool => \App\Core\BlockVisibility::isVisible($s['data'], $now)
        ));
    }

    /**
     * Ближайшая граница расписания слайдов обложки — по ней пересобирается кэш
     * страницы. Слайд, который ещё не начался, тоже обязан разморозить кэш.
     */
    public static function boundary(int $heroId, ?int $now = null): ?int
    {
        $next = null;
        foreach (self::forHero($heroId, true) as $slide) {
            $point = \App\Core\BlockVisibility::boundary($slide['data']);
            if ($point !== null && ($next === null || $point < $next)) {
                $next = $point;
            }
        }

        return $next;
    }

    /**
     * @param array<string, mixed> $data уже нормализованные поля слайда
     */
    public static function create(int $heroId, array $data): ?int
    {
        if (self::count($heroId) >= self::MAX_SLIDES) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO hero_slides (hero_id, title, sort_order, is_active, data)
             VALUES (:h, :t, :o, 1, :d)'
        );
        $stmt->execute([
            ':h' => $heroId,
            ':t' => mb_substr((string) ($data['title'] ?? ''), 0, 255),
            ':o' => self::nextOrder($heroId),
            ':d' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
        $id = (int) Database::pdo()->lastInsertId();
        self::bustCache();

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare('UPDATE hero_slides SET title = :t, data = :d WHERE id = :id');
        $stmt->execute([
            ':t' => mb_substr((string) ($data['title'] ?? ''), 0, 255),
            ':d' => json_encode($data, JSON_UNESCAPED_UNICODE),
            ':id' => $id,
        ]);
        self::bustCache();
    }

    public static function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM hero_slides WHERE id = :id')->execute([':id' => $id]);
        self::bustCache();
    }

    /** Включить/выключить слайд, не удаляя его. */
    public static function toggle(int $id): void
    {
        Database::pdo()
            ->prepare('UPDATE hero_slides SET is_active = 1 - is_active WHERE id = :id')
            ->execute([':id' => $id]);
        self::bustCache();
    }

    /** Копия слайда сразу после оригинала (вместе с переводами). */
    public static function duplicate(int $id): ?int
    {
        $slide = self::find($id);
        if ($slide === null) {
            return null;
        }

        return self::copyTo((int) $slide['hero_id'], $id, (int) $slide['sort_order'] + 1);
    }

    /**
     * Копирование слайда в обложку (та же или другая — используется и при
     * дублировании обложки целиком).
     */
    public static function copyTo(int $heroId, int $slideId, ?int $order = null): ?int
    {
        $slide = self::find($slideId);
        if ($slide === null || self::count($heroId) >= self::MAX_SLIDES) {
            return null;
        }

        $order ??= self::nextOrder($heroId);
        // Освобождаем позицию: копия встаёт сразу за оригиналом, а не в конец —
        // иначе после дублирования редактор ищет её глазами по всему списку.
        Database::pdo()
            ->prepare('UPDATE hero_slides SET sort_order = sort_order + 1 WHERE hero_id = :h AND sort_order >= :o')
            ->execute([':h' => $heroId, ':o' => $order]);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO hero_slides (hero_id, title, sort_order, is_active, data)
             VALUES (:h, :t, :o, :a, :d)'
        );
        $stmt->execute([
            ':h' => $heroId,
            ':t' => (string) $slide['title'],
            ':o' => $order,
            ':a' => (int) $slide['is_active'],
            ':d' => json_encode($slide['data'], JSON_UNESCAPED_UNICODE),
        ]);
        $newId = (int) Database::pdo()->lastInsertId();
        HeroSlideTranslation::copy($slideId, $newId);
        self::bustCache();

        return $newId;
    }

    /**
     * Новый порядок слайдов после перетаскивания. Принимаем только id этой
     * обложки: список приходит из браузера, и чужой id не должен переезжать
     * в чужую обложку.
     *
     * @param list<int> $ids
     */
    public static function reorder(int $heroId, array $ids): void
    {
        $own = [];
        foreach (self::forHero($heroId) as $slide) {
            $own[(int) $slide['id']] = true;
        }

        $order = 0;
        $stmt = Database::pdo()->prepare(
            'UPDATE hero_slides SET sort_order = :o WHERE id = :id AND hero_id = :h'
        );
        foreach ($ids as $id) {
            $id = (int) $id;
            if (!isset($own[$id])) {
                continue;
            }
            $stmt->execute([':o' => $order++, ':id' => $id, ':h' => $heroId]);
            unset($own[$id]);
        }
        // Слайды, которых не было в присланном списке (гонка с другой вкладкой),
        // дописываем в конец, а не теряем.
        foreach (array_keys($own) as $id) {
            $stmt->execute([':o' => $order++, ':id' => $id, ':h' => $heroId]);
        }
        self::bustCache();
    }

    public static function count(int $heroId): int
    {
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM hero_slides WHERE hero_id = :id');
        $stmt->execute([':id' => $heroId]);

        return (int) $stmt->fetchColumn();
    }

    private static function nextOrder(int $heroId): int
    {
        $stmt = Database::pdo()->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM hero_slides WHERE hero_id = :id');
        $stmt->execute([':id' => $heroId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $data = json_decode((string) ($row['data'] ?? ''), true);
        $row['data'] = HeroSlideData::withDefaults(is_array($data) ? $data : []);

        return $row;
    }

    private static function bustCache(): void
    {
        Cache::forgetPrefix('page:');
    }
}
