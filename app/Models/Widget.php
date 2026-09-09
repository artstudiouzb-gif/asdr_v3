<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\WidgetRenderer;

final class Widget
{
    public const TYPES = ['latest_news', 'contacts', 'custom_html', 'projects_list', 'team_list', 'subscribe', 'photo_slider', 'section_menu'];

    public const TYPE_LABELS = [
        'latest_news' => 'Последние новости',
        'contacts' => 'Контакты и соцсети',
        'custom_html' => 'Произвольный HTML / баннер',
        'projects_list' => 'Список проектов',
        'team_list' => 'Список команды',
        'subscribe' => 'Форма подписки на новости',
        'photo_slider' => 'Фотокарусель',
        'section_menu' => 'Меню раздела',
    ];

    public static function all(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM widgets ORDER BY sidebar ASC, sort_order ASC, id ASC');

        return $stmt->fetchAll();
    }

    public static function forSidebar(string $sidebar): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM widgets WHERE sidebar = :sidebar ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':sidebar' => $sidebar]);

        return $stmt->fetchAll();
    }

    /**
     * Активные виджеты колонки для языка: язык виджета совпадает или пуст.
     */
    public static function activeForSidebar(string $sidebar, string $lang): array
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM widgets WHERE sidebar = :sidebar AND is_active = 1 AND (lang = :lang OR lang = '')
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([':sidebar' => $sidebar, ':lang' => $lang]);

        return $stmt->fetchAll();
    }

    /**
     * Активные виджеты, выбранные внутри контентного блока. Порядок берётся
     * из JSON блока, а не из глобальной левой/правой колонки.
     *
     * @param array<int, mixed> $ids
     * @return list<array<string, mixed>>
     */
    public static function activeByIds(array $ids, string $lang): array
    {
        $clean = [];
        foreach ($ids as $rawId) {
            if (!is_scalar($rawId)) {
                continue;
            }
            $id = (int) $rawId;
            if ($id > 0 && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
            if (count($clean) >= 12) {
                break;
            }
        }
        if ($clean === []) {
            return [];
        }

        $params = [':lang' => $lang];
        $placeholders = [];
        foreach ($clean as $index => $id) {
            $placeholder = ':id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }

        $stmt = Database::pdo()->prepare(
            'SELECT * FROM widgets WHERE id IN (' . implode(', ', $placeholders) . ")
             AND is_active = 1 AND (lang = :lang OR lang = '')"
        );
        $stmt->execute($params);

        $byId = [];
        foreach ($stmt->fetchAll() as $widget) {
            $byId[(int) $widget['id']] = $widget;
        }

        $ordered = [];
        foreach ($clean as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    public static function renderSidebar(string $sidebar, string $lang): string
    {
        $html = '';
        foreach (self::activeForSidebar($sidebar, $lang) as $widget) {
            $html .= WidgetRenderer::render($widget, $lang);
        }

        return $html;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM widgets WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function create(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM widgets WHERE sidebar = :sidebar'
        );
        $stmt->execute([':sidebar' => $data['sidebar']]);
        $nextOrder = (int) $stmt->fetchColumn();

        $stmt = Database::pdo()->prepare(
            'INSERT INTO widgets (sidebar, type, title, lang, data, sort_order, is_active, created_at)
             VALUES (:sidebar, :type, :title, :lang, :data, :sort_order, :is_active, NOW())'
        );
        $stmt->execute([
            ':sidebar' => $data['sidebar'],
            ':type' => $data['type'],
            ':title' => $data['title'],
            ':lang' => $data['lang'],
            ':data' => json_encode($data['data'], JSON_UNESCAPED_UNICODE),
            ':sort_order' => $nextOrder,
            ':is_active' => !empty($data['is_active']) ? 1 : 0,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE widgets SET sidebar = :sidebar, title = :title, lang = :lang,
             data = :data, is_active = :is_active WHERE id = :id'
        );
        $stmt->execute([
            ':sidebar' => $data['sidebar'],
            ':title' => $data['title'],
            ':lang' => $data['lang'],
            ':data' => json_encode($data['data'], JSON_UNESCAPED_UNICODE),
            ':is_active' => !empty($data['is_active']) ? 1 : 0,
            ':id' => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM widgets WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function move(int $id, string $direction): void
    {
        $widget = self::findById($id);
        if (!$widget) {
            return;
        }
        $siblings = self::forSidebar((string) $widget['sidebar']);
        $index = null;
        foreach ($siblings as $i => $s) {
            if ((int) $s['id'] === $id) {
                $index = (int) $i;
                break;
            }
        }
        if ($index === null) {
            return;
        }
        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swap < 0 || $swap >= count($siblings)) {
            return;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE widgets SET sort_order = :order WHERE id = :id');
            $stmt->execute([':order' => $siblings[$swap]['sort_order'], ':id' => $siblings[$index]['id']]);
            $stmt->execute([':order' => $siblings[$index]['sort_order'], ':id' => $siblings[$swap]['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
