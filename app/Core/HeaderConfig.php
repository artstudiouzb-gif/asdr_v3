<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Чтение и запись конфигурации шапки сайта (mini-app конструктор).
 * Хранится JSON-строкой в settings['header_config']. Любые прочитанные
 * данные сливаются с дефолтной структурой, поэтому старые/неполные JSON
 * никогда не приводят к ошибкам обращения к отсутствующим ключам.
 */
final class HeaderConfig
{
    /**
     * Элементы-«кирпичики» шапки для конструктора (расставляются по зонам
     * topbar/middlebar/bottombar). Логотип и меню также могут управляться
     * как отдельные элементы или размещаться по умолчанию.
     */
    public const ELEMENTS = [
        'logo' => 'Логотип',
        'menu' => 'Главное меню',
        'search' => 'Поиск',
        'language' => 'Переключатель языков',
        'social' => 'Соцсети',
        'button' => 'Кнопка (CTA)',
        'theme' => 'Тёмная тема',
        'a11y' => 'Версия для слабовидящих',
        'phone' => 'Телефон',
        'email' => 'E-mail',
        'snippet' => 'Сниппет (текст/HTML)',
        'divider' => 'Разделитель',
        'spacer' => 'Гибкий отступ',
        'space' => 'Пробел',
    ];

    /** Стили верхней/утилитарной полосы шапки. */
    public const BAR_STYLES = ['navy', 'light', 'teal'];

    /** Высоты секций шапки. */
    public const HEIGHTS = ['slim', 'normal', 'tall'];

    /** Разделительные линии секций: во всю ширину / по ширине контейнера / нет. */
    public const BORDER_MODES = ['full', 'container', 'none'];

    /** Элементы, которые можно повторять в зонах (визуальные). Прочие — уникальны
     *  в пределах одной секции (но могут повторяться в разных секциях). */
    private const REPEATABLE = ['divider', 'spacer', 'space'];

