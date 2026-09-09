<?php

use App\Core\Flash;
use App\Core\HeaderConfig;
use App\Core\Locale;
use App\Models\Language;
use App\Models\MenuItem;
use App\Models\Setting;

/** @var string $metaTitle */
/** @var string $metaDescription */
/** @var string $extraHeadCss */
/** @var string $ogImage */
/** @var string $ogType */
/** @var array<int, string> $preloadImages */

$siteName = Setting::getLocalized('site_name', \App\Core\Locale::current(), 'ASDR');
// Логотип: переопределение на текущий язык (Шапка → логотип для языка),
// иначе общий логотип из Настроек.
$hcfgAll = \App\Core\HeaderConfig::get();
$logo = trim((string) ($hcfgAll['logo_by_lang'][\App\Core\Locale::current()] ?? ''));
if ($logo === '') {
    $logo = (string) Setting::get('logo_url', '');
}
// Выбранные семейства нужны для точечного preload локальных файлов шрифтов;
// сами CSS-переменные публикует SiteThemeCss.
// Стеки берём из SiteThemeCss — там же, откуда они попадают в CSS страницы.
// Иначе шапка предзагружает файлы одного семейства, а текст набирается другим:
// так сайт качал PT Sans и PT Serif, которыми ничего не набрано.
$fontStacks = \App\Core\SiteThemeCss::fontStacks();
$font = $fontStacks['body'];
$fontHeading = $fontStacks['heading'];
$extraHeadCss = $extraHeadCss ?? '';

// --- Дизайн-система: тема и локальный шрифт ---
$defaultTheme = Setting::get('default_theme', 'light'); // light | dark | auto
if (!in_array($defaultTheme, ['light', 'dark', 'auto'], true)) {
    $defaultTheme = 'light';
}
$fontUrl = Setting::get('font_url', '');           // ссылка на .woff2 локального шрифта
$fontFaceName = Setting::get('font_face_name', ''); // имя семейства для @font-face
// Не загружаем собственный файл, когда выбран базовый шрифт или локальный каталог.
if (\App\Core\DesignSettings::bodyFontChoice() !== 'style:custom') {
    $fontUrl = '';
    $fontFaceName = '';
}

// --- SEO / Open Graph ---
$appUrl = \App\Core\AppUrl::base();
// Canonical: путь плюс значащие параметры адреса (страница списка,
// рубрика, вкладка медиа-блока). Закрытый список — в SeoHelper.
$canonicalUrl = \App\Core\SeoHelper::canonicalUrl(
    $appUrl,
    Locale::url(Locale::path()),
    (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY) ?? '')
);
$ogType = $ogType ?? 'website';
// Приоритет OG-картинки: страница -> дефолтный OG:Image -> логотип (задача 116).
$defaultOg = Setting::get('default_og_image', '');
$ogImageRaw = ($ogImage ?? '') !== '' ? $ogImage : ($defaultOg !== '' ? $defaultOg : ($logo !== '' ? $logo : ''));
// Абсолютный URL для og:image.
if ($ogImageRaw !== '' && !preg_match('#^https?://#', $ogImageRaw)) {
    $ogImageRaw = $appUrl . '/' . ltrim($ogImageRaw, '/');
}
// Meta Description по умолчанию, если не задан на странице.
if (empty($metaDescription)) {
    $metaDescription = Setting::get('default_meta_description', '');
}
// Favicon / Theme Color / PWA (задача 116).
$faviconUrl = Setting::get('favicon_url', '');
$themeColor = Setting::get('theme_color', '');
$pwaShortName = Setting::get('pwa_short_name', '');

$hcfg = HeaderConfig::get();
$currentLang = Locale::current();

// --- Логотип ---
// Размеры логотипа в атрибутах: без них браузер не знает, сколько места
// занять, и шапка подпрыгивает при загрузке картинки — сдвиг ровно на первом
// экране. Media::dimensions() читает их из файла и сам пропускает SVG, где
// пиксельных размеров может не быть (см. грабли в CLAUDE.md).
$logoSizeAttr = static function (string $url): string {
    $size = \App\Core\Media::dimensions($url);

    return $size === null ? '' : ' width="' . $size[0] . '" height="' . $size[1] . '"';
};
$logoHtml = '<a href="' . htmlspecialchars(Locale::url('/', $currentLang), ENT_QUOTES) . '" class="site-header__logo">';
if ($logo !== '') {
    $logoHtml .= '<img class="site-header__logo-std" src="' . htmlspecialchars($logo, ENT_QUOTES) . '"'
        . $logoSizeAttr($logo) . ' alt="' . htmlspecialchars($siteName, ENT_QUOTES) . '">';
    // Светлый вариант логотипа для прозрачной шапки (задаётся в конструкторе):
    // сначала — для текущего языка, иначе — общий.
    $logoLight = trim((string) ($hcfgAll['logo_light_by_lang'][$currentLang] ?? ''));
    if ($logoLight === '') {
        $logoLight = trim((string) ($hcfgAll['logo_light'] ?? ''));
    }
    if ($logoLight !== '') {
        $logoHtml .= '<img class="site-header__logo-light" src="' . htmlspecialchars($logoLight, ENT_QUOTES) . '"'
            . $logoSizeAttr($logoLight) . ' alt="' . htmlspecialchars($siteName, ENT_QUOTES) . '">';
    }
} else {
    $logoHtml .= '<span>' . htmlspecialchars($siteName, ENT_QUOTES) . '</span>';
}
$logoHtml .= '</a>';

// --- Меню ---
// В меню хранится только имя иконки Tabler (напр. home, file-text, news).
$renderMenuIcon = static function (mixed $iconName): string {
    $name = \App\Core\Icon::cleanName($iconName);
    if ($name === '') {
        return '';
    }
    return '<span class="site-menu__icon" aria-hidden="true">'
        . \App\Core\Icon::render($name, 18, 'site-menu__svg') . '</span>';
};
// --- Определение активного пункта меню по URL ---
$currentReqUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$currentReqPath = rtrim($currentReqUri, '/') ?: '/';

// Правило подсветки одно на шапку и на боковое меню раздела: разъехавшись,
// они показывали бы разные «текущие» пункты на одной странице.
$isNavUrlActive = static fn (string $targetUrl): bool => \App\Core\SectionMenu::isUrlActive($targetUrl, $currentReqPath);

$menuHtml = '';
$et = static fn (string $text): string => htmlspecialchars(t($text), ENT_QUOTES);
$menuAlignment = in_array($hcfg['menu_position'] ?? '', ['left', 'center', 'right'], true)
    ? $hcfg['menu_position']
    : HeaderConfig::DEFAULTS['menu_position'];
