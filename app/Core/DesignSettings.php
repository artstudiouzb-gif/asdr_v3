<?php

declare(strict_types=1);

namespace App\Core;

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

    /**
     * Локальный каталог шрифтов из Google Fonts с полной поддержкой узбекской
     * кириллицы (cyrillic-ext + cyrillic). Третий элемент используется только
     * локальным установщиком при сохранении, браузер к Google Fonts не обращается.
     * slug => [подпись, CSS-стек, параметр family для css2 API].
     */
    public const GOOGLE_FONTS = [
        'pt-serif' => ['PT Serif (антиква)', "'PT Serif', 'PT Serif Fallback', Georgia, serif", 'PT+Serif:wght@400;700'],
        'lora' => ['Lora (антиква)', "'Lora', Georgia, serif", 'Lora:wght@400;600;700'],
        'merriweather' => ['Merriweather (антиква)', "'Merriweather', Georgia, serif", 'Merriweather:wght@400;700'],
        'noto-serif' => ['Noto Serif (антиква)', "'Noto Serif', 'Noto Serif Fallback', Georgia, serif", 'Noto+Serif:wght@400;600;700'],
        'ibm-plex-serif' => ['IBM Plex Serif (антиква)', "'IBM Plex Serif', Georgia, serif", 'IBM+Plex+Serif:wght@400;600;700'],
        'cormorant' => ['Cormorant Garamond (антиква)', "'Cormorant Garamond', Georgia, serif", 'Cormorant+Garamond:wght@500;600;700'],
        'pt-sans' => ['PT Sans', "'PT Sans', 'PT Sans Fallback', system-ui, sans-serif", 'PT+Sans:wght@400;700'],
        'inter' => ['Inter', "'Inter', 'Inter Fallback', system-ui, sans-serif", 'Inter:wght@400;600;700'],
        'inter-tight' => ['Inter Tight', "'Inter Tight', 'Inter Tight Fallback', system-ui, sans-serif", 'Inter+Tight:wght@400;500;600;700'],
        'montserrat' => ['Montserrat', "'Montserrat', 'Montserrat Fallback', system-ui, sans-serif", 'Montserrat:wght@400;600;700'],
        'roboto' => ['Roboto', "'Roboto', system-ui, sans-serif", 'Roboto:wght@400;500;700'],
        'open-sans' => ['Open Sans', "'Open Sans', system-ui, sans-serif", 'Open+Sans:wght@400;600;700'],
        'noto-sans' => ['Noto Sans', "'Noto Sans', 'Noto Sans Fallback', system-ui, sans-serif", 'Noto+Sans:wght@400;600;700'],
        'source-sans' => ['Source Sans 3', "'Source Sans 3', system-ui, sans-serif", 'Source+Sans+3:wght@400;600;700'],
        'ibm-plex-sans' => ['IBM Plex Sans', "'IBM Plex Sans', system-ui, sans-serif", 'IBM+Plex+Sans:wght@400;600;700'],
        'manrope' => ['Manrope', "'Manrope', 'Manrope Fallback', system-ui, sans-serif", 'Manrope:wght@400;600;700'],
        'rubik' => ['Rubik', "'Rubik', system-ui, sans-serif", 'Rubik:wght@400;500;700'],
        'raleway' => ['Raleway', "'Raleway', system-ui, sans-serif", 'Raleway:wght@400;600;700'],
        'exo2' => ['Exo 2', "'Exo 2', system-ui, sans-serif", 'Exo+2:wght@400;600;700'],
        'golos' => ['Golos Text', "'Golos Text', system-ui, sans-serif", 'Golos+Text:wght@400;600;700'],
    ];

    /**
     * Рукописные семейства — отдельный каталог, а не строки в GOOGLE_FONTS.
     *
     * Тот каталог предлагается для текста и заголовков всего сайта, и
     * рукописное семейство там было бы ловушкой: выбрал «красиво» — получил
     * нечитаемый сайт. Здесь у шрифта одна роль: выделенное слово в заголовке
     * (`*слово*`) и подпись под текстом. Обе — короткие куски в несколько слов.
     *
     * В списке только семейства с подмножествами cyrillic-ext + cyrillic +
     * latin: без cyrillic-ext не рисуются узбекские Ғғ Ққ Ҳҳ, а установка
     * такого шрифта отвалится проверкой покрытия (Marck Script поэтому и не
     * попал в список, хотя кириллица у него есть).
     */
    public const SCRIPT_FONTS = [
        'caveat' => ['Caveat (от руки, разборчивый)', "'Caveat', 'Segoe Script', cursive", 'Caveat:wght@400;600;700'],
        'bad-script' => ['Bad Script (почерк ручкой)', "'Bad Script', 'Segoe Script', cursive", 'Bad+Script:wght@400'],
        'great-vibes' => ['Great Vibes (каллиграфия)', "'Great Vibes', cursive", 'Great+Vibes:wght@400'],
        'pacifico' => ['Pacifico (вывеска)', "'Pacifico', cursive", 'Pacifico:wght@400'],
    ];

    /**
     * Оба каталога одним списком: тот, кто скачивает файлы и собирает
     * @font-face, различий между ролями не знает — ему нужен адрес.
     *
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function fontCatalog(): array
    {
        return self::GOOGLE_FONTS + self::SCRIPT_FONTS;
    }

    /** Стек выбранного рукописного шрифта или '' — если он не выбран. */
    public static function scriptFontStack(): string
    {
        $slug = (string) Setting::get('design_font_script', '');

        return isset(self::SCRIPT_FONTS[$slug]) ? self::SCRIPT_FONTS[$slug][1] : '';
    }

    /**
     * Шрифтовые пресеты: значение опции font_style => [подпись, CSS-стек].
     *
     * В админке этот список подписан «Локальные — без внешних запросов»,
     * поэтому называть здесь можно только семейства из поставки (Noto Sans,
     * Noto Serif) и системные стеки. Пресет с чужим семейством выглядел бы
     * рабочим, а рисовался бы Arial: файла нет, скачать его через font_style
     * нечем — слуга каталога у пресета не бывает.
     */
    public const FONTS = [
        'noto' => ['Noto Sans', "'Noto Sans', 'Noto Sans Fallback', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"],
        'system' => ['Системный', "system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif"],
        'serif' => ['С засечками', "Georgia, 'Times New Roman', serif"],
        'custom' => ['Свой шрифт', ''],
    ];

    /**
     * Прежние значения font_style, оставшиеся в БД от набора шрифтов до
     * перехода на Noto. Читаются как ближайший живой пресет: без этого
     * сохранённый «inter» стал бы неизвестным ключом и молча превращался в
     * «свой шрифт» с пустым стеком.
     */
    private const LEGACY_FONT_STYLES = ['pt' => 'noto', 'inter' => 'noto'];

    /** Ключ пресета шрифта с учётом прежних значений. */
    public static function fontStyleKey(string $style): string
    {
        return self::LEGACY_FONT_STYLES[$style] ?? $style;
    }

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
            'values' => ['container' => 'standard', 'radius' => 'small', 'card_gap' => 'sm', 'density' => 'standard', 'font_size' => 'md', 'line_height' => 'normal', 'heading_line_height' => 'normal', 'heading_font_weight' => '700', 'heading_letter_spacing' => 'normal', 'button' => 'rounded', 'card_style' => 'soft', 'sidebar_position' => 'floating', 'catalog_layout' => 'cards_lg', 'detail_layout' => 'plain', 'title_mark' => 'accent', 'title_reveal' => 'off', 'section_marker' => 'line', 'scroll_top' => 'on', 'palette' => 'gov_blue', 'font_style' => 'system'],
        ],
        'modern' => [
            'label' => 'Современный',
            'desc' => 'Крупные скругления, воздух, акцентная шапка.',
            'values' => ['container' => 'wide', 'radius' => 'large', 'card_gap' => 'md', 'density' => 'spacious', 'font_size' => 'lg', 'line_height' => 'relaxed', 'heading_line_height' => 'tight', 'heading_font_weight' => '800', 'heading_letter_spacing' => 'tight', 'button' => 'pill', 'card_style' => 'elevated', 'sidebar_position' => 'floating', 'catalog_layout' => 'cards_lg', 'detail_layout' => 'sidebar', 'title_mark' => 'accent', 'title_reveal' => 'fade', 'section_marker' => 'line', 'scroll_top' => 'on', 'palette' => 'violet', 'font_style' => 'noto'],
        ],
        'minimal' => [
            'label' => 'Минимал',
            'desc' => 'Прямые углы, максимум воздуха, список в каталоге.',
            'values' => ['container' => 'narrow', 'radius' => 'none', 'card_gap' => 'md', 'density' => 'spacious', 'font_size' => 'md', 'line_height' => 'normal', 'heading_line_height' => 'normal', 'heading_font_weight' => '700', 'heading_letter_spacing' => 'normal', 'button' => 'square', 'card_style' => 'flat', 'sidebar_position' => 'fixed', 'catalog_layout' => 'list', 'detail_layout' => 'plain', 'title_mark' => 'accent', 'title_reveal' => 'off', 'section_marker' => 'off', 'scroll_top' => 'on', 'palette' => 'graphite', 'font_style' => 'serif'],
        ],
        'compact' => [
            'label' => 'Компактный',
            'desc' => 'Плотная сетка, маленькие карточки — много данных.',
            'values' => ['container' => 'standard', 'radius' => 'small', 'card_gap' => 'xs', 'density' => 'compact', 'font_size' => 'sm', 'line_height' => 'tight', 'heading_line_height' => 'tight', 'heading_font_weight' => '700', 'heading_letter_spacing' => 'tight', 'button' => 'rounded', 'card_style' => 'soft', 'sidebar_position' => 'fixed', 'catalog_layout' => 'cards_sm', 'detail_layout' => 'sidebar', 'title_mark' => 'accent', 'title_reveal' => 'off', 'section_marker' => 'line', 'scroll_top' => 'on', 'palette' => 'classic_red', 'font_style' => 'system'],
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
        if ($google !== '' && isset(self::GOOGLE_FONTS[$google])) {
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
            if (isset(self::GOOGLE_FONTS[$slug])) {
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
        return self::normalizePixelValue((string) Setting::get('design_font_size_custom', ''), 12, 24);
    }

    public static function normalizeFontSize(string $raw): string
    {
        return self::normalizePixelValue($raw, 12, 24);
    }

    /** Точное скругление, 0–48px; пусто — значение пресета. */
    /**
     * Цвет тени карточек. Прежде тень была зашита в код тремя строками
     * `rgba(16,24,40,…)`: «Стиль карточек» выбирал форму, а цвет и силу
     * поменять было нечем — на тёплой или тёмной палитре холодная серо-синяя
     * тень читается грязным пятном.
     */
    public static function shadowColor(): string
    {
        return SettingsValidator::hexColor(
            (string) Setting::get('design_shadow_color', self::SHADOW_COLOR_DEFAULT),
            self::SHADOW_COLOR_DEFAULT
        );
    }

    /**
     * Сила тени в процентах от прежней: 100 — как было, 0 — тени нет вовсе.
     * Верхняя граница 300 % — дальше тень перестаёт быть тенью и становится
     * заливкой под карточкой.
     */
    public static function shadowStrength(): int
    {
        $raw = trim((string) Setting::get('design_shadow_strength', ''));
        if ($raw === '' || !is_numeric($raw)) {
            return 100;
        }

        return max(0, min(300, (int) round((float) $raw)));
    }

    /**
     * Цвет подложки текста на крупных карточках новостей (обложка и широкая).
     *
     * Прежде он был зашит литералом `rgba(6,14,28,…)` в четырёх местах темы:
     * на тёплой или светлой палитре холодная почти-чёрная вуаль читается
     * чужой, а поменять её было нечем — тот же случай, что был с тенью
     * карточек.
     */
    public static function veilColor(): string
    {
        return SettingsValidator::hexColor(
            (string) Setting::get('design_veil_color', self::VEIL_COLOR_DEFAULT),
            self::VEIL_COLOR_DEFAULT
        );
    }

    /**
     * Плотность подложки в процентах от прежней: 100 — как было (.88).
     *
     * Нижняя граница 40 %, а не 0: подложка существует затем, чтобы белый
     * заголовок читался на **любой** фотографии, и прозрачная вуаль вернула бы
     * читаемость в зависимость от кадра. Верхняя — 113 %, дальше плотность
     * упирается в единицу и настройка перестаёт что-либо менять.
     */
    public static function veilStrength(): int
    {
        $raw = trim((string) Setting::get('design_veil_strength', ''));
        if ($raw === '' || !is_numeric($raw)) {
            return 100;
        }

        return max(40, min(113, (int) round((float) $raw)));
    }

    /**
     * Подложка текста крупной карточки: цвет, плотность и цвет самого текста.
     *
     * Цвет текста не задаётся редактором, а **считается по контрасту**: светлая
     * вуаль требует тёмного заголовка, тёмная — светлого, и белый литерал на
     * жёлтой вуали дал бы нечитаемую строку, о которой никто бы не узнал.
     *
     * Контраст проверяется на обоих краях: подложка лежит на фотографии, а та
     * бывает и белой, и чёрной, поэтому годной считается плотность, при которой
     * заголовок проходит 4.5:1 в **худшем** из двух случаев. Если выбранной
     * плотности не хватает, она поднимается до первой достаточной — настройка
     * не должна позволять молча сломать читаемость. Поднятие возвращается
     * отдельным признаком, чтобы форма могла о нём сказать.
     *
     * @return array{rgb: string, alpha: float, fg: string, ratio: float, raised: bool}
     */
    public static function newsVeil(): array
    {
        $veil = AccentContrast::toRgb(self::veilColor());
        $wanted = min(1.0, self::VEIL_ALPHA_BASE * self::veilStrength() / 100);

        $best = static function (float $alpha) use ($veil): array {
            $pick = ['fg' => '#ffffff', 'ratio' => 0.0];
            foreach ([self::VEIL_FG_LIGHT, self::VEIL_FG_DARK] as $fg) {
                // Худший из краёв: подложка на белом кадре и на чёрном.
                $worst = min(
                    AccentContrast::ratio($fg, self::blend($veil, [255, 255, 255], $alpha)),
                    AccentContrast::ratio($fg, self::blend($veil, [0, 0, 0], $alpha))
                );
                if ($worst > $pick['ratio']) {
                    $pick = ['fg' => $fg, 'ratio' => $worst];
                }
            }

            return $pick;
        };

        $alpha = $wanted;
        $pick = $best($alpha);
        while ($pick['ratio'] < AccentContrast::AA_NORMAL && $alpha < 1.0) {
            $alpha = min(1.0, round($alpha + 0.01, 2));
            $pick = $best($alpha);
        }

        return [
            'rgb' => implode(', ', $veil),
            'alpha' => round($alpha, 3),
            'fg' => $pick['fg'],
            'ratio' => round($pick['ratio'], 2),
            'raised' => $alpha > $wanted + 0.0001,
        ];
    }

    /**
     * Цвет вуали плотности $alpha, положенной на кадр цвета $photo.
     *
     * Смешение считается в sRGB — там же, где его считает браузер, — иначе
     * проверка контраста отвечала бы не про то, что видно на экране.
     *
     * @param array{0:int,1:int,2:int} $veil
     * @param array{0:int,1:int,2:int} $photo
     */
    private static function blend(array $veil, array $photo, float $alpha): string
    {
        $mix = [];
        foreach ([0, 1, 2] as $i) {
            $mix[$i] = (int) round($alpha * $veil[$i] + (1 - $alpha) * $photo[$i]);
        }

        return AccentContrast::toHex($mix);
    }

    /** Тень карточек: форма из «Стиля карточек», цвет и сила — из настроек. */
    public static function cardShadow(string $style): string
    {
        if ($style === 'flat') {
            return 'none';
        }
        $strength = self::shadowStrength() / 100;
        if ($strength <= 0) {
            return 'none';
        }
        $color = static function (float $alpha) use ($strength): string {
            $hex = ltrim(self::shadowColor(), '#');
            $alpha = min(1.0, round($alpha * $strength, 3));

            return sprintf(
                'rgba(%d,%d,%d,%s)',
                (int) hexdec(substr($hex, 0, 2)),
                (int) hexdec(substr($hex, 2, 2)),
                (int) hexdec(substr($hex, 4, 2)),
                rtrim(rtrim(number_format($alpha, 3, '.', ''), '0'), '.') ?: '0'
            );
        };

        return $style === 'elevated'
            ? '0 10px 30px ' . $color(0.12)
            : '0 1px 3px ' . $color(0.06) . ', 0 6px 18px ' . $color(0.05);
    }

    public static function radiusCustom(): string
    {
        return self::normalizePixelValue((string) Setting::get('design_radius_custom', ''), 0, 48);
    }

    public static function normalizeRadius(string $raw): string
    {
        return self::normalizePixelValue($raw, 0, 48);
    }

    /**
     * Толщина метки заголовка секции, 1–12px. Пусто — 3px, как было записано
     * константой в правиле темы.
     */
    public static function sectionMarkerThickness(): string
    {
        return self::normalizePixelValue((string) Setting::get('design_section_marker_thickness', ''), 1, 12);
    }

    /**
     * Высота метки, 4–80px. Пусто — метка тянется по высоте самой строки
     * заголовка (прежнее поведение): у крупного заголовка черта длиннее, у
     * мелкого короче, и подгонять её под каждый кегль руками не нужно.
     */
    public static function sectionMarkerHeight(): string
    {
        return self::normalizePixelValue((string) Setting::get('design_section_marker_height', ''), 4, 80);
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
        return self::normalizePixelValue($raw, 0, 200);
    }

    /**
     * Размеры шрифта по элементам: ключ формы fs_* => [подпись, CSS-селектор,
     * placeholder-значение темы]. Пустое значение — размер темы не трогаем.
     * Правила выводятся с !important, чтобы предсказуемо перекрывать
     * компонентные clamp()-размеры тем (панель a11y всё равно сильнее).
     */
    /** Прежний цвет зашитой тени: холодный сине-серый. */
    public const SHADOW_COLOR_DEFAULT = '#101828';

    /** Прежний цвет подложки текста крупных карточек: rgb(6, 14, 28). */
    public const VEIL_COLOR_DEFAULT = '#060e1c';

    /** Плотность подложки при силе 100 % — ровно та, что была зашита в теме. */
    public const VEIL_ALPHA_BASE = 0.88;

    /** Кандидаты в цвет заголовка поверх подложки. */
    public const VEIL_FG_LIGHT = '#ffffff';
    public const VEIL_FG_DARK = '#0b1a30';

    public const TYPO_SIZES = [
        'fs_h1' => ['Заголовок H1', '.block-hero__title, .content-pagehead__title, .profile__name, .listing__title, .projdetail__title, .catdetail__title, .translation-notice__title, .newsdetail__title, .newsdetail-phero__title, .reader-mode__headline', '42'],
        'fs_h2' => ['Заголовок H2', '.section-title, .block-title, .block-text__title, .bio__title, .content-list__head h1, .block-news__title, .block-categories__title, .block-contact-cards__title, .block-counters__title, .block-projects__title, .block-team__title, .block-faq__title, .block-testimonials__title, .block-advantages__title, .block-banner__title, .block-featband__title, .block-map__title, .block-partners__title, .textimage__title, .subscribe-block__title, .section-head__title, .newslist-lead__title, .catdetail__subtitle, .block-timeline__title', '32'],
        'fs_h3' => ['Заголовок H3', '.newsfeat-lead__title, .orgstruct__head-name, .timeline-item__year, .timeline-cta__title, .ctaband__title, .profile__position, .featband__name, .bio-career__title, .bio-quote__mark, .widget__title, .bio-extra__title, .block-team__group-title, .newsdetail-card__title, .newsdetail-timeline__title, .newsdetail-subscribe__title', '24'],
        'fs_h4' => ['Заголовок H4', '.block-team__unit-title, .newsdetail-timeline__heading', '20'],
        'fs_h5' => ['Заголовок H5', '', '18'],
        'fs_h6' => ['Заголовок H6', '', '16'],
        'fs_lead' => ['Вводный и крупный текст', '.content-pagehead__lead, .content-list__lead, .listing__lead, .block-hero__lead, .block-hero__subtitle, .block-banner__text, .newsdetail__lead, .newsdetail-phero__lead, .newslist-lead__excerpt, .newsfeat-lead__excerpt, .profile__text, .bio-quote__text, .rich-content--lead', '17'],
        'fs_card_title' => ['Заголовки карточек и этапов', '.card__title, .content-card__title, .feature-card__title, .contact-card__title, .stage__title, .stage-item__title, .person-card__name, .doc-card__title, .repo-card__title, .project-card__title, .album-card__title, .imgcard__title, .catcard__title, .catdetail__card-title, .faq-item__q, .newsdetail-doc__title, .block-map__card-title, .gcal-list__title, .bio-edu__degree', '16'],
        // Карточка новости живёт своей жизнью: её заголовок стоит в ленте из
        // четырнадцати карточек и в подборках на главной, где длина строки
        // другая, чем у карточки документа или этапа. Пустое поле — размер
        // берётся у «Заголовков карточек», как было до появления настройки.
        'fs_news_title' => ['Заголовки карточек новостей', '.news-card__title, .relnews-card__title, .adjnews__title, .newsfeat-mini__title, .newsfeat-text__title, .newsdocs-item__title, .news-poll-card__question, .widget-latest-news__title', '16'],
        'fs_card_text' => ['Текст карточек и этапов', '.feature-card__text, .stage__text, .stage-item__text, .person-card__role, .act-card__desc, .doc-card__desc, .repo-card__desc, .news-card__desc, .news-card__excerpt, .project-card__desc, .relnews-card__excerpt, .imgcard__desc, .catcard__excerpt, .timeline-item__text, .featband__text, .bio-career__text, .newsdetail-timeline__desc, .newsdetail-points__item, .faq-item__a, .block-advantages__text, .contact-card__item, .block-map__card-address', '14'],
        'fs_meta' => ['Метаданные и подписи', '.crumbs, .content-crumbs, .content-card__meta, .content-detail__date, .stage__year, .stage__label, .act-card__number, .act-card__date, .act-card__meta, .person-card__more, .person-card__vacant, .news-card__date, .news-card__meta, .project-card__meta, .album-card__meta, .relnews-card__date, .adjnews__date, .newsfeat__date, .newsdocs-item__date, .doc-card__meta, .catcard__created, .catcard__meta-item, .catcard__file, .catdetail__date, .newsdetail__meta, .newsdetail__source, .newsdetail-gallery__caption, .newsdetail-timeline__date, .newsdetail-event__label, .newsdetail-doc__meta, .newsdetail-points__number, .bio-career__years, .bio-edu__years, .bio-edu__org, .block-text__media-caption, .article-media__caption, .article-media__credit, .media-caption, .gcal-list__time, .gcal-list__loc', '13'],
        'fs_small' => ['Мелкий и вспомогательный текст', 'small, .form-hint, .section-head__eyebrow, .block-hero__eyebrow, .content-badge, .newsdetail__badge, .news-badge, .faq-item__category, .search-suggest__type, .search-suggest__meta, .site-search-results__type, .news-poll-card__badge, .news-poll-card__meta', '13'],
        'fs_btn' => ['Кнопки и ссылки-действия', '.block-cta__button, .block-banner__button, .block-hero__button, .timeline-card__button, .timeline-cta__button, .ctaband__button, .profile__button, .newsdetail__btn, .newsdetail__reader-btn, .newsdetail-dl-btn, .doc-card__action, .act-card__action, .catcard__more, .content-toolbar__reset, .news-card__more, .newsfeat__more, .person-card__more, .block-map__card-link, .section-head__all, .gcal-nav__all, .btn-cta, .btn, button, input[type="button"], input[type="submit"]', '15'],
        'fs_menu' => ['Главное меню', '.site-menu__link', '13'],
        'fs_topbar' => ['Верхняя панель', '.site-topbar', '13'],
    ];

    /**
     * Шкала типографики: коэффициент между соседними ступенями. Размеры
     * заголовков считаются от базового размера текста, а не задаются каждый
     * отдельно — иначе H2 легко оказывается мельче H3, что и случалось.
     *
     * ключ => [подпись, коэффициент, пояснение]
     */
    public const TYPO_SCALES = [
        // Значение по умолчанию — «не вмешиваться»: на уже работающем сайте
        // включение шкалы поменяло бы все заголовки разом, без спроса.
        'theme' => ['Как в теме', 0.0, 'Размеры остаются такими, какие заданы в теме. Ничего не меняется.'],
        'compact' => ['Компактная', 1.2, 'Плотный ритм: много текста на экране, заголовки не давят.'],
        'classic' => ['Классическая', 1.25, 'Сбалансированная шкала для информационных сайтов.'],
        'expressive' => ['Выразительная', 1.333, 'Крупные заголовки, сильный контраст с текстом.'],
    ];

    /** Ступени шкалы относительно базового размера; H6 — половина шага. */
    private const SCALE_STEPS = ['fs_h6' => 0.5, 'fs_h5' => 1, 'fs_h4' => 2, 'fs_h3' => 3, 'fs_h2' => 4, 'fs_h1' => 5];

    public static function typoScale(): string
    {
        $scale = (string) Setting::get('design_typo_scale', 'theme');

        return isset(self::TYPO_SCALES[$scale]) ? $scale : 'theme';
    }

    /**
     * Размеры заголовков по выбранной шкале — то, что применится, если не
     * задано точное значение вручную.
     *
     * @return array<string,string> ключ fs_* => '32px'
     */
    public static function scaleSizes(): array
    {
        $ratioSetting = self::TYPO_SCALES[self::typoScale()][1];
        if ($ratioSetting <= 1.0) {
            return []; // «Как в теме» — размеры не навязываем
        }

        $base = (float) (preg_replace('/[^0-9.]/', '', self::fontSizeCustom()) ?: 0);
        if ($base <= 0) {
            $base = (float) (['sm' => 15, 'md' => 16, 'lg' => 17, 'xl' => 18][self::current()['font_size'] ?? 'md'] ?? 16);
        }
        $ratio = $ratioSetting;

        $sizes = [];
        foreach (self::SCALE_STEPS as $key => $step) {
            // Округляем до целого: дробные размеры вроде 13.12px и порождают
            // ощущение, что шкалы нет.
            $sizes[$key] = ((string) (int) round($base * ($ratio ** $step))) . 'px';
        }

        return $sizes;
    }

    /**
     * Итоговые размеры по элементам: ручное значение важнее шкалы,
     * при пустом поле для заголовков берётся шкала.
     *
     * @return array<string,string> ключ => '17px' или '' (не задан)
     */
    public static function typographySizes(): array
    {
        $scale = self::scaleSizes();
        $sizes = [];
        foreach (self::TYPO_SIZES as $key => $_) {
            $manual = self::normalizeFsSize((string) Setting::get('design_' . $key, ''));
            $sizes[$key] = $manual !== '' ? $manual : ($scale[$key] ?? '');
        }

        // Заголовки карточек новостей отделены от остальных карточек, но
        // незаданное поле обязано означать «как раньше»: до появления
        // настройки эти заголовки слушались «Заголовков карточек», и пустое
        // значение не должно менять размер на уже настроенном сайте.
        if ($sizes['fs_news_title'] === '') {
            $sizes['fs_news_title'] = $sizes['fs_card_title'];
        }

        return $sizes;
    }

    /** Только ручные переопределения — для формы в админке. @return array<string,string> */
    public static function typographyOverrides(): array
    {
        $sizes = [];
        foreach (self::TYPO_SIZES as $key => $_) {
            $sizes[$key] = self::normalizeFsSize((string) Setting::get('design_' . $key, ''));
        }

        return $sizes;
    }

    /** Нормализует размер шрифта элемента (8–96px); '' — не задан/невалиден. */
    public static function normalizeFsSize(string $raw): string
    {
        return self::normalizePixelValue($raw, 8, 96);
    }

    /**
     * `:not(...)` со всеми классами компонентных групп типографики.
     *
     * Нужен правилу по тегу заголовка: элемент, чей класс владелец явно
     * отнёс к «Заголовкам карточек», «Служебным подписям» и прочим группам,
     * должен слушаться этой группы, а не уровня заголовка. Берём только
     * простые селекторы вида «.class» — составные вроде «.a .b» в :not()
     * значили бы не то, что ожидается.
     */
    private static function componentTitleExclusion(): string
    {
        $classes = [];
        foreach (['fs_lead', 'fs_card_title', 'fs_news_title', 'fs_card_text', 'fs_meta', 'fs_menu', 'fs_topbar'] as $group) {
            foreach (explode(',', self::TYPO_SIZES[$group][1]) as $selector) {
                $selector = trim($selector);
                if (preg_match('/^\.[A-Za-z0-9_-]+$/', $selector) === 1) {
                    $classes[$selector] = true;
                }
            }
        }

        return $classes === [] ? '' : ':not(' . implode(',', array_keys($classes)) . ')';
    }

    /** CSS-правила для заданных размеров по элементам ('' — ничего не задано). */
    public static function typographyCss(): string
    {
        $rules = '';
        $variables = '';
        $headingRules = '';
        $sizes = self::typographySizes();
        foreach ($sizes as $key => $size) {
            if ($size !== '') {
                $variable = '--font-size-' . str_replace('_', '-', substr($key, 3));
                $variables .= $variable . ':' . $size . ';';
                $rules .= self::TYPO_SIZES[$key][1] . '{font-size:var(' . $variable . ') !important;}';
            }
        }

        // Компонентный класс не должен менять семантический уровень заголовка:
        // <h3 class="..."> получает настройку H3, даже если класс по ошибке
        // попал в другую группу. :root + [class]/:not([class]) дают правилу
        // достаточную специфичность против компонентных селекторов.
        //
        // Исключение — классы, явно перечисленные в компонентных группах
        // («Заголовки карточек» и т.п.). Заголовок карточки остаётся <h2> по
        // структуре страницы (h1 → h3 axe считает пропуском уровня), но
        // размер ему задаёт своя группа, а не настройка H2: иначе увеличение
        // H2 раздувало и карточки в списках.
        $componentExclusion = self::componentTitleExclusion();
        foreach (['fs_h1' => 'h1', 'fs_h2' => 'h2', 'fs_h3' => 'h3', 'fs_h4' => 'h4', 'fs_h5' => 'h5', 'fs_h6' => 'h6'] as $key => $tag) {
            if (($sizes[$key] ?? '') === '') {
                continue;
            }
            $variable = '--font-size-' . substr($key, 3);
            $headingRules .= ':root body ' . $tag . '[class]' . $componentExclusion
                . ',:root body ' . $tag . ':not([class]){font-size:var(' . $variable . ') !important;}';
        }

        // Служебные подписи используют отдельный tracking-токен: интервал
        // заголовков для uppercase-номеров и дат семантически не подходит.
        if (self::metaLetterSpacingCustom() !== '') {
            $rules .= self::TYPO_SIZES['fs_meta'][1]
                . '{letter-spacing:var(--meta-letter-spacing) !important;}';
        }

        return ($variables !== '' ? ':root{' . $variables . '}' : '') . $rules . $headingRules;
    }

    /** Точный межстрочный интервал, 1–2.5 (без единиц); пусто — значение пресета. */
    public static function lineHeightCustom(): string
    {
        return self::normalizeLineHeight((string) Setting::get('design_line_height_custom', ''));
    }

    public static function headingLineHeightCustom(): string
    {
        return self::normalizeLineHeight((string) Setting::get('design_heading_line_height_custom', ''));
    }

    public static function normalizeLineHeight(string $raw): string
    {
        $raw = trim(str_replace(',', '.', $raw));
        if ($raw === '' || !preg_match('/^\d(?:\.\d{1,2})?$/', $raw)) {
            return '';
        }
        $value = (float) $raw;
        if ($value < 1 || $value > 2.5) {
            return '';
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** Точный межбуквенный интервал метаданных, -0.1–0.3em; пусто — тема. */
    public static function metaLetterSpacingCustom(): string
    {
        return self::normalizeMetaLetterSpacing(
            (string) Setting::get('design_meta_letter_spacing_custom', '')
        );
    }

    public static function normalizeMetaLetterSpacing(string $raw): string
    {
        $raw = strtolower(trim(str_replace(',', '.', $raw)));
        $raw = preg_replace('/em$/', '', $raw) ?? '';
        if ($raw === '' || !preg_match('/^-?(?:\d+(?:\.\d{1,3})?|\.\d{1,3})$/', $raw)) {
            return '';
        }
        $value = (float) $raw;
        if ($value < -0.1 || $value > 0.3) {
            return '';
        }

        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') . 'em';
    }

    private static function normalizePixelValue(string $raw, float $min, float $max): string
    {
        $raw = strtolower(trim(str_replace(',', '.', $raw)));
        $raw = preg_replace('/px$/', '', $raw) ?? '';
        if ($raw === '' || !preg_match('/^\d{1,3}(?:\.\d)?$/', $raw)) {
            return '';
        }
        $value = (float) $raw;
        if ($value < $min || $value > $max) {
            return '';
        }
        $normalized = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return $normalized . 'px';
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
                self::normalizePixelValue((string) $input['section_marker_thickness'], 1, 12)
            );
        }
        if (array_key_exists('section_marker_height', $input)) {
            Setting::set(
                'design_section_marker_height',
                self::normalizePixelValue((string) $input['section_marker_height'], 4, 80)
            );
        }
        if (array_key_exists('menu_divider_thickness', $input)) {
            Setting::set('design_menu_divider_thickness', self::normalizePixelValue((string) $input['menu_divider_thickness'], 0, 10));
        }
        if (array_key_exists('menu_divider_height', $input)) {
            Setting::set('design_menu_divider_height', self::normalizePixelValue((string) $input['menu_divider_height'], 2, 100));
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
                $slug !== '' && isset(self::GOOGLE_FONTS[$slug]) ? $slug : ''
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
        $bodySlug = (string) Setting::get('design_font_google_body', '');
        if ($bodySlug !== '' && isset(self::GOOGLE_FONTS[$bodySlug])) {
            Setting::set('font_family', self::GOOGLE_FONTS[$bodySlug][1]);
        }

        $headingSlug = (string) Setting::get('design_font_google_heading', '');
        $bodyFont = (string) Setting::get('font_family', SiteThemeCss::DEFAULT_BODY_FONT);
        Setting::set(
            'font_heading',
            $headingSlug !== '' && isset(self::GOOGLE_FONTS[$headingSlug])
                ? self::GOOGLE_FONTS[$headingSlug][1]
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

    // --- Пользовательские конфигурации (сохранённые администратором) ---

    private const USER_PRESETS_KEY = 'design_user_presets';
    private const USER_PRESETS_MAX = 10;

    /** @return array<string,array{label:string,values:array<string,string>,colors?:array<int,string>,appearance?:array<string,string>}> */
    public static function userPresets(): array
    {
        $json = Setting::get(self::USER_PRESETS_KEY, '');
        $data = $json !== '' ? json_decode($json, true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * Сохраняет ТЕКУЩИЕ настройки дизайна как именованную конфигурацию.
     * Вместе с опциями снапшотится ручная тройка цвет/акцент/шрифт — чтобы
     * пресет с палитрой «Свои цвета» восстанавливался в точности.
     * Возвращает slug или null (пустое имя / превышен лимит).
     */
    public static function saveUserPreset(string $name): ?string
    {
        $name = mb_substr(trim($name), 0, 40);
        if ($name === '') {
            return null;
        }

        $presets = self::userPresets();
        // Сохраняем буквы любого языка: прежняя ASCII-регулярка превращала
        // почти все русские/узбекские названия в один ключ "preset".
        $baseSlug = preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($name)) ?: 'preset';
        $baseSlug = trim($baseSlug, '-') ?: 'preset';
        $slug = $baseSlug;
        if (isset($presets[$slug]) && (string) ($presets[$slug]['label'] ?? '') !== $name) {
            $suffix = 2;
            do {
                $slug = $baseSlug . '-' . $suffix++;
            } while (isset($presets[$slug]));
        }
        if (!isset($presets[$slug]) && count($presets) >= self::USER_PRESETS_MAX) {
            return null;
        }

        $custom = self::customAppearance();
        $semantic = self::semanticColors();
        $spacings = self::semanticSpacings();
        $presets[$slug] = [
            'label' => $name,
            'values' => self::current(),
            'colors' => [
                $custom['color_primary'],
                $custom['color_accent'],
                $custom['font_family'],
            ],
            'appearance' => [
                'color_primary' => $custom['color_primary'],
                'color_accent' => $custom['color_accent'],
                'font_family' => $custom['font_family'],
                'font_face_name' => Setting::get('font_face_name', ''),
                'font_url' => Setting::get('font_url', ''),
                'default_theme' => Setting::get('default_theme', 'light'),
                'font_google_heading' => Setting::get('design_font_google_heading', ''),
                'font_google_body' => Setting::get('design_font_google_body', ''),
                'font_script' => Setting::get('design_font_script', ''),
                'font_size_custom' => Setting::get('design_font_size_custom', ''),
                'radius_custom' => Setting::get('design_radius_custom', ''),
                'newsdetail_padding_top' => Setting::get('design_newsdetail_padding_top', ''),
                'newsdetail_padding_bottom' => Setting::get('design_newsdetail_padding_bottom', ''),
                'line_height_custom' => Setting::get('design_line_height_custom', ''),
                'heading_line_height_custom' => Setting::get('design_heading_line_height_custom', ''),
                'meta_letter_spacing_custom' => Setting::get('design_meta_letter_spacing_custom', ''),
                'typo_scale' => self::typoScale(),
            ] + array_combine(
                array_keys(self::TYPO_SIZES),
                array_map(static fn (string $k): string => (string) Setting::get('design_' . $k, ''), array_keys(self::TYPO_SIZES))
            ) + [
                'bg_primary' => $semantic['bg_primary'],
                'bg_surface' => $semantic['bg_surface'],
                'text_main' => $semantic['text_main'],
                'text_muted' => $semantic['text_muted'],
                'border_color' => $semantic['border_color'],
                'space_small' => $spacings['space_small'],
                'space_premium' => $spacings['space_premium'],
                'space_max' => $spacings['space_max'],
            ],
        ];
        Setting::set(self::USER_PRESETS_KEY, self::encodePresets($presets));
        Setting::set('design_preset', 'user:' . $slug);

        return $slug;
    }

    public static function deleteUserPreset(string $slug): bool
    {
        $presets = self::userPresets();
        if (!isset($presets[$slug])) {
            return false;
        }
        unset($presets[$slug]);
        Setting::set(self::USER_PRESETS_KEY, self::encodePresets($presets));
        if (Setting::get('design_preset', '') === 'user:' . $slug) {
            Setting::set('design_preset', '');
        }

        return true;
    }

    public static function applyUserPreset(string $slug): bool
    {
        $presets = self::userPresets();
        if (!isset($presets[$slug])) {
            return false;
        }
        $preset = $presets[$slug];
        $values = (array) ($preset['values'] ?? []);
        $colors = (array) ($preset['colors'] ?? []);
        $appearance = (array) ($preset['appearance'] ?? []);
        // Сначала восстанавливаем ручные значения, затем применяем через
        // обычный save. Так они сохраняются и после переключения пресетов.
        if (($values['palette'] ?? '') === 'custom' && count($colors) === 3) {
            if ($colors[0] !== '') { Setting::set('design_custom_color_primary', (string) $colors[0]); }
            if ($colors[1] !== '') { Setting::set('design_custom_color_accent', (string) $colors[1]); }
        }
        if (($values['font_style'] ?? '') === 'custom' && ($colors[2] ?? '') !== '') {
            Setting::set('design_custom_font_family', (string) $colors[2]);
        }
        // Новые пресеты хранят весь единый блок оформления; colors остаётся
        // fallback для конфигураций, созданных до унификации.
        $appearanceInput = array_intersect_key($appearance, array_flip(array_merge([
            'color_primary', 'color_accent', 'font_family', 'font_face_name',
            'font_url', 'default_theme', 'font_google_heading', 'font_google_body',
            'font_script',
            'font_size_custom', 'radius_custom', 'line_height_custom',
            'newsdetail_padding_top', 'newsdetail_padding_bottom',
            'heading_line_height_custom', 'meta_letter_spacing_custom', 'typo_scale',
            'bg_primary', 'bg_surface', 'text_main', 'text_muted', 'border_color',
            'space_small', 'space_premium', 'space_max',
        ], array_keys(self::TYPO_SIZES))));
        // Старые пользовательские конфигурации не знали об этих полях: при
        // их применении сбрасываем текущие переопределения, а не наследуем их.
        $appearanceInput = array_merge([
            'font_google_heading' => '',
            'font_google_body' => '',
            'font_script' => '',
            'font_size_custom' => '',
            'radius_custom' => '',
            'newsdetail_padding_top' => '',
            'newsdetail_padding_bottom' => '',
            'line_height_custom' => '',
            'heading_line_height_custom' => '',
            'meta_letter_spacing_custom' => '',
            // Конфигурации, сохранённые до появления шкалы, восстанавливают
            // поведение «как в теме», а не наследуют текущую шкалу.
            'typo_scale' => 'theme',
            // Старые конфигурации не содержали семантические интервалы.
            'space_small' => 'clamp(14px, 2.5vw, 24px)',
            'space_premium' => 'clamp(28px, 4vw, 56px)',
            'space_max' => 'clamp(40px, 5vw, 76px)',
        ], array_fill_keys(array_keys(self::TYPO_SIZES), ''), $appearanceInput);
        self::save(array_merge($values, $appearanceInput));

        Setting::set('design_preset', 'user:' . $slug);

        return true;
    }

    /** Префикс автокопий дизайна — по нему они узнаются и чистятся. */
    public const DESIGN_BACKUP_PREFIX = 'Автокопия дизайна';

    /** Сколько автокопий держим: они делят лимит с обычными конфигурациями. */
    private const DESIGN_BACKUP_KEEP = 2;

    /**
     * Снимок текущих настроек перед применением конфигурации: применение
     * переписывает всё разом, а отмены у раздела «Дизайн» нет.
     *
     * @return string|null название копии либо null, если сохранить не вышло
     */
    public static function autoBackupPreset(): ?string
    {
        // Старые автокопии убираем заранее — иначе упрёмся в лимит наборов.
        $presets = self::userPresets();
        $auto = array_filter(
            $presets,
            static fn (array $p): bool => str_starts_with((string) ($p['label'] ?? ''), self::DESIGN_BACKUP_PREFIX)
        );
        if (count($auto) >= self::DESIGN_BACKUP_KEEP) {
            foreach (array_slice(array_keys($auto), 0, count($auto) - self::DESIGN_BACKUP_KEEP + 1) as $slug) {
                unset($presets[$slug]);
            }
            Setting::set(self::USER_PRESETS_KEY, self::encodePresets($presets));
        }

        $name = self::DESIGN_BACKUP_PREFIX . ': ' . date('d.m.Y H:i');

        return self::saveUserPreset($name) !== null ? $name : null;
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
    public static function bodyClasses(array $v): string
    {
        return trim(sprintf(
            'design-catalog-%s design-sidebar-%s design-cards-%s design-detail-%s',
            preg_replace('/[^a-z_]/', '', (string) ($v['catalog_layout'] ?? 'grid')),
            preg_replace('/[^a-z]/', '', (string) ($v['sidebar_position'] ?? 'floating')),
            preg_replace('/[^a-z]/', '', (string) ($v['card_style'] ?? 'soft')),
            preg_replace('/[^a-z]/', '', (string) ($v['detail_layout'] ?? 'plain'))
        )) . ' design-mmenu-burger'
          . ' design-mark-' . (isset(self::OPTIONS['title_mark']['choices'][(string) ($v['title_mark'] ?? '')])
              ? (string) $v['title_mark']
              : 'accent')
          . (in_array($v['title_reveal'] ?? 'off', ['fade', 'wipe'], true)
              ? ' design-title-' . $v['title_reveal']
              : '')
          . ' design-secmark-' . (isset(self::OPTIONS['section_marker']['choices'][(string) ($v['section_marker'] ?? '')])
              ? (string) $v['section_marker']
              : 'line')
          . (($v['scroll_top'] ?? 'on') === 'on' ? ' design-scrolltop' : '');
    }

    /**
     * Пресеты строкой для настройки.
     *
     * `json_encode()` объявлена как `string|false` и при негодных данных (битая
     * кодировка в названии пресета) отдаёт `false`. В файле со `strict_types`
     * это значение уходило в `Setting::set()` типизированным параметром, то
     * есть сохранение раздела «Дизайн» падало с `TypeError`, ничего не говоря о
     * причине. `JSON_THROW_ON_ERROR` называет её вслух и не даёт записать в
     * настройку мусор вместо пресетов.
     *
     * @param array<mixed> $presets
     */
    private static function encodePresets(array $presets): string
    {
        return json_encode($presets, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

}
