<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\ContentEntry;
use App\Models\ContentType;
use App\Models\FileEntry;

/**
 * Переиспользуемый рендер и сбор значений кастомных полей (задача 132).
 * Применим к любому типу контента (задача 131). Значения — плоские инпуты с
 * префиксом (по умолчанию "f_"), файлы — через Uploader (реальная проверка MIME).
 */
final class ContentFields
{
    public static function inputName(array $field, string $prefix): string
    {
        return $prefix . preg_replace('/[^a-z0-9_]/i', '', (string) $field['name']);
    }

    /**
     * HTML одного поля.
     * @param array<string,mixed> $field
     * @param mixed $value
     */
    public static function renderInput(array $field, mixed $value, string $prefix = 'f_'): string
    {
        $name = htmlspecialchars(self::inputName($field, $prefix), ENT_QUOTES);
        $id = 'cf-' . $name;
        $label = htmlspecialchars((string) $field['label'], ENT_QUOTES);
        $required = !empty($field['required']) ? ' required' : '';
        $type = (string) $field['field_type'];
        $v = htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES);

        // Изображение: выбор из медиабиблиотеки + превью (единое поле AdminUi).
        if ($type === 'image') {
            return AdminUi::imageField(
                self::inputName($field, $prefix),
                is_scalar($value) ? (string) $value : '',
                ['label' => (string) $field['label']]
            );
        }

        $html = '<div class="form-field"><label for="' . $id . '">' . $label . '</label>';

        switch ($type) {
            case 'textarea':
                $html .= '<textarea id="' . $id . '" name="' . $name . '"' . $required . '>' . $v . '</textarea>';
                break;
            case 'number':
                $html .= '<input type="number" step="any" id="' . $id . '" name="' . $name . '" value="' . $v . '"' . $required . '>';
                break;
            case 'date':
                $html .= '<input type="date" id="' . $id . '" name="' . $name . '" value="' . $v . '"' . $required . '>';
                break;
            case 'file':
                if ($v !== '') {
                    $html .= '<div class="form-hint">Текущий: <a href="' . $v . '" target="_blank" rel="noopener">' . $v . '</a></div>';
                    $html .= '<input type="hidden" name="' . $name . '__keep" value="' . $v . '">';
                }
                $html .= '<input type="file" id="' . $id . '" name="' . $name . '"' . ($v === '' ? $required : '') . '>';
                break;
            case 'relation':
                $html .= self::renderRelation($field, $name, $id, is_scalar($value) ? (string) $value : '', $required);
                break;
            default: // text
                $html .= '<input type="text" id="' . $id . '" name="' . $name . '" value="' . $v . '"' . $required . '>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Безопасный HTML значения поля для публичного фронтенда (списки/карточки
     * пользовательских типов контента). Возвращает '' для пустых значений.
     *
     * @param array<string,mixed> $field
     * @param mixed $value
     */
    public static function displayValue(array $field, mixed $value): string
    {
        if ($value === null || $value === '' || (is_array($value) && $value === [])) {
            return '';
        }
        $type = (string) $field['field_type'];
        $scalar = is_scalar($value) ? (string) $value : '';

        switch ($type) {
            case 'textarea':
                // С разметкой — строгая очистка allowlist-санитайзером
                // (script/iframe/on*/javascript: вырезаются, остаётся только
                // безопасное форматирование текста). Простой текст — как раньше;
                // «тег» распознаём только по <буква...>, чтобы «5 < 7» и «<2>»
                // не считались разметкой.
                if (preg_match('/<[a-zA-Z][^>]*>/', $scalar) === 1) {
                    return HtmlSanitizer::sanitizeText($scalar);
                }
                return nl2br(htmlspecialchars($scalar, ENT_QUOTES));
            case 'date':
                $ts = strtotime($scalar);
                return $ts ? htmlspecialchars(date('d.m.Y', $ts), ENT_QUOTES) : htmlspecialchars($scalar, ENT_QUOTES);
            case 'image':
                return Media::picture(
                    $scalar,
                    (string) $field['label'],
                    null,
                    null,
                    '',
                    true,
                    '(max-width: 800px) 100vw, 800px'
                );
            case 'file':
                $href = htmlspecialchars($scalar, ENT_QUOTES);
                return '<a href="' . $href . '" target="_blank" rel="noopener" download>' . htmlspecialchars((string) $field['label'], ENT_QUOTES) . '</a>';
            case 'relation':
                $target = ContentType::findBySlug((string) ($field['options']['relation_type'] ?? ''));
                if ($target !== null && ctype_digit($scalar)) {
                    $entry = ContentEntry::findById((int) $scalar);
                    if ($entry !== null) {
                        return htmlspecialchars((string) $entry['title'], ENT_QUOTES);
                    }
                }
                return htmlspecialchars($scalar, ENT_QUOTES);
            default: // text, number
                return htmlspecialchars($scalar, ENT_QUOTES);
        }
    }

