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
     * Вид PDF у записи («Документом» / «Книгой»): служебный ключ данных, а не
     * поле типа — выбор есть у каждой записи с файлом, заводить для него поле
     * в каждом каталоге значило бы, что в одном его забудут.
     */
    public const OPEN_AS = '_open_as';

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
        $default = Language::defaultCode();
        $fields = ContentType::fields($typeId);
        $translations = [];
        $files = [];
        if ((int) ($type['has_translations'] ?? 0) === 1) {
            // Все языки одним запросом: текст нужен на языке страницы, а файлы
            // — всех версий, ими карточка предлагает документ на другом языке.
            $byLang = ContentEntry::translationsByLang(array_map(static fn (array $e): int => (int) $e['id'], $entries));
            $fileField = self::roles($fields)['file'];
            foreach ($byLang as $entryId => $versions) {
                if ($lang !== $default && isset($versions[$lang])) {
                    $translations[$entryId] = $versions[$lang];
                }
                foreach ($versions as $code => $version) {
                    $url = $fileField !== '' ? ($version['data'][$fileField] ?? '') : '';
                    if (is_string($url) && trim($url) !== '') {
                        $files[$entryId][$code] = trim($url);
                    }
                }
            }
        }

        return self::map($fields, $entries, $translations, $files, $default);
    }

    /**
     * Чистое преобразование: поля типа и записи — в документы блока.
     *
     * Файл у языковой версии свой (как у новости или страницы на другом
     * языке): основной файл записи — версия основного языка, файлы переводов
     * — версии своих языков. Тогда карточка ведёт посетителя к файлу на языке
     * страницы, а остальные предлагает подписями языков. Без переведённых
     * файлов основной файл остаётся общим — прежнее поведение.
     *
     * @param list<array<string, mixed>>|array<int, array<string, mixed>> $fields
     * @param list<array<string, mixed>> $entries
     * @param array<int, array{title:?string, data:array<string, mixed>}> $translations по id записи, язык страницы
     * @param array<int, array<string, string>> $files по id записи: код языка => файл перевода
     * @return list<array<string, mixed>>
     */
    public static function map(array $fields, array $entries, array $translations = [], array $files = [], string $defaultLang = ''): array
    {
        $roles = self::roles($fields);
        $items = [];
        foreach ($entries as $entry) {
            $entryId = (int) ($entry['id'] ?? 0);
            $data = is_array($entry['data'] ?? null)
                ? $entry['data']
                : (json_decode((string) ($entry['data'] ?? ''), true) ?: []);
            $title = trim((string) ($entry['title'] ?? ''));
            $translation = $translations[$entryId] ?? null;
            if ($translation !== null) {
                $translatedTitle = trim((string) ($translation['title'] ?? ''));
                if ($translatedTitle !== '') {
                    $title = $translatedTitle;
                }
                // Пустое поле перевода уступает основному языку — то же
                // правило, что у самого каталога. Файл перевода сюда не идёт:
                // он едет своей языковой версией (ниже), а не подменой основного.
                foreach ($translation['data'] as $key => $value) {
                    if ($key === $roles['file'] && $key !== '') {
                        continue;
                    }
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
            $url = $url !== '' && UrlGuard::isSafeLink($url) ? $url : '';
            $byLang = [];
            foreach ($files[$entryId] ?? [] as $code => $fileUrl) {
                if (is_string($code) && $code !== $defaultLang && UrlGuard::isSafeLink($fileUrl) && $fileUrl !== $url) {
                    $byLang[$code] = $fileUrl;
                }
            }
            if ($byLang !== [] && $url !== '' && $defaultLang !== '') {
                $byLang = [$defaultLang => $url] + $byLang;
                $url = '';
            }
            $cover = $value('image');
            $date = $value('date');
            $openAs = (string) ($data[self::OPEN_AS] ?? '');
            $items[] = [
                'title' => $title,
                'description' => self::description($value('textarea')),
                'cover' => $cover !== '' && UrlGuard::isSafeMedia($cover) ? $cover : '',
                'meta' => '',
                'url' => $url,
                'files' => $byLang,
                'number' => $value('text'),
                'date' => $date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
                    ? DateFormatter::format($date, 'd.m.Y')
                    : $date,
                // Пусто — как в настройке блока.
                'open_as' => in_array($openAs, PdfViewer::VIEWS, true) ? $openAs : '',
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
