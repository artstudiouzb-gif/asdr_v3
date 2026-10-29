<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Перевод формы обратной связи (механизм А: перевод накладывается на базовую
 * строку `forms`). Отдельной формы на язык нет: заявки со всех языков идут в
 * один журнал, а поля правятся в одном месте.
 *
 * Подписи полей лежат JSON'ом по **имени поля** (`fields_json`), а не по
 * номеру: порядок полей редактор меняет перетаскиванием, а имя — ключ
 * заявки и условий показа, оно стабильно.
 */
final class FormTranslation
{
    /** @return array<string, array<string, mixed>> переводы по коду языка */
    public static function forForm(int $formId): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM form_translations WHERE form_id = :id');
        $stmt->execute([':id' => $formId]);

        $result = [];
        foreach (Database::rows($stmt) as $row) {
            $result[(string) $row['lang']] = self::decode($row);
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    public static function find(int $formId, string $lang): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM form_translations WHERE form_id = :id AND lang = :lang LIMIT 1'
        );
        $stmt->execute([':id' => $formId, ':lang' => $lang]);
        $row = $stmt->fetch();

        return is_array($row) ? self::decode($row) : null;
    }

    /**
     * @param array{name?: string, success_message?: string, fields?: array<string, array{label?: string, options?: string}>} $data
     */
    public static function upsert(int $formId, string $lang, array $data): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO form_translations (form_id, lang, name, success_message, fields_json)
             VALUES (:form_id, :lang, :name, :success_message, :fields_json)
             ON DUPLICATE KEY UPDATE name = VALUES(name), success_message = VALUES(success_message),
                                     fields_json = VALUES(fields_json)'
        );
        $stmt->execute([
            ':form_id' => $formId,
            ':lang' => $lang,
            ':name' => ($data['name'] ?? '') !== '' ? $data['name'] : null,
            ':success_message' => ($data['success_message'] ?? '') !== '' ? $data['success_message'] : null,
            // Подписи набирает редактор: битый байт заменяется, а не роняет
            // сохранение формы целиком.
            ':fields_json' => json_encode(
                $data['fields'] ?? [],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            ),
        ]);
        \App\Core\Cache::forgetPrefix('page:');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $fields = json_decode((string) ($row['fields_json'] ?? ''), true);
        $row['fields'] = is_array($fields) ? $fields : [];

        return $row;
    }
}