    public const DEFAULTS = [
        'logo_position' => 'left',            // left | center
        'logo_width' => 240,                  // px, 40..600
        'logo_height' => 48,                  // px, 20..200
        'menu_position' => 'center',          // left | center | right
        // Поведение шапки: липкая (следует за прокруткой) и прозрачная
        // (накладывается на первый экран, при прокрутке становится сплошной).
        'sticky' => true,
        'sticky_full_width' => false,
        'transparent' => true,
        // Светлый (белый) вариант логотипа для прозрачной шапки.
        'logo_light' => '',
        // Логотип для конкретного языка (код => URL). Переопределяет общий
        // логотип (Настройки → Логотип) на страницах этого языка.
        'logo_by_lang' => [],
        // Светлый логотип для конкретного языка (прозрачная шапка).
        'logo_light_by_lang' => [],
        // Конструктор: раскладка элементов по зонам верхнего ряда (десктоп).
        'elements' => [
            'left' => ['logo'],
            'center' => [],
            'right' => ['search', 'language', 'theme', 'a11y'],
        ],
        // Отдельная раскладка для мобильной версии (компактнее по умолчанию).
        'elements_mobile' => [
            'left' => ['logo'],
            'center' => [],
            'right' => ['search', 'language'],
        ],
        'language_switcher' => [
            'enabled' => true,
            'format' => 'code',               // code | name | flag
            'style' => 'dropdown',            // dropdown | pills
        ],
        'social_buttons' => [],               // [{network, url}]
        'cta' => [
            'enabled' => false,
            'text' => '',
            'url' => '',
            'style' => 'filled',              // filled | outline
        ],
        // Pro Max: верхняя утилитарная полоса над шапкой (top section).
        'topbar' => [
            'enabled' => false,
            'style' => 'navy',                // из BAR_STYLES
            'show_mobile' => false,
            'show_border' => false,
            'height' => 'normal',             // из HEIGHTS
            'zones' => ['left' => [], 'center' => [], 'right' => []],
        ],
        // Высота основной секции (логотип + утилиты) и свой цвет фона
        // ('' — фон темы).
        'middlebar' => ['height' => 'normal', 'bg' => ''],
        // Pro Max: нижняя полоса (bottom section) — элементы рядом с меню.
        'bottombar' => [
            'height' => 'normal',
            'bg' => '',
            'zones' => ['left' => [], 'center' => [], 'right' => []],
        ],
        // Тень под шапкой: вкл/выкл и размер (px, размытие). В прозрачном
        // режиме шапки тень не рисуется (до прокрутки).
        'shadow' => ['enabled' => true, 'size' => 14],
        // Разделительные линии между секциями.
        'borders' => 'full',
        // Режим контейнера всей шапки: full (на всю ширину), container (в боксе), floating (парящее стекло).
        'container_mode' => 'full',
        // Значения для элементов «Телефон» и «E-mail».
        'contacts' => [
            'phone' => '',
            'email' => '',
        ],
        // Произвольный сниппет (HTML проходит санитайзер при сохранении).
        'snippet' => '',
        // Расширенные стили оформления шапки (цвета меню, полос, границ).
        'styles' => [
            'nav_color' => '',
            'nav_hover' => '',
            'nav_active' => '',
            'nav_color_transparent' => '',
            'nav_hover_transparent' => '',
            'nav_active_transparent' => '',
            'nav_pill_bg' => '',
            'nav_font_size' => 'normal',
            'nav_transform' => 'uppercase',
            'nav_letter_spacing' => 'normal',
            'nav_style_type' => 'underline',
            'nav_padding' => 'normal',
            'nav_icon_pos' => 'left',
            'nav_gap' => 18,
            'nav_overflow' => 'adaptive',
            'nav_item_dividers' => false,
            'nav_divider_color' => '',
            'nav_divider_color_transparent' => '',
            'nav_divider_width' => 1,
            'nav_divider_height' => 18,
            'hoverline_length' => 'normal',
            'hoverline_offset' => 'normal',
            'hoverline_thickness' => '2px',
            'submenu_style' => 'lines',
            'submenu_width' => 'normal',
            'submenu_font_size' => '13.8',
            'submenu_padding' => 'normal',
            'submenu_transform' => 'none',
            'submenu_radius' => 'soft',
            'submenu_shadow' => 'soft',
            'submenu_divider' => 'subtle',
            'submenu_bg' => '',
            'submenu_color' => '',
            'submenu_hover' => '',
            'submenu_divider_color' => '',
            'topbar_bg' => '',
            'topbar_text' => '',
            'border_color' => '',
            'border_width' => '',
            'elements_gap' => 'normal',
            'floating_radius' => 18,
            'floating_opacity' => 25,
            'floating_gradient_angle' => 135,
            'floating_blur' => 14,
        ],
    ];

