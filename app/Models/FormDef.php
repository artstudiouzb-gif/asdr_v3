<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Translations;

final class FormDef
{
    public static function all(): array
    {
        $stmt = Database::pdo()->query('SELECT * FROM forms ORDER BY created_at DESC');

        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM forms WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['fields'] = json_decode((string) $row['fields_json'], true) ?: [];

        return $row;
    }

    public static function findBySlug(string $slug): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM forms WHERE slug = :slug LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['fields'] = json_decode((string) $row['fields_json'], true) ?: [];

        return $row;
    }

    /**
     * Форма на языке посетителя: название, сообщение после отправки, подписи
     * полей и варианты выбора. Пустой перевод откатывается к основному языку
     * — недописанный перевод оставляет текст, а не дыру.
     *
     * **Значение варианта остаётся базовым**, переводится только подпись
     * (`option_labels`): условие показа сравнивает значение со строкой,
     * набранной на основном языке, а журнал заявок читают по-русски. Иначе
     * узбекская заявка приходила бы с другим значением, и условие на ней
     * молча не срабатывало.
     *
     * @param array<string, mixed> $form строка с разобранными `fields`
     * @return array<string, mixed>
     */
    public static function localize(array $form, string $lang): array
    {
        if ($lang === \App\Models\Language::defaultCode() || empty($form['id'])) {
            return $form;
        }
        $translation = FormTranslation::find((int) $form['id'], $lang);
        if ($translation === null) {
            return $form;
        }

        $form = Translations::overlayFields($form, $translation, ['name', 'success_message']);
        $map = is_array($translation['fields'] ?? null) ? $translation['fields'] : [];
        foreach ((array) ($form['fields'] ?? []) as $i => $field) {
            if (!is_array($field)) {
                continue;
            }
            $own = $map[(string) ($field['name'] ?? '')] ?? null;
            if (!is_array($own)) {
                continue;
            }
            $label = trim((string) ($own['label'] ?? ''));
            if ($label !== '') {
                $field['label'] = $label;
            }
            $labels = self::splitOptions((string) ($own['options'] ?? ''), true);
            if (array_filter($labels, static fn (string $v): bool => $v !== '') !== []) {
                $field['option_labels'] = $labels;
            }
            $form['fields'][$i] = $field;
        }

        return $form;
    }

    /**
     * Варианты выбора поля: значение (основной язык) и подпись (язык
     * посетителя). Перевод сопоставляется по номеру; недостающий или пустой
     * берёт подпись основного языка.
     *
     * @param array<string, mixed> $field
     * @return list<array{value: string, label: string}>
     */
    public static function choices(array $field): array
    {
        $labels = is_array($field['option_labels'] ?? null) ? array_values($field['option_labels']) : [];
        $choices = [];
        foreach (self::splitOptions((string) ($field['options'] ?? ''), true) as $i => $value) {
            if ($value === '') {
                continue;
            }
            $label = trim((string) ($labels[$i] ?? ''));
            $choices[] = ['value' => $value, 'label' => $label !== '' ? $label : $value];
        }

        return $choices;
    }

    /**
     * Строка «Вариант 1, Вариант 2» как список. Пустые места сохраняются
     * при `$keepEmpty`: по номеру сопоставляются переводы, и выпавший пустой
     * вариант сдвинул бы подписи остальных.
     *
     * @return list<string>
     */
    public static function splitOptions(string $options, bool $keepEmpty = false): array
    {
        if (trim($options) === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $options));

        return $keepEmpty ? $parts : array_values(array_filter($parts, static fn (string $v): bool => $v !== ''));
    }

    public static function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM forms WHERE slug = :slug';
        $params = [':slug' => $slug];
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
        $stmt = Database::pdo()->prepare(
            'INSERT INTO forms (name, slug, fields_json, notify_email, success_message, created_at)
             VALUES (:name, :slug, :fields_json, :notify_email, :success_message, NOW())'
        );
        $stmt->execute([
            ':name' => $data['name'],
            ':slug' => $data['slug'],
            ':fields_json' => json_encode($data['fields'], JSON_UNESCAPED_UNICODE),
            ':notify_email' => $data['notify_email'],
            ':success_message' => $data['success_message'],
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE forms SET name = :name, slug = :slug, fields_json = :fields_json,
             notify_email = :notify_email, success_message = :success_message WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $data['name'],
            ':slug' => $data['slug'],
            ':fields_json' => json_encode($data['fields'], JSON_UNESCAPED_UNICODE),
            ':notify_email' => $data['notify_email'],
            ':success_message' => $data['success_message'],
            ':id' => $id,
        ]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM forms WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
