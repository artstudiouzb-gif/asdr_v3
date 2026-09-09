<?php

declare(strict_types=1);

namespace App\Core\Hero;

use App\Core\AccentContrast;
use App\Core\Icon;
use App\Core\TitleMarkup;
use App\Core\UrlGuard;

/**
 * Разметка обложки: слои фона, контент, навигация.
 *
 * Порядок слоёв фиксирован и держится z-index'ами, а не порядком в потоке:
 *
 *     фон (картинка / видео / YouTube) → затемнение → контент → кнопки
 *     → навигация → индикатор
 *
 * Фон вынут из потока (`position:absolute`) — он не влияет ни на высоту
 * обложки, ни на ширину страницы. Навигация лежит отдельной полосой внизу, а
 * контенту снизу зарезервировано место под неё: перекрывать текст и кнопки
 * стрелками нельзя.
 *
 * Картинка-замена (`hero__fallback`) есть у КАЖДОГО слайда с видео и лежит под
 * ним всегда. Поэтому «видео не загрузилось», «автовоспроизведение запрещено»,
 * «видео выключено на телефоне» и «включено меньше движения» — это не четыре
 * разных сценария с отдельной обработкой, а один: скрыть видео, под ним уже
 * готовый кадр. Пустой обложки не бывает.
 */
final class HeroRenderer
{
    /** Готовые цветовые схемы: фон и цвет собственного текста обложки. */
    private const SCHEME_COLORS = [
        'light' => ['bg' => '#f2f5f9', 'fg' => '#101a2b'],
        'dark' => ['bg' => '#0b1220', 'fg' => '#ffffff'],
        'navy' => ['bg' => 'var(--gov-navy, #173a63)', 'fg' => '#ffffff'],
    ];

    private const OVERLAY_ANGLES = [
        'to_right' => '90deg',
        'to_left' => '270deg',
        'to_bottom' => '180deg',
        'to_top' => '0deg',
        'to_bottom_right' => '135deg',
        'to_bottom_left' => '225deg',
        'to_top_right' => '45deg',
        'to_top_left' => '315deg',
    ];

    /** Высоты секции: пресет → значение min-height. */
    private const HEIGHTS = [
        'compact' => 'clamp(280px, 32vw, 420px)',
        'regular' => 'clamp(420px, 46vw, 620px)',
        'tall' => 'clamp(520px, 60vw, 780px)',
        'full' => '100dvh',
    ];

    private const HEIGHTS_MOBILE = [
        'compact' => 'clamp(220px, 52vw, 320px)',
        'regular' => 'clamp(320px, 76vw, 460px)',
        'tall' => 'clamp(420px, 96vw, 560px)',
        'full' => '100dvh',
    ];

    /**
     * @param array<string, mixed> $hero строка heroes
     * @param array<int, array<string, mixed>> $slides строки hero_slides (уже локализованные)
     * @param array<string, mixed> $settings общие настройки обложки
     * @return array{html: string, css: string, preload: string}
     */
    public static function render(array $hero, array $slides, array $settings, int $blockId, string $headingTag = 'h1'): array
    {
        $slides = array_values(array_filter(
            $slides,
            static fn (array $slide): bool => !HeroSlideData::isEmpty($slide['data'])
        ));
        if ($slides === []) {
            return ['html' => '', 'css' => '', 'preload' => ''];
        }

        $scope = '#block-' . $blockId;
        $count = count($slides);
        $css = self::rootCss($scope, $settings);
        $slidesHtml = '';
        $preload = '';

        foreach ($slides as $index => $slide) {
            $data = $slide['data'];
            [$slideHtml, $slideCss] = self::slide(
                $data,
                $index,
                $count,
                $settings,
                $scope,
                $index === 0 ? $headingTag : 'h2'
            );
            $slidesHtml .= $slideHtml;
            if ($slideCss !== '') {
                $css .= "\n" . $slideCss;
            }
            if ($index === 0) {
                // Один кандидат в LCP: первый кадр обложки. Остальные слайды
                // грузятся лениво — предзагружать их значило бы конкурировать
                // с CSS и шрифтами первого экрана.
                $preload = HeroSlideData::fallbackImage($data);
            }
        }

        $label = trim((string) ($hero['name'] ?? ''));
        // В aria-label уходит текст без звёздочек: диктору не нужна разметка.
        $firstTitle = trim(TitleMarkup::plain((string) ($slides[0]['data']['title'] ?? '')));
        if ($firstTitle !== '') {
            $label = $firstTitle;
        }

        $html = '<div class="' . self::rootClasses($settings, $count) . '"'
            . self::rootAttributes($settings, $count, $label)
            . '>' . HeroNavigation::render($slides, $settings, $count)
            . '<div class="hero__slides">' . $slidesHtml . '</div>'
            . '</div>';

        return ['html' => $html, 'css' => $css, 'preload' => $preload];
    }

