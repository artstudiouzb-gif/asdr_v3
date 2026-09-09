<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Цвет текста секции и вложенных в неё компонентов.
 *
 * Раньше «Светлый текст» и пресет navy красили содержимое секции напрямую
 * (`#block-7 h3 { color:#fff }`), и правило добивало до любого потомка — в том
 * числе до заголовка внутри белой карточки. На navy-секции карточки получали
 * белый текст на белом фоне.
 *
 * Теперь цвет секции — это переменные (`--section-fg`, `--section-title-fg`,
 * `--section-muted-fg`, `--section-link-fg`), а компонент со своей
 * поверхностью объявляет их заново для своего поддерева (список — SURFACES,
 * правило живёт в gov-theme.css). Спор специфичностей при этом не возникает
 * вовсе: секция и карточка — разные элементы, каждый задаёт переменные себе,
 * а наследование делает остальное.
 *
 * Два уровня настройки разведены:
 *   `_bg_text_scheme`  — цвет текста самой секции (auto|light|dark|custom);
 *   `_bg_card_scheme`  — цвет вложенных карточек (auto|light|dark), где
 *                        auto означает «карточка сохраняет свою схему».
 */
final class SectionColors
{
    public const TEXT_SCHEMES = ['auto', 'light', 'dark', 'custom'];
    public const CARD_SCHEMES = ['auto', 'light', 'dark'];

    /**
     * Компоненты, у которых есть собственная поверхность и собственная
     * цветовая схема. Список собран по факту: это все классы публичного CSS,
     * которые сами задают себе непрозрачную заливку.
     *
     * Тест сверяет его с правилом в gov-theme.css — разъехаться они не должны.
     *
     * @var list<string>
     */
    public const SURFACES = [
        '.act-card',
        '.album-card',
        '.block-advantages__item',
        '.block-testimonials__item',
        '.cat-tile',
        '.catcard',
        '.catdetail__card',
        '.contact-card',
        '.content-card',
        '.doc-card',
        '.faq-item',
        '.feature-card',
        '.leader-card',
        '.mediacard',
        '.news-card',
        '.news-poll-card',
        '.newsdetail-card',
        '.newslist-lead',
        '.person-card',
        '.project-card',
        '.relnews-card',
        '.site-search-results__item',
        '.social-embed-card',
        '.team-card',
        '.testimonial',
        '.timeline-card',
        '.widget--style-card',
    ];

    /** Светлый текст: значения переменных для тёмного фона. */
    private const LIGHT = [
        '--section-fg' => 'rgba(255,255,255,.92)',
        '--section-title-fg' => '#fff',
        '--section-muted-fg' => 'rgba(255,255,255,.82)',
        '--section-link-fg' => '#fff',
    ];

    /** Тёмный текст: пригодится на светлой фотографии и на своём светлом цвете. */
    private const DARK = [
        '--section-fg' => 'var(--text-primary)',
        '--section-title-fg' => 'var(--gov-title)',
        '--section-muted-fg' => 'var(--text-muted)',
        '--section-link-fg' => 'var(--gov-teal-text)',
    ];

    /**
     * Цветовая схема текста секции с учётом старых данных.
     *
     * До появления настройки был флажок «Светлый текст» (`_bg_light_text`).
     * Записи с ним читаются как `light`, иначе сохранённые страницы разом
     * потеряли бы светлый текст на тёмных фонах.
     *
     * @param array<string, mixed> $data
     */
    public static function textScheme(array $data): string
    {
        $scheme = (string) ($data['_bg_text_scheme'] ?? '');
        if (in_array($scheme, self::TEXT_SCHEMES, true)) {
            return $scheme;
        }

        return !empty($data['_bg_light_text']) ? 'light' : 'auto';
    }

    /** @param array<string, mixed> $data */
    public static function cardScheme(array $data): string
    {
        $scheme = (string) ($data['_bg_card_scheme'] ?? '');

        return in_array($scheme, self::CARD_SCHEMES, true) ? $scheme : 'auto';
    }

    /**
     * Scoped CSS секции: цвет её текста и, если редактор попросил, цвет
     * вложенных карточек.
     *
     * @param array<string, mixed> $data
     */
    public static function build(array $data, int $blockId): string
    {
        $scope = '#block-' . $blockId;
        $css = self::textCss($data, $scope);
        $cards = self::cardCss($data, $scope);

        if ($cards !== '') {
            $css .= ($css !== '' ? "\n" : '') . $cards;
        }

        return $css;
    }

