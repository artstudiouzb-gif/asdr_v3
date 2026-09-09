<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Language;

/**
 * Хелперы разметки админки. Пока — единое поле выбора изображения с превью и
 * выбором из медиабиблиотеки (используется во всех типах контента).
 */
final class AdminUi
{

    /**
     * Подключение шрифта панели: объявления @font-face и предзагрузка двух
     * подмножеств.
     *
     * Панель набрана Noto Sans — тем же семейством, что и сайт по умолчанию.
     * Прежде она полагалась на системный стек, то есть выглядела по-разному на
     * каждой машине, а начертания 500 и 600 браузер подделывал синтетическим
     * жирным. Файл переменный (одна woff2 на всю ось 400–700), поэтому три
     * начертания шкалы не стоят ни одного лишнего байта.
     *
     * Предзагружаются кириллица и латиница: интерфейс русский, но цифры, даты и
     * латинские слова живут в латинском подмножестве и встречаются на каждом
     * экране. Расширенная кириллица (узбекские Ғ Қ Ҳ) объявлена и приезжает
     * только там, где такие буквы есть.
     *
     * Адрес шрифта — без `?v=`: в @font-face файл указан относительным путём без
     * версии, и preload с другим адресом браузер за тот же ресурс не считает.
     * Версионировать не нужно — имя файла меняется вместе с содержимым
     * (npm run sync:fonts).
     */
    public static function fontLinks(): string
    {
        $html = '';
        foreach (['cyrillic', 'latin'] as $subset) {
            $html .= '<link rel="preload" href="/assets/fonts/noto-sans/noto-sans-var-' . $subset
                . '.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
        }

        return $html . '<link rel="stylesheet" href="'
            . htmlspecialchars(Asset::url('/assets/css/noto-sans.css'), ENT_QUOTES) . '">' . "\n";
    }

    /** Возвращает иконку из локального спрайта Tabler для типа блока. */
    public static function blockIcon(string $type, int $size = 18): string
    {
        $iconMap = [
            'hero' => 'layout',
            'text' => 'document',
            'html' => 'code',
            'cta' => 'send',
            'advantages' => 'check',
            'slider' => 'media',
            'form' => 'send',
            'columns' => 'columns',
            'testimonials' => 'info',
            'counters' => 'stats',
            'team_list' => 'users',
            'projects_list' => 'briefcase',
            'news_latest' => 'document',
            'partners' => 'shield',
            'subscribe' => 'send',
            'faq' => 'info',
            'contact_cards' => 'user',
            'cards_grid' => 'grid',
            'media_gallery' => 'media',
            'news_feature' => 'document',
            'person_cards' => 'users',
            'timeline' => 'calendar',
            'news_docs' => 'document',
            'person_profile' => 'user',
            'bio_education' => 'user',
            'anchor_nav' => 'list',
            'stages' => 'calendar',
            'text_image' => 'document',
            'docs_list' => 'document',
            'map_point' => 'globe',
            'org_structure' => 'users',
        ];

        $iconName = $iconMap[$type] ?? 'block';
        return self::icon($iconName, $size);
    }

    /**
     * Инлайновая иконка для кнопки/метки. Неизвестное имя — пустая строка
     * (кнопка просто останется без иконки).
     */
    public static function icon(
        string $name,
        int $size = 16,
        string $class = 'btn__icon',
        float $strokeWidth = 2.0
    ): string
    {
        return Icon::render($name, $size, $class, $strokeWidth);
    }

    /** Каталог популярных иконок AdminUI с русскими подписями для селекторов меню и блоков. */
    public static function iconCatalog(): array
    {
        return [
            'home' => 'Главная / Дом',
            'document' => 'Документ / Страница',
            'news' => 'Новости / Публикации',
            'projects' => 'Проекты / Портфолио',
            'phone' => 'Телефон / Контакты',
            'email' => 'Почта / Письма',
            'globe' => 'Глобус / Сайт / Язык',
            'calendar' => 'Календарь / События',
            'briefcase' => 'Дела / Услуги',
            'tender' => 'Тендеры / Закупки',
            'users' => 'Команда / Люди',
            'info' => 'Информация / Справка',
            'shield' => 'Безопасность / Защита',
            'search' => 'Поиск по сайту',
            'stats' => 'Статистика / Аналитика',
            'media' => 'Медиа / Галерея',
            'image' => 'Изображение / Фото',
            'videos' => 'Видео / Медиатека',
            'files' => 'Файлы / Документы',
            'settings' => 'Настройки',
            'palette' => 'Дизайн / Стили',
            'grid' => 'Сетка / Разделы',
            'list' => 'Список / Каталог',
            'external' => 'Внешняя ссылка',
            'check' => 'Галочка / Успех',
            'sparkles' => 'Акцент / Важное',
            'user' => 'Личный кабинет',
            'code' => 'Разработка / Код',
            'social' => 'Соцсети',
            'clock' => 'Часы / Время',
            'a11y' => 'Доступность',
        ];
    }