    /** @param array<string, mixed> $s */
    private static function rootClasses(array $s, int $count): string
    {
        $classes = [
            'hero',
            'hero--w-' . $s['width'],
            'hero--h-' . $s['height'],
            'hero--scheme-' . $s['scheme'],
            'hero--tr-' . str_replace('_', '-', (string) $s['transition']),
            'hero--nav-' . str_replace('_', '-', (string) $s['nav_indicator']),
        ];
        if ($count > 1) {
            $classes[] = 'hero--carousel';
        }
        if ($s['height_mobile'] !== '') {
            $classes[] = 'hero--hm-' . $s['height_mobile'];
        }
        if (!$s['nav_arrows']) {
            $classes[] = 'hero--no-arrows';
        }
        if (!$s['nav_arrows_mobile']) {
            $classes[] = 'hero--no-arrows-mobile';
        }

        return implode(' ', $classes);
    }

    /** @param array<string, mixed> $s */
    private static function rootAttributes(array $s, int $count, string $label): string
    {
        $attrs = [
            'data-hero',
            'data-hero-transition="' . htmlspecialchars((string) $s['transition'], ENT_QUOTES) . '"',
            'data-hero-duration="' . (int) $s['transition_duration'] . '"',
            'data-hero-count="' . $count . '"',
        ];

        if ($count > 1) {
            $attrs[] = 'role="region"';
            $attrs[] = 'aria-roledescription="' . htmlspecialchars(t('Карусель'), ENT_QUOTES) . '"';
            $attrs[] = 'aria-label="' . htmlspecialchars($label !== '' ? $label : t('Обложка'), ENT_QUOTES) . '"';
            // Фокус на самой карусели — точка входа для стрелок клавиатуры.
            $attrs[] = 'tabindex="0"';
            if ($s['nav_swipe']) {
                $attrs[] = 'data-hero-swipe';
            }
            if ($s['autoplay']) {
                // Политикой паузы и мобильного запуска управляет runtime.
                $attrs[] = 'data-hero-autoplay="' . ((int) $s['autoplay_interval'] * 1000) . '"';
            }
        }

        return ' ' . implode(' ', $attrs);
    }

    /**
     * Переменные обложки. Всё, что может отличаться от слайда к слайду, здесь
     * задаёт лишь значение по умолчанию — слайд перекрывает его своим блоком.
     *
     * @param array<string, mixed> $s
     */
    private static function rootCss(string $scope, array $s): string
    {
        $vars = self::schemeVars((string) $s['scheme'], (string) $s['scheme_bg'], (string) $s['scheme_text'], (string) $s['scheme_accent']);
        $vars['--hero-overlay'] = self::overlayValue(
            (string) $s['overlay'],
            (string) $s['overlay_color'],
            (int) $s['overlay_opacity'],
            (string) $s['overlay_direction'],
            (string) $s['text_position']
        );
        $vars['--hero-panel-bg'] = $s['panel']
            ? 'rgba(' . self::rgb((string) $s['panel_color']) . ',' . self::alpha((int) $s['panel_opacity']) . ')'
            : 'transparent';
        $vars['--hero-duration'] = (int) $s['transition_duration'] . 'ms';
        $vars['--hero-title-size'] = 'var(--hero-title-' . $s['title_size'] . ')';
        $vars['--hero-subtitle-size'] = 'var(--hero-subtitle-' . $s['subtitle_size'] . ')';
        $vars['--hero-text-offset'] = (int) $s['text_offset_top'] . 'px';

        if ($s['height'] === 'custom' && $s['height_value'] !== '') {
            $vars['--hero-min-h'] = (string) $s['height_value'];
        } elseif (isset(self::HEIGHTS[$s['height']])) {
            $vars['--hero-min-h'] = self::HEIGHTS[$s['height']];
        }
        if ($s['text_width'] !== '') {
            $vars['--hero-text-width'] = (string) $s['text_width'];
        }

        $css = $scope . ' .hero{' . self::declarations($vars) . '}';

        // Телефон: своя высота — она задаётся не размером текста, а форматом
        // экрана. Размеры заголовка и подзаголовка мобильной настройки не
        // требуют: шкала --hero-title-* построена на clamp() с vw и на узком
        // экране сама садится на нижнюю ступень.
        $mobile = [];
        if ($s['height_mobile'] === 'custom' && $s['height_mobile_value'] !== '') {
            $mobile['--hero-min-h'] = (string) $s['height_mobile_value'];
        } elseif ($s['height_mobile'] !== '' && isset(self::HEIGHTS_MOBILE[$s['height_mobile']])) {
            $mobile['--hero-min-h'] = self::HEIGHTS_MOBILE[$s['height_mobile']];
        }
        // Отступ задан в пикселях по десктопу, и на телефоне крупное значение
        // выдавило бы текст за экран. Поэтому на узком экране он ограничен
        // десятой частью высоты окна: небольшие отступы не меняются вовсе,
        // а крайние сами становятся уместными — второй настройки не нужно.
        if ((int) $s['text_offset_top'] > 0) {
            $mobile['--hero-text-offset'] = 'min(' . (int) $s['text_offset_top'] . 'px,10vh)';
        }
        if ($mobile !== []) {
            $css .= "\n@media (max-width:720px){" . $scope . ' .hero{' . self::declarations($mobile) . '}}';
        }

        return $css;
    }

