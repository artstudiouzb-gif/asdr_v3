<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Design\CssValue;
use App\Core\Design\FontCatalog;
use App\Core\Design\Surfaces;
use App\Core\Design\Typography;
use App\Core\Design\UserPresets;
use App\Models\Setting;

/**
 * Управление дизайном сайта («тема-билдер»): готовые конфигурации (пресеты) и
 * точная настройка визуальных параметров. Значения хранятся в settings
 * (design_*), применяются на фронтенде через CSS-переменные и классы <body>.
 * Источник истины для админ-панели (карточки-опции) и для рендера.
 */
final class DesignSettings
{
    /**
     * Опции точной настройки: ключ => [label, hint, choices[value=>label], default].
     * Каждая опция рендерится в админке набором карточек-переключателей.
     */
    /**
     * Готовые палитры: значение опции palette => [подпись, основной, акцент].
     * 'custom' использует ручные цвета из этого же раздела «Дизайн».
     */
    public const PALETTES = [
        'gov_blue' => ['Гос-синий', '#0f2756', '#2563eb'],
        'classic_red' => ['Классика', '#1a1a1a', '#e63946'],
        'emerald' => ['Изумруд', '#065f46', '#059669'],
        'graphite' => ['Графит', '#111827', '#374151'],
        'violet' => ['Индиго', '#312e81', '#6d28d9'],
        'custom' => ['Свои цвета', '', ''],
    ];


    public const OPTIONS = [
        'palette' => [
            'label' => 'Цветовая палитра',
            'hint' => 'Основной и акцентный цвета сайта. «Свои цвета» — ручные значения ниже.',
            'group' => 'Цвета и шрифт',
            'choices' => ['gov_blue' => 'Гос-синий', 'classic_red' => 'Классика', 'emerald' => 'Изумруд', 'graphite' => 'Графит', 'violet' => 'Индиго', 'custom' => 'Свои цвета'],
            'default' => 'custom',
        ],
        'font_style' => [
            'label' => 'Шрифт сайта',
            'hint' => 'Основной шрифт выбирается в едином списке базовых, внешних и собственных шрифтов ниже.',
            'group' => 'Цвета и шрифт',
            'choices' => ['noto' => 'Noto Sans', 'system' => 'Системный', 'serif' => 'С засечками', 'custom' => 'Свой шрифт'],
            'default' => 'custom',
        ],
        'container' => [
            'label' => 'Ширина контейнера',
            'hint' => 'Максимальная ширина основного содержимого. Ниже можно задать свою точную ширину.',
            'group' => 'Общие',
            'choices' => ['narrow' => 'Узкий (1200px)', 'standard' => 'Стандарт (1440px)', 'wide' => 'Широкий (1500px)', 'ultra' => 'Очень широкий (1700px)', 'full' => 'На всю ширину'],
            // Умолчание — «Стандарт»: прежнее умолчание «Очень широкий» давало
            // те же 1440px, и после смены шкалы сайт молча уехал бы до 1700.
            'default' => 'standard',
        ],
        'radius' => [
            'label' => 'Скругление углов',
            'hint' => 'Радиус карточек и крупных блоков. Ниже можно задать точное значение.',
            'group' => 'Общие',
            'choices' => ['none' => 'Прямые', 'small' => 'Малое', 'medium' => 'Среднее', 'large' => 'Большое'],
            'default' => 'medium',
        ],
        'card_gap' => [
            'label' => 'Отступ между карточками',
            'hint' => 'Расстояние между элементами в сетках.',
            'group' => 'Общие',
            'choices' => ['xs' => '8px', 'sm' => '16px', 'md' => '24px', 'lg' => '32px'],
            'default' => 'md',
        ],
        'density' => [
            'label' => 'Плотность секций',
            'hint' => 'Вертикальные отступы между секциями страницы.',
            'group' => 'Общие',
            'choices' => ['compact' => 'Компактно', 'standard' => 'Стандарт', 'spacious' => 'Просторно'],
            'default' => 'standard',
        ],
        'scroll_top' => [
            'label' => 'Кнопка «Наверх»',
            'hint' => 'Плавающая кнопка прокрутки страницы вверх — появляется в углу после прокрутки.',
            'group' => 'Общие',
            'choices' => ['on' => 'Показывать', 'off' => 'Скрыть'],
            'default' => 'on',
        ],
        'font_size' => [
            'label' => 'Размер шрифта',
            'hint' => 'Базовый размер основного текста сайта.',
            'group' => 'Типографика',
            'choices' => ['sm' => 'Мельче', 'md' => 'Стандарт', 'lg' => 'Крупнее', 'xl' => 'Очень крупный'],
            'default' => 'md',
        ],
        'line_height' => [
            'label' => 'Межстрочный интервал текста',
            'hint' => 'Высота строки основного текста.',
            'group' => 'Типографика',
            'choices' => ['tight' => 'Плотный', 'normal' => 'Стандарт', 'relaxed' => 'Просторный'],
            'default' => 'normal',
        ],
        'heading_line_height' => [
            'label' => 'Межстрочный интервал заголовков',
            'hint' => 'Высота строки для всех заголовков H1–H6 и названий карточек.',
            'group' => 'Типографика',
            'choices' => ['tight' => 'Плотный (1.15)', 'normal' => 'Стандарт (1.25)', 'relaxed' => 'Просторный (1.35)'],
            'default' => 'normal',
        ],
        'heading_font_weight' => [
            'label' => 'Насыщенность заголовков',
            'hint' => 'Толщина шрифта (font-weight) для заголовков.',
            'group' => 'Типографика',
            'choices' => ['400' => 'Обычный (400)', '600' => 'Полужирный (600)', '700' => 'Жирный (700)', '800' => 'Сверхжирный (800)'],
            'default' => '700',
        ],
        'heading_letter_spacing' => [
            'label' => 'Межбуквенный интервал заголовков',
            'hint' => 'Расстояние между буквами в заголовках (letter-spacing).',
            'group' => 'Типографика',
            'choices' => ['tight' => 'Плотный (-0.03em)', 'normal' => 'Стандарт (-0.02em)', 'wide' => 'Широкий (0em)'],
            'default' => 'normal',
        ],
        'title_mark' => [
            'label' => 'Выделение в заголовках',
            'hint' => 'Как выглядит слово, обёрнутое звёздочками: «Стратегия *развития*».',
            'group' => 'Типографика',
            'choices' => [
                'accent' => 'Акцентный цвет',
                'script' => 'Рукописный шрифт',
                'underline' => 'Мазок под словом',
                'off' => 'Без выделения',
            ],
            'default' => 'accent',
        ],
        'section_marker' => [
            'label' => 'Метка заголовка секции',
            'hint' => 'Знак перед заголовком секции на внутренних страницах. У заголовка по центру и справа метка не рисуется: она указывает на начало строки, а там его нет.',
            'group' => 'Типографика',
            'choices' => [
                'line' => 'Линия слева',
                'emblem' => 'Знак-эмблема',
                'off' => 'Без метки',
            ],
            'default' => 'line',
        ],
        'title_reveal' => [
            'label' => 'Проявление заголовков',
            'hint' => 'Заголовок секции ждёт прокрутки бледным и набирает свой цвет. Приём выключается сам при «меньше движения» и в режимах контраста.',
            'group' => 'Типографика',
            'choices' => [
                'off' => 'Без проявления',
                'fade' => 'Проступает целиком',
                'wipe' => 'Проступает слева направо',
            ],
            'default' => 'off',
        ],
        'button' => [
            'label' => 'Форма кнопок',
            'hint' => 'Стиль углов у кнопок и CTA.',
            'group' => 'Общие',
            'choices' => ['square' => 'Прямые', 'rounded' => 'Скруглённые', 'pill' => 'Капсула'],
            'default' => 'pill',
        ],
        'link_style' => [
            'label' => 'Ссылки «Все …» у разделов',
            'hint' => 'Стрелка после текста или линия, которая прорисовывается под ним при наведении. При подчёркивании ссылки в тексте получают тонкую линию в покое и акцентную при наведении; на телефоне линия видна сразу.',
            'group' => 'Общие',
            'choices' => [
                'arrow' => 'Со стрелкой',
                'draw' => 'Подчёркивание при наведении',
            ],
            'default' => 'arrow',
        ],
        'button_fill' => [
            'label' => 'Контурные кнопки при наведении',
            'hint' => 'Второстепенная кнопка-контур заливается цветом при наведении и фокусе с клавиатуры. Основная кнопка залита всегда. При «меньше движения» заливка появляется без анимации.',
            'group' => 'Общие',
            'choices' => [
                'off' => 'Как в теме',
                'accent' => 'Акцентом слева направо',
                'primary' => 'Основным цветом снизу вверх',
            ],
            'default' => 'off',
        ],
        // Уровень анимаций сайта. Выбор владельца действует только в сторону
        // «меньше»: посетитель, попросивший меньше движения (системная
        // настройка или тумблер панели), получает своё и при «Полных».
        'motion' => [
            'label' => 'Анимации на сайте',
            'hint' => 'Полные — всё как задумано: появление блоков, переходы слайдов, бегущие ленты, автопрокрутка. Спокойные — без движения и украшений: плавно меняются только цвет и прозрачность при наведении, блоки проявляются на месте. Без анимаций — всё сменяется сразу. Посетитель, который сам попросил меньше движения, получает его при любом выборе.',
            'group' => 'Общие',
            'choices' => [
                'full' => 'Полные',
                'calm' => 'Спокойные',
                'off' => 'Без анимаций',
            ],
            'default' => 'full',
        ],
        'block_surface' => [
            'label' => 'Подложки блоков',
            'hint' => 'Кому карточка: всем блокам или только тому, что открывается по нажатию.',
            'group' => 'Общие',
            'choices' => [
                'all' => 'У всех блоков',
                'interactive' => 'Только у ссылок и кнопок',
            ],
            'default' => 'all',
        ],
        'card_style' => [
            'label' => 'Стиль карточек',
            'hint' => 'Тень и глубина карточек контента.',
            'group' => 'Общие',
            'choices' => ['flat' => 'Плоские', 'soft' => 'Мягкая тень', 'elevated' => 'Приподнятые'],
            'default' => 'soft',
        ],
        'sidebar_position' => [
            'label' => 'Боковая колонка при прокрутке',
            'hint' => 'Поведение сайдбара страниц с боковой колонкой.',
            'group' => 'Общие',
            'choices' => ['floating' => 'Плавающая', 'fixed' => 'Неподвижная'],
            'default' => 'floating',
        ],
        'catalog_layout' => [
            'label' => 'Шаблон списка разделов',
            'hint' => 'Как выводятся карточки в каталоге (Документы/Вакансии/Тендеры).',
            'group' => 'Каталог',
            'choices' => ['cards_lg' => 'Большие карточки', 'cards_sm' => 'Компактные карточки', 'list' => 'Списком'],
            'default' => 'cards_lg',
        ],
        'detail_layout' => [
            'label' => 'Шаблон детальной страницы',
            'hint' => 'Как показывать карточку записи каталога.',
            'group' => 'Каталог',
            'choices' => ['plain' => 'В одну колонку', 'sidebar' => 'С боковой панелью'],
            'default' => 'plain',
        ],
    ];

