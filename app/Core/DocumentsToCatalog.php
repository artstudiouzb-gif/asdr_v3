<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\ContentEntry;
use App\Models\ContentType;
use App\Models\Language;

/**
 * Перенос ручного списка блока «Документы» в каталог.
 *
 * Документ, набранный в блоке, живёт на одной странице; тот же документ в
 * каталоге заводится один раз и выводится где угодно. Переносить руками
 * значит заново загружать каждый файл — поле файла у записи принимает только
 * загрузку, — поэтому перенос делает сервер: запись ссылается на тот же файл.
 *
 * Роли полей типа — те же, что при выводе (`CatalogDocuments::roles`): файл,
 * картинка, многострочное, дата, первое однострочное. Чего у типа нет, то не
 * переносится, и об этом говорится прямо — молча потерянная обложка выглядела
 * бы как сбой каталога.
 */
final class DocumentsToCatalog
{
    /**
     * Чистая часть: документы блока — в записи каталога.
     *
     * Порядок сохраняется: первая запись списка — первый документ блока.
     *
     * @param array<mixed> $items документы блока
     * @param array<int, array<string, mixed>> $fields поля типа
     * @return array{entries: list<array{title:string, data:array<string, mixed>, translations:array<string, array<string, mixed>>}>, notes: list<string>}
     */
    public static function entries(array $items, array $fields, string $defaultLang, bool $translatable): array
    {
        $roles = CatalogDocuments::roles($fields);
        $entries = [];
        $lost = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $byLang = [];
            foreach (is_array($item['files'] ?? null) ? $item['files'] : [] as $code => $url) {
                $url = is_string($url) ? trim($url) : '';
                if (is_string($code) && $url !== '' && UrlGuard::isSafeLink($url)) {
                    $byLang[$code] = $url;
                }
            }
            $common = trim((string) ($item['url'] ?? ''));
            $common = $common !== '' && UrlGuard::isSafeLink($common) ? $common : '';
            // Основной файл — версия основного языка, затем общий, затем первая.
            $main = $byLang[$defaultLang] ?? ($common !== '' ? $common : (string) (reset($byLang) ?: ''));

            $data = [];
            $put = static function (string $role, string $value, string $what) use ($roles, &$data, &$lost): void {
                if ($value === '') {
                    return;
                }
                if ($roles[$role] === '') {
                    $lost[$what] = true;

                    return;
                }
                $data[$roles[$role]] = $value;
            };
            $put('file', $main, 'файлы (нет поля «Файл»)');
            $cover = trim((string) ($item['cover'] ?? ''));
            $put('image', $cover !== '' && UrlGuard::isSafeMedia($cover) ? $cover : '', 'обложки (нет поля «Изображение»)');
            $description = trim((string) ($item['description'] ?? ''));
            $put('textarea', $description !== '' ? HtmlSanitizer::sanitizeLead($description) : '', 'описания (нет многострочного поля)');
            $put('text', trim((string) ($item['number'] ?? '')), 'номера (нет текстового поля)');
            $date = trim((string) ($item['date'] ?? ''));
            $isoDate = self::isoDate($date);
            if ($date !== '' && $isoDate === '') {
                $lost['даты не в виде ДД.ММ.ГГГГ'] = true;
            }
            $put('date', $isoDate, 'даты (нет поля «Дата»)');
            $openAs = (string) ($item['open_as'] ?? '');
            if (in_array($openAs, PdfViewer::VIEWS, true)) {
                $data[CatalogDocuments::OPEN_AS] = $openAs;
            }

            $translations = [];
            foreach ($byLang as $code => $url) {
                if ($code === $defaultLang || $url === $main) {
                    continue;
                }
                if (!$translatable || $roles['file'] === '') {
                    $lost['файлы на других языках (у каталога выключены языковые версии)'] = true;
                    continue;
                }
                $translations[$code] = [$roles['file'] => $url];
            }
            if (trim((string) ($item['link_label'] ?? '')) !== '' || (is_array($item['file_labels'] ?? null) && $item['file_labels'] !== [])) {
                $lost['свои названия кнопок скачивания'] = true;
            }

            $entries[] = ['title' => $title, 'data' => $data, 'translations' => $translations];
        }

        return ['entries' => $entries, 'notes' => array_keys($lost)];
    }

    /**
     * Перенос в базу. Документ, уже лежащий в каталоге (то же название и тот
     * же файл), второй раз не заводится: кнопку нажмут и повторно.
     *
     * @param array<mixed> $items
     * @return array{created:int, skipped:int, notes:list<string>}
     */
    public static function run(int $typeId, array $items): array
    {
        $type = ContentType::findById($typeId);
        if ($type === null) {
            throw new \InvalidArgumentException('Каталог не найден.');
        }
        $fields = ContentType::fields($typeId);
        $fileField = CatalogDocuments::roles($fields)['file'];
        if ($fileField === '') {
            throw new \InvalidArgumentException('У каталога «' . $type['name'] . '» нет поля вида «Файл» — документам некуда положить файл. Добавьте поле в разделе «Поля» каталога.');
        }
        $plan = self::entries($items, $fields, Language::defaultCode(), (int) ($type['has_translations'] ?? 0) === 1);

        $existing = [];
        foreach (ContentEntry::forType($typeId) as $row) {
            $rowData = json_decode((string) ($row['data'] ?? ''), true);
            $existing[self::key((string) $row['title'], is_array($rowData) ? (string) ($rowData[$fileField] ?? '') : '')] = true;
        }

        $created = 0;
        $skipped = 0;
        // С конца: у записей одной секунды порядок решает номер, свежий
        // первым, — так первый документ блока остаётся первым и в каталоге.
        foreach (array_reverse($plan['entries']) as $entry) {
            $key = self::key($entry['title'], (string) ($entry['data'][$fileField] ?? ''));
            if (isset($existing[$key])) {
                $skipped++;
                continue;
            }
            $slug = Slug::make($entry['title']);
            if ($slug === '' || ContentEntry::slugExists($typeId, $slug)) {
                $slug = ($slug !== '' ? $slug . '-' : 'document-') . bin2hex(random_bytes(2));
            }
            $id = ContentEntry::create($typeId, $entry['title'], $slug, 'published', $entry['data']);
            foreach ($entry['translations'] as $code => $data) {
                ContentEntry::upsertTranslation($id, $code, null, $data);
            }
            $existing[$key] = true;
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'notes' => $plan['notes']];
    }

    /** Дата блока набрана свободно: «12.03.2026» или «2026-03-12». */
    public static function isoDate(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $date, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return '';
    }

    private static function key(string $title, string $file): string
    {
        return mb_strtolower(trim($title)) . "\n" . trim($file);
    }
}