    /**
     * Поле выбора иконки из полного локального каталога Tabler.
     *
     * @param array{label?:string,hint?:string,id?:string,placeholder?:string} $opts
     */
    public static function iconField(string $name, mixed $value = '', array $opts = []): string
    {
        $iconName = Icon::cleanName($value);
        $label = (string) ($opts['label'] ?? 'Иконка');
        $hint = (string) ($opts['hint'] ?? 'Выберите иконку Tabler или введите её имя.');
        $placeholder = (string) ($opts['placeholder'] ?? 'Например: home');
        $defaultId = 'icon_' . trim((string) preg_replace('/[^a-z0-9_]+/i', '_', $name), '_');
        $id = (string) ($opts['id'] ?? $defaultId);
        $esc = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES);

        $html = '<div class="form-field tabler-icon-field" data-icon-field>';
        $html .= '<label for="' . $esc($id) . '">' . $esc($label) . '</label>';
        $html .= '<div class="tabler-icon-field__control">';
        $html .= '<span class="tabler-icon-field__preview" data-icon-preview aria-hidden="true">'
            . ($iconName !== '' ? Icon::render($iconName, 22) : Icon::render('photo-off', 22))
            . '</span>';
        $html .= '<input type="text" id="' . $esc($id) . '" name="' . $esc($name) . '" value="'
            . $esc($iconName) . '" placeholder="' . $esc($placeholder)
            . '" autocomplete="off" spellcheck="false" data-icon-input>';
        $html .= '<button type="button" class="btn btn--secondary tabler-icon-field__choose" data-icon-picker-open>'
            . Icon::render('icons', 17) . '<span>Выбрать</span></button>';
        $html .= '<button type="button" class="btn btn--secondary tabler-icon-field__clear" data-icon-clear '
            . 'aria-label="Убрать иконку" title="Без иконки">' . Icon::render('x', 17) . '</button>';
        $html .= '</div>';
        if ($hint !== '') {
            $html .= '<span class="form-hint">' . $esc($hint) . '</span>';
        }
        $html .= '</div>';

