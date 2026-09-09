<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Фон секции блока: свой цвет, градиент, фотография или узор.
 *
 * До сих пор у секции были только пресеты темы (светлый / оттенок / тёмный).
 * Всё остальное собирается здесь и уезжает в scoped CSS блока: инлайн-стили в
 * блоках запрещены тестами, а значения приходят от редактора и в разметке им
 * не место.
 *
 * Режим один на секцию — нормализатор об этом позаботился: два фона (скажем,
 * градиент и фото) дали бы кашу, а какой из них главный, не угадать.
 */
final class BlockBackground
{
    /** Встроенные узоры — один список на фон секции и на элемент коллажа. */
    public const PATTERNS = ['dots', 'grid', 'diagonal', 'emblem'];

    /**
     * Классы секции и её scoped CSS.
     *
     * @param array<string, mixed> $data данные блока (ключи с префиксом `_`)
     * @return array{class: string, css: string}
     */
    public static function build(array $data, int $blockId): array
    {
        $mode = (string) ($data['_bg_mode'] ?? 'preset');
        if ($mode === 'preset' || $mode === '') {
            return ['class' => '', 'css' => ''];
        }

        $scope = '#block-' . $blockId;
        $class = ' cms-block--bg cms-block--bg-custom';

        $vars = [];
        $layers = [];
        // Цвет текста секции считает SectionColors и подмешивает BlockRenderer:
        // он нужен и секциям без своего фона (пресет navy), поэтому здесь его
        // больше нет.
        $extraCss = '';

        if ($mode === 'color') {
            $vars['--block-bg'] = (string) ($data['_bg_color'] ?? '');
        }

        if ($mode === 'gradient') {
            $angle = (int) ($data['_bg_gradient_angle'] ?? 135);
            $vars['--block-bg'] = sprintf(
                'linear-gradient(%ddeg, %s 0%%, %s 100%%)',
                $angle,
                (string) ($data['_bg_gradient_from'] ?? ''),
                (string) ($data['_bg_gradient_to'] ?? '')
            );
        }

        if ($mode === 'image') {
            $image = (string) ($data['_bg_image'] ?? '');
            $tile = ($data['_bg_repeat'] ?? 'cover') === 'tile';
            // Фон в CSS не грузится лениво, поэтому подставляем WebP-набор:
            // браузер возьмёт его, а старый — исходный файл.
            $vars['--block-bg'] = Media::cssImageSet($image);
            $vars['--block-bg-size'] = $tile
                ? ((int) ($data['_bg_tile_size'] ?? 120)) . 'px'
                : 'cover';
            $vars['--block-bg-repeat'] = $tile ? 'repeat' : 'no-repeat';
            if (!$tile) {
                $vars['--block-bg-position'] = MediaPosition::cssValue((string) ($data['_bg_position'] ?? ''));
                if (!empty($data['_bg_fixed'])) {
                    $class .= ' cms-block--bg-fixed';
                }
            }
            $overlay = (int) ($data['_bg_overlay'] ?? 0);
            if ($overlay > 0) {
                $class .= ' cms-block--bg-overlay';
                $vars['--block-bg-overlay'] = ($overlay / 100) . '';
            }
        }

        if ($mode === 'pattern') {
            $base = (string) ($data['_bg_color'] ?? '');
            $ink = (string) ($data['_bg_pattern_color'] ?? '');
            if ($ink === '') {
                $ink = 'var(--gov-teal)';
            }
            $size = (int) ($data['_bg_pattern_size'] ?? 28);
            $opacity = ((int) ($data['_bg_pattern_opacity'] ?? 12)) / 100;
            $tint = 'color-mix(in srgb, ' . $ink . ' ' . round($opacity * 100) . '%, transparent)';

            $class .= ' cms-block--bg-pattern cms-block--bg-pattern-' . self::patternKey($data);
            $vars['--block-bg'] = $base !== '' ? $base : 'var(--gov-surface)';
            $vars['--block-pattern-size'] = $size . 'px';
            $vars['--block-pattern-ink'] = $tint;
        }

        foreach ($vars as $name => $value) {
            if ($value === '') {
                continue;
            }
            $layers[] = $name . ':' . $value;
        }

        $css = $layers === [] ? '' : $scope . '{' . implode(';', $layers) . '}';

        return [
            'class' => $class,
            'css' => trim($css . $extraCss),
        ];
    }