    /**
     * Готовые конфигурации: применяют набор опций одним кликом.
     */
    public const PRESETS = [
        'classic' => [
            'label' => 'Классический',
            'desc' => 'Строгий официальный стиль, умеренные отступы.',
            'values' => ['container' => 'standard', 'radius' => 'small', 'card_gap' => 'sm', 'density' => 'standard', 'font_size' => 'md', 'line_height' => 'normal', 'heading_line_height' => 'normal', 'heading_font_weight' => '700', 'heading_letter_spacing' => 'normal', 'button' => 'rounded', 'block_surface' => 'all', 'card_style' => 'soft', 'sidebar_position' => 'floating', 'catalog_layout' => 'cards_lg', 'detail_layout' => 'plain', 'title_mark' => 'accent', 'title_reveal' => 'off', 'section_marker' => 'line', 'scroll_top' => 'on', 'link_style' => 'arrow', 'button_fill' => 'off', 'motion' => 'full', 'palette' => 'gov_blue', 'font_style' => 'system'],
        ],
        'modern' => [
            'label' => 'Современный',
            'desc' => 'Крупные скругления, воздух, акцентная шапка.',
            'values' => ['container' => 'wide', 'radius' => 'large', 'card_gap' => 'md', 'density' => 'spacious', 'font_size' => 'lg', 'line_height' => 'relaxed', 'heading_line_height' => 'tight', 'heading_font_weight' => '800', 'heading_letter_spacing' => 'tight', 'button' => 'pill', 'block_surface' => 'all', 'card_style' => 'elevated', 'sidebar_position' => 'floating', 'catalog_layout' => 'cards_lg', 'detail_layout' => 'sidebar', 'title_mark' => 'accent', 'title_reveal' => 'fade', 'section_marker' => 'line', 'scroll_top' => 'on', 'link_style' => 'draw', 'button_fill' => 'accent', 'motion' => 'full', 'palette' => 'violet', 'font_style' => 'noto'],
        ],
        'minimal' => [
            'label' => 'Минимал',
            'desc' => 'Прямые углы, максимум воздуха, список в каталоге.',
            'values' => ['container' => 'narrow', 'radius' => 'none', 'card_gap' => 'md', 'density' => 'spacious', 'font_size' => 'md', 'line_height' => 'normal', 'heading_line_height' => 'normal', 'heading_font_weight' => '700', 'heading_letter_spacing' => 'normal', 'button' => 'square', 'block_surface' => 'all', 'card_style' => 'flat', 'sidebar_position' => 'fixed', 'catalog_layout' => 'list', 'detail_layout' => 'plain', 'title_mark' => 'accent', 'title_reveal' => 'off', 'section_marker' => 'off', 'scroll_top' => 'on', 'link_style' => 'draw', 'button_fill' => 'primary', 'motion' => 'full', 'palette' => 'graphite', 'font_style' => 'serif'],
        ],
        'compact' => [
            'label' => 'Компактный',
            'desc' => 'Плотная сетка, маленькие карточки — много данных.',
            'values' => ['container' => 'standard', 'radius' => 'small', 'card_gap' => 'xs', 'density' => 'compact', 'font_size' => 'sm', 'line_height' => 'tight', 'heading_line_height' => 'tight', 'heading_font_weight' => '700', 'heading_letter_spacing' => 'tight', 'button' => 'rounded', 'block_surface' => 'all', 'card_style' => 'soft', 'sidebar_position' => 'fixed', 'catalog_layout' => 'cards_sm', 'detail_layout' => 'sidebar', 'title_mark' => 'accent', 'title_reveal' => 'off', 'section_marker' => 'line', 'scroll_top' => 'on', 'link_style' => 'arrow', 'button_fill' => 'off', 'motion' => 'full', 'palette' => 'classic_red', 'font_style' => 'system'],
        ],
    ];

