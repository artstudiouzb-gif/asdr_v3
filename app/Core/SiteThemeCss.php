<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Builds the validated, database-driven CSS layer for the public site.
 *
 * The result is published by GeneratedCss, not embedded into page markup.
 */
final class SiteThemeCss
{
    /** @param array<string, string> $designValues
     *  @param array<string, mixed> $headerConfig
     */
    public static function build(array $designValues, array $headerConfig, bool $transparentHeader): string
    {
        $primary = (string) Setting::get('color_primary', '#0F2B46');
        $accent = (string) Setting::get('color_accent', '#009BBE');
        $colors = DesignSettings::semanticColors();
        $spacings = DesignSettings::semanticSpacings();
        ['body' => $font, 'heading' => $heading] = self::fontStacks();
        $styles = (array) ($headerConfig['styles'] ?? []);
        $cardHoverLift = max(0, min(20, (int) ($designValues['card_hover_lift'] ?? DesignSettings::cardHoverLift())));
        $cardHoverTranslate = $cardHoverLift === 0 ? '0px' : '-' . $cardHoverLift . 'px';

        $variables = [
            '--color-primary' => $primary,
            '--color-accent' => $accent,
            '--gov-navy' => 'var(--color-primary)',
            '--gov-teal' => 'var(--color-accent)',
            '--gov-teal-text' => AccentContrast::onLight($accent, $colors['bg_surface']),
            // Тот же акцент под отдельным именем. `--gov-teal-text` тёмная
            // секция переопределяет на светлый вариант, и белая карточка
            // внутри неё унаследовала бы его — на белом он нечитаем. Это имя
            // секции не трогают, поэтому карточка берёт по нему свой цвет
            // (App\Core\SectionColors::SURFACES в gov-theme.css).
            '--gov-teal-on-light' => AccentContrast::onLight($accent, $colors['bg_surface']),
            '--gov-teal-on-dark' => AccentContrast::onDark($accent),
            // Текст на подложке, подмешанной из самого акцента (плашки тегов,
            // номера тезисов, бейджи опроса). Такая подложка темнее и
            // «акцентнее» поверхности, поэтому --gov-teal-text на ней не
            // добирает до 4.5:1: axe ловил 2.71–4.41. Считаем отдельный
            // вариант ровно от того фона, на котором текст и лежит.
            // Для тёмной темы отдельный расчёт не нужен: там подложка светлее
            // фона, и подходит уже готовый --gov-teal-on-dark.
            '--gov-teal-tint-light' => AccentContrast::onLight(
                $accent,
                self::mixHex($accent, $colors['bg_surface'], 0.13)
            ),
            // Текст поверх заливки акцентом: белый читается не на всяком
            // акценте, поэтому цвет выбирается по контрасту, а не на глаз.
            '--on-accent' => AccentContrast::onFill($accent),
            // Заливка акцентом, на которой гарантированно читается БЕЛЫЙ.
            // Нужна там, где белый текст — требование оформления, а не выбор
            // по контрасту (активная вкладка карточки руководителя). Берём
            // вариант акцента, добирающий 4.5:1 к белому: раз пара проходит в
            // одну сторону, она проходит и в другую. Подбирать оттенок руками
            // нельзя — акцент задаётся в админке.
            '--accent-fill-strong' => AccentContrast::onLight($accent, '#ffffff'),
            '--bg-primary' => $colors['bg_primary'],
            '--bg-surface' => $colors['bg_surface'],
            '--text-main' => $colors['text_main'],
            '--text-muted' => $colors['text_muted'],
            '--border-color' => $colors['border_color'],
            '--gov-bg' => 'var(--bg-primary)',
            '--gov-surface' => 'var(--bg-surface)',
            '--gov-ink' => 'var(--text-main)',
            '--gov-muted' => 'var(--text-muted)',
            '--gov-border' => 'var(--border-color)',
            '--space-small' => $spacings['space_small'],
            '--space-premium' => $spacings['space_premium'],
            '--space-max' => $spacings['space_max'],
            '--feature-card-hover-lift' => $cardHoverTranslate,
            '--font-family' => $font,
            '--font-heading' => $heading,
            // Рукописная роль объявляется всегда: не выбран шрифт — переменная
            // повторяет заголовочный стек. Так `.tx-mark` и подпись не остаются
            // с несуществующим семейством и не откатываются на курсив браузера.
            '--font-script' => DesignSettings::scriptFontStack() !== ''
                ? DesignSettings::scriptFontStack()
                : $heading,
            '--header-logo-width' => (int) ($headerConfig['logo_width'] ?? 240) . 'px',
            '--header-logo-height' => (int) ($headerConfig['logo_height'] ?? 48) . 'px',
            '--header-elements-gap' => self::elementsGap((string) ($styles['elements_gap'] ?? 'normal')),
            '--header-floating-radius' => (int) ($styles['floating_radius'] ?? 18) . 'px',
            '--header-floating-opacity' => (string) round(
                max(5, min(100, (int) ($styles['floating_opacity'] ?? 25))) / 100,
                2
            ),
            '--header-floating-angle' => (int) ($styles['floating_gradient_angle'] ?? 135) . 'deg',
            '--header-floating-blur' => (int) ($styles['floating_blur'] ?? 14) . 'px',
            '--hoverline-bottom' => self::hoverlineOffset((string) ($styles['hoverline_offset'] ?? 'normal')),
            '--hoverline-height' => self::hoverlineThickness((string) ($styles['hoverline_thickness'] ?? '2px')),
            '--hoverline-inset' => self::hoverlineInset((string) ($styles['hoverline_length'] ?? 'normal')),
            '--submenu-width' => self::submenuWidth((string) ($styles['submenu_width'] ?? 'normal')),
            '--submenu-font-size' => self::submenuFontSize((string) ($styles['submenu_font_size'] ?? '13.8')),
            '--submenu-padding-y' => self::submenuPadding((string) ($styles['submenu_padding'] ?? 'normal')),
            '--submenu-text-transform' => self::submenuTransform((string) ($styles['submenu_transform'] ?? 'none')),
            '--submenu-radius' => self::submenuRadius((string) ($styles['submenu_radius'] ?? 'soft')),
            '--submenu-shadow' => self::submenuShadow((string) ($styles['submenu_shadow'] ?? 'soft')),
        ];

        // Пустые значения намеренно не публикуем: у обычного и premium-макета
        // разные адаптивные fallback-отступы, которые должны сохраниться до
        // явной настройки администратора.
        $newsPaddingTop = DesignSettings::newsDetailPaddingTop();
        if ($newsPaddingTop !== '') {
            $variables['--newsdetail-padding-top'] = $newsPaddingTop;
        }
        $newsPaddingBottom = DesignSettings::newsDetailPaddingBottom();
        if ($newsPaddingBottom !== '') {
            $variables['--newsdetail-padding-bottom'] = $newsPaddingBottom;
        }
        $metaLetterSpacing = DesignSettings::metaLetterSpacingCustom();
        if ($metaLetterSpacing !== '') {
            $variables['--meta-letter-spacing'] = $metaLetterSpacing;
        }

        foreach (DesignSettings::TYPO_SIZES as $key => $meta) {
            $manual = DesignSettings::normalizeFsSize((string) \App\Models\Setting::get('design_' . $key, ''));
            $val = $manual !== '' ? $manual : ($meta[2] . 'px');
            $variables['--font-size-' . str_replace('_', '-', substr($key, 3))] = $val;
        }

        foreach ([
            '--header-mid-bg' => (string) ($headerConfig['middlebar']['bg'] ?? ''),
            '--header-nav-bg' => (string) ($headerConfig['bottombar']['bg'] ?? ''),
            '--menu-color' => (string) ($styles['nav_color'] ?? ''),
            '--menu-hover' => (string) ($styles['nav_hover'] ?? ''),
            '--menu-active' => (string) ($styles['nav_active'] ?? ''),
            '--menu-color-transparent' => (string) ($styles['nav_color_transparent'] ?? ''),
            '--menu-hover-transparent' => (string) ($styles['nav_hover_transparent'] ?? ''),
            '--menu-active-transparent' => (string) ($styles['nav_active_transparent'] ?? ''),
            '--menu-pill-bg' => (string) ($styles['nav_pill_bg'] ?? ''),
            '--submenu-bg' => (string) ($styles['submenu_bg'] ?? ''),
            '--submenu-color' => (string) ($styles['submenu_color'] ?? ''),
            '--submenu-hover' => (string) ($styles['submenu_hover'] ?? ''),
            '--submenu-divider-color' => (string) ($styles['submenu_divider_color'] ?? ''),
        ] as $name => $value) {
            if ($value !== '') {
                $variables[$name] = $value;
            }
        }
        // Своя эмблема из настроек дизайна. Она работает трафаретом (CSS-маской),
        // поэтому от файла нужна только форма — цвет даёт тема. Пусто или
        // небезопасный адрес — остаётся эмблема из стилей.
        // Берём только свой адрес: знак с чужого домена — это и сторонний
        // запрос с каждой страницы, и лишняя дыра в CSP.
        $emblem = trim((string) Setting::get('design_emblem', ''));
        if ($emblem !== '' && str_starts_with($emblem, '/') && UrlGuard::isSafeMedia($emblem)) {
            // Кавычки, скобки и пробелы в адресе не отбрасываем вместе с
            // эмблемой, а кодируем: из-за них знак молча пропадал у файла с
            // «неудобным» именем.
            $variables['--gov-emblem'] = 'url("' . Emblem::cssUrl($emblem) . '")';
        }

        if (!empty($headerConfig['shadow']['enabled']) && !$transparentHeader) {
            $variables['--header-shadow-size'] = (int) ($headerConfig['shadow']['size'] ?? 14) . 'px';
        }

        $css = ':root{' . implode('', array_map(
            static fn (string $name, string $value): string => $name . ':' . $value . ';',
            array_keys($variables),
            array_values($variables)
        )) . "}\n";
        $css .= DesignSettings::cssVariables($designValues) . "\n";
        $css .= self::headerRules($styles);
        // Фон подвала настраивается в его конструкторе теми же режимами, что и
        // фон секции страницы; правило живёт здесь, потому что подвал общий.
        $footerBackground = BlockBackground::cssFor(
            '.site-footer',
            (array) (FooterConfig::get()['background'] ?? [])
        );
        if ($footerBackground !== '') {
            $css .= $footerBackground . "\n";
        }
        $css = self::fontFaceRule() . $css;

        $globalCss = (string) Setting::get('custom_css_global', '');
        if (trim($globalCss) !== '') {
            $css .= "\n" . $globalCss . "\n";
        }

        return $css;
    }

