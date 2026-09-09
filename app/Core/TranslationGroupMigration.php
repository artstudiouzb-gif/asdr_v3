<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class TranslationGroupMigration
{
    private static bool $migrated = false;

    public static function run(): void
    {
        if (self::$migrated || !Database::isConnected()) {
            return;
        }

        TranslationGroupHelper::ensureSchema();
        $pdo = Database::pdo();

        // 1. Убеждаемся, что все существующие записи имеют lang='ru' и translation_group_id = id
        $pdo->exec("UPDATE news SET lang = 'ru' WHERE lang IS NULL OR lang = ''");
        $pdo->exec("UPDATE news SET translation_group_id = id WHERE translation_group_id IS NULL OR translation_group_id = 0");

        $pdo->exec("UPDATE pages SET lang = 'ru' WHERE lang IS NULL OR lang = ''");
        $pdo->exec("UPDATE pages SET translation_group_id = id WHERE translation_group_id IS NULL OR translation_group_id = 0");

        // Проекты — строки той же таблицы pages, отдельная нормализация им не нужна.

        // 2. Миграция news_translations в независимые строки таблицы news
        try {
            if ((bool) $pdo->query("SHOW TABLES LIKE 'news_translations'")->fetchColumn()) {
                $rows = $pdo->query("SELECT * FROM news_translations")->fetchAll();
                foreach ($rows as $nt) {
                    $origId = (int) $nt['news_id'];
                    $lang = (string) $nt['lang'];
                    $title = trim((string) ($nt['title'] ?? ''));
                    if ($title === '' || $lang === '' || $lang === 'ru') {
                        continue;
                    }

                    $existStmt = $pdo->prepare("SELECT id FROM news WHERE translation_group_id = :gid AND lang = :lang AND deleted_at IS NULL LIMIT 1");
                    $existStmt->execute([':gid' => $origId, ':lang' => $lang]);
                    $exists = $existStmt->fetchColumn();
                    if ($exists !== false) {
                        continue;
                    }

                    $origStmt = $pdo->prepare("SELECT * FROM news WHERE id = :id LIMIT 1");
                    $origStmt->execute([':id' => $origId]);
                    $orig = $origStmt->fetch();
                    if (!$orig) {
                        continue;
                    }

                    $newSlug = ($orig['slug'] ?? 'news') . '-' . strtolower($lang);
                    $slugCheck = $pdo->prepare("SELECT id FROM news WHERE slug = :s LIMIT 1");
                    $slugCheck->execute([':s' => $newSlug]);
                    if ($slugCheck->fetchColumn() !== false) {
                        $newSlug .= '-' . bin2hex(random_bytes(2));
                    }

                    $ins = $pdo->prepare(
                        "INSERT INTO news (title, slug, excerpt, lead_html, badge, content, image, video_url, audio_url, audio_title, hashtags, press_release_url, key_points, event_meta, timeline_json, docs, source_note, layout_type, sidebar_layout, focal_x, focal_y, meta_title, meta_description, status, published_at, author_id, lang, translation_group_id, created_at)
                          VALUES (:t, :s, :e, :lh, :b, :c, :img, :v, :a, :at, :h, :pr, :kp, :em, :tj, :dc, :sn, :lt, :sl, :fx, :fy, :mt, :md, :st, :pub, :auth, :lang, :gid, NOW())"
                    );
                    $ins->execute([
                        ':t' => $title,
                        ':s' => $newSlug,
                        ':e' => $nt['excerpt'] ?? $orig['excerpt'] ?? null,
                        ':lh' => $nt['lead_html'] ?? $orig['lead_html'] ?? null,
                        ':b' => $nt['badge'] ?? $orig['badge'] ?? null,
                        ':c' => $nt['content'] ?? $orig['content'] ?? null,
                        ':img' => $orig['image'] ?? null,
                        ':v' => $orig['video_url'] ?? null,
                        ':a' => $orig['audio_url'] ?? null,
                        ':at' => $orig['audio_title'] ?? null,
                        ':h' => $nt['hashtags'] ?? $orig['hashtags'] ?? null,
                        ':pr' => $orig['press_release_url'] ?? null,
                        ':kp' => $nt['key_points'] ?? $orig['key_points'] ?? null,
                        ':em' => $nt['event_meta'] ?? $orig['event_meta'] ?? null,
                        ':tj' => $nt['timeline_json'] ?? $orig['timeline_json'] ?? null,
                        ':dc' => $nt['docs'] ?? $orig['docs'] ?? null,
                        ':sn' => $orig['source_note'] ?? null,
                        ':lt' => $orig['layout_type'] ?? 'standard',
                        ':sl' => $orig['sidebar_layout'] ?? 'right_sidebar',
                        ':fx' => $orig['focal_x'] ?? null,
                        ':fy' => $orig['focal_y'] ?? null,
                        ':mt' => $nt['meta_title'] ?? $orig['meta_title'] ?? null,
                        ':md' => $nt['meta_description'] ?? $orig['meta_description'] ?? null,
                        ':st' => $orig['status'] ?? 'published',
                        ':pub' => $orig['published_at'] ?? date('Y-m-d H:i:s'),
                        ':auth' => $orig['author_id'] ?? null,
                        ':lang' => $lang,
                        ':gid' => $origId,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Logger::swallowed('TranslationGroupMigration: перенос news_translations в отдельные записи не завершён', $e);
        }

        // 3. Миграция page_translations в независимые строки таблицы pages
        try {
            if ((bool) $pdo->query("SHOW TABLES LIKE 'page_translations'")->fetchColumn()) {
                $rows = $pdo->query("SELECT * FROM page_translations")->fetchAll();
                foreach ($rows as $pt) {
                    $origId = (int) $pt['page_id'];
                    $lang = (string) $pt['lang'];
                    $title = trim((string) ($pt['title'] ?? ''));
                    if ($title === '' || $lang === '' || $lang === 'ru') {
                        continue;
                    }

                    $existStmt = $pdo->prepare("SELECT id FROM pages WHERE translation_group_id = :gid AND lang = :lang AND deleted_at IS NULL LIMIT 1");
                    $existStmt->execute([':gid' => $origId, ':lang' => $lang]);
                    $exists = $existStmt->fetchColumn();
                    if ($exists !== false) {
                        continue;
                    }

                    $origStmt = $pdo->prepare("SELECT * FROM pages WHERE id = :id LIMIT 1");
                    $origStmt->execute([':id' => $origId]);
                    $orig = $origStmt->fetch();
                    if (!$orig) {
                        continue;
                    }

                    $newSlug = ($orig['slug'] ?? 'page') . '-' . strtolower($lang);
                    // entity_type переносим как есть: проект — это страница с
                    // подтипом, и его языковая версия обязана остаться проектом.
                    $ins = $pdo->prepare(
                        "INSERT INTO pages (title, slug, entity_type, meta_title, meta_description, `lead`, cover_image, is_featured, sort_order, status, is_home, layout_type, hide_chrome, transparent_header, lang, translation_group_id, created_at)
                         VALUES (:t, :s, :et, :mt, :md, :l, :ci, :if, :so, :st, 0, :lt, :hc, :th, :lang, :gid, NOW())"
                    );
                    $ins->execute([
                        ':t' => $title,
                        ':s' => $newSlug,
                        ':et' => $orig['entity_type'] ?? 'page',
                        ':ci' => $orig['cover_image'] ?? null,
                        ':if' => $orig['is_featured'] ?? 0,
                        ':so' => $orig['sort_order'] ?? 0,
                        ':mt' => $pt['meta_title'] ?? $orig['meta_title'] ?? null,
                        ':md' => $pt['meta_description'] ?? $orig['meta_description'] ?? null,
                        ':l' => $pt['lead'] ?? $orig['lead'] ?? null,
                        ':st' => $orig['status'] ?? 'published',
                        ':lt' => $orig['layout_type'] ?? 'no_sidebar',
                        ':hc' => $orig['hide_chrome'] ?? 0,
                        ':th' => $orig['transparent_header'] ?? 0,
                        ':lang' => $lang,
                        ':gid' => $origId,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Logger::swallowed('TranslationGroupMigration: перенос page_translations в отдельные записи не завершён', $e);
        }

        // Шага «перенос project_translations» больше нет: проект — это страница
        // с подтипом, и его перевод хранится там же, где перевод страницы
        // (page_translations + блоки своего языка). Отдельный перенос повторно
        // создавал бы языковые копии проектов.

        self::$migrated = true;
    }
}