$menuItems = MenuItem::activeForLang($currentLang);
if (!empty($menuItems)) {
    $st = $hcfg['styles'] ?? [];
    $navFontSize = in_array($st['nav_font_size'] ?? '', ['compact', 'normal', 'large'], true) ? $st['nav_font_size'] : 'normal';
    $navTransform = in_array($st['nav_transform'] ?? '', ['uppercase', 'capitalize', 'none'], true) ? $st['nav_transform'] : 'uppercase';
    $navLetterSpacing = in_array($st['nav_letter_spacing'] ?? '', ['normal', 'wide', 'wider'], true) ? $st['nav_letter_spacing'] : 'normal';
    $navStyleType = in_array($st['nav_style_type'] ?? '', ['underline', 'dot', 'pill', 'glow', 'minimal'], true) ? $st['nav_style_type'] : 'underline';
    $navPadding = in_array($st['nav_padding'] ?? '', ['compact', 'normal', 'spacious'], true) ? $st['nav_padding'] : 'normal';
    $navIconPos = in_array($st['nav_icon_pos'] ?? '', ['left', 'top'], true) ? $st['nav_icon_pos'] : 'left';
    $navDividers = !empty($st['nav_item_dividers']) ? ' site-menu--with-item-dividers' : '';
    $submenuStyle = in_array($st['submenu_style'] ?? '', ['lines', 'minimal', 'cards'], true) ? $st['submenu_style'] : 'lines';
    $submenuTransform = in_array($st['submenu_transform'] ?? '', ['uppercase', 'capitalize', 'none'], true) ? $st['submenu_transform'] : 'none';
    $submenuDivider = in_array($st['submenu_divider'] ?? '', ['subtle', 'accent', 'none'], true) ? $st['submenu_divider'] : 'subtle';

    $menuClasses = 'site-menu'
        . ' site-menu--font-' . $navFontSize
        . ' site-menu--transform-' . $navTransform
        . ' site-menu--spacing-' . $navLetterSpacing
        . ' site-menu--style-' . $navStyleType
        . ' site-menu--padding-' . $navPadding
        . ' site-menu--icon-' . $navIconPos
        . ' site-menu--submenu-' . $submenuStyle
        . ' site-menu--submenu-transform-' . $submenuTransform
        . ' site-menu--submenu-divider-' . $submenuDivider
        . ' site-menu--align-' . $menuAlignment
        . $navDividers;

    $menuHtml = '<nav class="' . $menuClasses . '" aria-label="' . $et('Основное меню') . '">';
    foreach ($menuItems as $mi) {
        // Пункт-разделитель: визуальная черта/зазор без ссылки.
        if (!empty($mi['is_divider'])) {
            $menuHtml .= '<span class="site-menu__divider" role="separator" aria-hidden="true"></span>';
            continue;
        }

        $url = MenuItem::resolveUrl($mi, $currentLang);
        $icon = $renderMenuIcon($mi['icon_svg'] ?? '');
        $isIconOnly = !empty($mi['hide_title']) && $icon !== '';
        $textClass = $isIconOnly ? 'site-menu__text site-menu__text--hidden' : 'site-menu__text';
        $iconOnlyClass = $isIconOnly ? ' site-menu__link--icon-only' : '';
        $titleAttr = $isIconOnly ? ' title="' . htmlspecialchars($mi['title'], ENT_QUOTES) . '" aria-label="' . htmlspecialchars($mi['title'], ENT_QUOTES) . '"' : '';

        $badgeHtml = '';
        if (!empty($mi['badge_text'])) {
            $bColor = htmlspecialchars($mi['badge_color'] ?: 'red', ENT_QUOTES);
            $bPos = htmlspecialchars(MenuItem::cleanBadgePos($mi['badge_pos'] ?? 'right'), ENT_QUOTES);
            $badgeHtml = '<span class="site-menu__badge site-menu__badge--' . $bColor . ' site-menu__badge--pos-' . $bPos . '">'
                . htmlspecialchars($mi['badge_text'], ENT_QUOTES) . '</span>';
        }
        
        // Показываем только активные дочерние пункты (разделители в подменю не поддерживаем).
        $children = array_values(array_filter(
            $mi['children'] ?? [],
            static fn ($c) => (int) $c['is_active'] === 1 && empty($c['is_divider'])
        ));

        $label = $badgeHtml . $icon . '<span class="' . $textClass . '">' . htmlspecialchars($mi['title'], ENT_QUOTES) . '</span>';

        $isDirectActive = $isNavUrlActive($url);

        if ($children === []) {
            $activeClass = $isDirectActive ? ' is-active' : '';
            $ariaCurrent = $isDirectActive ? ' aria-current="page"' : '';
            $menuHtml .= '<a class="site-menu__link' . $activeClass . $iconOnlyClass . '" href="' . htmlspecialchars($url, ENT_QUOTES) . '"' . $ariaCurrent . $titleAttr . '>'
                . $label . '</a>';
            continue;
        }

        // Пункт с выпадающим подменю (dropdown на desktop hover/focus, tap на мобильных).
        // mega_columns 2..4 — широкая панель в несколько колонок вместо столбца.
        $megaCols = MenuItem::megaColumns($mi['mega_columns'] ?? 0);
        $hasActiveChild = false;
        $submenuHtml = '';
        foreach ($children as $child) {
            $childUrl = MenuItem::resolveUrl($child, $currentLang);
            $isChildActive = $isNavUrlActive($childUrl);
            if ($isChildActive) {
                $hasActiveChild = true;
            }
            $childIcon = $renderMenuIcon($child['icon_svg'] ?? '');
            $isChildIconOnly = !empty($child['hide_title']) && $childIcon !== '';
            $cTextClass = $isChildIconOnly ? 'site-menu__text site-menu__text--hidden' : 'site-menu__text';
            $cIconOnlyClass = $isChildIconOnly ? ' site-submenu__link--icon-only' : '';
            $cTitleAttr = $isChildIconOnly ? ' title="' . htmlspecialchars($child['title'], ENT_QUOTES) . '" aria-label="' . htmlspecialchars($child['title'], ENT_QUOTES) . '"' : '';

            $childBadge = '';
            if (!empty($child['badge_text'])) {
                $cColor = htmlspecialchars($child['badge_color'] ?: 'red', ENT_QUOTES);
                $cPos = htmlspecialchars(MenuItem::cleanBadgePos($child['badge_pos'] ?? 'right'), ENT_QUOTES);
                $childBadge = '<span class="site-menu__badge site-menu__badge--' . $cColor . ' site-menu__badge--pos-' . $cPos . '">'
                    . htmlspecialchars($child['badge_text'], ENT_QUOTES) . '</span>';
            }
            $cActiveClass = $isChildActive ? ' is-active' : '';
            $cAriaCurrent = $isChildActive ? ' aria-current="page"' : '';
            $submenuHtml .= '<a class="site-submenu__link' . $cActiveClass . $cIconOnlyClass . '" href="' . htmlspecialchars($childUrl, ENT_QUOTES) . '"' . $cAriaCurrent . $cTitleAttr . '>'
                . $childBadge . $childIcon . '<span class="' . $cTextClass . '">' . htmlspecialchars($child['title'], ENT_QUOTES) . '</span></a>';
        }

        $isParentActive = $isDirectActive || $hasActiveChild;
        $parentActiveClass = $isParentActive ? ' is-active' : '';
        $parentAriaCurrent = $isDirectActive ? ' aria-current="page"' : '';

        $menuHtml .= '<div class="site-menu__item site-menu__item--has-children'
            . ($isParentActive ? ' is-active' : '')
            . ($megaCols > 0 ? ' site-menu__item--mega' : '') . '">';
        $menuHtml .= '<a class="site-menu__link' . $parentActiveClass . $iconOnlyClass . '" href="' . htmlspecialchars($url, ENT_QUOTES) . '"' . $parentAriaCurrent . $titleAttr . '>'
            . $label . '</a>';
        $menuHtml .= '<button type="button" class="site-menu__toggle" aria-expanded="false" aria-label="' . $et('Открыть подменю') . '">'
            . \App\Core\Icon::render('chevron-down', 12, 'site-menu__toggle-icon', 2.5) . '</button>';
        $menuHtml .= $megaCols > 0
            ? '<div class="site-submenu site-submenu--mega site-submenu--cols-' . $megaCols . '">'
            : '<div class="site-submenu">';
        $menuHtml .= $submenuHtml;
        $menuHtml .= '</div></div>';
    }
    $menuHtml .= '</nav>';
}

