<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Конфигурация конструктора подвала: набор колонок с микро-виджетами и нижняя
 * строка (копирайт). Хранится JSON-строкой в settings['footer_config'].
 * Любые прочитанные данные сливаются с дефолтами — старые/неполные JSON
 * не приводят к ошибкам.
 */
final class FooterConfig
{
    /** Доступные виджеты колонки подвала: value => подпись для админки. */
    public const WIDGETS = [
        'about' => 'Логотип и описание',
        'menu' => 'Меню сайта',
        'contacts' => 'Контакты (адрес, телефон, email)',
        'social' => 'Соцсети',
        'subscribe' => 'Подписка на новости (форма)',
        'text' => 'Текст / HTML (сниппет)',
    ];

    /**
     * Виджеты, которым принадлежит поле «Текст»: «Логотип и описание» выводит
     * его подписью под знаком, «Текст / HTML» — самим содержимым колонки.
     * Остальным виджетам текст не принадлежит и при сохранении обнуляется.
     */
    public const TEXT_WIDGETS = ['about', 'text'];

    /**
     * Версия формата конфигурации. Поднимается, когда меняется смысл уже
     * сохранённых данных: v2 развёл «Логотип и описание» и «Контакты» —
     * до неё первый виджет печатал адрес, телефон и почту сам, и на сайте с
     * обеими колонками они выводились дважды.
     */
    public const VERSION = 2;

    public const STYLES = ['columns', 'minimal'];

    /** Максимум колонок в подвале. */
    public const MAX_COLUMNS = 4;

    /**
     * Максимум ссылок в нижней строке. Их место — служебные страницы (условия
     * использования, карта сайта, обратная связь), а не второе меню: строка
     * идёт мелким кеглем рядом с копирайтом, и с пятой ссылки она перестаёт
     * читаться как приписка и начинает спорить с настоящей навигацией подвала.
     */
    public const MAX_BOTTOM_LINKS = 4;

    public const DEFAULTS = [
        'v' => self::VERSION,
        'style' => 'columns',                 // columns | minimal
        'columns' => [
            ['heading' => '', 'widget' => 'about', 'text' => ''],
            ['heading' => 'Разделы', 'widget' => 'menu', 'text' => ''],
            ['heading' => 'Связь', 'widget' => 'contacts', 'text' => ''],
        ],
        // Плейсхолдеры: {year} — текущий год, {site} — название сайта.
        'bottom' => '© {year} {site}',
        // Ссылки справа в строке копирайта: [{label, url}].
        'bottom_links' => [],
        // Фон подвала: те же режимы, что и у секции страницы (цвет, градиент,
        // фотография, узор). Пустой режим — как было, из темы.
        'background' => ['_bg_mode' => 'preset'],
    ];

