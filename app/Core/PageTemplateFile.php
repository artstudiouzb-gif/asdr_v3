<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\BlockData\BlockPresentationNormalizer;
use InvalidArgumentException;

/**
 * Шаблон страницы как файл: выгрузка библиотеки шаблонов наружу и загрузка
 * обратно.
 *
 * Внутри системы шаблон уже был (`block_snippets`), но жил только в базе:
 * перенести сборку на другой сайт или прислать её готовой было нечем.
 *
 * Файл — это конверт с пометкой формата и списком блоков. Пометка нужна не
 * ради строгости: без неё пользователь узнаёт об ошибке только после импорта,
 * когда страница собралась не тем, чем он думал.
 *
 * **Присланный файл — не свои данные.** Тип блока проверяется по реестру, а
 * поля `data` пересекаются с умолчаниями этого типа: ключ, которого у типа
 * нет, всё равно потерялся бы при первом сохранении в админке, поэтому
 * честнее отбросить его сразу и сказать об этом вслух.
 */
final class PageTemplateFile
{
    /** Пометка формата: по ней чужой JSON отличается от шаблона страницы. */
    public const KIND = 'artstudio.page-template';

    /** Версия формата. Файл более новой версии импортировать нельзя. */
    public const VERSION = 1;

    /** Больше этого блоков в одной странице не бывает — дальше это ошибка файла. */
    public const MAX_BLOCKS = 300;

