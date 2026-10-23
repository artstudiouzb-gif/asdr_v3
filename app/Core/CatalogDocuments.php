<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\ContentEntry;
use App\Models\ContentType;
use App\Models\Language;

/**
 * Документы блока «Документы» из типа контента каталога.
 *
 * Документ заводится один раз — записью каталога — и выводится на любой
 * странице: набранный руками список в каждом блоке расходился бы с соседним
 * при первой правке. Своих полей «файл», «обложка», «описание» у типа нет —
 * поля у типа произвольные, — поэтому роль поля выводится из его вида:
 * первое поле-файл — файл документа, картинка — обложка, многострочное —
 * описание, дата — дата акта, первое однострочное — номер акта. Отдельная
 * настройка «какое поле что значит» повторяла бы вид поля и расходилась с ним.
 */
final class CatalogDocuments
{
    /** Больше документов блок не выводит: это витрина, а не архив каталога. */
    public const LIMIT = 60;

    /**
     * Документы опубликованных записей типа на языке страницы. Тип удалён —
     * null: блок возвращается к своему списку, а не пустеет молча.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function items(int $typeId, ?string $lang = null): ?array
    {
        $type = ContentType::findById($typeId);
        if ($type === null) {
            return null;
        }
        $entries = array_slice(ContentEntry::forType($typeId, 'published'), 0, self::LIMIT);
        $lang ??= Locale::current();
        $translations = [];
        if ((int) ($type['has_translations'] ?? 0) === 1 && $lang !== Language::defaultCode()) {
            $translations = ContentEntry::translationsFor(array_map(static fn (array $e): int => (int) $e['id'], $entries), $lang);
        }

        return self::map(ContentType::fields($typeId), $entries, $translations);
    }

    /**
     * Чистое преобразование: поля типа и записи — в документы блока.
     *
     * @param list<array<string, mixed>>|array<int, array<string, mixed>> $fields
     * @param list<array<string, mixed>> $entries
     * @param array<int, array{title:?string, data:array<string, mixed>}> $translations по id записи
     * @return list<array<string, mixed>>
     */
    public static function map(array $fields, array $entries, array $translations = []): array
    {
        $roles = self::roles($fields);
        $items = [];
        foreach ($entries as $entry) {
            $data = is_array($entry['data'] ?? null)
                ? $entry['data']
                : (json_decode((string) ($entry['data'] ?? ''), true) ?: []);
            $title = trim((string) ($entry['title'] ?? ''));
            $translation = $translations[(int) ($entry['id'] ?? 0)] ?? null;
            if ($translation !== null) {
                $translatedTitle = trim((string) ($translation['title'] ?? ''));
                if ($translatedTitle !== '') {
                    $title = $translatedTitle;
                }
                // Пустое поле перевода уступает основному языку — то же
                // правило, что у самого каталога.
                foreach ($translation['data'] as $key => $value) {
                    if (is_string($value) ? trim($value) !== '' : ($value !== null && $value !== [])) {
                        $data[$key] = $value;
                    }
                }
            }
            if ($title === '') {
                continue;
            }
            $value = static function (string $role) use ($roles, $data): string {
                $name = $roles[$role] ?? '';
                $raw = $name !== '' ? ($data[$name] ?? null) : null;

                return is_scalar($raw) ? trim((string) $raw) : '';
            };
            $url = $value('file');
            $cover = $value('image');
            $date = $value('date');
            $items[] = [
                'title' => $title,
                'description' => self::description($value('textarea')),
                'cover' => $cover !== '' && UrlGuard::isSafeMedia($cover) ? $cover : '',
                'meta' => '',
                'url' => $url !== '' && UrlGuard::isSafeLink($url) ? $url : '',
                'files' => [],
                'number' => $value('text'),
                'date' => $date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
                    ? DateFormatter::format($date, 'd.m.Y')
                    : $date,
            ];
        }

        return $items;
    }

    /**
     * Имя поля на каждую роль: первое поле нужного вида.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array{file:string, image:string, textarea:string, date:string, text:string}
     */
    public static function roles(array $fields): array
    {
        $roles = ['file' => '', 'image' => '', 'textarea' => '', 'date' => '', 'text' => ''];
        foreach ($fields as $field) {
            $kind = (string) ($field['field_type'] ?? '');
            if (array_key_exists($kind, $roles) && $roles[$kind] === '') {
                $roles[$kind] = (string) ($field['name'] ?? '');
            }
        }

        return $roles;
    }

    /**
     * Многострочное поле каталога бывает простым текстом и разметкой. Простой
     * текст становится абзацами, разметка проходит тем же очистителем, что и
     * описание, набранное в блоке.
     */
    private static function description(string $text): string
    {
        if ($text === '') {
            return '';
        }
        if (preg_match('/<[a-zA-Z][^>]*>/', $text) === 1) {
            return HtmlSanitizer::sanitizeLead($text);
        }
        $paragraphs = preg_split('/\R{2,}/u', $text) ?: [];

        return implode('', array_map(
            static fn (string $p): string => '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES)) . '</p>',
            array_filter($paragraphs, static fn (string $p): bool => trim($p) !== '')
        ));
    }
}
