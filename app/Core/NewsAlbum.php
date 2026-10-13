<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;
use App\Models\News;
use App\Models\PhotoAlbum;

/**
 * Фотоальбом из новости, которая показывается галереей: «Умный макет» или
 * явная «Галерея», у которых кадров (обложка + галерея) больше одного —
 * то же правило, по которому страница новости включает слайдер
 * (`News::showsGallery`). Иначе альбомы искали бы только явную «Галерею», а
 * на сайте почти все галереи — «Умный макет».
 *
 * Снимки события живут в новости, а раздел «Фотоальбомы» собирался руками —
 * то есть те же фотографии загружали второй раз, и чаще всего не загружали
 * вовсе. Альбом берёт у новости только заголовок и фотографии и ведёт
 * ссылкой обратно на неё (`photo_albums.news_id`): текст события остаётся в
 * одном месте.
 *
 * Альбом — производная запись: сохранение новости пересобирает его (заголовок,
 * переводы заголовка, набор фото, дата, публикация), поэтому править фото
 * нужно в новости. Новость, переставшая быть галереей, свой альбом не
 * удаляет — его могли уже отметить «на главной» или сослаться на него.
 *
 * Новость переводится отдельной записью (механизм Б), а альбом — полями
 * (механизм А): заголовок записи основного языка становится заголовком
 * альбома, заголовки остальных — его переводами. Фото берутся у записи, у
 * которой они есть, — обычно галерею заполняют один раз.
 */
final class NewsAlbum
{
    public const BACKFILL_DEFAULT = 20;

    /**
     * Собрать или обновить альбом новости. Возвращает id альбома или null,
     * если новость не галерея либо фотографий в ней нет.
     */
    public static function sync(int $newsId): ?int
    {
        $members = self::groupMembers($newsId);
        if ($members === []) {
            return null;
        }
        $primary = self::pickPrimary($members);
        $photos = self::photos($members);
        $pdo = Database::pdo();
        $existing = $pdo->prepare('SELECT id FROM photo_albums WHERE news_id = :n LIMIT 1');
        $existing->execute([':n' => (int) $primary['id']]);
        $albumId = (int) $existing->fetchColumn();
        // Создаёт альбом только галерея. Уже созданный альбом следует за
        // новостью и дальше — иначе снятые из неё фото оставались бы в нём и
        // считались «используемыми» при чистке медиатеки.
        if ($photos === [] || $albumId <= 0 && !News::showsGallery($primary['layout_type'] ?? '', count($photos))) {
            return null;
        }

        $title = trim((string) $primary['title']);
        $published = self::isLive($primary);
        $date = (string) ($primary['published_at'] ?? '') !== '' ? (string) $primary['published_at'] : (string) $primary['created_at'];

        if ($albumId <= 0) {
            $albumId = (int) PhotoAlbum::create($title, '', '', $published);
            if ($albumId <= 0) {
                return null;
            }
            $pdo->prepare('UPDATE photo_albums SET news_id = :n WHERE id = :id')->execute([':n' => (int) $primary['id'], ':id' => $albumId]);
        }
        // Описание альбома пустое намеренно: текст события живёт в новости,
        // и альбом ведёт к нему ссылкой, а не второй копией.
        $pdo->prepare('UPDATE photo_albums SET title = :t, is_published = :p, created_at = :d WHERE id = :id')->execute([
            ':t' => mb_substr($title, 0, 255),
            ':p' => $published ? 1 : 0,
            ':d' => $date,
            ':id' => $albumId,
        ]);

        $pdo->prepare('DELETE FROM photo_album_images WHERE album_id = :a')->execute([':a' => $albumId]);
        $insert = $pdo->prepare(
            'INSERT INTO photo_album_images (album_id, image_url, caption, credit, sort_order, created_at)
             VALUES (:a, :u, :c, :cr, :o, NOW())'
        );
        foreach ($photos as $i => $photo) {
            $insert->execute([
                ':a' => $albumId,
                ':u' => mb_substr($photo['url'], 0, 500),
                ':c' => mb_substr($photo['caption'], 0, 255),
                ':cr' => mb_substr($photo['credit'], 0, 255),
                ':o' => $i + 1,
            ]);
        }

        $base = (string) ($primary['lang'] ?? '');
        $pdo->prepare('DELETE FROM photo_album_translations WHERE album_id = :a')->execute([':a' => $albumId]);
        $tr = $pdo->prepare('INSERT INTO photo_album_translations (album_id, lang, title, description) VALUES (:a, :l, :t, NULL)');
        foreach ($members as $member) {
            $lang = (string) ($member['lang'] ?? '');
            $memberTitle = trim((string) $member['title']);
            if ($lang === '' || $lang === $base || $memberTitle === '' || !self::isLive($member) && $published) {
                continue;
            }
            $tr->execute([':a' => $albumId, ':l' => $lang, ':t' => mb_substr($memberTitle, 0, 255)]);
        }
        Cache::forgetPrefix('page:');

        return $albumId;
    }