    /**
     * Цвет текста самой секции. Правила перечисляют элементы явно: тело текста
     * во многих компонентах красится своим `color` (--text-muted,
     * .rich-content), и одного `color` на секции ему мало — абзац остался бы
     * тёмно-серым на тёмном фоне.
     *
     * @param array<string, mixed> $data
     */
    private static function textCss(array $data, string $scope): string
    {
        $scheme = self::textScheme($data);
        if ($scheme === 'auto') {
            return '';
        }

        if ($scheme === 'custom') {
            $color = (string) ($data['_bg_text_color'] ?? '');
            if (preg_match('/^#[0-9a-f]{6}$/i', $color) !== 1) {
                return '';
            }
            $vars = [
                '--section-fg' => strtolower($color),
                '--section-title-fg' => strtolower($color),
                // Приглушённый тон того же цвета: отдельной настройки на него
                // нет, а одинаковый цвет у заголовка и подписи стирает разницу
                // между ними.
                '--section-muted-fg' => 'color-mix(in srgb, ' . strtolower($color) . ' 82%, transparent)',
                '--section-link-fg' => strtolower($color),
            ];
        } else {
            $vars = $scheme === 'light' ? self::LIGHT : self::DARK;
        }

        $css = $scope . '{' . self::declarations($vars) . 'color:var(--section-fg)}'
            . "\n" . $scope . ' ' . self::TITLE_SELECTOR . '{color:var(--section-title-fg)}'
            . "\n" . $scope . ' ' . self::BODY_SELECTOR . '{color:var(--section-muted-fg)}'
            . "\n" . $scope . ' ' . self::LINK_SELECTOR . '{color:var(--section-link-fg)}';

        // Карточки темы полупрозрачны (стекло с размытием). На светлой странице
        // это читается как белая карточка, а на тёмной секции подложка
        // просвечивает и даёт серо-синий тон, на котором тёмный текст карточки
        // теряет контраст. На тёмной секции делаем поверхность непрозрачной:
        // читаемость важнее эффекта.
        if ($scheme === 'light' && self::cardScheme($data) === 'auto') {
            $css .= "\n" . $scope . ' ' . self::surfaceSelector() . '{background-color:var(--gov-surface)}';
        }

        return $css;
    }

    /**
     * Цвет вложенных карточек. `auto` ничего не печатает: карточка и так
     * объявляет переменные себе (правило в gov-theme.css), и это ровно то
     * поведение, ради которого настройка сделана — белая карточка внутри
     * тёмной секции остаётся с тёмным текстом.
     *
     * @param array<string, mixed> $data
     */
    private static function cardCss(array $data, string $scope): string
    {
        $scheme = self::cardScheme($data);
        if ($scheme === 'auto') {
            return '';
        }

        $surfaces = $scope . ' ' . self::surfaceSelector();
        if ($scheme === 'light') {
            return $surfaces . '{' . self::declarations(self::DARK) . 'background:#fff;color:var(--section-fg)}';
        }

        // Тёмные карточки: не сплошная заливка, а полупрозрачное осветление
        // поверх фона секции — так карточка читается и на цвете, и на фото.
        return $surfaces . '{' . self::declarations(self::LIGHT)
            . 'background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.18);color:var(--section-fg)}';
    }

    /** `:is(.card-a, .card-b, …)` — один селектор на весь список поверхностей. */
    public static function surfaceSelector(): string
    {
        return ':is(' . implode(',', self::SURFACES) . ')';
    }

    /** Заголовки: и теги, и компонентные классы вида `__title`. */
    private const TITLE_SELECTOR = ':is(h1,h2,h3,h4,h5,h6,[class$="__title"],[class*="__title "])';

    /** Тело текста: абзацы, списки, таблицы и компонентные классы описаний. */
    private const BODY_SELECTOR = ':is(p,li,dd,dt,td,th,blockquote,figcaption,.rich-content,'
        . '[class*="__text"],[class*="__desc"],[class*="__lead"],[class*="__excerpt"],'
        . '[class*="__body"],[class*="__note"])';

    private const LINK_SELECTOR = ':is(a,.section-head__all,[class*="__more"],[class*="__link"])';

    /** @param array<string, string> $vars */
    private static function declarations(array $vars): string
    {
        $out = '';
        foreach ($vars as $name => $value) {
            $out .= $name . ':' . $value . ';';
        }

        return $out;
    }
}