    public static function get(): array
    {
        $raw = Setting::get('header_config', '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return self::mergeDefaults($decoded);
    }

    /**
     * `JSON_THROW_ON_ERROR` по той же причине, что у подвала и у пресетов
     * «Дизайна»: `json_encode()` отдаёт `false` на битой кодировке, а
     * `Setting::set()` принимает `string` — под `strict_types` сохранение
     * шапки падало бы с `TypeError` вместо понятной ошибки.
     */
    public static function save(array $config): void
    {
        $clean = self::mergeDefaults($config);
        Setting::set('header_config', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Публичная нормализация конфигурации (валидация значений) без записи в БД. */
    public static function normalize(array $config): array
    {
        return self::mergeDefaults($config);
    }

    /**
     * Карта «код языка => URL логотипа». Оставляем только активные языки и
     * непустые значения. Ключи фильтруем по формату кода языка.
     *
     * @return array<string,string>
     */
    private static function normalizeLangMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $active = [];
        try {
            $active = \App\Models\Language::activeCodes();
        } catch (\Throwable $e) {
            // Таблица языков недоступна — не фильтруем по активности.
        }
        $map = [];
        foreach ($raw as $code => $url) {
            $code = strtolower(trim((string) $code));
            if ($code === '' || !preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code)) {
                continue;
            }
            if ($active !== [] && !in_array($code, $active, true)) {
                continue;
            }
            $url = trim((string) $url);
            if ($url !== '') {
                $map[$code] = $url;
            }
        }

        return $map;
    }

    /**
     * Нормализует раскладку элементов по зонам: только известные типы;
     * неповторяемые (поиск, языки и т.п.) — по одному на всю шапку (первое
     * вхождение выигрывает), разделители можно повторять.
     *
     * @param array<string, mixed> $raw
     * @return array{left: list<string>, center: list<string>, right: list<string>}
     */
    private static function normalizeZones(array $raw): array
    {
        $seen = [];
        $zones = ['left' => [], 'center' => [], 'right' => []];
        foreach (array_keys($zones) as $zone) {
            foreach ((array) ($raw[$zone] ?? []) as $el) {
                $el = (string) $el;
                if (!isset(self::ELEMENTS[$el])) {
                    continue;
                }
                if (!in_array($el, self::REPEATABLE, true)) {
                    if (isset($seen[$el])) {
                        continue;
                    }
                    $seen[$el] = true;
                }
                $zones[$zone][] = $el;
            }
        }

        return $zones;
    }

    private static function mergeDefaults(array $config): array
    {
        $result = self::DEFAULTS;

        $result['logo_position'] = in_array($config['logo_position'] ?? '', ['left', 'center'], true)
            ? $config['logo_position'] : self::DEFAULTS['logo_position'];

        $logoSize = static function (mixed $value, int $default, int $min, int $max): int {
            if (!is_scalar($value) || !preg_match('/^\d+$/', trim((string) $value))) {
                return $default;
            }

            return max($min, min($max, (int) $value));
        };
        $result['logo_width'] = $logoSize($config['logo_width'] ?? null, self::DEFAULTS['logo_width'], 40, 600);
        $result['logo_height'] = $logoSize($config['logo_height'] ?? null, self::DEFAULTS['logo_height'], 20, 200);

        $result['menu_position'] = in_array($config['menu_position'] ?? '', ['left', 'center', 'right'], true)
            ? $config['menu_position'] : self::DEFAULTS['menu_position'];

        $result['sticky'] = !empty($config['sticky']);
        $result['sticky_full_width'] = !empty($config['sticky_full_width']);
        $result['transparent'] = !empty($config['transparent']);
        $result['logo_light'] = trim((string) ($config['logo_light'] ?? ''));
        $result['logo_by_lang'] = self::normalizeLangMap($config['logo_by_lang'] ?? null);
        $result['logo_light_by_lang'] = self::normalizeLangMap($config['logo_light_by_lang'] ?? null);

        // Конструктор: раскладки элементов по зонам для десктопа и мобильного.
        $result['elements'] = isset($config['elements']) && is_array($config['elements'])
            ? self::normalizeZones($config['elements'])
            : self::DEFAULTS['elements'];
        $result['elements_mobile'] = isset($config['elements_mobile']) && is_array($config['elements_mobile'])
            ? self::normalizeZones($config['elements_mobile'])
            : self::DEFAULTS['elements_mobile'];

        $ls = (array) ($config['language_switcher'] ?? []);
        $isLanguagePlaced = false;
        foreach (['elements', 'topbar', 'bottombar'] as $secKey) {
            if (isset($result[$secKey]['zones']) && is_array($result[$secKey]['zones'])) {
                foreach ($result[$secKey]['zones'] as $zList) {
                    if (is_array($zList) && in_array('language', $zList, true)) {
                        $isLanguagePlaced = true;
                        break 2;
                    }
                }
            } elseif (is_array($result[$secKey])) {
                foreach ($result[$secKey] as $zList) {
                    if (is_array($zList) && in_array('language', $zList, true)) {
                        $isLanguagePlaced = true;
                        break 2;
                    }
                }
            }
        }
        $result['language_switcher'] = [
            'enabled' => !empty($ls['enabled']) || $isLanguagePlaced || !isset($config['language_switcher']),
            'format' => in_array($ls['format'] ?? '', ['code', 'name', 'flag', 'code_flag'], true) ? $ls['format'] : 'code',
            'style' => in_array($ls['style'] ?? '', ['dropdown', 'pills'], true) ? $ls['style'] : 'dropdown',
            'always_show' => !empty($ls['always_show']),
        ];

        $result['social_buttons'] = [];
        foreach ((array) ($config['social_buttons'] ?? []) as $btn) {
            $network = trim((string) ($btn['network'] ?? ''));
            $url = trim((string) ($btn['url'] ?? ''));
            if ($network !== '' && $url !== '') {
                $result['social_buttons'][] = ['network' => $network, 'url' => $url];
            }
        }
        $result['social_style'] = in_array($config['social_style'] ?? '', ['monochrome', 'colored', 'outline'], true) ? $config['social_style'] : 'monochrome';

        $cta = (array) ($config['cta'] ?? []);
        $result['cta'] = [
            'enabled' => !empty($cta['enabled']),
            'text' => trim((string) ($cta['text'] ?? '')),
            'url' => trim((string) ($cta['url'] ?? '')),
            'style' => in_array($cta['style'] ?? '', ['filled', 'outline', 'glass', 'neon'], true) ? $cta['style'] : 'filled',
            'icon' => in_array($cta['icon'] ?? '', ['none', 'phone', 'calendar', 'arrow', 'send'], true) ? $cta['icon'] : 'none',
        ];

        $srch = (array) ($config['search'] ?? []);
        $result['search'] = [
            'style' => in_array($srch['style'] ?? '', ['inline', 'modal', 'expandable'], true) ? $srch['style'] : 'inline',
            'placeholder' => trim((string) ($srch['placeholder'] ?? 'Поиск по сайту...')),
        ];

        // Pro Max: секции top/bottom.
        $height = static fn (mixed $v): string => in_array($v, self::HEIGHTS, true) ? $v : 'normal';
        $topbar = (array) ($config['topbar'] ?? []);
        $result['topbar'] = [
            'enabled' => !empty($topbar['enabled']),
            'style' => in_array($topbar['style'] ?? '', self::BAR_STYLES, true) ? $topbar['style'] : 'navy',
            'show_mobile' => !empty($topbar['show_mobile']),
            'show_border' => !empty($topbar['show_border']),
            'height' => $height($topbar['height'] ?? ''),
            'zones' => isset($topbar['zones']) && is_array($topbar['zones'])
                ? self::normalizeZones($topbar['zones'])
                : self::DEFAULTS['topbar']['zones'],
        ];

        // Свой цвет фона секции: #RRGGBB или '' (фон темы).
        $hexOrEmpty = static fn (mixed $v): string => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v) ? strtolower((string) $v) : '';
        $middlebar = (array) ($config['middlebar'] ?? []);
        $result['middlebar'] = [
            'height' => $height($middlebar['height'] ?? ''),
            'bg' => $hexOrEmpty($middlebar['bg'] ?? ''),
        ];
        $bottombar = (array) ($config['bottombar'] ?? []);
        $result['bottombar'] = [
            'height' => $height($bottombar['height'] ?? ''),
            'bg' => $hexOrEmpty($bottombar['bg'] ?? ''),
            'zones' => isset($bottombar['zones']) && is_array($bottombar['zones'])
                ? self::normalizeZones($bottombar['zones'])
                : self::DEFAULTS['bottombar']['zones'],
        ];
        $shadow = (array) ($config['shadow'] ?? []);
        $shadowSize = is_scalar($shadow['size'] ?? null) && preg_match('/^\d+$/', trim((string) $shadow['size']))
            ? max(2, min(60, (int) $shadow['size']))
            : self::DEFAULTS['shadow']['size'];
        $result['shadow'] = [
            'enabled' => !empty($shadow['enabled']),
            'size' => $shadowSize,
        ];
        $result['borders'] = in_array($config['borders'] ?? '', self::BORDER_MODES, true)
            ? $config['borders'] : 'full';
        $result['container_mode'] = in_array($config['container_mode'] ?? '', ['full', 'container', 'floating'], true)
            ? $config['container_mode'] : 'full';

        $contacts = (array) ($config['contacts'] ?? []);
        $result['contacts'] = [
            'phone' => trim((string) ($contacts['phone'] ?? '')),
            'email' => trim((string) ($contacts['email'] ?? '')),
        ];

        // Сниппет: строгий allowlist-санитайзер (без <script>/on*-обработчиков).
        $snippet = (string) ($config['snippet'] ?? '');
        $result['snippet'] = $snippet !== '' ? HtmlSanitizer::sanitize($snippet) : '';

        $styles = (array) ($config['styles'] ?? []);
        $boundedInt = static function (mixed $value, int $default, int $min, int $max): int {
            if (!is_scalar($value) || !preg_match('/^\d+$/', trim((string) $value))) {
                return $default;
            }

            return max($min, min($max, (int) $value));
        };
        $boundedDecimal = static function (mixed $value, string $default, float $min, float $max): string {
            $legacy = ['compact' => '12.5', 'normal' => '13.8', 'large' => '15.2'];
            $raw = trim((string) $value);
            if (isset($legacy[$raw])) {
                return $legacy[$raw];
            }
            if (!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/', $raw)) {
                return $default;
            }
            $number = max($min, min($max, (float) $raw));

            return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
        };
        $result['styles'] = [
            'nav_color' => $hexOrEmpty($styles['nav_color'] ?? ''),
            'nav_hover' => $hexOrEmpty($styles['nav_hover'] ?? ''),
            'nav_active' => $hexOrEmpty($styles['nav_active'] ?? ''),
            'nav_color_transparent' => $hexOrEmpty($styles['nav_color_transparent'] ?? ''),
            'nav_hover_transparent' => $hexOrEmpty($styles['nav_hover_transparent'] ?? ''),
            'nav_active_transparent' => $hexOrEmpty($styles['nav_active_transparent'] ?? ''),
            'nav_pill_bg' => $hexOrEmpty($styles['nav_pill_bg'] ?? ''),
            'nav_font_size' => in_array($styles['nav_font_size'] ?? '', ['compact', 'normal', 'large'], true) ? $styles['nav_font_size'] : 'normal',
            'nav_transform' => in_array($styles['nav_transform'] ?? '', ['uppercase', 'capitalize', 'none'], true) ? $styles['nav_transform'] : 'uppercase',
            'nav_letter_spacing' => in_array($styles['nav_letter_spacing'] ?? '', ['normal', 'wide', 'wider'], true) ? $styles['nav_letter_spacing'] : 'normal',
            'nav_style_type' => in_array($styles['nav_style_type'] ?? '', ['underline', 'dot', 'pill', 'glow', 'minimal'], true) ? $styles['nav_style_type'] : 'underline',
            'nav_padding' => in_array($styles['nav_padding'] ?? '', ['compact', 'normal', 'spacious'], true) ? $styles['nav_padding'] : 'normal',
            'nav_icon_pos' => in_array($styles['nav_icon_pos'] ?? '', ['left', 'top'], true) ? $styles['nav_icon_pos'] : 'left',
            'nav_gap' => $boundedInt($styles['nav_gap'] ?? null, 18, 0, 64),
            'nav_overflow' => in_array($styles['nav_overflow'] ?? '', ['adaptive', 'wrap'], true)
                ? $styles['nav_overflow'] : 'adaptive',
            'nav_item_dividers' => !empty($styles['nav_item_dividers']),
            'nav_divider_color' => $hexOrEmpty($styles['nav_divider_color'] ?? ''),
            'nav_divider_color_transparent' => $hexOrEmpty($styles['nav_divider_color_transparent'] ?? ''),
            'nav_divider_width' => $boundedInt($styles['nav_divider_width'] ?? null, 1, 1, 10),
            'nav_divider_height' => $boundedInt($styles['nav_divider_height'] ?? null, 18, 4, 64),
            'hoverline_length' => in_array($styles['hoverline_length'] ?? '', ['compact', 'normal', 'full', '12px', '8px', '0px'], true) ? $styles['hoverline_length'] : 'normal',
            'hoverline_offset' => in_array($styles['hoverline_offset'] ?? '', ['close', 'normal', 'far', '1px', '2px', '4px', '8px'], true) ? $styles['hoverline_offset'] : 'normal',
            'hoverline_thickness' => in_array($styles['hoverline_thickness'] ?? '', ['thin', 'normal', 'thick', 'heavy', '1px', '2px', '3px', '4px', '5px', '6px', '8px'], true) ? $styles['hoverline_thickness'] : '2px',
            'submenu_style' => in_array($styles['submenu_style'] ?? '', ['lines', 'minimal', 'cards'], true) ? $styles['submenu_style'] : 'lines',
            'submenu_width' => in_array($styles['submenu_width'] ?? '', ['compact', 'normal', 'wide'], true) ? $styles['submenu_width'] : 'normal',
            'submenu_font_size' => $boundedDecimal($styles['submenu_font_size'] ?? null, '13.8', 10, 24),
            'submenu_padding' => in_array($styles['submenu_padding'] ?? '', ['compact', 'normal', 'spacious'], true) ? $styles['submenu_padding'] : 'normal',
            'submenu_transform' => in_array($styles['submenu_transform'] ?? '', ['none', 'uppercase', 'capitalize'], true) ? $styles['submenu_transform'] : 'none',
            'submenu_radius' => in_array($styles['submenu_radius'] ?? '', ['none', 'soft', 'rounded'], true) ? $styles['submenu_radius'] : 'soft',
            'submenu_shadow' => in_array($styles['submenu_shadow'] ?? '', ['none', 'soft', 'deep'], true) ? $styles['submenu_shadow'] : 'soft',
            'submenu_divider' => in_array($styles['submenu_divider'] ?? '', ['subtle', 'accent', 'none'], true) ? $styles['submenu_divider'] : 'subtle',
            'submenu_bg' => $hexOrEmpty($styles['submenu_bg'] ?? ''),
            'submenu_color' => $hexOrEmpty($styles['submenu_color'] ?? ''),
            'submenu_hover' => $hexOrEmpty($styles['submenu_hover'] ?? ''),
            'submenu_divider_color' => $hexOrEmpty($styles['submenu_divider_color'] ?? ''),
            'topbar_bg' => $hexOrEmpty($styles['topbar_bg'] ?? ''),
            'topbar_text' => $hexOrEmpty($styles['topbar_text'] ?? ''),
            'border_color' => $hexOrEmpty($styles['border_color'] ?? ''),
            'border_width' => in_array($styles['border_width'] ?? '', ['none', 'thin', 'thick'], true) ? $styles['border_width'] : '',
            'elements_gap' => in_array($styles['elements_gap'] ?? '', ['ultra_compact', 'compact', 'normal', 'spacious', 'loose'], true) ? $styles['elements_gap'] : 'normal',
            'floating_radius' => isset($styles['floating_radius']) ? max(0, min(60, (int) $styles['floating_radius'])) : 18,
            'floating_opacity' => isset($styles['floating_opacity']) ? max(5, min(100, (int) $styles['floating_opacity'])) : 25,
            'floating_gradient_angle' => isset($styles['floating_gradient_angle']) ? max(0, min(360, (int) $styles['floating_gradient_angle'])) : 135,
            'floating_blur' => isset($styles['floating_blur']) ? max(0, min(40, (int) $styles['floating_blur'])) : 14,
        ];

        return $result;
    }
}