    public static function get(): array
    {
        $raw = Setting::get('footer_config', '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return self::mergeDefaults($decoded);
    }

    /**
     * `json_encode()` объявлена как `string|false` и при негодных данных
     * (битая кодировка в подписи колонки, пришедшая из конструктора) отдаёт
     * `false`. В файле со `strict_types` это значение уходит в
     * `Setting::set()` типизированным параметром — сохранение подвала падало
     * бы с `TypeError`, ничего не говоря о причине. `JSON_THROW_ON_ERROR`
     * называет её вслух и не даёт записать в настройку мусор вместо
     * конфигурации. Тот же приём, что у пресетов «Дизайна».
     */
    public static function save(array $config): void
    {
        Setting::set(
            'footer_config',
            json_encode(self::mergeDefaults($config), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
    }

    public static function normalize(array $config): array
    {
        return self::mergeDefaults($config);
    }

    /**
     * Разворачивает плейсхолдеры нижней строки ({year}, {site}) и очищает от
     * разметки (простой текст). Чистая функция для рендера и тестов.
     */
    public static function renderBottom(string $template, string $siteName): string
    {
        $out = strtr($template, [
            '{year}' => (string) date('Y'),
            '{site}' => $siteName,
        ]);

        return trim(strip_tags($out));
    }

    /**
     * Хранимые ключи фона (`_bg_*`) обратно в вид формы: нормализатор работает
     * с тем, что прислал редактор, и второй его копии заводить не за чем.
     *
     * @param array<string, mixed> $background
     * @return array<string, mixed>
     */
    private static function backgroundInput(array $background): array
    {
        $input = [];
        foreach ($background as $key => $value) {
            $input[ltrim((string) $key, '_')] = $value;
        }

        return $input;
    }

    private static function mergeDefaults(array $config): array
    {
        $result = self::DEFAULTS;

        $result['style'] = in_array($config['style'] ?? '', self::STYLES, true)
            ? $config['style'] : self::DEFAULTS['style'];
        // Фон приходит уже нормализованным (ключи с `_`), но конфиг правят и
        // руками — прогоняем через тот же нормализатор, что и у блоков.
        $background = is_array($config['background'] ?? null) ? $config['background'] : [];
        $result['background'] = \App\Core\BlockData\BlockPresentationNormalizer::background(
            self::backgroundInput($background)
        );

        if (isset($config['columns']) && is_array($config['columns'])) {
            $columns = [];
            foreach ($config['columns'] as $col) {
                if (!is_array($col)) {
                    continue;
                }
                $widget = (string) ($col['widget'] ?? '');
                if (!isset(self::WIDGETS[$widget])) {
                    continue;
                }
                $text = (string) ($col['text'] ?? '');
                // Текст — безопасный HTML (тот же санитайзер, что для контента
                // редактора); виджеты без своего текста его не хранят.
                $text = (in_array($widget, self::TEXT_WIDGETS, true) && trim($text) !== '')
                    ? HtmlSanitizer::sanitize($text)
                    : '';
                $columns[] = [
                    'heading' => mb_substr(trim((string) ($col['heading'] ?? '')), 0, 100),
                    'widget' => $widget,
                    'text' => $text,
                ];
                if (count($columns) >= self::MAX_COLUMNS) {
                    break;
                }
            }
            $result['columns'] = self::upgradeColumns($columns, (int) ($config['v'] ?? 1));
        } else {
            $result['columns'] = self::DEFAULTS['columns'];
        }

        $result['bottom'] = mb_substr(trim((string) ($config['bottom'] ?? self::DEFAULTS['bottom'])), 0, 300);
        if ($result['bottom'] === '') {
            $result['bottom'] = self::DEFAULTS['bottom'];
        }
        $result['bottom_links'] = self::normalizeBottomLinks($config['bottom_links'] ?? null);
        $result['v'] = self::VERSION;

        return $result;
    }

    /**
     * Ссылки нижней строки: подпись и адрес. Ссылка без подписи или без адреса
     * не выводится — пустой текст ссылки диктор читает как сам адрес. Адрес
     * проверяет тот же `UrlGuard`, что и остальной вывод в href: поле правит
     * редактор, а `javascript:` в подвале виден на каждой странице сайта.
     *
     * @return list<array{label: string, url: string}>
     */
    private static function normalizeBottomLinks(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $links = [];
        foreach ($raw as $link) {
            if (!is_array($link)) {
                continue;
            }
            $label = mb_substr(trim((string) ($link['label'] ?? '')), 0, 60);
            $url = mb_substr(trim((string) ($link['url'] ?? '')), 0, 300);
            if ($label === '' || $url === '' || !UrlGuard::isSafeLink($url)) {
                continue;
            }
            $links[] = ['label' => $label, 'url' => $url];
            if (count($links) >= self::MAX_BOTTOM_LINKS) {
                break;
            }
        }

        return $links;
    }

    /**
     * Разовый перенос при переходе на v2: «Логотип и описание» перестал
     * печатать контакты, поэтому подвалу, где они держались только на нём,
     * добавляется колонка «Контакты» — иначе адрес и телефон исчезли бы с
     * сайта молча. Делается ровно один раз: результат помечен номером версии,
     * и удалённую потом колонку сохранение обратно не вернёт.
     *
     * @param list<array{heading: string, widget: string, text: string}> $columns
     * @return list<array{heading: string, widget: string, text: string}>
     */
    private static function upgradeColumns(array $columns, int $version): array
    {
        if ($version >= self::VERSION || count($columns) >= self::MAX_COLUMNS) {
            return $columns;
        }

        $widgets = array_column($columns, 'widget');
        if (!in_array('about', $widgets, true) || in_array('contacts', $widgets, true)) {
            return $columns;
        }

        $columns[] = ['heading' => 'Контакты', 'widget' => 'contacts', 'text' => ''];

        return $columns;
    }
}