    /** @param array<string, mixed> $headerConfig */
    public static function headerClasses(array $headerConfig, bool $transparentHeader): string
    {
        return (($headerConfig['middlebar']['bg'] ?? '') !== '' ? ' site-header--mid-bg' : '')
            . (($headerConfig['bottombar']['bg'] ?? '') !== '' ? ' site-header--nav-bg' : '')
            . (!empty($headerConfig['shadow']['enabled']) && !$transparentHeader ? ' site-header--shadow' : '');
    }

    /** @param array<string, mixed> $styles */
    private static function headerRules(array $styles): string
    {
        $navGap = max(0, min(64, (int) ($styles['nav_gap'] ?? 18)));
        $dividerWidth = max(1, min(10, (int) ($styles['nav_divider_width'] ?? 1)));
        $dividerHeight = max(4, min(64, (int) ($styles['nav_divider_height'] ?? 18)));
        $dividerColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($styles['nav_divider_color'] ?? ''))
            ? strtolower((string) $styles['nav_divider_color'])
            : 'color-mix(in srgb,currentColor 35%,transparent)';
        $transparentDividerColor = preg_match(
            '/^#[0-9a-fA-F]{6}$/',
            (string) ($styles['nav_divider_color_transparent'] ?? '')
        ) ? strtolower((string) $styles['nav_divider_color_transparent']) : 'rgba(255,255,255,.45)';
        $rules = ':root{--menu-gap:' . $navGap . 'px;--menu-divider-width:' . $dividerWidth
            . 'px;--menu-divider-height:' . $dividerHeight . 'px;--menu-divider-color:'
            . $dividerColor . ';--menu-divider-color-transparent:' . $transparentDividerColor . ";}\n";
        foreach ([
            'nav_color' => '.site-menu__link{color:%s !important;}',
            'nav_hover' => '.site-menu__link:hover{color:%s !important;}',
            'nav_active' => '.site-menu__link.is-active,.site-menu__link[aria-current="page"]{color:%s !important;}',
            'topbar_bg' => '.site-topbar{background-color:%s !important;}',
            'topbar_text' => '.site-topbar,.site-topbar a{color:%s !important;}',
            'border_color' => '.site-header{border-bottom-color:%s !important;}',
        ] as $key => $template) {
            $value = (string) ($styles[$key] ?? '');
            if ($value !== '') {
                $rules .= sprintf($template, $value) . "\n";
            }
        }
        $borderWidth = (string) ($styles['border_width'] ?? '');
        if ($borderWidth !== '') {
            $rules .= '.site-header{border-bottom-width:' . $borderWidth
                . " !important;border-bottom-style:solid;}\n";
        }