    /**
     * Один слайд: фон, затемнение, контент, кнопки.
     *
     * @param array<string, mixed> $d
     * @param array<string, mixed> $s
     * @return array{string, string}
     */
    private static function slide(array $d, int $index, int $count, array $s, string $scope, string $headingTag): array
    {
        $first = $index === 0;
        // Раскладка у всех слайдов общая: она принадлежит обложке, а не
        // отдельному кадру. Переопределения у слайда убраны — они удваивали
        // поверхность ошибок и почти не использовались.
        $position = (string) $s['text_position'];
        $alignY = (string) $s['text_align_y'];
        $panel = (bool) $s['panel'];
        $contentScheme = self::contentScheme($d, $s);

        $classes = [
            'hero__slide',
            'hero--pos-' . $position,
            'hero--y-' . $alignY,
            'hero--content-' . $contentScheme,
        ];
        if ($first) {
            $classes[] = 'is-active';
        }
        if ($panel) {
            $classes[] = 'hero__slide--panel';
        }
        // Свой класс редактора идёт последним: по нему пишут стили в «Свой
        // CSS» страницы, и он должен выигрывать у классов раскладки.
        if ((string) $d['css_class'] !== '') {
            $classes[] = (string) $d['css_class'];
        }

        $html = '<div class="' . implode(' ', $classes) . '" data-hero-slide data-hero-index="' . $index . '"'
            // Светлый ли кадр — нужно прозрачной шапке: её белое лого и меню
            // на светлом слайде пропадают.
            . ' data-hero-scheme="' . self::headerScheme($d, $s) . '"'
            // Своя длительность показа. Атрибута нет — слайд держится столько
            // же, сколько остальные (интервал обложки).
            . ((int) $d['duration'] > 0 ? ' data-hero-slide-duration="' . ((int) $d['duration'] * 1000) . '"' : '')
            . ' role="group" aria-roledescription="' . htmlspecialchars(t('Слайд'), ENT_QUOTES) . '"'
            . ' aria-label="' . ($index + 1) . ' ' . htmlspecialchars(t('из'), ENT_QUOTES) . ' ' . $count . '"'
            . '>'
            . HeroMediaRenderer::render($d, $first)
            . self::overlay($d, $s)
            . self::cover($d)
            . self::watermark($d)
            . '<div class="hero__inner"><div class="hero__text">'
            . self::art($d)
            . self::text($d, $headingTag)
            . self::actions($d)
            . '</div></div>'
            . '</div>';

        return [$html, self::slideCss($d, $s, $scope, $index, $position)];
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $s
     */
    private static function overlay(array $d, array $s): string
    {
        $mode = $d['overlay'] !== '' ? (string) $d['overlay'] : (string) $s['overlay'];

        return $mode === 'none' ? '' : '<div class="hero__overlay" aria-hidden="true"></div>';
    }

    /**
     * Ссылка-подложка: кликается весь слайд, но кнопки остаются отдельными
     * ссылками, а не вложенными в чужую (такая вложенность недопустима в HTML
     * и ломает переход по Tab).
     *
     * @param array<string, mixed> $d
     */
    private static function cover(array $d): string
    {
        $url = (string) $d['link_url'];
        if ($url === '' || !UrlGuard::isSafeLink($url)) {
            return '';
        }

        return '<a class="hero__cover" href="' . htmlspecialchars($url, ENT_QUOTES) . '"'
            . ($d['link_new_tab'] ? ' target="_blank" rel="noopener"' : '')
            . ' tabindex="-1" aria-hidden="true"></a>';
    }

    /** @param array<string, mixed> $d */
    private static function art(array $d): string
    {
        $image = (string) $d['art_image'];
        if ($image === '' || !UrlGuard::isSafeMedia($image)) {
            return '';
        }
        $alt = (string) $d['art_alt'];
        $size = (string) $d['art_size'];
        $width = match ($size) {
            'small' => 120,
            'large' => 360,
            'custom' => (int) $d['art_width'],
            default => 220,
        };

        // Explicit width keeps wide SVG/PNG logos stable in the auto grid
        // column. Height stays automatic, preserving the original ratio.
        return '<span class="hero__art hero__art--' . htmlspecialchars($size, ENT_QUOTES)
            . ' hero__art--' . htmlspecialchars((string) $d['art_position'], ENT_QUOTES) . '"'
            . ($alt === '' ? ' aria-hidden="true"' : '') . '>'
            . '<img src="' . htmlspecialchars($image, ENT_QUOTES) . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '"'
            . ' width="' . $width . '" loading="lazy" decoding="async"></span>';
    }
    /**
     * Фоновая надпись — крупное слово за контентом.
     *
     * Лежит между фоном и текстом и ничего не перехватывает: `aria-hidden`
     * убирает её у диктора (иначе он читает её посреди заголовка), а
     * `pointer-events: none` в CSS — у мыши, иначе слово шириной в экран
     * накрыло бы кнопки.
     *
     * @param array<string, mixed> $d
     */
    private static function watermark(array $d): string
    {
        $text = trim((string) $d['watermark']);
        if ($text === '') {
            return '';
        }

        return '<span class="hero__watermark hero__watermark--x-' . htmlspecialchars((string) $d['watermark_x'], ENT_QUOTES)
            . ' hero__watermark--y-' . htmlspecialchars((string) $d['watermark_y'], ENT_QUOTES) . '"'
            . ' aria-hidden="true">' . htmlspecialchars($text, ENT_QUOTES) . '</span>';
    }

    /** @param array<string, mixed> $d */
    private static function text(array $d, string $headingTag): string
    {
        $html = '';
        if ($d['eyebrow'] !== '') {
            $html .= '<span class="hero__eyebrow">' . htmlspecialchars((string) $d['eyebrow'], ENT_QUOTES) . '</span>';
        }
        if ($d['title'] !== '') {
            $title = TitleMarkup::html((string) $d['title']);
            $url = (string) $d['link_url'];
            if ($url !== '' && UrlGuard::isSafeLink($url)) {
                $title = '<a class="hero__title-link" href="' . htmlspecialchars($url, ENT_QUOTES) . '"'
                    . ($d['link_new_tab'] ? ' target="_blank" rel="noopener"' : '') . '>' . $title . '</a>';
            }
            $html .= '<' . $headingTag . ' class="hero__title">' . $title . '</' . $headingTag . '>';
        }
        if ($d['subtitle'] !== '') {
            $html .= '<p class="hero__subtitle">' . nl2br(htmlspecialchars((string) $d['subtitle'], ENT_QUOTES)) . '</p>';
        }

        return $html;
    }

    /** @param array<string, mixed> $d */
    private static function actions(array $d): string
    {
        $buttons = '';
        foreach (['cta', 'cta2'] as $key) {
            $buttons .= self::button($d, $key);
        }

        return $buttons === '' ? '' : '<div class="hero__actions">' . $buttons . '</div>';
    }

    /** @param array<string, mixed> $d */
    private static function button(array $d, string $key): string
    {
        if (empty($d[$key . '_enabled'])) {
            return '';
        }
        $text = trim((string) $d[$key . '_text']);
        $url = (string) $d[$key . '_url'];
        if ($text === '' || $url === '' || !UrlGuard::isSafeLink($url)) {
            return '';
        }

        // Значок кнопки — только иконка из набора Tabler. Своя картинка внутри
        // кнопки стоила трёх полей на кнопку (файл, режим, ширина) ради
        // случая, которого не встретилось: логотип в кнопке ломает высоту
        // строки и не переживает смену темы.
        $icon = (string) $d[$key . '_icon'];
        $iconHtml = $icon !== ''
            ? '<span class="hero__cta-icon" aria-hidden="true">' . Icon::render($icon, 20) . '</span>'
            : '';

        return '<a class="hero__cta hero__cta--' . htmlspecialchars((string) $d[$key . '_style'], ENT_QUOTES)
            . ($iconHtml !== '' ? ' hero__cta--with-icon' : '') . '"'
            . ' href="' . htmlspecialchars($url, ENT_QUOTES) . '"'
            . (!empty($d[$key . '_new_tab']) ? ' target="_blank" rel="noopener"' : '')
            . '>' . $iconHtml . '<span>' . htmlspecialchars($text, ENT_QUOTES) . '</span></a>';
    }
    /**
     * Переменные конкретного слайда: они нужны только там, где слайд отходит
     * от настроек обложки.
     *
     * @param array<string, mixed> $d
     * @param array<string, mixed> $s
     */
    private static function slideCss(array $d, array $s, string $scope, int $index, string $position): string
    {
        // Цвет и фон принадлежат обложке: у слайда своей схемы больше нет.
        // Кадр отличается фотографией и наложением, а не палитрой.
        $vars = [];

        $overlayMode = $d['overlay'] !== '' ? (string) $d['overlay'] : (string) $s['overlay'];
        if ($overlayMode !== 'none'
            && ($d['overlay'] !== '' || $d['overlay_color'] !== '' || $d['overlay_opacity'] >= 0 || $d['overlay_direction'] !== '')
        ) {
            $vars['--hero-overlay'] = self::overlayValue(
                $overlayMode,
                $d['overlay_color'] !== '' ? (string) $d['overlay_color'] : (string) $s['overlay_color'],
                $d['overlay_opacity'] >= 0 ? (int) $d['overlay_opacity'] : (int) $s['overlay_opacity'],
                $d['overlay_direction'] !== '' ? (string) $d['overlay_direction'] : (string) $s['overlay_direction'],
                $position
            );
        }
        if ($d['image_fit'] === 'contain') {
            $vars['--hero-fit'] = 'contain';
        }
        // Цвет кнопки и ссылки — свой у кадра. Надпись на заливке по-прежнему
        // считается по контрасту, а не выбирается: --on-accent объявлен от
        // акцента сайта, и без пересчёта на светлой кнопке остался бы белый
        // текст — тот же случай, что и у цвета кнопки самой обложки.
        if ($d['cta_color'] !== '') {
            $vars['--hero-accent'] = (string) $d['cta_color'];
            $vars['--on-accent'] = AccentContrast::onFill((string) $d['cta_color']);
        }
        if ($d['link_color'] !== '') {
            $vars['--hero-link'] = (string) $d['link_color'];
        }
        // Прозрачность фоновой надписи — своя у слайда, поэтому переменной в
        // scoped CSS: инлайн-стили в блоках запрещены.
        if (trim((string) $d['watermark']) !== '') {
            $vars['--hero-watermark-opacity'] = (string) round(((int) $d['watermark_opacity']) / 100, 3);
            $vars['--hero-watermark-size'] = (int) $d['watermark_size'] . 'vw';
        }

        // Своих переопределений по ширине экрана у слайда нет: раскладка и
        // размеры принадлежат обложке. Здесь оставались пустые каркасы —
        // слияние с пустым массивом и ветка @media, которая не выполнялась
        // никогда; мёртвый код читается как работающий приём.
        $selector = $scope . ' .hero__slide[data-hero-index="' . $index . '"]';

        return $vars === [] ? '' : $selector . '{' . self::declarations($vars) . '}';
    }

    /**
     * Цвет собственного текста обложки.
     *
     * `auto` смотрит на то, что реально окажется под текстом: фотография с
     * затемнением почти всегда тёмная, светлая схема без медиа — светлая.
     * Схема обложки при этом НЕ навязывает цвет вложенным компонентам: у них
     * своя поверхность и свой цвет (см. `.hero__surface` в hero.css).
     *
     * @param array<string, mixed> $d
     * @param array<string, mixed> $s
     */
    /**
     * С какой плотности наложение считается решающим: ниже неё сквозь вуаль
     * видно саму фотографию, и цвет по ней предсказать нельзя.
     */
    private const VEIL_DECIDES_FROM = 25;

    private static function contentScheme(array $d, array $s): string
    {
        $mode = $d['content_scheme'] !== '' ? (string) $d['content_scheme'] : (string) $s['content_scheme'];
        if ($mode === 'light' || $mode === 'dark') {
            return $mode;
        }

        // Свой цвет текста задан вручную — «авто» его не переспорит. Классы
        // hero--content-light/dark объявляют --hero-fg на .hero__text, то есть
        // глубже схемы, и затирали выбранный цвет: поле в форме было, а цвет
        // всегда выходил белым или тёмно-синим. Отдельное значение снимает
        // оба правила, и остаётся цвет из схемы.
        if (self::hasOwnTextColor($s)) {
            return 'custom';
        }

        // Плотное наложение перекрывает то, что под ним, и решает за кадр —
        // и за фотографию, и за заливку: светлая вуаль поверх navy-фона
        // осветляет его ровно так же, как осветляет снимок.
        $veilIsLight = self::veilIsLight($d, $s);
        if ($veilIsLight !== null) {
            return $veilIsLight ? 'dark' : 'light';
        }

        // Наложения нет или оно слабое: под ним видно сам фон, и цвет берётся
        // по схеме — на фотографию положиться нельзя, а схема предсказуема.
        return self::schemeIsDark($s) ? 'light' : 'dark';
    }

    /**
     * Задан ли цвет текста вручную: схема «Custom» обложки со своим цветом.
     *
     * @param array<string, mixed> $s
     */
    private static function hasOwnTextColor(array $s): bool
    {
        if ((string) $s['scheme'] !== 'custom') {
            return false;
        }

        $color = (string) $s['scheme_text'];

        return $color !== '';
    }

    /**
     * Светлое ли наложение поверх фона: `true` — осветляет кадр, `false` —
     * затемняет, `null` — наложения нет, оно слишком слабое, чтобы решать за
     * кадром, или это градиент там, где нужен ответ про всю ширину
     * (`$solidOnly`).
     *
     * @param array<string, mixed> $d
     * @param array<string, mixed> $s
     */
    private static function veilIsLight(array $d, array $s, bool $solidOnly = false): ?bool
    {
        $mode = $d['overlay'] !== '' ? (string) $d['overlay'] : (string) $s['overlay'];
        $opacity = $d['overlay_opacity'] >= 0 ? (int) $d['overlay_opacity'] : (int) $s['overlay_opacity'];
        if ($mode === 'none' || $opacity < self::VEIL_DECIDES_FROM) {
            return null;
        }
        if ($solidOnly && $mode !== 'solid') {
            return null;
        }

        $color = $d['overlay_color'] !== '' ? (string) $d['overlay_color'] : (string) $s['overlay_color'];

        return self::luminance($color) >= 0.5;
    }

    /**
     * Схема кадра для прозрачной шапки: `light` разрешает ей переключиться на
     * тёмный набор, `dark` оставляет светлый.
     *
     * Ответ `light` даётся только когда фон слайда — заливка. Под фотографией
     * или видео яркость верхней полосы не известна никому: схема красит
     * подложку, которой за картинкой не видно, и «светлая схема» на снимке
     * ночного города переключила бы шапку в тёмное по тёмному. Для медиа
     * работает затемняющая подложка шапки — она не зависит от кадра.
     *
     * @param array<string, mixed> $d
     * @param array<string, mixed> $s
     */
    private static function headerScheme(array $d, array $s): string
    {
        // Сплошное наложение кроет кадр целиком — по нему судить можно.
        // Градиент гуще там, где стоит текст, и к другому краю сходит на нет,
        // а шапка тянется во всю ширину: над прозрачным краем она получила бы
        // тёмный набор поверх неосветлённого снимка. Поэтому для шапки
        // градиент не в счёт — там работает её собственная подложка.
        $veilIsLight = self::veilIsLight($d, $s, true);
        if ($veilIsLight !== null) {
            return $veilIsLight ? 'light' : 'dark';
        }

        if ((string) $d['media_type'] !== 'none') {
            return 'dark';
        }

        // Под шапкой заливка обложки: своей схемы у слайда нет.
        return self::schemeIsDark($s) ? 'dark' : 'light';
    }

    /**
     * Тёмная ли заливка обложки. Схема принадлежит обложке целиком: у слайда
     * своей нет, он отличается кадром и наложением.
     *
     * @param array<string, mixed> $s
     */
    private static function schemeIsDark(array $s): bool
    {
        $scheme = (string) $s['scheme'];
        if ($scheme === 'custom') {
            return self::luminance((string) $s['scheme_bg']) < 0.5;
        }

        return $scheme !== 'light';
    }

    /**
     * @return array<string, string>
     */
    private static function schemeVars(string $scheme, string $bg, string $fg, string $accent): array
    {
        if ($scheme === 'custom') {
            $vars = [
                '--hero-bg' => $bg !== '' ? $bg : '#0b1a30',
                '--hero-fg' => $fg !== '' ? $fg : (self::luminance($bg) < 0.5 ? '#ffffff' : '#101a2b'),
            ];
        } else {
            $preset = self::SCHEME_COLORS[$scheme] ?? self::SCHEME_COLORS['navy'];
            $vars = ['--hero-bg' => $preset['bg'], '--hero-fg' => $preset['fg']];
        }
        // Акцент по умолчанию берётся из настроек «Дизайна»: фирменный цвет
        // задаётся в админке, а не прибивается в коде.
        $vars['--hero-accent'] = $accent !== '' ? $accent : 'var(--gov-teal)';

        // Надпись на основной кнопке считается по контрасту от её заливки, а не
        // настраивается отдельно. `--on-accent` объявлен в `:root` от акцента
        // сайта, поэтому свой цвет кнопки менял заливку и не менял надпись:
        // на светлой заливке оставался белый текст. Отдельного поля «цвет
        // текста кнопки» не заводим — оно позволило бы выбрать нечитаемое
        // сочетание, а решает здесь контраст.
        if ($accent !== '') {
            $vars['--on-accent'] = AccentContrast::onFill($accent);
        }

        return $vars;
    }

    /** Готовое значение для `background` слоя затемнения. */
    private static function overlayValue(string $mode, string $color, int $opacity, string $direction, string $textPosition): string
    {
        if ($mode === 'none') {
            return 'transparent';
        }

        $rgb = self::rgb($color);
        $alpha = self::alpha($opacity);
        if ($mode === 'solid') {
            return 'rgba(' . $rgb . ',' . $alpha . ')';
        }

        // «Авто»: градиент уходит от той стороны, где стоит текст, — темнее
        // там, где читают, прозрачнее там, где смотрят на фотографию.
        $angle = self::OVERLAY_ANGLES[$direction]
            ?? ($textPosition === 'right' ? '270deg' : ($textPosition === 'center' ? '0deg' : '90deg'));

        return 'linear-gradient(' . $angle . ', rgba(' . $rgb . ',' . $alpha . ') 0%, rgba(' . $rgb . ','
            . self::alpha((int) round($opacity * 0.82)) . ') 38%, rgba(' . $rgb . ','
            . self::alpha((int) round($opacity * 0.28)) . ') 72%, rgba(' . $rgb . ',0) 100%)';
    }

    private static function rgb(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            $hex = '0b1a30';
        }

        return (int) hexdec(substr($hex, 0, 2)) . ',' . (int) hexdec(substr($hex, 2, 2)) . ',' . (int) hexdec(substr($hex, 4, 2));
    }

    private static function alpha(int $percent): string
    {
        return rtrim(rtrim(number_format(max(0, min(100, $percent)) / 100, 2, '.', ''), '0'), '.') ?: '0';
    }

    /** Относительная светлота цвета (0 — чёрный, 1 — белый). */
    private static function luminance(string $hex): float
    {
        $hex = ltrim(trim($hex), '#');
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return 0.0;
        }
        $r = (int) hexdec(substr($hex, 0, 2)) / 255;
        $g = (int) hexdec(substr($hex, 2, 2)) / 255;
        $b = (int) hexdec(substr($hex, 4, 2)) / 255;

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

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
