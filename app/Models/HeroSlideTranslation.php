<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\BlockData\BlockDataInput;
use App\Core\Cache;
use App\Core\Database;

/**
 * Перевод содержимого слайда.
 *
 * Медиа, цвета и раскладка остаются общими для всех языков. Переводятся
 * читаемые тексты и адреса действий: у RU/UZ версии одной кнопки могут быть
 * разные целевые страницы.
 */
final class HeroSlideTranslation
{
    /** @var list<string> */
    public const FIELDS = [
        'eyebrow', 'title', 'subtitle', 'cta_text', 'cta_url',
        'cta2_text', 'cta2_url', 'link_url', 'art_alt', 'watermark',
    ];

    /** @var list<string> */
    private const LINK_FIELDS = ['cta_url', 'cta2_url', 'link_url'];

    /**
     * Все переводы одного слайда, по коду языка (для формы).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forSlide(int $slideId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM hero_slide_translations WHERE slide_id = :id');
        $stmt->execute([':id' => $slideId]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['lang']] = $row;
        }

        return $result;
    }

    /**
     * Один язык для набора слайдов — обложка рендерится на каждой странице,
     * поэтому N+1 здесь недопустим.
     *
     * @param list<int> $slideIds
     * @return array<int, array<string, mixed>>
     */
    public static function forSlides(array $slideIds, string $lang): array
    {
        $slideIds = array_values(array_unique(array_filter(
            array_map('intval', $slideIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($slideIds === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($slideIds), '?'));
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM hero_slide_translations WHERE slide_id IN ({$in}) AND lang = ?"
        );
        $stmt->execute([...$slideIds, $lang]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['slide_id']] = $row;
        }

        return $result;
    }

    /** @param array<string, mixed> $values */
    public static function upsert(int $slideId, string $lang, array $values): void
    {
        $clean = [];
        foreach (self::FIELDS as $field) {
            if (in_array($field, self::LINK_FIELDS, true)) {
                $value = BlockDataInput::safeLink($values[$field] ?? '');
                $clean[$field] = $value === '' ? null : mb_substr($value, 0, 2048);
                continue;
            }

            $value = trim((string) ($values[$field] ?? ''));
            $clean[$field] = $value === ''
                ? null
                : mb_substr($value, 0, $field === 'subtitle' ? 2000 : 255);
        }

        // Полностью пустой перевод не храним: язык без текста и без своей
        // ссылки ничем не отличается от наследования основного языка.
        if (array_filter($clean, static fn (?string $v): bool => $v !== null) === []) {
            self::delete($slideId, $lang);

            return;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO hero_slide_translations
                (slide_id, lang, eyebrow, title, subtitle, cta_text, cta_url, cta2_text, cta2_url, link_url, art_alt, watermark)
             VALUES
                (:id, :lang, :eyebrow, :title, :subtitle, :cta_text, :cta_url, :cta2_text, :cta2_url, :link_url, :art_alt, :watermark)
             ON DUPLICATE KEY UPDATE
                eyebrow = VALUES(eyebrow), title = VALUES(title), subtitle = VALUES(subtitle),
                cta_text = VALUES(cta_text), cta_url = VALUES(cta_url),
                cta2_text = VALUES(cta2_text), cta2_url = VALUES(cta2_url),
                link_url = VALUES(link_url), art_alt = VALUES(art_alt), watermark = VALUES(watermark)'
        );
        $stmt->execute([
            ':id' => $slideId,
            ':lang' => $lang,
            ':eyebrow' => $clean['eyebrow'],
            ':title' => $clean['title'],
            ':subtitle' => $clean['subtitle'],
            ':cta_text' => $clean['cta_text'],
            ':cta_url' => $clean['cta_url'],
            ':cta2_text' => $clean['cta2_text'],
            ':cta2_url' => $clean['cta2_url'],
            ':link_url' => $clean['link_url'],
            ':art_alt' => $clean['art_alt'],
            ':watermark' => $clean['watermark'],
        ]);
        Cache::forgetPrefix('page:');
    }

    public static function delete(int $slideId, string $lang): void
    {
        Database::pdo()
            ->prepare('DELETE FROM hero_slide_translations WHERE slide_id = :id AND lang = :lang')
            ->execute([':id' => $slideId, ':lang' => $lang]);
        Cache::forgetPrefix('page:');
    }

    /** Переводы вместе с копией слайда: дубль сохраняет и языковые URL. */
    public static function copy(int $fromSlideId, int $toSlideId): void
    {
        Database::pdo()->prepare(
            'INSERT INTO hero_slide_translations
                (slide_id, lang, eyebrow, title, subtitle, cta_text, cta_url, cta2_text, cta2_url, link_url, art_alt, watermark)
             SELECT :to, lang, eyebrow, title, subtitle, cta_text, cta_url, cta2_text, cta2_url, link_url, art_alt, watermark
             FROM hero_slide_translations WHERE slide_id = :from
             ON DUPLICATE KEY UPDATE
                eyebrow = VALUES(eyebrow), title = VALUES(title), subtitle = VALUES(subtitle),
                cta_text = VALUES(cta_text), cta_url = VALUES(cta_url),
                cta2_text = VALUES(cta2_text), cta2_url = VALUES(cta2_url),
                link_url = VALUES(link_url), art_alt = VALUES(art_alt), watermark = VALUES(watermark)'
        )->execute([':to' => $toSlideId, ':from' => $fromSlideId]);
    }

    /**
     * Языки, на которых у слайда есть хоть одно собственное значение.
     *
     * @param array<int|string> $ids
     * @return array<int, list<string>>
     */
    public static function availableLangsForIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $map = [];
        foreach ($ids as $id) {
            $map[$id] = [];
        }
        if ($ids === []) {
            return $map;
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $default = Language::defaultCode();

        $base = Database::pdo()->prepare("SELECT id, title FROM hero_slides WHERE id IN ({$in})");
        $base->execute($ids);
        foreach ($base->fetchAll() as $row) {
            $id = (int) $row['id'];
            if (isset($map[$id]) && trim((string) ($row['title'] ?? '')) !== '') {
                $map[$id][] = $default;
            }
        }

        $trans = Database::pdo()->prepare(
            "SELECT slide_id, lang FROM hero_slide_translations
             WHERE slide_id IN ({$in})
               AND (
                    TRIM(COALESCE(title, '')) <> '' OR TRIM(COALESCE(subtitle, '')) <> ''
                    OR TRIM(COALESCE(cta_text, '')) <> '' OR TRIM(COALESCE(cta_url, '')) <> ''
                    OR TRIM(COALESCE(cta2_text, '')) <> '' OR TRIM(COALESCE(cta2_url, '')) <> ''
                    OR TRIM(COALESCE(link_url, '')) <> '' OR TRIM(COALESCE(art_alt, '')) <> ''
                    OR TRIM(COALESCE(watermark, '')) <> ''
               )"
        );
        $trans->execute($ids);
        foreach ($trans->fetchAll() as $row) {
            $id = (int) $row['slide_id'];
            $lang = (string) $row['lang'];
            if (isset($map[$id]) && !in_array($lang, $map[$id], true)) {
                $map[$id][] = $lang;
            }
        }

        return $map;
    }
}