    /** Текущие значения всех опций (из settings, с дефолтами). @return array<string,string> */
    public static function current(): array
    {
        $values = [];
        foreach (self::OPTIONS as $key => $opt) {
            $stored = (string) Setting::get('design_' . $key, '');
            $values[$key] = isset($opt['choices'][$stored]) ? $stored : $opt['default'];
        }
        $values['card_hover_lift'] = (string) self::cardHoverLift();

        return $values;
    }

    /** Проверяет и нормализует одно значение опции. */
    public static function sanitize(string $key, string $value): ?string
    {
        if (!isset(self::OPTIONS[$key])) {
            return null;
        }
        return isset(self::OPTIONS[$key]['choices'][$value]) ? $value : self::OPTIONS[$key]['default'];
    }

    /** Сохраняет набор значений (только известные опции). @param array<string,mixed> $input */
    /**
     * Своя ширина контейнера (design_container_custom): '' если не задана/
     * невалидна. Принимает 640–2400 (px), px/rem/vw/% с единицей, или число.
     */
    public static function containerCustom(): string
    {
        $raw = trim((string) Setting::get('design_container_custom', ''));
        return self::normalizeWidth($raw);
    }

    /** Нормализует пользовательскую ширину или возвращает '' при невалидной. */
    public static function normalizeWidth(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d{2,4}(px|rem|vw|%)$/', $raw)) {
            return $raw;
        }
        if (preg_match('/^\d{3,4}$/', $raw)) {
            $n = (int) $raw;
            return ($n >= 640 && $n <= 2400) ? $n . 'px' : '';
        }
        return '';
    }

    /**
     * Единое значение выбора основного шрифта для формы дизайна.
     * Старые design_font_style/design_font_google_body остаются форматом
     * хранения, поэтому обновление не требует миграции базы.
     */
    public static function bodyFontChoice(): string
    {
        $google = (string) Setting::get('design_font_google_body', '');
        if ($google !== '' && isset(self::googleFontCatalog()[$google])) {
            return 'google:' . $google;
        }

        $style = self::fontStyleKey((string) Setting::get('design_font_style', 'custom'));
        return 'style:' . (isset(self::FONTS[$style]) ? $style : 'custom');
    }

    /**
     * Нормализует единый выбор шрифта в совместимые внутренние поля.
     * @return array{font_style:string,font_google_body:string}
     */
    public static function normalizeBodyFontChoice(string $choice): array
    {
        if (str_starts_with($choice, 'google:')) {
            $slug = substr($choice, 7);
            if (isset(self::googleFontCatalog()[$slug])) {
                return ['font_style' => 'system', 'font_google_body' => $slug];
            }
        }
        if (str_starts_with($choice, 'style:')) {
            $style = substr($choice, 6);
            if (isset(self::FONTS[$style])) {
                return ['font_style' => $style, 'font_google_body' => ''];
            }
        }

        return ['font_style' => 'custom', 'font_google_body' => ''];
    }

    /** Точный базовый размер текста, 12–24px; пусто — значение пресета. */
    public static function fontSizeCustom(): string
    {
        return CssValue::pixels((string) Setting::get('design_font_size_custom', ''), 12, 24);
    }

    public static function normalizeFontSize(string $raw): string
    {
        return CssValue::pixels($raw, 12, 24);
    }

    /** Точное скругление, 0–48px; пусто — значение пресета. */
    public static function radiusCustom(): string
    {
        return CssValue::pixels((string) Setting::get('design_radius_custom', ''), 0, 48);
    }

    public static function normalizeRadius(string $raw): string
    {
        return CssValue::pixels($raw, 0, 48);
    }

    /**
     * Толщина метки заголовка секции, 1–12px. Пусто — 3px, как было записано
     * константой в правиле темы.
     */
    public static function sectionMarkerThickness(): string
    {
        return CssValue::pixels((string) Setting::get('design_section_marker_thickness', ''), 1, 12);
    }

    /**
     * Высота метки, 4–80px. Пусто — метка тянется по высоте самой строки
     * заголовка (прежнее поведение): у крупного заголовка черта длиннее, у
     * мелкого короче, и подгонять её под каждый кегль руками не нужно.
     */
    public static function sectionMarkerHeight(): string
    {
        return CssValue::pixels((string) Setting::get('design_section_marker_height', ''), 4, 80);
    }

    /** Подъём feature-card и карточек, наследующих его hover, 0–20px. */
    public static function cardHoverLift(): int
    {
        return self::normalizeCardHoverLift((string) Setting::get('design_card_hover_lift', '4'));
    }

    public static function normalizeCardHoverLift(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return 4;
        }

        return max(0, min(20, (int) $raw));
    }

    /**
     * Точные вертикальные отступы детальной страницы новости, 0–200px.
     * Пустое значение оставляет адаптивный отступ, заданный темой.
     */
    public static function newsDetailPaddingTop(): string
    {
        return self::normalizeNewsDetailSpacing(
            (string) Setting::get('design_newsdetail_padding_top', '')
        );
    }

    public static function newsDetailPaddingBottom(): string
    {
        return self::normalizeNewsDetailSpacing(
            (string) Setting::get('design_newsdetail_padding_bottom', '')
        );
    }

    public static function normalizeNewsDetailSpacing(string $raw): string
    {
        return CssValue::pixels($raw, 0, 200);
    }

    /**
     * Ручные значения внешнего вида. Хранятся отдельно от материализованных
     * рабочих ключей цветов и font_family, чтобы готовый пресет не затирал настройки
     * пользователя при последующем возврате к варианту «Свои…».
     *
     * @return array{color_primary:string,color_accent:string,font_family:string}
     */
    public static function customAppearance(): array
    {
        return [
            'color_primary' => SettingsValidator::hexColor(
                (string) Setting::get('design_custom_color_primary', Setting::get('color_primary', '#0F2B46')),
                '#173a63'
            ),
            'color_accent' => SettingsValidator::hexColor(
                (string) Setting::get('design_custom_color_accent', Setting::get('color_accent', '#009BBE')),
                '#17999b'
            ),
            'font_family' => (string) Setting::get(
                'design_custom_font_family',
                Setting::get('font_family', "'Manrope', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif")
            ),
        ];
    }

    /** @return array{bg_primary:string,bg_surface:string,text_main:string,text_muted:string,border_color:string} */
    public static function semanticColors(): array
    {
        $defaults = [
            // Страница — чуть серая, карточки — белые: карточка «поднимается»
            // над фоном без тени и рамки. Обратный порядок делал карточки
            // серыми пятнами на белом.
            //
            // Значения совпадают с токенами темы (`gov-theme.css`), и это не
            // совпадение, а условие: слой «Дизайна» печатается в `:root`
            // после темы и перекрывает её. Пока здесь стояли нейтральные
            // серые (#1a1a1a, #666666, #E6EBF0), тема со своей холодной
            // шкалой не действовала ни на одном сайте: рядом с синими
            // заголовками (--gov-navy) нейтральный серый текст читается как
            // грязный, а подписи были тусклыми — 5.74:1 против 7.58:1 у
            // холодного #475569. Меняя значение здесь, меняем и токен темы.
            'bg_primary' => '#f8fafc',
            'bg_surface' => '#ffffff',
            'text_main' => '#0f172a',
            'text_muted' => '#475569',
            'border_color' => '#e2e8f0',
        ];
        $colors = [];
        foreach ($defaults as $key => $fallback) {
            $colors[$key] = SettingsValidator::hexColor(
                (string) Setting::get('design_semantic_' . $key, $fallback),
                $fallback
            );
        }

        return $colors;
    }

    /**
     * Вертикальный ритм секций.
     *
     * Набор умолчаний выбирает «Плотность секций». До этого настройка не
     * действовала вовсе: каждый блок выводится с классом
     * `cms-block--space-<пресет>`, а тот берёт отступ из `--space-*`, минуя
     * `--section-pad`, куда плотность и печаталась. Замерено обходом страницы:
     * переключение «Компактно» → «Просторно» не меняло ни одного пикселя —
     * редактор двигал настройку, а страница оставалась прежней.
     *
     * Явные значения из полей «Отступы» по-прежнему главнее: плотность задаёт
     * умолчание, а не переписывает выбор редактора. Набор «Стандарт» совпадает
     * с прежними значениями, поэтому сайт без этой настройки не меняется.
     *
     * @return array{space_small:string,space_premium:string,space_max:string}
     */
    public static function semanticSpacings(): array
    {
        $byDensity = [
            'compact' => [
                'space_small' => 'clamp(10px, 1.8vw, 16px)',
                'space_premium' => 'clamp(18px, 2.6vw, 36px)',
                'space_max' => 'clamp(26px, 3.4vw, 50px)',
            ],
            'standard' => [
                'space_small' => 'clamp(14px, 2.5vw, 24px)',
                'space_premium' => 'clamp(28px, 4vw, 56px)',
                'space_max' => 'clamp(40px, 5vw, 76px)',
            ],
            'spacious' => [
                'space_small' => 'clamp(18px, 3.2vw, 32px)',
                'space_premium' => 'clamp(38px, 5.4vw, 76px)',
                'space_max' => 'clamp(54px, 6.8vw, 104px)',
            ],
        ];
        $density = (string) Setting::get('design_density', 'standard');
        $defaults = $byDensity[$density] ?? $byDensity['standard'];
        $spacings = [];
        foreach ($defaults as $key => $fallback) {
            $spacings[$key] = SettingsValidator::safeCssValue(
                (string) Setting::get('design_spacing_' . $key, $fallback),
                $fallback
            );
        }

        return $spacings;
    }

    /**
     * @return list<string> предупреждения для редактора (пустой список — всё принято)
     */
    public static function save(array $input): array
    {
        $warnings = [];

        // Новая форма присылает один выбор вместо двух конкурирующих полей.
        // Прямые font_style/font_google_body по-прежнему принимаются от пресетов
        // и старых форм.
        if (array_key_exists('font_body_choice', $input)) {
            $input = array_merge($input, self::normalizeBodyFontChoice((string) $input['font_body_choice']));
        }
        foreach (self::OPTIONS as $key => $opt) {
            // Частичные формы и новые версии конструктора не должны
            // сбрасывать отсутствующие параметры на defaults.
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $val = self::sanitize($key, (string) $input[$key]);
            Setting::set('design_' . $key, (string) $val);
        }
        // Фирменная эмблема: путь к SVG в медиабиблиотеке. Пустое присланное
        // поле — это очистка (редактор нажал «×»), возвращается встроенный знак.
        if (array_key_exists('emblem', $input) || !empty($_FILES['emblem_file'])) {
            $emblem = trim((string) (ImageField::resolve('emblem_file', 'emblem', (string) Setting::get('design_emblem', ''), Auth::id()) ?? ''));
            // Только свой файл: знак с чужого домена — сторонний запрос с
            // каждой страницы, и тема его всё равно не примет.
            $ok = $emblem !== '' && str_starts_with($emblem, '/') && UrlGuard::isSafeMedia($emblem);
            // Файл, который не годится трафаретом (не разобрался, без viewBox),
            // молча сохранять нельзя: на сайте он просто не появится, и
            // редактор будет считать, что эмблема установлена.
            if ($ok) {
                $verdict = Emblem::check($emblem);
                if (!$verdict['ok']) {
                    $ok = false;
                    $warnings[] = $verdict['error'];
                }
            } elseif ($emblem !== '') {
                $warnings[] = 'Эмблема принимается только файлом этого сайта: загрузите SVG в медиабиблиотеку.';
            }
            if ($ok || $emblem === '') {
                Setting::set('design_emblem', $ok ? $emblem : '');
            }
        }
        // Своя ширина контейнера — отдельное свободное поле (не из choices).
        if (array_key_exists('container_custom', $input)) {
            Setting::set('design_container_custom', self::normalizeWidth(trim((string) $input['container_custom'])));
        }
        if (array_key_exists('font_size_custom', $input)) {
            Setting::set('design_font_size_custom', self::normalizeFontSize((string) $input['font_size_custom']));
        }
        if (array_key_exists('radius_custom', $input)) {
            Setting::set('design_radius_custom', self::normalizeRadius((string) $input['radius_custom']));
        }
        if (array_key_exists('card_hover_lift', $input)) {
            Setting::set(
                'design_card_hover_lift',
                (string) self::normalizeCardHoverLift((string) $input['card_hover_lift'])
            );
        }
        if (array_key_exists('newsdetail_padding_top', $input)) {
            Setting::set(
                'design_newsdetail_padding_top',
                self::normalizeNewsDetailSpacing((string) $input['newsdetail_padding_top'])
            );
        }
        if (array_key_exists('newsdetail_padding_bottom', $input)) {
            Setting::set(
                'design_newsdetail_padding_bottom',
                self::normalizeNewsDetailSpacing((string) $input['newsdetail_padding_bottom'])
            );
        }
        if (array_key_exists('line_height_custom', $input)) {
            Setting::set('design_line_height_custom', self::normalizeLineHeight((string) $input['line_height_custom']));
        }
        if (array_key_exists('heading_line_height_custom', $input)) {
            Setting::set('design_heading_line_height_custom', self::normalizeLineHeight((string) $input['heading_line_height_custom']));
        }
        if (array_key_exists('meta_letter_spacing_custom', $input)) {
            Setting::set(
                'design_meta_letter_spacing_custom',
                self::normalizeMetaLetterSpacing((string) $input['meta_letter_spacing_custom'])
            );
        }
        if (array_key_exists('heading_font_weight', $input)) {
            $weight = (string) $input['heading_font_weight'];
            Setting::set('design_heading_font_weight', in_array($weight, ['400', '500', '600', '700', '800'], true) ? $weight : '700');
        }
        if (array_key_exists('heading_letter_spacing', $input)) {
            $spacing = (string) $input['heading_letter_spacing'];
            Setting::set('design_heading_letter_spacing', in_array($spacing, ['tight', 'normal', 'wide'], true) ? $spacing : 'normal');
        }
        if (array_key_exists('heading_line_height', $input)) {
            $lh = (string) $input['heading_line_height'];
            Setting::set('design_heading_line_height', in_array($lh, ['tight', 'normal', 'relaxed'], true) ? $lh : 'normal');
        }
        if (array_key_exists('typo_scale', $input)) {
            $scale = (string) $input['typo_scale'];
            Setting::set('design_typo_scale', isset(self::TYPO_SCALES[$scale]) ? $scale : 'classic');
        }
        foreach (array_keys(self::TYPO_SIZES) as $fsKey) {
            if (array_key_exists($fsKey, $input)) {
                Setting::set('design_' . $fsKey, self::normalizeFsSize((string) $input[$fsKey]));
            }
        }
        if (array_key_exists('menu_divider_color', $input)) {
            Setting::set('design_menu_divider_color', SettingsValidator::hexColor((string) $input['menu_divider_color'], '#ffffff'));
        }
        if (array_key_exists('menu_divider_color_use', $input)) {
            Setting::set('design_menu_divider_color_use', (string) $input['menu_divider_color_use'] === '1' ? '1' : '0');
        }
        if (array_key_exists('section_marker_thickness', $input)) {
            Setting::set(
                'design_section_marker_thickness',
                CssValue::pixels((string) $input['section_marker_thickness'], 1, 12)
            );
        }
        if (array_key_exists('section_marker_height', $input)) {
            Setting::set(
                'design_section_marker_height',
                CssValue::pixels((string) $input['section_marker_height'], 4, 80)
            );
        }
        if (array_key_exists('menu_divider_thickness', $input)) {
            Setting::set('design_menu_divider_thickness', CssValue::pixels((string) $input['menu_divider_thickness'], 0, 10));
        }
        if (array_key_exists('menu_divider_height', $input)) {
            Setting::set('design_menu_divider_height', CssValue::pixels((string) $input['menu_divider_height'], 2, 100));
        }

        // Ручные цвета и шрифт сохраняются отдельно от активного пресета.
        // При первом сохранении старые рабочие ключи цветов и font_family используются как
        // значения по умолчанию — миграция базы не требуется.
        if (array_key_exists('color_primary', $input)) {
            Setting::set('design_custom_color_primary', SettingsValidator::hexColor(
                (string) $input['color_primary'],
                self::customAppearance()['color_primary']
            ));
            Setting::set('design_palette', 'custom');
        }
        if (array_key_exists('color_accent', $input)) {
            Setting::set('design_custom_color_accent', SettingsValidator::hexColor(
                (string) $input['color_accent'],
                self::customAppearance()['color_accent']
            ));
            Setting::set('design_palette', 'custom');
        }
        $semantic = self::semanticColors();
        foreach ($semantic as $key => $current) {
            if (array_key_exists($key, $input)) {
                Setting::set(
                    'design_semantic_' . $key,
                    SettingsValidator::hexColor((string) $input[$key], $current)
                );
            }
        }
        if (array_key_exists('shadow_color', $input)) {
            Setting::set('design_shadow_color', SettingsValidator::hexColor(
                (string) $input['shadow_color'],
                self::shadowColor()
            ));
        }
        if (array_key_exists('shadow_strength', $input)) {
            $strength = (string) $input['shadow_strength'];
            Setting::set(
                'design_shadow_strength',
                is_numeric($strength) ? (string) max(0, min(300, (int) round((float) $strength))) : '100'
            );
        }
        if (array_key_exists('veil_color', $input)) {
            Setting::set('design_veil_color', SettingsValidator::hexColor(
                (string) $input['veil_color'],
                self::veilColor()
            ));
        }
        if (array_key_exists('veil_strength', $input)) {
            // Границы те же, что читает veilStrength(): значение приходит из
            // формы, а её можно подделать.
            $veilStrength = (string) $input['veil_strength'];
            Setting::set(
                'design_veil_strength',
                is_numeric($veilStrength) ? (string) max(40, min(113, (int) round((float) $veilStrength))) : '100'
            );
        }
        $spacings = self::semanticSpacings();
        foreach ($spacings as $key => $current) {
            if (array_key_exists($key, $input)) {
                Setting::set(
                    'design_spacing_' . $key,
                    SettingsValidator::safeCssValue((string) $input[$key], $current)
                );
            }
        }

        if (array_key_exists('font_family', $input)) {
            $family = mb_substr(trim((string) $input['font_family']), 0, 200);
            if ($family !== '') {
                Setting::set('design_custom_font_family', $family);
            }
        }
        if (array_key_exists('font_face_name', $input)) {
            $face = preg_replace('/[^a-zA-Z0-9 _-]/', '', trim((string) $input['font_face_name'])) ?? '';
            Setting::set('font_face_name', mb_substr($face, 0, 80));
        }
        if (array_key_exists('font_url', $input)) {
            $url = mb_substr(trim((string) $input['font_url']), 0, 500);
            Setting::set('font_url', $url === '' || UrlGuard::isSafeLink($url) ? $url : '');
        }
        if (array_key_exists('default_theme', $input)) {
            $theme = in_array($input['default_theme'], ['light', 'dark', 'auto'], true)
                ? (string) $input['default_theme']
                : 'light';
            Setting::set('default_theme', $theme);
        }

        // Запоминаем выбранные шрифты каталога до материализации. Пустое
        // значение отключает отдельный шрифт для соответствующей роли.
        foreach (['heading', 'body'] as $role) {
            $inputKey = 'font_google_' . $role;
            if (!array_key_exists($inputKey, $input)) {
                continue;
            }
            $slug = (string) $input[$inputKey];
            Setting::set(
                'design_font_google_' . $role,
                $slug !== '' && isset(self::googleFontCatalog()[$slug]) ? $slug : ''
            );
        }

        // Рукописное семейство — своя роль со своим каталогом: им набирается
        // выделенное слово в заголовке и подпись, а не текст сайта.
        if (array_key_exists('font_script', $input)) {
            $scriptSlug = (string) $input['font_script'];
            Setting::set('design_font_script', isset(self::SCRIPT_FONTS[$scriptSlug]) ? $scriptSlug : '');
        }

        // Материализация палитры/шрифта в реальные настройки сайта
        // (color_primary/color_accent/font_family, их читает фронтенд).
        $custom = self::customAppearance();
        $palette = (string) Setting::get('design_palette', 'custom');
        if ($palette !== 'custom' && isset(self::PALETTES[$palette])) {
            Setting::set('color_primary', self::PALETTES[$palette][1]);
            Setting::set('color_accent', self::PALETTES[$palette][2]);
        } else {
            Setting::set('color_primary', $custom['color_primary']);
            Setting::set('color_accent', $custom['color_accent']);
        }
        $font = self::fontStyleKey((string) Setting::get('design_font_style', 'custom'));
        if ($font !== 'custom' && isset(self::FONTS[$font])) {
            Setting::set('font_family', self::FONTS[$font][1]);
        } else {
            Setting::set('font_family', $custom['font_family']);
        }

        // Шрифты локального каталога имеют явный приоритет над базовой ролью.
        // Отключение шрифта текста возвращает выбранный выше пресет/свой стек.
        // Каталог — весь, а не отобранные двадцать: форма предлагает и
        // остальные семейства индекса, и проверка по короткому списку молча
        // отбрасывала выбор — файлы скачивались, а сайт оставался на прежнем.
        $catalog = self::googleFontCatalog();
        $bodySlug = (string) Setting::get('design_font_google_body', '');
        if ($bodySlug !== '' && isset($catalog[$bodySlug])) {
            Setting::set('font_family', $catalog[$bodySlug][1]);
        }

        $headingSlug = (string) Setting::get('design_font_google_heading', '');
        $bodyFont = (string) Setting::get('font_family', SiteThemeCss::DEFAULT_BODY_FONT);
        Setting::set(
            'font_heading',
            $headingSlug !== '' && isset($catalog[$headingSlug])
                ? $catalog[$headingSlug][1]
                : $bodyFont
        );

        return $warnings;
    }

    /** Применяет готовую конфигурацию (встроенную или пользовательскую «user:slug»). */
    public static function applyPreset(string $preset): bool
    {
        if (str_starts_with($preset, 'user:')) {
            return self::applyUserPreset(substr($preset, 5));
        }
        if (!isset(self::PRESETS[$preset])) {
            return false;
        }
        // Встроенный пресет должен полностью определять типографику, поэтому
        // отключаем ранее выбранные шрифты каталога, которые иначе имели бы
        // приоритет над шрифтом пресета.
        self::save(array_merge(self::PRESETS[$preset]['values'], [
            'font_google_heading' => '',
            'font_google_body' => '',
            'font_size_custom' => '',
            'radius_custom' => '',
            'newsdetail_padding_top' => '',
            'newsdetail_padding_bottom' => '',
            'line_height_custom' => '',
            'meta_letter_spacing_custom' => '',
        ], array_fill_keys(array_keys(self::TYPO_SIZES), '')));
        Setting::set('design_preset', $preset);

        return true;
    }

    /**
     * CSS-переменные для фронтенда на основе текущих значений.
     * @param array<string,string> $v
     */
    /**
     * Действующая максимальная ширина контента: пресет или своя точная
     * ширина. 'none' — контент на всю ширину экрана.
     *
     * Вынесено из cssVariables(), потому что это значение нужно ещё и
     * подсказкам в админке: раньше форма шапки называла ширину контейнера
     * жёстко зашитым «1280px», хотя настоящее значение задаётся здесь и по
     * умолчанию другое.
     *
     * @param array<string, mixed>|null $v Настройки; null — взять текущие.
     */
    public static function containerWidth(?array $v = null): string
    {
        $v = $v ?? self::current();
        $preset = ['narrow' => '1200px', 'standard' => '1440px', 'wide' => '1500px', 'ultra' => '1700px', 'full' => 'none'];
        $container = $preset[$v['container'] ?? 'standard'] ?? '1440px';
        // Своя точная ширина имеет приоритет над пресетом (число трактуем как px).
        $custom = self::containerCustom();

        return $custom !== '' ? $custom : $container;
    }

    public static function cssVariables(array $v): string
    {
        $container = self::containerWidth($v);
        $radius = ['none' => '0px', 'small' => '8px', 'medium' => '14px', 'large' => '22px'][$v['radius'] ?? 'medium'] ?? '14px';
        $customRadius = self::radiusCustom();
        if ($customRadius !== '') {
            $radius = $customRadius;
        }
        $gap = ['xs' => '8px', 'sm' => '16px', 'md' => '24px', 'lg' => '32px'][$v['card_gap'] ?? 'md'] ?? '24px';
        $section = ['compact' => '28px', 'standard' => '46px', 'spacious' => '72px'][$v['density'] ?? 'standard'] ?? '46px';
        $btn = ['square' => '0px', 'rounded' => '10px', 'pill' => '999px'][$v['button'] ?? 'rounded'] ?? '10px';
        if ($customRadius !== '' && ($v['button'] ?? 'rounded') === 'rounded') {
            $btn = $customRadius;
        }
        $fontSize = ['sm' => '15px', 'md' => '16px', 'lg' => '17px', 'xl' => '18px'][$v['font_size'] ?? 'md'] ?? '16px';
        $customFontSize = self::fontSizeCustom();
        if ($customFontSize !== '') {
            $fontSize = $customFontSize;
        }
        $lineHeight = ['tight' => '1.45', 'normal' => '1.6', 'relaxed' => '1.8'][$v['line_height'] ?? 'normal'] ?? '1.6';
        $customLineHeight = self::lineHeightCustom();
        if ($customLineHeight !== '') {
            $lineHeight = $customLineHeight;
        }
        $shadow = self::cardShadow((string) ($v['card_style'] ?? 'soft'));
        // Подложка текста крупных карточек новостей: цвет и плотность —
        // настройки, цвет заголовка поверх неё считается по контрасту.
        $veil = self::newsVeil();

        $divColor = (string) Setting::get('design_menu_divider_color_use', '0') === '1'
            ? (string) Setting::get('design_menu_divider_color', '')
            : '';
        if ($divColor === '') {
            $divColor = 'color-mix(in srgb, currentColor 35%, transparent)';
        }

        $divThickness = (string) Setting::get('design_menu_divider_thickness', '');
        if ($divThickness === '') {
            $divThickness = '1px';
        }

        $divHeight = (string) Setting::get('design_menu_divider_height', '');
        if ($divHeight === '') {
            $divHeight = '18px';
        }

        // Метка заголовка секции. Пустая высота оставляет прежнее поведение —
        // черта по высоте строки; свой размер задаётся числом, потому что нужный
        // зависит от кегля заголовка и пресетом его не угадать.
        $markerWidth = self::sectionMarkerThickness();
        $markerWidth = $markerWidth === '' ? '3px' : $markerWidth;
        $markerHeight = self::sectionMarkerHeight();
        $markerHeight = $markerHeight === '' ? 'calc(100% - .32em)' : $markerHeight;
        // Знак-эмблема квадратный, поэтому его сторону задаёт высота метки, а
        // не толщина линии: у линии толщина 3px, и знак был бы полоской.
        $markerEmblem = self::sectionMarkerHeight();
        $markerEmblem = $markerEmblem === '' ? '.9em' : $markerEmblem;

        $headingLineHeight = ['tight' => '1.15', 'normal' => '1.25', 'relaxed' => '1.35'][$v['heading_line_height'] ?? 'normal'] ?? '1.25';
        $customHeadingLineHeight = self::headingLineHeightCustom();
        if ($customHeadingLineHeight !== '') {
            $headingLineHeight = $customHeadingLineHeight;
        }
        $headingFontWeight = isset($v['heading_font_weight']) && in_array((string) $v['heading_font_weight'], ['400', '500', '600', '700', '800'], true) ? (string) $v['heading_font_weight'] : '700';
        $headingLetterSpacing = ['tight' => '-0.03em', 'normal' => '-0.02em', 'wide' => '0em'][$v['heading_letter_spacing'] ?? 'normal'] ?? '-0.02em';

        // Точечные размеры по элементам дописываются после :root; итоговую
        // строку SiteThemeCss публикует во внешнем сгенерированном файле.
        return self::typographyCss() . sprintf(
            ':root{--container-max:%s;--radius:%s;--radius-sm:calc(%s * .6);--card-gap:%s;--section-pad:%s;--btn-radius:%s;--base-font-size:%s;--base-line-height:%s;--heading-line-height:%s;--heading-font-weight:%s;--heading-letter-spacing:%s;--card-shadow:%s;--menu-divider-color:%s;--menu-divider-width:%s;--menu-divider-height:%s;--section-marker-width:%s;--section-marker-height:%s;--section-marker-emblem:%s;--newshero-veil-rgb:%s;--newshero-veil-alpha:%s;--newshero-fg:%s;}',
            $container,
            $radius,
            $radius,
            $gap,
            $section,
            $btn,
            $fontSize,
            $lineHeight,
            $headingLineHeight,
            $headingFontWeight,
            $headingLetterSpacing,
            $shadow,
            $divColor,
            $divThickness,
            $divHeight,
            $markerWidth,
            $markerHeight,
            $markerEmblem,
            $veil['rgb'],
            rtrim(rtrim(number_format($veil['alpha'], 3, '.', ''), '0'), '.'),
            $veil['fg']
        );
    }

    /**
     * Классы глобального дизайна для <body>.
     *
     * Шапка, поиск, мобильное меню и подвал имеют собственные конструкторы и
     * больше не дублируются здесь: два источника истины давали конфликтующие
     * sticky/search/footer режимы. Класс design-mmenu-burger остаётся
     * постоянным совместимым переключателем адаптивного drawer-меню.
     *
     * @param array<string,string> $v
     */
    /**
     * Атрибут уровня анимаций для <html>. Атрибут, а не класс на <body>, по
     * двум причинам: скрипты (theme-init.js) спрашивают его до того, как
     * <body> разобран, а правило «уровня» обязано весить больше компонентных
     * переходов с флагом приоритета — режимный класс на <body> по правилам
     * темы обёрнут в :where() и не весит ничего. «Полные» не печатаются.
     */
    public static function motionAttribute(array $v): string
    {
        $level = (string) ($v['motion'] ?? 'full');

        return in_array($level, ['calm', 'off'], true) ? 'data-motion="' . $level . '"' : '';
    }

    public static function bodyClasses(array $v): string
    {
        return trim(sprintf(
            'design-catalog-%s design-sidebar-%s design-cards-%s design-detail-%s',
            preg_replace('/[^a-z_]/', '', (string) ($v['catalog_layout'] ?? 'grid')),
            preg_replace('/[^a-z]/', '', (string) ($v['sidebar_position'] ?? 'floating')),
            preg_replace('/[^a-z]/', '', (string) ($v['card_style'] ?? 'soft')),
            preg_replace('/[^a-z]/', '', (string) ($v['detail_layout'] ?? 'plain'))
        )) . ' design-mmenu-burger'
          . ' design-surface-' . (isset(self::OPTIONS['block_surface']['choices'][(string) ($v['block_surface'] ?? '')])
              ? (string) $v['block_surface']
              : 'all')
          . ' design-mark-' . (isset(self::OPTIONS['title_mark']['choices'][(string) ($v['title_mark'] ?? '')])
              ? (string) $v['title_mark']
              : 'accent')
          . (in_array($v['title_reveal'] ?? 'off', ['fade', 'wipe'], true)
              ? ' design-title-' . $v['title_reveal']
              : '')
          . ' design-secmark-' . (isset(self::OPTIONS['section_marker']['choices'][(string) ($v['section_marker'] ?? '')])
              ? (string) $v['section_marker']
              : 'line')
          . (($v['scroll_top'] ?? 'on') === 'on' ? ' design-scrolltop' : '')
          . (($v['link_style'] ?? 'arrow') === 'draw' ? ' design-links-draw' : '')
          . (in_array($v['button_fill'] ?? 'off', ['accent', 'primary'], true)
              ? ' design-btnfill design-btnfill-' . $v['button_fill']
              : '');
    }

    // --- Фасад: вынесенные части «Дизайна» (App\Core\Design\*). Вызывающие и
    // тесты обращаются к DesignSettings как раньше; новое пишется сразу туда.

    // FontCatalog
    public const GOOGLE_FONTS = FontCatalog::GOOGLE_FONTS;
    public const SCRIPT_FONTS = FontCatalog::SCRIPT_FONTS;
    public const FONTS = FontCatalog::FONTS;

    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function googleFontsExtra(): array
    {
        return FontCatalog::googleFontsExtra();
    }

    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function googleFontCatalog(): array
    {
        return FontCatalog::googleFontCatalog();
    }

    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function fontCatalog(): array
    {
        return FontCatalog::fontCatalog();
    }

    public static function scriptFontStack(): string
    {
        return FontCatalog::scriptFontStack();
    }

    public static function fontStyleKey(string $style): string
    {
        return FontCatalog::fontStyleKey($style);
    }

    // Typography
    public const TYPO_SIZES = Typography::TYPO_SIZES;
    public const TYPO_SCALES = Typography::TYPO_SCALES;

    public static function typoScale(): string
    {
        return Typography::typoScale();
    }

    /** @return array<string,string> ключ fs_* => '32px' */
    public static function scaleSizes(): array
    {
        return Typography::scaleSizes();
    }

    /** @return array<string,string> ключ => '17px' или '' (не задан) */
    public static function typographySizes(): array
    {
        return Typography::typographySizes();
    }

    /** @return array<string,string> */
    public static function typographyOverrides(): array
    {
        return Typography::typographyOverrides();
    }

    public static function normalizeFsSize(string $raw): string
    {
        return Typography::normalizeFsSize($raw);
    }

    public static function typographyCss(): string
    {
        return Typography::typographyCss();
    }

    public static function lineHeightCustom(): string
    {
        return Typography::lineHeightCustom();
    }

    public static function headingLineHeightCustom(): string
    {
        return Typography::headingLineHeightCustom();
    }

    public static function normalizeLineHeight(string $raw): string
    {
        return Typography::normalizeLineHeight($raw);
    }

    public static function metaLetterSpacingCustom(): string
    {
        return Typography::metaLetterSpacingCustom();
    }

    public static function normalizeMetaLetterSpacing(string $raw): string
    {
        return Typography::normalizeMetaLetterSpacing($raw);
    }

    // Surfaces
    public const SHADOW_COLOR_DEFAULT = Surfaces::SHADOW_COLOR_DEFAULT;
    public const VEIL_COLOR_DEFAULT = Surfaces::VEIL_COLOR_DEFAULT;
    public const VEIL_ALPHA_BASE = Surfaces::VEIL_ALPHA_BASE;
    public const VEIL_FG_LIGHT = Surfaces::VEIL_FG_LIGHT;
    public const VEIL_FG_DARK = Surfaces::VEIL_FG_DARK;

    public static function shadowColor(): string
    {
        return Surfaces::shadowColor();
    }

    public static function shadowStrength(): int
    {
        return Surfaces::shadowStrength();
    }

    public static function veilColor(): string
    {
        return Surfaces::veilColor();
    }

    public static function veilStrength(): int
    {
        return Surfaces::veilStrength();
    }

    /** @return array{rgb: string, alpha: float, fg: string, ratio: float, raised: bool} */
    public static function newsVeil(): array
    {
        return Surfaces::newsVeil();
    }

    public static function cardShadow(string $style): string
    {
        return Surfaces::cardShadow($style);
    }

    // UserPresets
    public const DESIGN_BACKUP_PREFIX = UserPresets::DESIGN_BACKUP_PREFIX;

    /** @return array<string,array{label:string,values:array<string,string>,colors?:array<int,string>,appearance?:array<string,string>}> */
    public static function userPresets(): array
    {
        return UserPresets::userPresets();
    }

    public static function saveUserPreset(string $name): ?string
    {
        return UserPresets::saveUserPreset($name);
    }

    public static function deleteUserPreset(string $slug): bool
    {
        return UserPresets::deleteUserPreset($slug);
    }

    public static function applyUserPreset(string $slug): bool
    {
        return UserPresets::applyUserPreset($slug);
    }

    /** @return string|null название копии либо null, если сохранить не вышло */
    public static function autoBackupPreset(): ?string
    {
        return UserPresets::autoBackupPreset();
    }
}
