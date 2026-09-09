<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Icon;

/**
 * Пользовательский тип контента (задача 131): slug, название, признак
 * мультиязычности и набор полей (content_type_fields).
 */
final class ContentType
{
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'image', 'file', 'relation'];

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return Database::pdo()->query('SELECT * FROM content_types ORDER BY name ASC')->fetchAll();
    }

    /** Публичные типы (показываются на сайте). @return array<int, array<string, mixed>> */
    public static function allPublic(): array
    {
        return Database::pdo()->query('SELECT * FROM content_types WHERE is_public = 1 ORDER BY name ASC')->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM content_types WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public static function findBySlug(string $slug): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM content_types WHERE slug = :s LIMIT 1');
        $stmt->execute([':s' => $slug]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Адрес раздела каталога без языкового префикса: `catalog/<slug>` или
     * просто `<slug>`, если у типа снят префикс.
     *
     * Единственное место, где это знание живёт. Прежде `'catalog/' . $slug`
     * было написано в десятке мест — списке, карточке, календаре, поиске,
     * открытых данных и уведомлении о языке, — и переключатель у одного типа
     * пришлось бы вносить в каждое из них.
     *
     * @param array<string, mixed> $type
     */
    public static function path(array $type): string
    {
        $slug = (string) ($type['slug'] ?? '');

        return empty($type['root_url']) ? 'catalog/' . $slug : $slug;
    }

    /**
     * Адрес записи раздела.
     *
     * @param array<string, mixed> $type
     */
    public static function entryPath(array $type, string $entrySlug): string
    {
        return self::path($type) . '/' . $entrySlug;
    }

    /**
     * Публичный тип, живущий в корне сайта. Не «любой тип с таким слагом»:
     * маршрут `/{slug}` обслуживает страницы, и отвечать каталогом он вправе
     * только там, где префикс снят осознанно.
     *
     * @return array<string, mixed>|null
     */
    public static function findRootBySlug(string $slug): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM content_types
             WHERE slug = :s AND is_public = 1 AND root_url = 1 LIMIT 1'
        );
        $stmt->execute([':s' => $slug]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Занятые адреса первого уровня: у них свои маршруты, объявленные до
     * `/{slug}`, поэтому тип с таким слагом в корне не открылся бы никогда —
     * настройка выглядела бы сохранённой и не работала.
     */
    public const RESERVED_ROOT = [
        'admin', 'api', 'assets', 'health', 'install', 'repo', 'uploads',
        'catalog', 'news', 'projects', 'albums', 'opendata', 'search',
        'goals', 'forms', 'push', 'subscribe', 'unsubscribe', 'script',
        'sitemap.xml', 'robots.txt', 'rss.xml', 'captcha.png',
    ];

    /**
     * Причина, по которой слаг нельзя вынести в корень, или пустая строка.
     *
     * Проверка нужна ровно потому, что столкновение молчаливое: маршрут
     * `/{slug}` сначала ищет страницу, поэтому тип с занятым адресом просто
     * никогда бы не открылся, а редактор видел бы сохранённую настройку.
     */
    public static function rootUrlConflict(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '') {
            return 'Укажите адрес раздела.';
        }
        if (in_array($slug, self::RESERVED_ROOT, true)) {
            return 'Адрес «/' . $slug . '» занят служебным разделом сайта.';
        }
        if (in_array($slug, Language::activeCodes(), true)) {
            return 'Адрес «/' . $slug . '» занят языковой версией сайта.';
        }

        $stmt = Database::pdo()->prepare(
            "SELECT entity_type FROM pages WHERE slug = :s AND deleted_at IS NULL LIMIT 1"
        );
        $stmt->execute([':s' => $slug]);
        $entity = $stmt->fetchColumn();
        if ($entity === 'page') {
            return 'Адрес «/' . $slug . '» занят страницей сайта.';
        }

        return '';
    }

    public static function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM content_types WHERE slug = :s';
        $params = [':s' => $slug];
        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
            $params[':id'] = $excludeId;
        }
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function create(string $slug, string $name, bool $hasTranslations, string $description = '', bool $isPublic = true, string $icon = '', bool $rootUrl = false): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO content_types (slug, name, description, icon, has_translations, is_public, root_url, created_at)
             VALUES (:s, :n, :d, :i, :t, :p, :r, NOW())'
        );
        $stmt->execute([
            ':s' => $slug,
            ':n' => $name,
            ':d' => $description,
            ':i' => Icon::cleanName($icon),
            ':t' => $hasTranslations ? 1 : 0,
            ':p' => $isPublic ? 1 : 0,
            ':r' => $rootUrl ? 1 : 0,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, string $name, bool $hasTranslations, string $description = '', bool $isPublic = true, string $icon = '', bool $rootUrl = false): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE content_types SET name = :n, description = :d, icon = :i, has_translations = :t, is_public = :p, root_url = :r WHERE id = :id'
        );
        $stmt->execute([
            ':n' => $name,
            ':d' => $description,
            ':i' => Icon::cleanName($icon),
            ':t' => $hasTranslations ? 1 : 0,
            ':p' => $isPublic ? 1 : 0,
            ':r' => $rootUrl ? 1 : 0,
            ':id' => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        Database::pdo()->prepare('DELETE FROM content_types WHERE id = :id')->execute([':id' => $id]);
    }

    /** @return array<int, array<string, mixed>> поля типа (с декодированными options) */
    public static function fields(int $typeId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM content_type_fields WHERE type_id = :t ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':t' => $typeId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['options'] = $r['options'] ? (json_decode((string) $r['options'], true) ?: []) : [];
        }

        return $rows;
    }

    /**
     * Полностью заменяет набор полей типа (из конструктора полей, задача 132).
     *
     * @param array<int, array{name:string, label:string, field_type:string, required:bool, options:array}> $fields
     */
    public static function replaceFields(int $typeId, array $fields): void
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM content_type_fields WHERE type_id = :t')->execute([':t' => $typeId]);
            $ins = $pdo->prepare(
                'INSERT INTO content_type_fields (type_id, name, label, field_type, required, sort_order, options, created_at)
                 VALUES (:t, :n, :l, :ft, :req, :ord, :opt, NOW())'
            );
            $order = 0;
            foreach ($fields as $f) {
                $ins->execute([
                    ':t' => $typeId,
                    ':n' => $f['name'],
                    ':l' => $f['label'],
                    ':ft' => in_array($f['field_type'], self::FIELD_TYPES, true) ? $f['field_type'] : 'text',
                    ':req' => !empty($f['required']) ? 1 : 0,
                    ':ord' => $order++,
                    ':opt' => !empty($f['options']) ? json_encode($f['options'], JSON_UNESCAPED_UNICODE) : null,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
