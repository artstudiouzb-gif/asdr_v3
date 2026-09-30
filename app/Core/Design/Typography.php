<?php

declare(strict_types=1);

namespace App\Core\Design;

use App\Core\DesignSettings;
use App\Models\Setting;

/**
 * Типографика «Дизайна»: размеры по элементам, шкала заголовков,
 * межстрочные и межбуквенные интервалы и CSS, который из них собирается.
 *
 * Вынесено из DesignSettings (там остался фасад с теми же именами).
 */
final class Typography
{
    /**
     * Размеры шрифта по элементам: ключ формы fs_* => [подпись, CSS-селектор,
     * placeholder-значение темы]. Пустое значение — размер темы не трогаем.
     * Правила выводятся с !important, чтобы предсказуемо перекрывать
     * компонентные clamp()-размеры тем (панель a11y всё равно сильнее).
     */
    public const TYPO_SIZES = [
        'fs_h1' => ['Заголовок H1', '.block-hero__title, .content-pagehead__title, .listing__title, .projdetail__title, .catdetail__title, .translation-notice__title, .newsdetail__title, .newsdetail-phero__title, .reader-mode__headline', '42'],
        'fs_h2' => ['Заголовок H2', '.section-title, .block-title, .block-text__title, .bio__title, .content-list__head h1, .block-news__title, .block-categories__title, .block-contact-cards__title, .block-projects__title, .block-team__title, .block-testimonials__title, .block-banner__title, .block-featband__title, .block-map__title, .block-partners__title, .textimage__title, .subscribe-block__title, .section-head__title, .newslist-lead__title, .catdetail__subtitle, .block-timeline__title', '32'],
        'fs_h3' => ['Заголовок H3', '.orgstruct__head-name, .timeline-cta__title, .ctaband__title, .featband__name, .bio-career__title, .bio-quote__mark, .widget__title, .bio-extra__title, .block-team__group-title, .newsdetail-card__title, .newsdetail-timeline__title, .newsdetail-subscribe__title', '24'],
        'fs_h4' => ['Заголовок H4', '.block-team__unit-title, .newsdetail-timeline__heading', '20'],
        'fs_h5' => ['Заголовок H5', '', '18'],
        'fs_h6' => ['Заголовок H6', '', '16'],
        'fs_lead' => ['Вводный и крупный текст', '.content-pagehead__lead, .content-list__lead, .listing__lead, .block-hero__lead, .block-hero__subtitle, .block-banner__text, .newsdetail__lead, .newsdetail-phero__lead, .newslist-lead__excerpt, .bio-quote__text, .rich-content--lead', '17'],
        'fs_card_title' => ['Заголовки карточек и этапов', '.card__title, .content-card__title, .feature-card__title, .contact-card__title, .stage__title, .stage-item__title, .doc-card__title, .repo-card__title, .project-card__title, .album-card__title, .imgcard__title, .catcard__title, .catdetail__card-title, .faq-item__q, .newsdetail-doc__title, .block-map__card-title, .gcal-list__title, .bio-edu__degree, .icon-text__value', '16'],
        // Карточка новости живёт своей жизнью: её заголовок стоит в ленте из
        // четырнадцати карточек и в подборках на главной, где длина строки
        // другая, чем у карточки документа или этапа. Пустое поле — размер
        // берётся у «Заголовков карточек», как было до появления настройки.
        'fs_news_title' => ['Заголовки карточек новостей', '.news-card__title, .relnews-card__title, .adjnews__title, .newsdocs-item__title, .news-poll-card__question, .widget-latest-news__title', '16'],
        'fs_card_text' => ['Текст карточек и этапов', '.feature-card__text, .stage__text, .stage-item__text, .act-card__desc, .doc-card__desc, .repo-card__desc, .news-card__desc, .news-card__excerpt, .project-card__desc, .relnews-card__excerpt, .imgcard__desc, .catcard__excerpt, .timeline-item__text, .featband__text, .bio-career__text, .newsdetail-timeline__desc, .newsdetail-points__item, .faq-item__a, .contact-card__item, .block-map__card-address', '14'],
        'fs_meta' => ['Метаданные и подписи', '.crumbs, .content-crumbs, .content-card__meta, .content-detail__date, .stage__year, .stage__label, .act-card__number, .act-card__date, .act-card__meta, .news-card__date, .news-card__meta, .project-card__meta, .album-card__meta, .relnews-card__date, .adjnews__date, .newsdocs-item__date, .doc-card__meta, .catcard__created, .catcard__meta-item, .catcard__file, .catdetail__date, .newsdetail__meta, .newsdetail__source, .newsdetail-gallery__caption, .newsdetail-timeline__date, .newsdetail-event__label, .newsdetail-doc__meta, .newsdetail-points__number, .bio-career__years, .bio-edu__years, .bio-edu__org, .block-text__media-caption, .article-media__caption, .article-media__credit, .media-caption, .gcal-list__time, .gcal-list__loc, .icon-text__label', '13'],
        'fs_small' => ['Мелкий и вспомогательный текст', 'small, .form-hint, .section-head__eyebrow, .block-hero__eyebrow, .content-badge, .newsdetail__badge, .news-badge, .faq-item__category, .search-suggest__type, .search-suggest__meta, .site-search-results__type, .news-poll-card__badge, .news-poll-card__meta', '13'],
        'fs_btn' => ['Кнопки и ссылки-действия', '.block-cta__button, .block-banner__button, .block-hero__button, .timeline-card__button, .timeline-cta__button, .ctaband__button, .newsdetail__btn, .newsdetail__reader-btn, .newsdetail-dl-btn, .doc-card__action, .act-card__action, .catcard__more, .content-toolbar__reset, .news-card__more, .block-map__card-link, .section-head__all, .gcal-nav__all, .btn-cta, .btn, button, input[type="button"], input[type="submit"]', '15'],
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

        $base = (float) (preg_replace('/[^0-9.]/', '', DesignSettings::fontSizeCustom()) ?: 0);
        if ($base <= 0) {
            $base = (float) (['sm' => 15, 'md' => 16, 'lg' => 17, 'xl' => 18][DesignSettings::current()['font_size'] ?? 'md'] ?? 16);
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

    /**
     * Только ручные переопределения — для формы в админке.
     *
     * @return array<string,string>
     */
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
        return CssValue::pixels($raw, 8, 96);
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
}