// --- Переключатель языков ---
// Переключатель всегда показывает все активные языки. Наличие перевода не
// должно лишать посетителя возможности явно сменить язык интерфейса.
// --- Настройки отображения: состояние из cookie (страница сразу приходит
// в нужном виде, без мигания «обычная → крупная») ---
$a11ySettings = \App\Core\A11ySettings::fromCookie($_COOKIE[\App\Core\A11ySettings::COOKIE] ?? null);
$a11yActive = \App\Core\A11ySettings::isActive($a11ySettings);
$a11yToggle = '<button type="button" class="a11y-toggle' . ($a11yActive ? ' is-active' : '') . '" data-a11y-open'
    . ' aria-label="' . $et('Настройки отображения') . '" title="' . $et('Настройки отображения: размер текста, контраст, интервалы') . '"'
    . ' aria-controls="a11y-panel" aria-expanded="false">'
    . \App\Core\Icon::render('eye', 18)
    . '<span>' . $et('Для слабовидящих') . '</span></button>';

$langHtml = '';
$activeLangs = Language::active();
$hrefLangs = $activeLangs;
$contentLangs = \App\Core\Locale::contentLangs();
if ($contentLangs !== null) {
    // Для SEO по-прежнему объявляем только реально существующие переводы.
    $hrefLangs = array_values(array_filter(
        $hrefLangs,
        static fn (array $l): bool => in_array((string) $l['code'], $contentLangs, true)
    ));
}
if (!empty($hcfg['language_switcher']['enabled']) && (count($activeLangs) >= 1 || !empty($hcfg['language_switcher']['always_show']))) {
    $format = $hcfg['language_switcher']['format'] ?? 'code';
    $style = $hcfg['language_switcher']['style'] ?? 'dropdown';
    $path = Locale::path();

    $currentObj = null;
    foreach ($activeLangs as $l) {
        if ((string) $l['code'] === $currentLang) {
            $currentObj = $l;
            break;
        }
    }
    $currentObj ??= $activeLangs[0] ?? ['code' => 'ru', 'name' => 'Русский'];

    $currentCode = (string) $currentObj['code'];
    $currentName = (string) $currentObj['name'];

    // Узбекская кириллица — не отдельный язык, а письменность: контент один,
    // текст переводится транслитерацией. В списке она стоит рядом с узбекским,
    // а ссылка ведёт на переключатель письменности.
    $uzCyrillicOn = $currentLang === 'uz' && $a11ySettings['script'] === 'cyrl';
    $langOptions = [];
    foreach ($activeLangs as $l) {
        $code = (string) $l['code'];
        // У связанной записи-перевода свой адрес: ведём сразу на него, иначе
        // переключение языка отвечало редиректом.
        // Материал, которого нет на выбранном языке, — отдельный случай:
        // новости и проекты переводятся отдельной записью, и «тот же путь под
        // другим префиксом» там не существует — переключатель вёл на 404.
        // Посетителя, попросившего другой язык, ведём на главную этого языка.
        $codeHasContent = $contentLangs === null || in_array($code, $contentLangs, true);
        $langPath = $codeHasContent ? Locale::alternatePath($code) : '';
        $href = Locale::url($langPath, $code)
            . '?' . \App\Core\LocalePreference::QUERY . '=' . rawurlencode($code);
        if ($code === 'uz') {
            // На узбекский всегда возвращаемся в латинице, даже из кириллицы.
            $href = '/script/latn?to=' . rawurlencode($href);
        }
        $langOptions[] = [
            'name' => (string) $l['name'],
            // Короткое название языка, а не его код: «Рус» читается как язык,
            // «RU» — как технический идентификатор.
            'short' => \App\Models\Language::shortLabel($l),
            'href' => $href,
            'active' => $code === $currentLang && !($code === 'uz' && $uzCyrillicOn),
        ];

        if ($code !== 'uz') {
            continue;
        }
        $uzHref = Locale::url($langPath, 'uz') . '?' . \App\Core\LocalePreference::QUERY . '=uz';
        $langOptions[] = [
            'name' => 'Ўзбекча',
            'short' => 'Ўзб',
            'href' => '/script/cyrl?to=' . rawurlencode($uzHref),
            'active' => $uzCyrillicOn,
        ];
    }

    if ($uzCyrillicOn) {
        $currentName = 'Ўзбекча';
        $currentCode = 'Ўзб';
    }

    // Названия языков не переводятся транслитерацией: «O‘zbekcha» должно
    // остаться латиницей и в кириллическом режиме.
    if ($style === 'pills') {
        $langHtml = '<div class="site-lang-switcher" data-no-translit>';
        foreach ($langOptions as $option) {
            $label = $format === 'name' ? $option['name'] : $option['short'];
            $isActive = $option['active'] ? ' is-active' : '';
            $langHtml .= '<a class="site-lang-switcher__item' . $isActive . '" href="' . htmlspecialchars($option['href'], ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
        }
        $langHtml .= '</div>';
    } else {
        $triggerLabel = match ($format) {
            'name' => $currentName,
            // Компактный вид: короткое название текущего языка.
            default => $uzCyrillicOn ? $currentCode : \App\Models\Language::shortLabel($currentObj),
        };

        $globeSvg = \App\Core\Icon::render('world', 15, 'site-lang-dropdown__globe');

        $langHtml = '<details class="site-lang-dropdown" data-lang-dropdown data-no-translit>';
        $langHtml .= '<summary class="site-lang-dropdown__trigger" aria-label="' . htmlspecialchars(t('Выбрать язык'), ENT_QUOTES) . '">';
        $langHtml .= $globeSvg;
        $langHtml .= '<span class="site-lang-dropdown__current">' . htmlspecialchars((string) $triggerLabel, ENT_QUOTES) . '</span>';
        $langHtml .= \App\Core\Icon::render('chevron-down', 12, 'site-lang-dropdown__arrow', 2.5);
        $langHtml .= '</summary>';

        $langHtml .= '<div class="site-lang-dropdown__menu" role="menu">';
        foreach ($langOptions as $option) {
            $isActive = $option['active'];

            $langHtml .= '<a class="site-lang-dropdown__item' . ($isActive ? ' is-active' : '') . '" href="' . htmlspecialchars($option['href'], ENT_QUOTES) . '" role="menuitem">';
            $langHtml .= '<span class="site-lang-dropdown__item-name">' . htmlspecialchars($option['name'], ENT_QUOTES) . '</span>';
            if ($isActive) {
                $langHtml .= \App\Core\Icon::render('check', 14, 'site-lang-dropdown__check', 3);
            }
            $langHtml .= '</a>';
        }
        $langHtml .= '</div>';
        $langHtml .= '</details>';
    }
}

// --- Кнопки соцсетей ---
$socialHtml = '';
if (!empty($hcfg['social_buttons'])) {
    $socialStyle = $hcfg['social_style'] ?? 'monochrome';
    $socialHtml = '<div class="site-social site-social--' . htmlspecialchars($socialStyle, ENT_QUOTES) . '">';
    foreach ($hcfg['social_buttons'] as $btn) {
        $socialHtml .= '<a class="site-social__link site-social__link--' . htmlspecialchars($btn['network'], ENT_QUOTES) . '" href="'
            . htmlspecialchars($btn['url'], ENT_QUOTES) . '" target="_blank" rel="noopener" aria-label="' . htmlspecialchars($btn['network'], ENT_QUOTES) . '">'
            . \App\Core\SocialIcons::glyph((string) $btn['network']) . '</a>';
    }
    $socialHtml .= '</div>';
}

// --- CTA-кнопка ---
$ctaHtml = '';
if ($hcfg['cta']['enabled'] && $hcfg['cta']['text'] !== '') {
    $ctaIconSvg = '';
    $iconType = $hcfg['cta']['icon'] ?? 'none';
    $ctaIconName = match ($iconType) {
        'phone' => 'phone',
        'calendar' => 'calendar',
        'send' => 'send',
        'arrow' => 'arrow-right',
        default => '',
    };
    if ($ctaIconName !== '') {
        $ctaIconSvg = \App\Core\Icon::render($ctaIconName, 15);
    }

    $ctaHtml = '<a class="site-cta site-cta--' . htmlspecialchars($hcfg['cta']['style'], ENT_QUOTES) . '" href="'
        . htmlspecialchars($hcfg['cta']['url'] !== '' ? $hcfg['cta']['url'] : '#', ENT_QUOTES) . '">'
        . $ctaIconSvg . '<span>' . htmlspecialchars(t($hcfg['cta']['text']), ENT_QUOTES) . '</span></a>';
}

// --- Переключатель темы (Светлая / Тёмная), анимированные иконки ---
$themeToggle = '<button type="button" class="site-theme-toggle" aria-label="' . $et('Сменить тему') . '" title="' . $et('Светлая/тёмная тема') . '">'
    . '<span class="site-theme-toggle__inner" aria-hidden="true">'
    . \App\Core\Icon::render('sun', 24, 'site-theme-toggle__sun')
    . \App\Core\Icon::render('moon', 24, 'site-theme-toggle__moon')
    . '</span></button>';

// --- Тема-билдер: значения дизайна + классы для <body> ---
$designVals = \App\Core\DesignSettings::current();
$designBodyClass = \App\Core\DesignSettings::bodyClasses($designVals);
$searchConfig = (array) ($hcfg['search'] ?? []);
$searchType = ($searchConfig['style'] ?? 'inline') === 'modal' ? 'overlay' : 'inline';
$designBodyClass .= ' design-search-' . $searchType;
// Подпись поля поиска приходит из конструктора шапки и печаталась как есть,
// поэтому на узбекской странице оставалась русской — хотя перевод в словаре
// есть (и для многоточия, и для трёх точек). Прогоняем через t(): свой текст
// редактора, которого в словаре нет, остаётся без изменений.
$searchPlaceholder = trim((string) ($searchConfig['placeholder'] ?? ''));
$searchPlaceholder = $searchPlaceholder === ''
    ? t('Поиск по сайту…')
    : t($searchPlaceholder);

// --- Поиск по сайту: безрамочная иконка-лупа с плавно выезжающим полем ввода ---
$searchAction = htmlspecialchars(Locale::url('search', $currentLang), ENT_QUOTES);
$searchIcon = \App\Core\Icon::render('search', 20);
$searchCloseIcon = \App\Core\Icon::render('x', 16);

$inlineSearchHtml = '<div class="site-search-wrap">'
    . '<button type="button" class="site-search-toggle" aria-label="' . $et('Открыть поиск') . '" aria-expanded="false" data-search-toggle>' . $searchIcon . '</button>'
    . '<form class="site-search" method="get" action="' . $searchAction . '" role="search">'
    . '<input type="search" name="q" minlength="2" required autocomplete="off" placeholder="' . htmlspecialchars($searchPlaceholder, ENT_QUOTES) . '" aria-label="' . htmlspecialchars(t('Поиск по сайту'), ENT_QUOTES) . '">'
    . '<button type="submit" class="site-search__submit" aria-label="' . $et('Найти') . '">' . $searchIcon . '</button>'
    . '<button type="button" class="site-search__close" aria-label="' . $et('Закрыть') . '" data-search-close>' . $searchCloseIcon . '</button>'
    . '</form>'
    . '</div>';
$overlaySearchHtml = '<button type="button" class="site-search-toggle" aria-label="' . $et('Открыть поиск') . '" aria-controls="site-search-popover" aria-expanded="false" data-search-toggle>' . $searchIcon . '</button>';
$searchHtml = $searchType === 'overlay' ? $overlaySearchHtml : $inlineSearchHtml;

// --- Бургер для мобильного меню ---
$burgerHtml = $menuHtml !== ''
    ? '<button type="button" class="site-burger" data-mobile-menu-toggle aria-label="' . $et('Меню') . '" aria-controls="site-drawer" aria-expanded="false"><span></span><span></span><span></span></button>'
    : '';


// Прозрачная шапка: глобальный режим применяется только на страницах,
// где включён флаг «Прозрачная шапка» (переменная $transparentHeader из вью).
$transparentOn = !empty($hcfg['transparent']) && !empty($transparentHeader ?? null);
$logoPos = $hcfg['logo_position'];
$navAlign = $menuAlignment;

// В адаптивном desktop-режиме основное меню остаётся горизонтальным.
// JS переносит в этот контейнер только последние пункты, которым не хватило
// места. Полная копия $menuHtml по-прежнему используется в mobile drawer.
$priorityMenuHtml = $menuHtml;
if (
    $priorityMenuHtml !== ''
    && ($hcfg['styles']['nav_overflow'] ?? 'adaptive') === 'adaptive'
) {
    $priorityMenuHtml = preg_replace(
        '/<nav class="([^"]*)"/',
        '<nav class="$1" data-priority-menu',
        $priorityMenuHtml,
        1
    ) ?? $priorityMenuHtml;

    $priorityControl = '<div class="site-menu__overflow" data-priority-overflow hidden>'
        . '<button type="button" class="site-menu__overflow-toggle" data-priority-overflow-toggle'
        . ' aria-label="' . $et('Ещё разделы') . '" aria-haspopup="true" aria-expanded="false">'
        . '<span></span><span></span><span></span></button>'
        . '<div class="site-menu__overflow-panel" data-priority-overflow-panel hidden></div>'
        . '</div>';

    if (str_ends_with($priorityMenuHtml, '</nav>')) {
        $priorityMenuHtml = substr($priorityMenuHtml, 0, -6) . $priorityControl . '</nav>';
    }
}

// --- Раскладка по зонам верхнего ряда ---
// Конструктор: элементы-«кирпичики» расставляются по зонам согласно
// header_config.elements. Логотип и бургер размещаются отдельно.
$phoneVal = trim((string) ($hcfg['contacts']['phone'] ?? ''));
$emailVal = trim((string) ($hcfg['contacts']['email'] ?? ''));
$phoneHtml = $phoneVal !== ''
    ? '<a class="hdr-contact" href="tel:' . htmlspecialchars(preg_replace('/[^+\d]/', '', $phoneVal) ?? '', ENT_QUOTES) . '">'
        . \App\Core\Icon::render('phone', 15, 'ui-icon', 1.8)
        . htmlspecialchars($phoneVal, ENT_QUOTES) . '</a>'
    : '';
$emailHtml = $emailVal !== ''
    ? '<a class="hdr-contact" href="mailto:' . htmlspecialchars($emailVal, ENT_QUOTES) . '">'
        . \App\Core\Icon::render('mail', 15, 'ui-icon', 1.8)
        . htmlspecialchars($emailVal, ENT_QUOTES) . '</a>'
    : '';
$snippetHtml = (string) ($hcfg['snippet'] ?? ''); // очищен санитайзером при сохранении

$fragments = [
    'logo' => $logoHtml,
    'menu' => $priorityMenuHtml,
    'search' => $searchHtml,
    'language' => $langHtml,
    'social' => $socialHtml,
    'button' => $ctaHtml,
    'theme' => $themeToggle,
    'a11y' => $a11yToggle,
    'phone' => $phoneHtml,
    'email' => $emailHtml,
    'snippet' => $snippetHtml !== '' ? '<span class="hdr-snippet">' . $snippetHtml . '</span>' : '',
    'divider' => '<span class="site-header__divider" aria-hidden="true"></span>',
    'spacer' => '<span class="site-header__spacer" aria-hidden="true"></span>',
    'space' => '<span class="site-header__space" aria-hidden="true"></span>',
];

// --- Pro Max: верхняя утилитарная полоса (top section) ---
$topbarHtml = '';
if (!empty($hcfg['topbar']['enabled'])) {
    $tbZones = '';
    foreach (['left', 'center', 'right'] as $z) {
        $inner = '';
        foreach ((array) ($hcfg['topbar']['zones'][$z] ?? []) as $el) {
            $inner .= $fragments[$el] ?? '';
        }
        $tbZones .= '<div class="site-topbar__zone site-topbar__zone--' . $z . '">' . $inner . '</div>';
    }
    $tbStyle = in_array($hcfg['topbar']['style'] ?? 'navy', HeaderConfig::BAR_STYLES, true) ? $hcfg['topbar']['style'] : 'navy';
    $tbMobile = !empty($hcfg['topbar']['show_mobile']) ? ' site-topbar--mobile-on' : '';
    $tbBorder = !empty($hcfg['topbar']['show_border']) ? ' site-topbar--with-border' : '';
    $tbHeight = ' site-topbar--h-' . (in_array($hcfg['topbar']['height'] ?? 'normal', HeaderConfig::HEIGHTS, true) ? $hcfg['topbar']['height'] : 'normal');
    $topbarHtml = '<div class="site-topbar site-topbar--' . $tbStyle . $tbMobile . $tbBorder . $tbHeight . '"><div class="site-topbar__inner">' . $tbZones . '</div></div>';
}

// --- Pro Max: элементы нижней полосы (bottom section, рядом с меню) ---
$bottomExtras = ['left' => '', 'center' => '', 'right' => ''];
$hasBottomExtras = false;
foreach (['left', 'center', 'right'] as $z) {
    foreach ((array) ($hcfg['bottombar']['zones'][$z] ?? []) as $el) {
        $frag = $fragments[$el] ?? '';
        if ($frag !== '') {
            $bottomExtras[$z] .= $frag;
            $hasBottomExtras = true;
        }
    }
}
// Собираем зоны отдельно для десктопа и мобильного (разные наборы элементов);
// на фронте нужный вариант показывается по media-запросу.
$composeZone = static function (string $zone, string $variant) use ($hcfg, $fragments): string {
    $html = '';
    foreach ($hcfg[$variant][$zone] ?? [] as $el) {
        $html .= $fragments[$el] ?? '';
    }
    return $html;
};
$composed = ['left' => '', 'center' => '', 'right' => ''];
foreach (['left', 'center', 'right'] as $zone) {
    $desktop = $composeZone($zone, 'elements');
    $mobile = $composeZone($zone, 'elements_mobile');
    if ($desktop !== '') {
        $composed[$zone] .= '<span class="hdr-util hdr-util--desktop">' . $desktop . '</span>';
    }
    if ($mobile !== '') {
        $composed[$zone] .= '<span class="hdr-util hdr-util--mobile">' . $mobile . '</span>';
    }
}

$zones = ['left' => '', 'center' => '', 'right' => ''];
$zones['left'] .= $burgerHtml;
$zones['left'] .= $composed['left'];
$zones['center'] .= $composed['center'];
$zones['right'] .= $composed['right'];

$drawerMenu = '';

if ($menuHtml !== '') {
    $drawerMenu = $menuHtml;
}

// Dynamic design values are published as an immutable external stylesheet.
// This keeps the public HTML free from persistent inline CSS attributes and
// database-driven embedded CSS blocks while preserving the live design builder.
$headerExtraClass = \App\Core\SiteThemeCss::headerClasses($hcfg, $transparentOn);
$generatedCssUrls = [];
$themeCssUrl = \App\Core\GeneratedCss::publish(
    \App\Core\SiteThemeCss::build($designVals, $hcfg, $transparentOn),
    'site-theme'
);
if ($themeCssUrl !== null) {
    $generatedCssUrls['theme'] = $themeCssUrl;
}
if ($extraHeadCss !== '') {
    $pageCssUrl = \App\Core\GeneratedCss::publish($extraHeadCss, 'page');
    if ($pageCssUrl !== null) {
        $generatedCssUrls['page'] = $pageCssUrl;
    }
}
?>
<!DOCTYPE html>
<html
    lang="<?= htmlspecialchars($currentLang, ENT_QUOTES) ?>"
    data-theme="<?= htmlspecialchars($defaultTheme, ENT_QUOTES) ?>"
    <?= \App\Core\A11ySettings::htmlAttributes($a11ySettings) ?>
>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php // Синхронный и ВНЕШНИЙ по обоим требованиям сразу: синхронный — иначе
      // мигание темы до применения атрибута; внешний — публичная шапка не
      // держит исполняемых инлайн-скриптов даже с nonce (тест 171). Файл
      // отдаётся с Cache-Control: immutable, так что цена — один запрос при
      // первом визите. ?>
<script src="<?= htmlspecialchars(\App\Core\Asset::url('/assets/js/theme-init.js'), ENT_QUOTES) ?>"></script>
<?= \App\Core\SeoHelper::resourceHintsHtml() ?>
<?= \App\Core\SeoHelper::faviconsHtml($appUrl) ?>
<?php
$pageTitleText = trim((string) ($metaTitle ?? ''));
if ($pageTitleText === '') {
    $fullTitle = $siteName;
} elseif ($siteName !== '' && !str_contains(mb_strtolower($pageTitleText), mb_strtolower($siteName))) {
    $fullTitle = $pageTitleText . ' — ' . $siteName;
} else {
    $fullTitle = $pageTitleText;
}
?>
<title><?= htmlspecialchars($fullTitle, ENT_QUOTES) ?></title>
<?= \App\Core\Icon::browserConfigHtml() ?>
<?php if (!empty($robotsNoindex)): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<?php if (!empty($metaDescription)): ?>
<meta name="description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES) ?>">
<?php endif; ?>
<link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES) ?>">
<?php // Соседние страницы списка: поисковику это подсказка, что страницы
      // связаны в ленту, а не дублируют друг друга. Значения ставит вью
      // списка перед подключением шапки. ?>