    private static function renderRelation(array $field, string $name, string $id, string $value, string $required): string
    {
        $targetSlug = (string) ($field['options']['relation_type'] ?? '');
        $target = $targetSlug !== '' ? ContentType::findBySlug($targetSlug) : null;
        if ($target === null) {
            return '<input type="text" id="' . $id . '" name="' . $name . '" value="' . htmlspecialchars($value, ENT_QUOTES) . '"' . $required . '>';
        }

        $out = '<select id="' . $id . '" name="' . $name . '"' . $required . '><option value="">— не выбрано —</option>';
        foreach (ContentEntry::forType((int) $target['id']) as $entry) {
            $sel = (string) $entry['id'] === $value ? ' selected' : '';
            $out .= '<option value="' . (int) $entry['id'] . '"' . $sel . '>'
                . htmlspecialchars((string) $entry['title'], ENT_QUOTES) . '</option>';
        }

        return $out . '</select>';
    }

    /**
     * Собирает значения полей из запроса. Валидирует required, парсит number/date,
     * файлы сохраняет через Uploader.
     *
     * @param array<int,array<string,mixed>> $fields
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    public static function collect(array $fields, string $prefix = 'f_', ?int $uploadedBy = null, bool $handleFiles = true): array
    {
        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            $fname = (string) $field['name'];
            $input = self::inputName($field, $prefix);
            $type = (string) $field['field_type'];
            $required = !empty($field['required']);

            if ($type === 'file') {
                if (!$handleFiles) {
                    continue;
                }
                $file = $_FILES[$input] ?? null;
                $uploaded = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                $keep = trim((string) ($_POST[$input . '__keep'] ?? ''));
                if ($uploaded) {
                    try {
                        $stored = Uploader::store($file, 'public', $uploadedBy);
                        $values[$fname] = FileEntry::publicUrl($stored);
                    } catch (\Throwable $e) {
                        $errors[$fname] = $e->getMessage();
                    }
                } elseif ($keep !== '') {
                    $values[$fname] = $keep;
                } elseif ($required) {
                    $errors[$fname] = 'Приложите файл «' . $field['label'] . '».';
                }
                continue;
            }

            $value = trim((string) ($_POST[$input] ?? ''));
            $value = (string) preg_replace('/[\x{200B}\x{FEFF}\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u', '', $value);
            if ($required && $value === '') {
                $errors[$fname] = 'Поле «' . $field['label'] . '» обязательно.';
                continue;
            }
            if ($type === 'number' && $value !== '' && !is_numeric($value)) {
                $errors[$fname] = 'Поле «' . $field['label'] . '» должно быть числом.';
                continue;
            }
            if ($type === 'date' && $value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $errors[$fname] = 'Поле «' . $field['label'] . '» должно быть датой.';
                continue;
            }

            $values[$fname] = $value;
        }

        return [$values, $errors];
    }
}
