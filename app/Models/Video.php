<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Slug;
use App\Core\Logger;
use App\Core\Translations;

/**
 * Видеозаписи: обложка + ссылка на видео (YouTube/внешнее). Блок «Медиа» на
 * главной собирает отмеченные (is_featured) автоматически.
 */
final class Video
{
    /** @return array<int, array<string, mixed>> */
    public static function all(bool $publishedOnly = false, ?string $lang = null): array
    {
        $sql = 'SELECT * FROM videos';
        if ($publishedOnly) {
            $sql .= ' WHERE is_published = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, COALESCE(published_at, created_at) DESC, id DESC';

        $rows = Database::pdo()->query($sql)->fetchAll();

        if ($lang === null || $lang === Language::defaultCode()) {
            return $rows;
        }

        return self::localizeRows($rows, $lang);
    }

    /**
     * Накладывает перевод указанного языка на базовую строку. Пустые поля
     * перевода откатываются к значению основного языка (graceful fallback).
     */
    public static function localize(array $row, string $lang): array
    {
        return self::applyTranslation($row, VideoTranslation::find((int) $row['id'], $lang));
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private static function localizeRows(array $rows, string $lang): array
    {
        $translations = VideoTranslation::forVideoIds(
            array_map(static fn (array $row): int => (int) $row['id'], $rows),
            $lang
        );
        return array_map(
            static fn (array $row): array => self::applyTranslation($row, $translations[(int) $row['id']] ?? null),
            $rows
        );
    }

    private static function applyTranslation(array $row, ?array $translation): array
    {
        return Translations::overlayFields($row, $translation, ['title', 'description']);
    }

    /**
     * Языки контента для набора видео одним запросом (без N+1).
     * Контент на языке = непустой перевод заголовка или описания.
     *
     * @param array<int|string> $ids
     * @return array<int, array<int, string>>
     */
    /**
     * Языки контента для набора роликов одним запросом (без N+1).
     * Разбор — общий для всех сущностей механизма А: полсотни строк, которые
     * повторялись здесь дословно, живут в `Translations::availableLangs()`.
     *
     * @param array<int|string> $ids
     * @return array<int, array<int, string>>
     */
    public static function availableLangsForIds(array $ids): array
    {
        return Translations::availableLangs('videos', $ids, ['title', 'description']);
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM videos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** Запись, импортированная с YouTube (ключ — id ролика). */
    public static function findByYoutubeId(string $youtubeId): ?array
    {
        $youtubeId = trim($youtubeId);
        if ($youtubeId === '') {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT * FROM videos WHERE youtube_id = :y LIMIT 1');
        $stmt->execute([':y' => $youtubeId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Заполняет карточку данными ролика с канала. Ссылка, id ролика и дата
     * публикации принадлежат источнику; название, описание, обложку и
     * длительность переписываем только при $overwriteText — редактор мог
     * поправить их вручную.
     *
     * @param array{title?:string, description?:string, cover_url?:string, video_url?:string,
     *              duration?:string, youtube_id?:string, published_at?:?string} $data
     */
    public static function applyYoutube(int $id, array $data, bool $overwriteText = true): void
    {
        $current = self::findById($id);
        if ($current === null) {
            return;
        }

        $keep = static fn (string $field): string => (string) ($current[$field] ?? '');
        $incoming = static fn (string $field): string => trim((string) ($data[$field] ?? ''));

        $title = $overwriteText && $incoming('title') !== '' ? $incoming('title') : $keep('title');
        $description = $overwriteText && $incoming('description') !== ''
            ? $incoming('description')
            : $keep('description');
        // Обложка и длительность подставляются и без перезаписи, если своих нет.
        $cover = $incoming('cover_url') !== '' && ($overwriteText || $keep('cover_url') === '')
            ? $incoming('cover_url')
            : $keep('cover_url');
        $duration = $incoming('duration') !== '' && ($overwriteText || $keep('duration') === '')
            ? $incoming('duration')
            : $keep('duration');

        $stmt = Database::pdo()->prepare(
            'UPDATE videos SET title = :t, description = :d, cover_url = :c, video_url = :v,
             duration = :dur, youtube_id = :y, source = :src, published_at = :pub WHERE id = :id'
        );
        $stmt->execute([
            ':t' => mb_substr($title, 0, 255),
            ':d' => $description,
            ':c' => mb_substr($cover, 0, 500),
            ':v' => mb_substr($incoming('video_url') !== '' ? $incoming('video_url') : $keep('video_url'), 0, 500),
            ':dur' => mb_substr($duration, 0, 20),
            ':y' => mb_substr($incoming('youtube_id'), 0, 32),
            ':src' => 'youtube',
            ':pub' => $data['published_at'] ?? ($current['published_at'] ?? null),
            ':id' => $id,
        ]);
        self::bustPageCache();
    }

    /** Отметить публикацию/показ на главной у импортированной записи. */
    public static function setFlags(int $id, bool $published, bool $featured): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE videos SET is_published = :p, is_featured = :f WHERE id = :id'
        );
        $stmt->execute([':p' => $published ? 1 : 0, ':f' => $featured ? 1 : 0, ':id' => $id]);
        self::bustPageCache();
    }

    public static function create(string $title): ?int
    {
        $title = trim($title);
        if ($title === '') {
            return null;
        }
        $base = Slug::make($title) ?: 'video';
        $slug = $base;
        $n = 2;
        while (self::slugExists($slug)) {
            $slug = $base . '-' . $n++;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO videos (title, slug, created_at) VALUES (:t, :s, NOW())'
        );
        $stmt->execute([':t' => mb_substr($title, 0, 255), ':s' => mb_substr($slug, 0, 255)]);
        $id = (int) Database::pdo()->lastInsertId();
        self::bustPageCache();

        return $id;
    }

    public static function update(
        int $id,
        string $title,
        string $description,
        string $coverUrl,
        string $videoUrl,
        string $duration,
        bool $published,
        bool $featured,
        int $sortOrder
    ): void {
        $stmt = Database::pdo()->prepare(
            'UPDATE videos SET title = :t, description = :d, cover_url = :c, video_url = :v,
             duration = :dur, is_published = :p, is_featured = :f, sort_order = :o WHERE id = :id'
        );
        $stmt->execute([
            ':t' => mb_substr(trim($title), 0, 255),
            ':d' => trim($description),
            ':c' => mb_substr(trim($coverUrl), 0, 500),
            ':v' => mb_substr(trim($videoUrl), 0, 500),
            ':dur' => mb_substr(trim($duration), 0, 20),
            ':p' => $published ? 1 : 0,
            ':f' => $featured ? 1 : 0,
            ':o' => $sortOrder,
            ':id' => $id,
        ]);
        self::bustPageCache();
    }

    /**
     * Массовые действия из списка: публикация, «на главной», удаление.
     * Одним запросом на всю выборку — импорт с канала приносит записи
     * десятками, и построчный цикл здесь означал бы сотню запросов.
     *
     * @param array<int, int|string> $ids
     */
    public static function setPublishedMany(array $ids, bool $published): int
    {
        return self::updateFlagMany($ids, 'is_published', $published);
    }

    /** @param array<int, int|string> $ids */
    public static function setFeaturedMany(array $ids, bool $featured): int
    {
        return self::updateFlagMany($ids, 'is_featured', $featured);
    }

    /** @param array<int, int|string> $ids */
    public static function deleteMany(array $ids): int
    {
        $ids = self::normalizeIds($ids);
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare("DELETE FROM videos WHERE id IN ($in)");
        $stmt->execute($ids);
        self::bustPageCache();

        return $stmt->rowCount();
    }

    /** @param array<int, int|string> $ids */
    private static function updateFlagMany(array $ids, string $column, bool $value): int
    {
        $ids = self::normalizeIds($ids);
        if ($ids === []) {
            return 0;
        }
        // Имя колонки не из пользовательского ввода — оно приходит из методов
        // выше, значения по-прежнему уходят плейсхолдерами.
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare("UPDATE videos SET {$column} = ? WHERE id IN ($in)");
        $stmt->execute(array_merge([$value ? 1 : 0], $ids));
        self::bustPageCache();

        // Не rowCount(): MySQL не считает строки, у которых значение и так было
        // нужным, и «Опубликовано: 0» вместо «12» выглядело бы как сбой.
        return count($ids);
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, int>
     */
    private static function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
    }

    public static function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM videos WHERE id = :id')->execute([':id' => $id]);
        self::bustPageCache();
    }

    public static function slugExists(string $slug): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM videos WHERE slug = :s LIMIT 1');
        $stmt->execute([':s' => $slug]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Видео для блока «Медиа» на главной: отмеченные «показать на главном»;
     * если ни одного — откат на последние опубликованные.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forHome(int $limit = 8, ?string $lang = null): array
    {
        $limit = max(1, min(24, $limit));
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM videos WHERE is_published = 1 AND is_featured = 1
             ORDER BY sort_order ASC, COALESCE(published_at, created_at) DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (empty($rows)) {
            $stmt = Database::pdo()->prepare(
                'SELECT * FROM videos WHERE is_published = 1
                 ORDER BY sort_order ASC, COALESCE(published_at, created_at) DESC, id DESC LIMIT ' . $limit
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();
        }

        if ($lang === null || $lang === Language::defaultCode()) {
            return $rows;
        }

        return self::localizeRows($rows, $lang);
    }

    /**
     * Срез опубликованных видео для постраничного вывода в блоке «Медиа».
     *
     * От forHome() отличается смыслом: там витрина главной (отмеченные
     * «на главной», иначе последние), здесь — весь список подряд, потому что
     * читатель листает его страницами и обязан дойти до последней записи.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function publishedSlice(int $limit, int $offset = 0, ?string $lang = null): array
    {
        $limit = max(1, min(48, $limit));
        $offset = max(0, $offset);
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM videos WHERE is_published = 1
             ORDER BY sort_order ASC, COALESCE(published_at, created_at) DESC, id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        if ($lang === null || $lang === Language::defaultCode()) {
            return $rows;
        }

        return self::localizeRows($rows, $lang);
    }

    /** Сколько всего опубликованных видео (для полосы страниц). */
    public static function publishedTotal(): int
    {
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM videos WHERE is_published = 1')->fetchColumn();
    }

    private static function bustPageCache(): void
    {
        \App\Core\Cache::forgetPrefix('page:');
    }
}