<?php if (!empty($pagerPrev)): ?>
<link rel="prev" href="<?= htmlspecialchars($appUrl . $pagerPrev, ENT_QUOTES) ?>">
<?php endif; ?>
<?php if (!empty($pagerNext)): ?>
<link rel="next" href="<?= htmlspecialchars($appUrl . $pagerNext, ENT_QUOTES) ?>">
<?php endif; ?>
<?php // hreflang: текущий путь на языках, где контент реально существует
      // ($hrefLangs отфильтрован по Locale::contentLangs выше),
      // + x-default (основной язык). Одинокий hreflang не выводим. ?>
<?php if (count($hrefLangs) > 1): ?>
<?php foreach ($hrefLangs as $hrefLang): ?>
<link rel="alternate" hreflang="<?= htmlspecialchars((string) $hrefLang['code'], ENT_QUOTES) ?>" href="<?= htmlspecialchars($appUrl . Locale::url(Locale::alternatePath((string) $hrefLang['code']), (string) $hrefLang['code']), ENT_QUOTES) ?>">
<?php endforeach; ?>
<?php $xDefault = \App\Models\Language::defaultCode(); ?>
<link rel="alternate" hreflang="x-default" href="<?= htmlspecialchars($appUrl . Locale::url(Locale::alternatePath($xDefault), $xDefault), ENT_QUOTES) ?>">
<?php endif; ?>
<link rel="alternate" type="application/rss+xml" title="<?= htmlspecialchars($siteName . ' — ' . t('Новости'), ENT_QUOTES) ?>" href="<?= htmlspecialchars(Locale::url('news/rss.xml', $currentLang), ENT_QUOTES) ?>">
<?php
// og:locale:alternate объявляет те же языки, что и hreflang выше: список уже
// отфильтрован по наличию перевода, поэтому карточка ссылки не обещает
// страницу, которой нет.
$ogAlternateLangs = [];
foreach ($hrefLangs as $hrefLangRow) {
    $ogAlternateLangs[] = (string) $hrefLangRow['code'];
}
?>
<?= \App\Core\OpenGraphHelper::render(
    $appUrl,
    $metaTitle,
    $metaDescription,
    $canonicalUrl,
    $currentLang,
    $ogImageRaw,
    $ogType,
    $publishedAt ?? null,
    $modifiedAt ?? null,
    $ogAlternateLangs
) ?>
<?= \App\Core\SeoHelper::organizationSchema($appUrl) ?>
<?= \App\Core\SeoHelper::websiteSchema($appUrl, $siteName) ?>
<?php // Первый Hero уже отрендерен/закэширован до header, поэтому его LCP-кандидат
      // можно начать загружать до блокирующих stylesheet. ?>