    /**
     * Тот же фон для элемента вне конструктора блоков — подвала сайта.
     *
     * У секции слои рисуют `::before`/`::after` самого блока; здесь селектор
     * приходит снаружи, а слой один: подвал не бывает «полноширинным
     * наполовину».
     *
     * @param array<string, mixed> $data ключи с префиксом `_`, как у блока
     */
    public static function cssFor(string $selector, array $data): string
    {
        $mode = (string) ($data['_bg_mode'] ?? 'preset');
        if ($mode === 'preset' || $mode === '') {
            return '';
        }

        $built = self::build($data, 0);
        // Переменные из scoped-строки блока переносим на чужой селектор.
        $vars = '';
        if (preg_match('/\{(.+?)\}/', $built['css'], $m) === 1) {
            $vars = $m[1];
        }
        if ($vars === '') {
            return '';
        }

        $css = $selector . '{position:relative;isolation:isolate;' . $vars . ';'
            . 'background:var(--block-bg,transparent);'
            . 'background-size:var(--block-bg-size,cover);'
            . 'background-position:var(--block-bg-position,center center);'
            . 'background-repeat:var(--block-bg-repeat,no-repeat);}';

        if (str_contains($built['class'], 'cms-block--bg-pattern')) {
            // Узор поверх заливки — отдельным слоем, иначе базовый цвет
            // пропадает вместе с краской узора.
            $css .= $selector . '::before{content:"";position:absolute;inset:0;z-index:0;pointer-events:none;'
                . self::patternLayer($data) . '}'
                . $selector . ' > *{position:relative;z-index:1;}';
        } elseif (str_contains($built['class'], 'cms-block--bg-overlay')) {
            $css .= $selector . '::before{content:"";position:absolute;inset:0;z-index:0;pointer-events:none;'
                . 'background:color-mix(in srgb, var(--gov-navy) calc(var(--block-bg-overlay,.45) * 100%), transparent);}'
                . $selector . ' > *{position:relative;z-index:1;}';
        }

        return $css;
    }

    /** @param array<string, mixed> $data */
    private static function patternLayer(array $data): string
    {
        return self::patternCss(self::patternKey($data));
    }

    /**
     * Объявления встроенного узора. Публичны, потому что узор нужен не только
     * фону секции: «Коллаж» рисует им отдельный элемент композиции, и второй
     * список тех же градиентов разъехался бы с этим при первой правке.
     *
     * Размер и цвет — переменные (`--block-pattern-size`, `--block-pattern-ink`):
     * вызывающий задаёт их у себя, а сам набор узоров один на всех.
     */
    public static function patternCss(string $key): string
    {
        $size = 'var(--block-pattern-size,28px)';
        $ink = 'var(--block-pattern-ink)';

        return match (in_array($key, self::PATTERNS, true) ? $key : 'dots') {
            'grid' => 'background-image:linear-gradient(to right,' . $ink . ' 1px,transparent 1px),'
                . 'linear-gradient(to bottom,' . $ink . ' 1px,transparent 1px);background-size:' . $size . ' ' . $size . ';',
            'diagonal' => 'background-image:repeating-linear-gradient(45deg,' . $ink . ' 0,' . $ink . ' 1px,'
                . 'transparent 1px,transparent calc(' . $size . ' / 2));',
            // Абсолютный адрес, а не var(--gov-emblem): переменная объявлена
            // с относительным url('../img/emblem.svg'), и в сгенерированном
            // файле темы (он лежит в /uploads/public) браузер разрешает его от
            // этого файла — маска указывала в никуда.
            'emblem' => 'background:' . $ink . ';-webkit-mask:url("' . self::emblemUrl() . '") 0 0 / ' . $size . ' ' . $size
                . ' repeat;mask:url("' . self::emblemUrl() . '") 0 0 / ' . $size . ' ' . $size . ' repeat;',
            default => 'background-image:radial-gradient(' . $ink . ' 1.5px,transparent 1.6px);'
                . 'background-size:' . $size . ' ' . $size . ';',
        };
    }

    /**
     * Файл эмблемы: настройка «Дизайна» либо штатный файл темы.
     *
     * Публичный, потому что знак нужен не только фону секции: им же рисуется
     * разделитель. Брать `var(--gov-emblem)` в статическом файле нельзя —
     * переменная объявлена относительным url и разрешается от того файла, где
     * объявлена, а не от того, где используется.
     */
    public static function emblemUrl(): string
    {
        $custom = trim((string) \App\Models\Setting::get('design_emblem', ''));

        return Emblem::cssUrl(
            $custom !== '' && UrlGuard::isSafeMedia($custom) ? $custom : '/assets/img/emblem.svg'
        );
    }

    /** @param array<string, mixed> $data */
    private static function patternKey(array $data): string
    {
        $pattern = (string) ($data['_bg_pattern'] ?? 'dots');

        return in_array($pattern, self::PATTERNS, true) ? $pattern : 'dots';
    }
}