    /**
     * Альбомы для последних новостей-галерей — то, что было опубликовано до
     * появления автосборки. Повтор безопасен: существующие альбомы
     * обновляются, а не дублируются.
     *
     * @return array{created:int,updated:int,skipped:int}
     */
    public static function backfill(int $limit = self::BACKFILL_DEFAULT): array
    {
        $limit = max(1, min(500, $limit));
        // Одна строка на группу перевода: альбом у группы один. Галерея ли
        // это, решают кадры, а их в запросе не посчитать дёшево, поэтому
        // кандидаты перебираются от свежих, пока не наберётся $limit альбомов.
        $rows = Database::pdo()->query(
            "SELECT MIN(id) AS id, MAX(COALESCE(published_at, created_at)) AS sort_at
               FROM news
              WHERE deleted_at IS NULL AND status = 'published' AND (layout_type IS NULL OR layout_type IN ('', 'standard', 'gallery'))
              GROUP BY COALESCE(NULLIF(translation_group_id, 0), id)
              ORDER BY sort_at DESC"
        )->fetchAll(\PDO::FETCH_COLUMN);

        $known = self::albumNewsIds();
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($rows as $id) {
            if ($result['created'] + $result['updated'] >= $limit) {
                break;
            }
            $members = self::groupMembers((int) $id);
            $primaryId = $members === [] ? 0 : (int) self::pickPrimary($members)['id'];
            $had = isset($known[$primaryId]);
            if (self::sync((int) $id) === null) {
                $result['skipped']++;
            } elseif ($had) {
                $result['updated']++;
            } else {
                $result['created']++;
            }
        }

        return $result;
    }

    /**
     * Адрес новости альбома на языке посетителя: запись этого языка, иначе
     * запись, от имени которой собран альбом. null — новости больше нет или
     * она снята с публикации.
     *
     * @param array<string, mixed> $album
     */
    public static function newsUrl(array $album, string $lang): ?string
    {
        $newsId = (int) ($album['news_id'] ?? 0);
        if ($newsId <= 0) {
            return null;
        }
        $live = array_values(array_filter(self::groupMembers($newsId), static fn (array $m): bool => self::isLive($m)));
        if ($live === []) {
            return null;
        }
        $row = null;
        foreach ($live as $member) {
            if ((string) $member['lang'] === $lang) {
                $row = $member;
                break;
            }
        }
        if ($row === null) {
            foreach ($live as $member) {
                if ((int) $member['id'] === $newsId) {
                    $row = $member;
                }
            }
            $row ??= $live[0];
            $lang = (string) $row['lang'];
        }

        return Locale::url('/news/' . rawurlencode((string) $row['slug']), $lang);
    }

    /** @return array<int, true> id новостей, у которых уже есть альбом */
    private static function albumNewsIds(): array
    {
        $ids = Database::pdo()->query('SELECT news_id FROM photo_albums WHERE news_id IS NOT NULL')->fetchAll(\PDO::FETCH_COLUMN);

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /** @return list<array<string, mixed>> */
    private static function groupMembers(int $newsId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT n.id, n.title, n.slug, n.lang, n.status, n.layout_type, n.image, n.published_at, n.created_at
               FROM news n
               JOIN news s ON s.id = :id
              WHERE n.deleted_at IS NULL
                AND COALESCE(NULLIF(n.translation_group_id, 0), n.id) = COALESCE(NULLIF(s.translation_group_id, 0), s.id)
              ORDER BY n.id'
        );
        $stmt->execute([':id' => $newsId]);

        return Database::rows($stmt);
    }

    /**
     * Запись основного языка, иначе первая в группе.
     *
     * @param non-empty-list<array<string, mixed>> $members
     * @return array<string, mixed>
     */
    private static function pickPrimary(array $members): array
    {
        $default = Language::defaultCode();
        foreach ($members as $member) {
            if ((string) $member['lang'] === $default) {
                return $member;
            }
        }

        return $members[0];
    }

    /**
     * Кадры записи, у которой они есть, — тем же правилом, что страница
     * новости: обложка, затем галерея без повтора обложки. Обычно галерею
     * заполняют у одной языковой версии.
     *
     * @param list<array<string, mixed>> $members
     * @return list<array{url:string,caption:string,credit:string}>
     */
    private static function photos(array $members): array
    {
        $stmt = Database::pdo()->prepare('SELECT path, caption, credit, alt_text FROM news_images WHERE news_id = :n ORDER BY sort_order, id');
        $best = [];
        foreach ($members as $member) {
            $stmt->execute([':n' => (int) $member['id']]);
            $slides = News::slides((string) ($member['image'] ?? ''), (string) $member['title'], Database::rows($stmt));
            if (count($slides) > count($best)) {
                $best = $slides;
            }
        }

        return array_map(
            static fn (array $slide): array => ['url' => $slide['path'], 'caption' => $slide['caption'], 'credit' => $slide['credit']],
            $best
        );
    }

    /**
     * Опубликована и уже наступила. Отложенная новость даёт скрытый альбом:
     * иначе фото события появились бы в разделе раньше самой новости.
     *
     * @param array<string, mixed> $row
     */
    private static function isLive(array $row): bool
    {
        if ((string) ($row['status'] ?? '') !== 'published') {
            return false;
        }
        $at = (string) ($row['published_at'] ?? '');

        return $at === '' || strtotime($at) <= time();
    }
}