<?php foreach (array_slice(array_values(array_unique($preloadImages ?? [])), 0, 1) as $preloadImage): ?>
<?= \App\Core\Media::preloadLink((string) $preloadImage, '100vw') ?>
<?php endforeach; ?>
<?php
$cdnBase = \App\Core\Asset::cdnBase();
$cdnParts = $cdnBase !== '' ? parse_url($cdnBase) : false;
$cdnOrigin = '';
if (is_array($cdnParts) && in_array($cdnParts['scheme'] ?? '', ['http', 'https'], true) && !empty($cdnParts['host'])) {
    $cdnOrigin = $cdnParts['scheme'] . '://' . $cdnParts['host']
        . (isset($cdnParts['port']) ? ':' . (int) $cdnParts['port'] : '');
}
?>
<?php if ($cdnOrigin !== ''): ?>
<link rel="preconnect" href="<?= htmlspecialchars($cdnOrigin, ENT_QUOTES) ?>" crossorigin>
<link rel="dns-prefetch" href="//<?= htmlspecialchars((string) ($cdnParts['host'] ?? ''), ENT_QUOTES) ?>">
<?php endif; ?>
<?php if ($fontUrl !== '' && $fontFaceName !== ''): ?>
<link rel="preload" href="<?= htmlspecialchars($fontUrl, ENT_QUOTES) ?>" as="font" type="font/woff2" crossorigin>
<?php endif; ?>
<?php $faviconTag = \App\Core\Favicon::settingTag($faviconUrl); ?>
<?php if ($faviconTag !== ''): ?>
<?= $faviconTag ?>