        return $html;
    }

    public static function navigationIcon(string $name): string
    {
        // Ключ раздела и имя иконки совпадают далеко не всегда, а неизвестный
        // ключ Icon::render отдаёт пустой строкой — пункт меню оставался вовсе
        // без иконки, и таких было девятнадцать из тридцати. Соответствие
        // задаётся здесь явно; новый раздел без строки в этой карте иконки не
        // получит (стережёт тест).
        // Разделы, делившие один значок, разведены: медиафайлы отличаются от
        // фотоальбомов, пользователи — от команды. Одинаковые значки в колонке
        // читались как один и тот же раздел.
        $name = [
            'team' => 'users',
            'users' => 'user-shield',
            'security' => 'shield',
            'news_categories' => 'category',
            'pages' => 'file-text',
            'heroes' => 'slideshow',
            'projects' => 'briefcase',
            'albums' => 'photo',
            'files' => 'folder',
            'videos' => 'movie',
            'subscribers' => 'mail',
            'repository' => 'archive',
            'design' => 'palette',
            'goals' => 'target',
            'widgets' => 'layout-grid',
            'header' => 'layout-navbar',
            'footer' => 'layout-bottombar',
            'languages' => 'language',
            'content_types' => 'list-details',
            'telegram' => 'brand-telegram',
            'webhooks' => 'webhook',
            'redirects' => 'route',
            'seo' => 'world-search',
            'performance' => 'gauge',
            'database' => 'database',
            'audit' => 'history',
            'update' => 'cloud-download',
        ][$name] ?? $name;

        return self::icon($name, 18, 'admin-nav-item__icon', 1.7);
    }

    /**
     * Заголовок карточки с единой системой иконок UI/UX Pro Max.
     */
    public static function cardHeader(string $title, string $iconName = 'info', string $color = 'var(--admin-accent)', string $actionHtml = ''): string
    {
        $html = '<div class="admin-card-header">';
        $html .= '<div class="admin-card-header__title">';
        $html .= '<span class="admin-card-header__icon" style="--admin-card-header-color:' . htmlspecialchars($color, ENT_QUOTES) . ';">' . self::icon($iconName, 22) . '</span>';
        $html .= '<h3>' . htmlspecialchars($title, ENT_QUOTES) . '</h3>';
        $html .= '</div>';
        if ($actionHtml !== '') {
            $html .= '<div>' . $actionHtml . '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Поле изображения: превью + URL-инпут + кнопка «Медиабиблиотека» + очистка,
     * опционально с companion-инпутом загрузки файла (FileReader-превью).
     *
     * @param string $urlName  имя поля со ссылкой (то, что читает контроллер)
     * @param string $urlValue текущее значение (URL)
     * @param array{label?:string,hint?:string,file?:?string,accept?:string,id?:string} $opts
     */
    public static function imageField(string $urlName, string $urlValue, array $opts = []): string
    {
        $label = $opts['label'] ?? 'Изображение';
        $hint = $opts['hint'] ?? '';
        $fileName = $opts['file'] ?? null;
        $accept = $opts['accept'] ?? 'image/*';
        $id = $opts['id'] ?? 'imgfld_' . preg_replace('/[^a-z0-9_]/i', '_', $urlName);

        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $hasImg = trim($urlValue) !== '';
        $fileTitle = $hasImg ? (string) preg_replace('~^.*/~', '', $urlValue) : '';

        $html = '<div class="form-field image-field" data-image-field>';
        $html .= '<label for="' . $esc($id) . '">' . $esc($label) . '</label>';
        $html .= '<div class="image-field__row">';

        // Превью вписывает картинку целиком (contain): логотип с широкой
        // надписью при обрезке по краям превращался в бессмысленный фрагмент.
        $html .= '<div class="image-field__preview" data-image-preview>';
        if ($hasImg) {
            $html .= '<img src="' . $esc($urlValue) . '" alt="">';
        } else {
            $html .= '<span class="image-field__placeholder" aria-hidden="true">'
                . Icon::render('photo', 22, 'image-field__placeholder-icon', 1.6) . '</span>';
        }
        $html .= '</div>';

        // Управление. Кнопки в одной строке, адрес файла — под ними: раньше
        // поле занимало три ряда, из которых один был нативным «Выберите файл».
        $html .= '<div class="image-field__main">';
        $html .= '<div class="image-field__name" data-image-name>'
            . ($hasImg ? $esc($fileTitle) : 'Файл не выбран') . '</div>';
        $html .= '<div class="image-field__controls">';
        $html .= '<button type="button" class="btn btn--small" data-media-pick data-media-target="#' . $esc($id) . '">'
            . Icon::render('photo', 15, 'btn__icon') . '<span>Выбрать из библиотеки</span></button>';
        if ($fileName !== null) {
            $html .= '<label class="btn btn--small image-field__upload">'
                . Icon::render('upload', 15, 'btn__icon') . '<span>Загрузить</span>'
                . '<input type="file" name="' . $esc($fileName) . '" accept="' . $esc($accept) . '" data-image-file></label>';
        }
        $html .= '<button type="button" class="btn btn--small image-field__clear" data-image-clear title="Очистить" aria-label="Очистить">'
            . Icon::render('x', 15) . '</button>';
        $html .= '</div>';
        $html .= '<input type="text" class="image-field__url" id="' . $esc($id) . '" name="' . $esc($urlName) . '" value="' . $esc($urlValue) . '"'
            . ' data-image-input placeholder="URL или выбор из медиабиблиотеки">';

        if ($hint !== '') {
            $html .= '<span class="form-hint">' . $esc($hint) . '</span>';
        }
        $html .= '</div></div></div>';

        return $html;
    }

    /**
     * Два одинаковых набора пресетов кадрирования: для широкого и мобильного
     * экрана. Произвольный CSS не принимается.
     */
    public static function mediaPositionFields(string $desktop = 'center-center', string $mobile = 'center-center'): string
    {
        $desktop = MediaPosition::normalize($desktop);
        $mobile = MediaPosition::normalize($mobile);
        $labels = [
            'left-top' => 'Слева сверху',
            'center-top' => 'По центру сверху',
            'right-top' => 'Справа сверху',
            'left-center' => 'Слева',
            'center-center' => 'По центру',
            'right-center' => 'Справа',
            'left-bottom' => 'Слева снизу',
            'center-bottom' => 'По центру снизу',
            'right-bottom' => 'Справа снизу',
        ];
        $select = static function (string $name, string $label, string $selected) use ($labels): string {
            $html = '<div class="form-field"><label for="' . $name . '">' . $label . '</label><select id="' . $name . '" name="' . $name . '">';
            foreach ($labels as $value => $option) {
                $html .= '<option value="' . $value . '"' . ($selected === $value ? ' selected' : '') . '>' . $option . '</option>';
            }

            return $html . '</select></div>';
        };

        return '<div class="media-position-fields">'
            . $select('image_position', 'Кадрирование на широком экране', $desktop)
            . $select('image_position_mobile', 'Кадрирование на телефоне', $mobile)
            . '</div>';
    }

    /**
     * Поле выбора цвета с галочкой «по умолчанию». Значение читается
     * контроллером через BlockController::color() — при включённой галочке
     * $name_off цвет сбрасывается. JavaScript дополнительно блокирует поле,
     * а без JavaScript работает нативный input[type=color].
     */
    public static function colorField(string $name, ?string $value, string $label, string $defaultHex = '#173a63', string $offLabel = 'По умолчанию'): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $val = ($value !== null && $value !== '') ? $value : $defaultHex;
        $off = ($value === null || $value === '');

        // Состояние «по умолчанию» — часть поля, а не галочка сбоку: раньше
        // при включённой галочке образец продолжал показывать посторонний цвет,
        // и было не понять, что именно уйдёт на сайт.
        $html = '<div class="form-field colorfield' . ($off ? ' is-default' : '') . '" data-colorfield>';
        $html .= '<label for="' . $esc($name) . '">' . $esc($label) . '</label>';
        $html .= '<div class="colorfield__control">';
        $html .= '<input type="color" id="' . $esc($name) . '" name="' . $esc($name) . '" value="' . $esc($val) . '">';
        $html .= '</div>';
        $html .= '<label class="colorfield__off"><input type="checkbox" name="' . $esc($name) . '_off" value="1"'
            . ($off ? ' checked' : '') . '><span>' . $esc($offLabel) . '</span></label>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Интерактивный умный виджет выбора фокальной точки (UI/UX Pro Max).
     * Позволяет кликать прямо по изображению, выбирать готовые пресеты из сетки 3x3
     * и мгновенно видеть точное кадрирование.
     */
    public static function focalPointField(string $xName = 'focal_x', string $yName = 'focal_y', ?string $xVal = '', ?string $yVal = '', string $imageTargetInput = 'image_url'): string
    {
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);
        $x = is_numeric($xVal) ? max(0, min(100, (int) $xVal)) : 50;
        $y = is_numeric($yVal) ? max(0, min(100, (int) $yVal)) : 50;

        $html = '<div class="form-field focal-field" data-focal-picker data-image-input-name="' . $esc($imageTargetInput) . '">';
        $html .= '<label class="focal-field__label u-inline-e925a44577">Фокальная точка обложки <span class="form-hint">(для мобильного кадрирования)</span></label>';

        $html .= '<div class="focal-field__container">';

        // Визуальная интерактивная область клика с маркером.
        $html .= '<div class="focal-field__preview" data-focal-canvas title="Кликните по обложке для установки точки фокусировки">';
        $html .= '<img class="u-inline-c8be1ccba6" src="" alt="" data-focal-img>';
        $html .= '<div class="focal-field__placeholder" data-focal-placeholder><span>Кликните по фото для установки точки фокусировки</span></div>';
        $html .= '<div class="focal-field__pin" data-focal-pin style="--focal-x:' . $x . '%;--focal-y:' . $y . '%;">'
            . self::icon('target', 18, 'focal-field__pin-icon', 2.5) . '</div>';
        $html .= '</div>';

        // Панель быстрой сетки 3x3 и числовых полей.
        $html .= '<div class="focal-field__controls">';
        $html .= '<div class="focal-field__grid">';
        $presets = [
            ['0', '0', self::icon('arrow-up-left', 15), 'Сверху слева (0/0)'],
            ['50', '0', self::icon('arrow-up', 15), 'Сверху по центру (50/0)'],
            ['100', '0', self::icon('arrow-up-right', 15), 'Сверху справа (100/0)'],
            ['0', '50', self::icon('arrow-left', 15), 'Слева (0/50)'],
            ['50', '50', self::icon('target', 15), 'По центру (50/50)'],
            ['100', '50', self::icon('arrow-right', 15), 'Справа (100/50)'],
            ['0', '100', self::icon('arrow-down-left', 15), 'Снизу слева (0/100)'],
            ['50', '100', self::icon('arrow-down', 15), 'Снизу по центру (50/100)'],
            ['100', '100', self::icon('arrow-down-right', 15), 'Снизу справа (100/100)'],
        ];
        foreach ($presets as [$px, $py, $icon, $title]) {
            $active = ($x === (int) $px && $y === (int) $py) ? ' is-active' : '';
            $html .= '<button type="button" class="btn btn--small btn--icon focal-field__preset' . $active . '" data-focal-set-x="' . $px . '" data-focal-set-y="' . $py . '" title="' . $title . '" aria-label="' . $title . '">' . $icon . '</button>';
        }
        $html .= '</div>';

        $html .= '<div class="focal-field__inputs">';
        $html .= '<div class="focal-field__input-wrap"><label>X (%)</label><input type="number" name="' . $esc($xName) . '" data-focal-input-x min="0" max="100" value="' . ($xVal !== '' ? $esc((string) $xVal) : '') . '" placeholder="50"></div>';
        $html .= '<div class="focal-field__input-wrap"><label>Y (%)</label><input type="number" name="' . $esc($yName) . '" data-focal-input-y min="0" max="100" value="' . ($yVal !== '' ? $esc((string) $yVal) : '') . '" placeholder="50"></div>';
        $html .= '<button type="button" class="btn btn--small" data-focal-reset title="Сбросить на 50/50">Сброс</button>';
        $html .= '</div>';

        $html .= '</div>'; // controls
        $html .= '</div>'; // container
        $html .= '<span class="form-hint">Кликните мышью по фотографии выше или выберите быстрое положение на сетке.</span>';
        $html .= '</div>'; // form-field

        return $html;
    }

    /**
     * Рендерит горизонтальную панель переключения языков (subsubsub):
     * Все языки (349) | Русский (151) | O‘zbekcha (160) | English (38)
     */
    public static function renderLangSubsubsub(string $currentLang, array $counts, string $baseUrl, array $params = []): string
    {
        $languages = Language::active();
        $items = [];

        // Вкладка "Все языки"
        $allParams = $params;
        $allParams['lang'] = 'all';
        unset($allParams['page']);
        $allUrl = $baseUrl . '?' . http_build_query($allParams);
        $allCount = isset($counts['all']) ? (int) $counts['all'] : (int) array_sum(array_map('intval', $counts));
        $isAllActive = ($currentLang === '' || $currentLang === 'all');

        $items[] = sprintf(
            '<a href="%s" class="lang-filter-link %s">Все языки <span class="lang-filter-count">%d</span></a>',
            htmlspecialchars($allUrl, ENT_QUOTES),
            $isAllActive ? 'is-active' : '',
            $allCount
        );

        foreach ($languages as $lang) {
            $code = (string) $lang['code'];
            $name = (string) $lang['name'];
            $count = (int) ($counts[$code] ?? 0);

            $langParams = $params;
            $langParams['lang'] = $code;
            unset($langParams['page']);
            $url = $baseUrl . '?' . http_build_query($langParams);
            $isActive = ($currentLang === $code);

            $items[] = sprintf(
                '<a href="%s" class="lang-filter-link %s">%s <span class="lang-filter-count">%d</span></a>',
                htmlspecialchars($url, ENT_QUOTES),
                $isActive ? 'is-active' : '',
                htmlspecialchars($name, ENT_QUOTES),
                $count
            );
        }

        return '<div class="lang-subsubsub-bar u-inline-c0f5c28d5f">'
            . implode('<span class="u-inline-16a33d931a">|</span>', $items)
            . '</div>';
    }

    /**
     * Рендерит интерактивный живой предпросмотр SEO и карточек Telegram / соцсетей.
     */
    public static function seoPreviewBox(array $data = []): string
    {
        $siteName = htmlspecialchars(\App\Models\Setting::get('site_name', 'ASDR CMS'), ENT_QUOTES);
        $baseUrl = \App\Core\AppUrl::base();
        $domain = parse_url($baseUrl, PHP_URL_HOST) ?: 'agency.gov.uz';

        $title = htmlspecialchars((string) ($data['title'] ?? 'Заголовок вашей новости'), ENT_QUOTES);
        $excerpt = htmlspecialchars((string) ($data['excerpt'] ?? $data['meta_description'] ?? 'Краткое описание или лид новости отображается здесь...'), ENT_QUOTES);
        $image = htmlspecialchars((string) ($data['image_url'] ?? ''), ENT_QUOTES);
        $imageClass = $image === '' ? ' is-hidden' : '';
        $placeholderClass = $image !== '' ? ' is-hidden' : '';
        $searchIcon = Icon::render('search', 18);
        $socialIcon = Icon::render('message-circle', 16);
        $imageIcon = Icon::render('photo', 22);
        $worldIcon = Icon::render('world', 12);

        return <<<HTML
<div class="seo-live-preview u-inline-136e5b68ed">
    <div class="u-inline-7d8f4cd990">
        <div class="u-inline-a359bfa933">
            {$searchIcon}
            Интерактивный SEO & Соцсети Предпросмотр
        </div>
        <div class="seo-preview-tabs u-inline-1d8943fa86">
            <button type="button" class="btn btn--small btn--outline is-active u-inline-94db95e1b0" data-seo-tab="google">{$searchIcon} Поисковики (Google/Yandex)</button>
            <button type="button" class="btn btn--small btn--outline u-inline-94db95e1b0" data-seo-tab="social">{$socialIcon} Telegram & Соцсети</button>
        </div>
    </div>

    <!-- 1. Google / Yandex Card -->
    <div class="seo-preview-panel u-inline-21d91b7027" data-seo-panel="google">
        <div class="u-inline-b75b1843b5">
            <div class="u-inline-d60c3b2481">
                <span class="u-inline-0484dc067f">A</span>
                <span class="u-inline-43ae67e204">{$siteName}</span>
                <span class="u-inline-ab7dab3c79">https://{$domain} › news › ...</span>
            </div>
            <div class="u-inline-152ccfa629" data-seo-google-title>
                {$title}
            </div>
            <div class="u-inline-f8b0a0e950" data-seo-google-desc>
                {$excerpt}
            </div>
        </div>
        <div class="u-inline-6804b2c0e5">
            <span>Заголовок: <strong data-seo-title-count>0</strong> / 60 симв.</span>
            <span>Описание: <strong data-seo-desc-count>0</strong> / 160 симв.</span>
        </div>
    </div>

    <!-- 2. Telegram / Social Card -->
    <div class="seo-preview-panel u-inline-c8be1ccba6" data-seo-panel="social">
        <div class="u-inline-99bcb5a34b">
            <div class="seo-social-img-wrap u-inline-0d855cb4f4">
                <img class="seo-social-image{$imageClass}" src="{$image}" alt="" data-seo-social-img>
                <span class="seo-social-placeholder{$placeholderClass}" data-seo-social-noimg>{$imageIcon} Фотография новости</span>
            </div>
            <div class="u-inline-4680c65b6a">
                <div class="u-inline-7235472cc2" data-seo-social-title>{$title}</div>
                <div class="u-inline-4774201452" data-seo-social-desc>{$excerpt}</div>
                <div class="u-inline-2ce9644849">
                    {$worldIcon}
                    {$domain}
                </div>
            </div>
        </div>
    </div>
</div>
HTML;
    }
}