        return $rules;
    }

    private static function fontFaceRule(): string
    {
        if (DesignSettings::bodyFontChoice() !== 'style:custom') {
            return '';
        }
        $url = (string) Setting::get('font_url', '');
        $name = preg_replace(
            '/[^a-zA-Z0-9 _-]/',
            '',
            trim((string) Setting::get('font_face_name', ''))
        ) ?? '';
        if ($url === '' || $name === '' || !UrlGuard::isSafeLink($url)) {
            return '';
        }
        $url = str_replace(["\\", "'", "\r", "\n"], ["\\\\", "\\'", '', ''], $url);

        return "@font-face{font-family:'{$name}';src:url('{$url}') format('woff2');"
            . "font-weight:100 900;font-display:swap;}\n";
    }

    /**
     * Семейства, для которых в gov-fonts.css объявлено запасное начертание с
     * подогнанными метриками. Пока веб-шрифт грузится (font-display: swap),
     * текст рисуется системным, и в момент подмены строка меняет ширину —
     * макет дёргается. Без правки расхождение доходит до 9,4 % по ширине,
     * с ней — 0,6 % (замерено в браузере).
     */
    private const METRIC_FALLBACKS = ['PT Sans', 'PT Serif', 'Manrope', 'Montserrat', 'Inter Tight', 'Inter', 'Noto Serif'];

    /** Пара по умолчанию: Inter — текст, Noto Serif — заголовки. Обе в поставке. */
    public const DEFAULT_BODY_FONT = "'Noto Sans', 'Noto Sans Fallback', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
    public const DEFAULT_HEADING_FONT = "'Noto Serif', 'Noto Serif Fallback', Georgia, 'Times New Roman', serif";

    /**
     * Единственный источник шрифтовых стеков сайта.
     *
     * До этого умолчания были в двух местах и различались: SiteThemeCss рисовал
     * страницу одним семейством, а шапка предзагружала файлы другого. Сайт
     * тянул два шрифта, которыми ничего не набрано, — около 49 КБ на каждую
     * загрузку, и они конкурировали с картинкой первого экрана.
     *
     * @return array{body: string, heading: string}
     */
    public static function fontStacks(): array
    {
        $body = self::fontValue(
            (string) Setting::get('font_family', self::DEFAULT_BODY_FONT),
            self::DEFAULT_BODY_FONT
        );
        $heading = (string) Setting::get('font_heading', self::DEFAULT_HEADING_FONT);
        $heading = self::fontValue($heading !== '' ? $heading : $body, self::DEFAULT_HEADING_FONT);

        return ['body' => $body, 'heading' => $heading];
    }

    /**
     * Смешивает два цвета в sRGB — то же, что делает color-mix() в CSS.
     * Нужен, чтобы посчитать контраст относительно фактической подложки,
     * а не относительно поверхности под ней.
     */
    private static function mixHex(string $top, string $bottom, float $share): string
    {
        $share = max(0.0, min(1.0, $share));
        [$tr, $tg, $tb] = AccentContrast::toRgb($top);
        [$br, $bg, $bb] = AccentContrast::toRgb($bottom);

        return AccentContrast::toHex([
            (int) round($tr * $share + $br * (1 - $share)),
            (int) round($tg * $share + $bg * (1 - $share)),
            (int) round($tb * $share + $bb * (1 - $share)),
        ]);
    }

    private static function fontValue(string $value, string $fallback): string
    {
        $clean = preg_replace("/[^a-zA-Z0-9 ,'\\-]/", '', $value) ?: $fallback;

        return self::withMetricFallback($clean);
    }

    /**
     * Дописывает запасное начертание сразу после основного семейства.
     * Делается на рендере, а не только в значениях по умолчанию: у уже
     * установленных сайтов стек лежит в настройках, и правка дефолтов их
     * не затронула бы.
     */
    public static function withMetricFallback(string $stack): string
    {
        foreach (self::METRIC_FALLBACKS as $family) {
            $fallback = $family . ' Fallback';
            if (str_contains($stack, $fallback)) {
                return $stack;
            }
            // Семейство должно быть первым в стеке — только тогда подмена
            // идёт именно с него, и подгонять метрики имеет смысл.
            foreach (["'{$family}'", "\"{$family}\"", $family] as $needle) {
                if (str_starts_with(ltrim($stack), $needle)) {
                    $pos = strpos($stack, $needle) + strlen($needle);

                    return substr($stack, 0, $pos) . ", '{$fallback}'" . substr($stack, $pos);
                }
            }
        }

        return $stack;
    }

    private static function elementsGap(string $value): string
    {
        return match ($value) {
            'ultra_compact' => '6px',
            'compact' => '10px',
            'spacious' => '28px',
            'loose' => '38px',
            default => '18px',
        };
    }

    private static function hoverlineOffset(string $value): string
    {
        return match ($value) {
            'close', '1px' => '1px',
            'far', '8px' => '8px',
            default => is_numeric($value) ? (int) $value . 'px' : '4px',
        };
    }

    private static function hoverlineThickness(string $value): string
    {
        return match ($value) {
            'thin', '1px' => '1px',
            'normal', '2px' => '2px',
            'thick', '3px' => '3px',
            'heavy', '4px' => '4px',
            '5px' => '5px',
            '6px' => '6px',
            '8px' => '8px',
            default => is_numeric($value) ? (int) $value . 'px' : '2px',
        };
    }

    private static function hoverlineInset(string $value): string
    {
        return match ($value) {
            'compact', '12px' => '12px',
            'full', '0px' => '0px',
            default => is_numeric($value) ? (int) $value . 'px' : '4px',
        };
    }

    private static function submenuWidth(string $value): string
    {
        return match ($value) {
            'compact' => '220px',
            'wide' => '320px',
            default => '260px',
        };
    }

    private static function submenuFontSize(string $value): string
    {
        $legacy = ['compact' => '12.5', 'normal' => '13.8', 'large' => '15.2'];
        $value = $legacy[$value] ?? $value;
        if (!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/', $value)) {
            return '13.8px';
        }

        return max(10, min(24, (float) $value)) . 'px';
    }

    private static function submenuPadding(string $value): string
    {
        return match ($value) {
            'compact' => '8px',
            'spacious' => '14px',
            default => '11px',
        };
    }

    private static function submenuTransform(string $value): string
    {
        return match ($value) {
            'uppercase' => 'uppercase',
            'capitalize' => 'capitalize',
            default => 'none',
        };
    }

    private static function submenuRadius(string $value): string
    {
        return match ($value) {
            'none' => '0px',
            'rounded' => '16px',
            default => '10px',
        };
    }

    private static function submenuShadow(string $value): string
    {
        return match ($value) {
            'none' => 'none',
            'deep' => '0 24px 60px rgba(15,23,42,.18),0 6px 18px rgba(15,23,42,.08)',
            default => '0 18px 42px rgba(15,23,42,.11),0 3px 10px rgba(15,23,42,.05)',
        };
    }
}