<?php endif; ?>
<?php if ($themeColor !== ''): ?>
<meta name="theme-color" content="<?= htmlspecialchars($themeColor, ENT_QUOTES) ?>">
<?php endif; ?>
<?php if ($pwaShortName !== ''): ?>
<link rel="manifest" href="/manifest.webmanifest">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($pwaShortName, ENT_QUOTES) ?>">
<?php endif; ?>
<?php // Preload только реально выбранных семейств: лишние preload конкурируют с CSS/LCP. ?>
<?php
$fontPreloads = [];
foreach ([(string) $font, (string) $fontHeading] as $selectedFont) {
    // Только семейства из поставки: путь к файлу здесь захардкожен, а у
    // скачанных на сервер шрифтов он свой (/uploads/public/fonts/<slug>/…).
    // Порядок важен: «Noto Serif» содержит «Noto S», поэтому имена
    // проверяются целиком и совпадение прерывает перебор.
    foreach ([
        'Noto Serif' => '/assets/fonts/noto-serif/noto-serif-var-cyrillic.woff2',
        'Noto Sans' => '/assets/fonts/noto-sans/noto-sans-var-cyrillic.woff2',
    ] as $family => $fontFile) {
        // Совпадать должно начало стека: семейство, которым реально набран
        // текст. Иначе запасное начертание («Inter Fallback») или дальний
        // элемент стека тянул бы за собой лишний preload.
        if (stripos(ltrim($selectedFont, " '\""), $family) === 0) {
            $fontPreloads[$fontFile] = true;
            break;
        }
    }
}
?>
<?php // Адрес — БЕЗ ?v=: в @font-face файл указан относительным путём без версии,
      // а preload с другим адресом браузер за тот же ресурс не считает и качает
      // файл вторым запросом. Версионировать шрифты и не нужно: имя файла меняется
      // вместе с содержимым (npm run sync:fonts). ?>