    /** Предел размера файла: разбор JSON держит всё дерево в памяти. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Файл шаблона: конверт с пометкой формата и блоками.
     *
     * @param array<int, mixed> $blocks
     */
    public static function export(string $name, array $blocks): string
    {
        // Пустой файл вместо шаблона — отказ, который замечают уже при
        // импорте, когда собралось не то. Названия и тексты блоков приходят
        // от редактора, поэтому негодный байт заменяем, а не теряем конверт.
        return (string) json_encode([
            'kind' => self::KIND,
            'version' => self::VERSION,
            'name' => $name,
            'exported_at' => date('c'),
            'blocks' => array_values($blocks),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Имя файла для выгрузки. Кириллица в заголовке Content-Disposition
     * доезжает не до всякого клиента, поэтому имя транслитерируется, а полное
     * уходит отдельным полем filename*.
     */
    public static function fileName(string $name): string
    {
        // Slug::make сам придумывает имя, когда переводить нечего («!!!»),
        // поэтому запасной ветки здесь не нужно — пустым результат не бывает.
        return mb_substr(Slug::make($name), 0, 60) . '.json';
    }

    /**
     * Разбор присланного файла.
     *
     * @param bool $allowRawCode супер-админ: разрешены «Свой CSS» и блок «HTML»
     * @return array{name: string, blocks: array<int, array<string, mixed>>, warnings: array<int, string>}
     * @throws InvalidArgumentException с готовым текстом для редактора
     */
    public static function parse(string $json, bool $allowRawCode): array
    {
        if (trim($json) === '') {
            throw new InvalidArgumentException('Файл пуст.');
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Файл больше 2 МБ — это не шаблон страницы.');
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Файл не разобрался как JSON: ' . json_last_error_msg() . '.');
        }
        if ((string) ($decoded['kind'] ?? '') !== self::KIND) {
            throw new InvalidArgumentException(
                'Это не шаблон страницы: в файле нет пометки «' . self::KIND . '».'
            );
        }
        $version = (int) ($decoded['version'] ?? 0);
        if ($version > self::VERSION) {
            throw new InvalidArgumentException(
                'Файл сделан более новой версией системы (формат ' . $version . '). Обновите сайт.'
            );
        }
        if (!is_array($decoded['blocks'] ?? null) || $decoded['blocks'] === []) {
            throw new InvalidArgumentException('В файле нет блоков.');
        }
        if (count($decoded['blocks']) > self::MAX_BLOCKS) {
            throw new InvalidArgumentException('В файле больше ' . self::MAX_BLOCKS . ' блоков.');
        }

        $warnings = [];
        $blocks = [];
        foreach ($decoded['blocks'] as $index => $raw) {
            $block = self::block($raw, (int) $index, $allowRawCode, $warnings);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        if ($blocks === []) {
            throw new InvalidArgumentException(
                'Ни один блок из файла не подошёл: ' . implode('; ', array_slice($warnings, 0, 3)) . '.'
            );
        }

        return [
            'name' => mb_substr(trim((string) ($decoded['name'] ?? '')), 0, 190),
            'blocks' => $blocks,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, string> $warnings
     * @return array<string, mixed>|null
     */
    private static function block(mixed $raw, int $index, bool $allowRawCode, array &$warnings): ?array
    {
        $human = 'блок №' . ($index + 1);
        if (!is_array($raw)) {
            $warnings[] = $human . ' пропущен: это не объект';
            return null;
        }

        $type = (string) ($raw['type'] ?? '');
        if (!BlockTypeRegistry::has($type)) {
            $warnings[] = $human . ' пропущен: неизвестный тип «' . mb_substr($type, 0, 40) . '»';
            return null;
        }
        // Разметку блока «HTML» правит только супер-админ. Импорт — та же
        // правка: иначе запрет обходится присланным файлом.
        if ($type === 'html' && !$allowRawCode) {
            $warnings[] = $human . ' пропущен: блок «HTML-код» доступен только супер-администратору';
            return null;
        }

        $customCss = (string) ($raw['custom_css'] ?? '');
        if ($customCss !== '' && !$allowRawCode) {
            $warnings[] = $human . ': «Свой CSS» снят — его правит только супер-администратор';
            $customCss = '';
        }

        $block = [
            'type' => $type,
            'title' => isset($raw['title']) && (string) $raw['title'] !== ''
                ? mb_substr((string) $raw['title'], 0, 255)
                : null,
            'data' => self::data($type, $raw['data'] ?? [], $human, $warnings),
            'custom_css' => $customCss,
            'is_active' => (int) ($raw['is_active'] ?? 1) === 0 ? 0 : 1,
        ];

        $children = $raw['children'] ?? [];
        if (!is_array($children) || $children === []) {
            return $block;
        }
        // Содержимое бывает только у контейнеров, и контейнер в контейнер не
        // вкладывается — эти два правила держит весь конструктор блоков.
        if (!BlockTypeRegistry::isContainer($type)) {
            $warnings[] = $human . ': вложенные блоки сняты — «'
                . (BlockTypeRegistry::TYPE_LABELS[$type] ?? $type) . '» не контейнер';
            return $block;
        }

        $block['children'] = [];
        foreach ($children as $childIndex => $rawChild) {
            $childHuman = $human . ', вложенный №' . ((int) $childIndex + 1);
            if (!is_array($rawChild)) {
                $warnings[] = $childHuman . ' пропущен: это не объект';
                continue;
            }
            $childType = (string) ($rawChild['type'] ?? '');
            if (!BlockTypeRegistry::has($childType)) {
                $warnings[] = $childHuman . ' пропущен: неизвестный тип «' . mb_substr($childType, 0, 40) . '»';
                continue;
            }
            if (BlockTypeRegistry::isContainer($childType)) {
                $warnings[] = $childHuman . ' пропущен: контейнер в контейнер не вкладывается';
                continue;
            }
            if ($childType === 'html' && !$allowRawCode) {
                $warnings[] = $childHuman . ' пропущен: блок «HTML-код» доступен только супер-администратору';
                continue;
            }

            $childCss = (string) ($rawChild['custom_css'] ?? '');
            $block['children'][] = [
                'column_index' => max(0, min(11, (int) ($rawChild['column_index'] ?? 0))),
                'type' => $childType,
                'title' => isset($rawChild['title']) && (string) $rawChild['title'] !== ''
                    ? mb_substr((string) $rawChild['title'], 0, 255)
                    : null,
                'data' => self::data($childType, $rawChild['data'] ?? [], $childHuman, $warnings),
                'custom_css' => $allowRawCode ? $childCss : '',
                'is_active' => (int) ($rawChild['is_active'] ?? 1) === 0 ? 0 : 1,
            ];
        }

        return $block;
    }

    /**
     * Поля блока делятся надвое, и проверяются они по-разному.
     *
     * Поля типа (`title`, `items`, `image`…) пересекаются с умолчаниями этого
     * типа: ключ, которого у типа нет, всё равно потерялся бы при первом
     * сохранении в админке, поэтому честнее отбросить его сразу и сказать
     * вслух. Значения при этом остаются как есть — на выводе их чистят сами
     * шаблоны блоков (UrlGuard на адресах, экранирование на тексте).
     *
     * Оформление секции (ключи с `_`) прогоняется через тот самый
     * нормализатор, которым пользуется форма блока. Перечислять эти ключи
     * руками нельзя: список разъезжается с нормализатором молча, и импорт
     * начинает либо терять оформление, либо пропускать непроверенное
     * значение внутрь.
     *
     * @param array<int, string> $warnings
     * @return array<string, mixed>
     */
    private static function data(string $type, mixed $raw, string $human, array &$warnings): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $typed = [];
        $presentation = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_')) {
                $presentation[$key] = $value;
            } else {
                $typed[(string) $key] = $value;
            }
        }

        $allowed = array_flip(array_keys(BlockTypeRegistry::defaultsFor($type)));
        $kept = array_intersect_key($typed, $allowed);

        $dropped = array_diff(array_keys($typed), array_keys($kept));
        if ($dropped !== []) {
            $warnings[] = $human . ': отброшены поля, которых нет у типа «'
                . (BlockTypeRegistry::TYPE_LABELS[$type] ?? $type) . '» — '
                . implode(', ', array_slice($dropped, 0, 6));
        }

        $data = array_merge($kept, self::presentation($presentation));

        // Перевёрнутое окно показа означало бы блок, который не покажется
        // никогда: снимаем условия и говорим об этом, а не молчим.
        if (BlockPresentationNormalizer::hasInvalidVisibilityWindow($data)) {
            $data['_visible_from'] = '';
            $data['_visible_to'] = '';
            $warnings[] = $human . ': условия показа сняты — в файле окончание раньше начала';
        }

        return $data;
    }

    /**
     * Оформление секции из файла — через нормализатор формы.
     *
     * Имена в хранилище отличаются от имён формы только ведущим `_`, кроме
     * `_reveal`: там хранится пара {enabled, type}, а форма присылает один
     * `reveal_type`.
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    private static function presentation(array $stored): array
    {
        $input = [];
        foreach ($stored as $key => $value) {
            $input[substr((string) $key, 1)] = $value;
        }

        $reveal = $stored['_reveal'] ?? null;
        $input['reveal_type'] = is_array($reveal) && !empty($reveal['enabled'])
            ? (string) ($reveal['type'] ?? '')
            : '';

        return BlockPresentationNormalizer::normalize($input);
    }
}