<?php foreach (array_keys($fontPreloads) as $fontFile): ?>
<link rel="preload" href="<?= htmlspecialchars($fontFile, ENT_QUOTES) ?>" as="font" type="font/woff2" crossorigin>
<?php endforeach; ?>
<?php foreach (\App\Core\LocalGoogleFonts::stylesheetsForSelected() as $fontStylesheet): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($fontStylesheet, ENT_QUOTES) ?>" data-local-google-font>
<?php endforeach; ?>
<?php foreach (\App\Core\FrontendAssets::styles() as $stylesheet): ?>
<link rel="stylesheet" href="<?= htmlspecialchars(\App\Core\Asset::url($stylesheet), ENT_QUOTES) ?>">
<?php endforeach; ?>
<?php // Части темы, вынесенные из бандла (стили детальной новости и т.п.).
      // Место строго здесь: после темы, но до дизайн-CSS из админки. ?>
<?= \App\Core\AssetCollector::renderThemeStyles() ?>
<?php foreach ($generatedCssUrls as $cssScope => $generatedCssUrl): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($generatedCssUrl, ENT_QUOTES) ?>" data-generated-site-css="<?= htmlspecialchars($cssScope, ENT_QUOTES) ?>">
<?php endforeach; ?>
<?php // Стили блоков, которые есть на этой странице (AssetCollector::CSS_MAP). ?>
<?= \App\Core\AssetCollector::renderStyles() ?>
<?php if (!empty($page['custom_css']) && !empty($page['id'])): ?>
<?php
$pageCssUrls = \App\Core\CustomAssetHelper::resolveCssUrls((string) $page['custom_css'], (int) $page['id']);
// Внешний домен нужно разрешить в CSP, иначе браузер отклонит <link>.
// Вьюха рендерится в буфер, поэтому заголовок ещё можно переслать.
\App\Core\SecurityHeaders::allowPageAssets(\App\Core\CustomAssetHelper::originsOf($pageCssUrls), []);
?>
<?php foreach ($pageCssUrls as $pageCssUrl): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($pageCssUrl, ENT_QUOTES) ?>" data-page-custom-css>
<?php endforeach; ?>
<?php endif; ?>
</head>
<?php
$bodyClass = trim($designBodyClass
    . (!empty($isHome) && empty($hideChrome) ? ' site-home' : '')
    . (!empty($previewNotice) ? ' is-preview' : '')
    . (\App\Core\AppToolbar::isVisible() ? ' has-admin-bar' : ''));
?>
<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES) ?>">
<?= \App\Core\AppToolbar::renderHtml(get_defined_vars()) ?>
<a href="#main-content" class="skip-link"><?= $et('Перейти к содержимому') ?></a>
<?php if (!empty($previewNotice)): ?>
<div class="preview-bar" role="status">
    <?= \App\Core\Icon::render('eye', 18) ?> <?= $et('Режим предпросмотра — эта версия не опубликована и закрыта от индексации.') ?>
</div>
<?php endif; ?>
<?php if (empty($hideChrome)): // лендинг (группа 6) скрывает шапку сайта ?>
<?= $topbarHtml ?>
<?php
$hasHeaderContent = trim($zones['left'] . $zones['center'] . $zones['right'] . $topbarHtml) !== '';
$containerMode = in_array($hcfg['container_mode'] ?? 'full', ['full', 'container', 'floating'], true) ? $hcfg['container_mode'] : 'full';
$headerClasses = [
    'site-header',
    'site-header--logo-' . $logoPos,
    'site-header--container-' . $containerMode,
    $hasBottomExtras ? 'site-header--has-nav' : '',
    $drawerMenu !== '' ? 'site-header--has-drawer' : '',
    !empty($hcfg['sticky']) ? 'site-header--sticky' : '',
    !empty($hcfg['sticky_full_width']) ? 'site-header--sticky-full' : '',
    $transparentOn ? 'site-header--transparent' : '',
    'site-header--h-' . (in_array($hcfg['middlebar']['height'] ?? 'normal', HeaderConfig::HEIGHTS, true)
        ? $hcfg['middlebar']['height']
        : 'normal'),
    'site-header--nav-h-' . (in_array($hcfg['bottombar']['height'] ?? 'normal', HeaderConfig::HEIGHTS, true)
        ? $hcfg['bottombar']['height']
        : 'normal'),
    'site-header--borders-' . (in_array($hcfg['borders'] ?? 'full', HeaderConfig::BORDER_MODES, true)
        ? $hcfg['borders']
        : 'full'),
    trim($headerExtraClass),
];
$headerClasses = implode(' ', array_filter($headerClasses));
?>
<?php if ($hasHeaderContent): ?>
<header
    class="<?= htmlspecialchars($headerClasses, ENT_QUOTES) ?>"
    <?php if (($hcfg['styles']['nav_overflow'] ?? 'adaptive') === 'adaptive'): ?>
        data-header-menu-adaptive
    <?php endif; ?>
    <?php if (!empty($hcfg['sticky']) || $transparentOn): ?>
        data-header-scroll
    <?php endif; ?>
>
    <div class="site-header__inner">
        <div class="site-header__zone site-header__zone--left"><?= $zones['left'] ?></div>
        <div class="site-header__zone site-header__zone--center"><?= $zones['center'] ?></div>
        <div class="site-header__zone site-header__zone--right"><?= $zones['right'] ?></div>
    </div>
    <?php if ($hasBottomExtras): ?>
    <div class="site-nav site-nav--align-<?= htmlspecialchars($navAlign, ENT_QUOTES) ?><?= ' site-nav--with-extras' ?>">
        <div class="site-nav__inner">
            <?php if ($bottomExtras['left'] !== ''): ?><span class="site-nav__extra site-nav__extra--left"><?= $bottomExtras['left'] ?></span><?php endif; ?>
            <?php if ($bottomExtras['center'] !== ''): ?><span class="site-nav__extra site-nav__extra--center"><?= $bottomExtras['center'] ?></span><?php endif; ?>
            <?php if ($bottomExtras['right'] !== ''): ?><span class="site-nav__extra site-nav__extra--right"><?= $bottomExtras['right'] ?></span><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</header>
<?php endif; ?>
<?php if ($drawerMenu !== ''): ?>
<?php // Off-canvas меню вынесено за пределы <header>, чтобы position:fixed не
      // зависел от containing block шапки (sticky/трансформации). ?>
<div class="site-drawer" id="site-drawer" data-drawer aria-hidden="true">
    <button type="button" class="site-drawer__backdrop" data-mobile-menu-toggle aria-label="<?= $et('Закрыть меню') ?>" tabindex="-1"></button>
    <div class="site-drawer__panel" role="dialog" aria-label="<?= $et('Меню') ?>" aria-modal="true">
        <button type="button" class="site-drawer__close" data-mobile-menu-toggle aria-label="<?= $et('Закрыть меню') ?>" aria-expanded="false"><?= \App\Core\Icon::render('x', 20) ?></button>
        <?= $drawerMenu ?>
    </div>
</div>
<?php endif; ?>
<?php if ($searchType === 'overlay'): ?>
<div class="site-search-overlay" id="site-search-popover" data-search-overlay hidden role="dialog" aria-modal="true" aria-label="<?= htmlspecialchars(t('Поиск по сайту'), ENT_QUOTES) ?>">
    <form class="site-search-overlay__form" method="get" action="<?= $searchAction ?>" role="search">
        <input type="search" name="q" minlength="2" required autocomplete="off" placeholder="<?= htmlspecialchars($searchPlaceholder, ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Поиск по сайту'), ENT_QUOTES) ?>" data-search-input>
        <button type="submit" class="site-search-overlay__submit"><?= $et('Найти') ?></button>
        <button type="button" class="site-search-overlay__close" aria-label="<?= $et('Закрыть поиск') ?>" data-search-close><?= \App\Core\Icon::render('x', 20) ?></button>
    </form>
</div>
<?php endif; ?>

<!-- Быстрый поиск Ctrl + K (Command Palette) -->
<div class="site-quick-search" id="site-quick-search-modal" hidden role="dialog" aria-modal="true" aria-label="<?= htmlspecialchars(t('Быстрый поиск по сайту'), ENT_QUOTES) ?>">
    <button type="button" class="site-quick-search__backdrop" data-quick-search-close aria-label="<?= $et('Закрыть') ?>" tabindex="-1"></button>
    <div class="site-quick-search__container">
        <form class="site-quick-search__form" method="get" action="<?= $searchAction ?>" role="search">
            <span class="site-quick-search__icon" aria-hidden="true"><?= $searchIcon ?></span>
            <input type="search" name="q" class="site-quick-search__input" id="site-quick-search-input" minlength="2" required autocomplete="off" placeholder="<?= htmlspecialchars(t('Быстрый поиск по сайту (Ctrl + K)…'), ENT_QUOTES) ?>" aria-label="<?= htmlspecialchars(t('Поиск по сайту'), ENT_QUOTES) ?>">
            <span class="site-quick-search__badge" aria-hidden="true">ESC</span>
            <button type="button" class="site-quick-search__close" aria-label="<?= $et('Закрыть') ?>" data-quick-search-close><?= \App\Core\Icon::render('x', 20) ?></button>
        </form>
        <div class="site-quick-search__hint">
            <span><kbd>Ctrl</kbd> + <kbd>K</kbd> <?= $et('открыть') ?></span>
            <span><kbd>Esc</kbd> <?= $et('закрыть') ?></span>
        </div>
    </div>
</div>
<!-- Контейнер для всплывающих Toast-уведомлений -->
<div class="site-toast-container" id="site-toast-container" aria-live="polite" aria-atomic="true"></div>
<?php endif; ?>
<main class="site-content" id="main-content"<?= ($visualSystemScope ?? true) ? ' data-visual-system' : '' ?>>
<header class="print-only print-header">
    <?php if ($logo !== ''): ?>
        <img src="<?= htmlspecialchars($logo, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($siteName, ENT_QUOTES) ?>">
    <?php else: ?>
        <div class="print-name"><?= htmlspecialchars($siteName, ENT_QUOTES) ?></div>
    <?php endif; ?>
</header>
<?php foreach (Flash::pull() as $flash): ?>
    <div class="site-alert site-alert--<?= htmlspecialchars($flash['type'], ENT_QUOTES) ?>"
         role="<?= ($flash['type'] ?? '') === 'error' ? 'alert' : 'status' ?>"
         aria-live="<?= ($flash['type'] ?? '') === 'error' ? 'assertive' : 'polite' ?>">
        <?= htmlspecialchars($flash['message'], ENT_QUOTES) ?>
    </div>
<?php endforeach; ?>
