<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Схематичные миниатюры вариантов блока для формы редактора.
 *
 * «Вариант отображения» был выпадающим списком: чтобы узнать, чем «Мозаика»
 * отличается от «Колонок», редактор выбирал значение, сохранял блок и смотрел
 * на страницу — то есть выбор проверялся публикацией. Плитка со схемой
 * раскладки отвечает на тот же вопрос до сохранения.
 *
 * Миниатюры не рисуются по одной: каждая описывается короткой строкой вида
 * `grid:3+icon` (сетка из трёх карточек с иконкой), и из неё собирается SVG.
 * Иначе три с лишним десятка почти одинаковых картинок разъехались бы между
 * собой при первой правке, а новый вариант блока получал бы миниатюру только
 * после того, как кто-нибудь нарисует ему свою.
 *
 * Рисунок наследует цвет текста (`currentColor`) и потому одинаково читается
 * в светлой и тёмной панели: у выбранной плитки он берёт акцент.
 */
final class VariantPreview
{
    /** Холст миниатюры: пропорция примерно как у секции страницы. */
    private const W = 64;
    private const H = 40;

    /** Поля внутри холста. */
    private const PAD = 4;

    /**
     * Известные раскладки. Ключ — имя схемы, значение — описание для человека:
     * оно уходит в `aria-label`, потому что для диктора набор прямоугольников
     * не значит ничего, а подпись варианта рядом называет не раскладку, а
     * замысел («Мозаика», «Редакционный»).
     *
     * @var array<string, string>
     */
    public const SHAPES = [
        'grid' => 'сетка карточек',
        'row' => 'элементы в ряд',
        'band' => 'сплошная полоса',
        'list' => 'список строк',
        'track' => 'полоса с прокруткой',
        'split' => 'две колонки',
        'mosaic' => 'мозаика из крупных и мелких',
        'bars' => 'горизонтальные полосы',
        'stacked' => 'одна полоса из долей',
        'meter' => 'шкала выполнения',
        'rule' => 'горизонтальная линия',
        'emblem' => 'знак по центру',
        'space' => 'пустое место',
        'text' => 'текстовые строки',
        'quote' => 'текст и карточка цитаты',
        'timeline' => 'годы слева, события справа',
        'card' => 'одна карточка по центру',
        'table' => 'таблица со строками и колонками',
        'tree' => 'схема подчинения',
        'auto' => 'сетка, а при переполнении — прокрутка',
    ];

    /**
     * Модификаторы — деталь внутри карточки. Тоже перечислены явно: опечатка в
     * имени иначе прошла бы молча и оставила плитку без той единственной
     * детали, ради которой вариант и выбирают.
     *
     * @var list<string>
     */
    public const MODIFIERS = [
        'icon', 'icon-left', 'num', 'photo', 'photo-below', 'photo-left',
        'frame', 'plain', 'dot', 'accent', 'striped', 'bordered', 'spine',
    ];

    /**
     * SVG-миниатюра по описанию раскладки.
     *
     * Описание: `<схема>[:<число>][+<модификатор>…]`, например `grid:3+icon`.
     * Неизвестная схема — не повод ронять форму блока: вместо рисунка выходит
     * пустой холст, а сторож (тест 376) требует, чтобы описание было из
     * известных, и падает раньше, чем это увидит редактор.
     */
    public static function svg(string $shape): string
    {
        [$name, $count, $mods] = self::parse($shape);
        $body = match ($name) {
            'grid' => self::grid($count, $mods),
            'row' => self::row($count, $mods),
            'band' => self::band($mods),
            'list' => self::list($count, $mods),
            'track' => self::track($count, $mods),
            'split' => self::split($mods),
            'mosaic' => self::mosaic(),
            'bars' => self::bars(),
            'stacked' => self::stacked(),
            'meter' => self::meter(),
            'rule' => self::rule($mods),
            'emblem' => self::emblem(),
            'space' => self::space(),
            'text' => self::text($count, $mods),
            'quote' => self::quote(),
            'timeline' => self::timeline($count),
            'card' => self::card($mods),
            'table' => self::table($mods),
            'tree' => self::tree($mods),
            'auto' => self::auto(),
            default => '',
        };

        return '<svg class="variant-card__thumb" viewBox="0 0 ' . self::W . ' ' . self::H
            . '" width="' . self::W . '" height="' . self::H . '" aria-hidden="true" focusable="false">'
            . $body . '</svg>';
    }

    /** Описание раскладки словами — для диктора. */
    public static function label(string $shape): string
    {
        [$name] = self::parse($shape);

        return self::SHAPES[$name] ?? '';
    }

    /** Схема известна и модификаторы тоже: это проверяет тест, а не редактор. */
    public static function isKnown(string $shape): bool
    {
        [$name, , $mods] = self::parse($shape);
        if (!isset(self::SHAPES[$name])) {
            return false;
        }
        foreach ($mods as $mod) {
            if (!in_array($mod, self::MODIFIERS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0:string, 1:int, 2:list<string>}
     */
    private static function parse(string $shape): array
    {
        $parts = explode('+', $shape);
        $head = array_shift($parts) ?? '';
        $count = 3;
        if (str_contains($head, ':')) {
            [$head, $raw] = explode(':', $head, 2);
            $count = max(1, min(8, (int) $raw));
        }

        return [$head, $count, array_values(array_filter($parts))];
    }

    /** Прямоугольник: заливка — «фотография», обводка — рамка карточки. */
    private static function box(float $x, float $y, float $w, float $h, float $opacity = 0.22, float $radius = 2): string
    {
        return '<rect x="' . self::n($x) . '" y="' . self::n($y) . '" width="' . self::n($w)
            . '" height="' . self::n($h) . '" rx="' . self::n($radius) . '" fill="currentColor" opacity="'
            . self::n($opacity) . '"/>';
    }

    private static function frame(float $x, float $y, float $w, float $h, float $radius = 2): string
    {
        return '<rect x="' . self::n($x) . '" y="' . self::n($y) . '" width="' . self::n($w)
            . '" height="' . self::n($h) . '" rx="' . self::n($radius)
            . '" fill="none" stroke="currentColor" stroke-width="1" opacity="0.35"/>';
    }

    /** Строка текста. */
    private static function line(float $x, float $y, float $w, float $opacity = 0.45, float $h = 2): string
    {
        return self::box($x, $y, $w, $h, $opacity, $h / 2);
    }

    /**
     * Начинка карточки: иконка, номер, кадр и строки текста.
     *
     * @param list<string> $mods
     */
    private static function cardInner(float $x, float $y, float $w, float $h, array $mods): string
    {
        $pad = 2.5;
        $inner = $x + $pad;
        $width = $w - $pad * 2;
        $top = $y + $pad;
        $out = '';

        if (in_array('photo', $mods, true) || in_array('photo-below', $mods, true)) {
            $photoH = in_array('photo-below', $mods, true) ? $h * 0.45 : $h * 0.55;
            $out .= self::box($x, $y, $w, $photoH, 0.3, 2);
            $top = $y + $photoH + $pad;
        } elseif (in_array('icon-left', $mods, true)) {
            $out .= self::box($inner, $top, 5, 5, 0.55, 1.5);
            $out .= self::line($inner + 7, $top + 1, max(4, $width - 9), 0.5);
            $top += 8;
            $out .= self::line($inner, $top, $width, 0.3);

            return $out;
        } elseif (in_array('icon', $mods, true)) {
            $out .= self::box($inner, $top, 5, 5, 0.55, 1.5);
            $top += 7.5;
        }

        if (in_array('num', $mods, true)) {
            // Номер карточки — плашка в правом верхнем углу. Цифра на холсте
            // шириной 64 не читается, а две насечки вместо неё выглядели
            // кавычками: миниатюра должна показывать место номера, а не
            // пытаться его написать.
            $out .= self::box($x + $w - $pad - 5, $y + $pad, 5, 4, 0.3, 1);
        }

        // В карточке уже 12 пикселей строки текста сливаются в серый шум:
        // у «компактных категорий» их шесть в ряд, и рисовать там абзац
        // значит показать не раскладку, а рябь.
        if ($width < 8) {
            return $out . self::line($inner, $top, $width, 0.45);
        }

        $out .= self::line($inner, $top, min($width, $width * 0.8), 0.5);
        if ($h - ($top - $y) > 8) {
            $out .= self::line($inner, $top + 4, $width, 0.28);
            $out .= self::line($inner, $top + 7.5, $width * 0.7, 0.28);
        }

        return $out;
    }

    /** @param list<string> $mods */
    private static function grid(int $count, array $mods): string
    {
        $count = max(2, min(6, $count));
        $gap = 2.5;
        $x = self::PAD;
        $w = (self::W - self::PAD * 2 - $gap * ($count - 1)) / $count;
        $h = self::H - self::PAD * 2;
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            $left = $x + $i * ($w + $gap);
            if (in_array('accent', $mods, true)) {
                // Редакционная карточка: линия сверху вместо рамки и подложки.
                $out .= self::line($left, self::PAD, $w, 0.6, 1.5);
            } elseif (!in_array('plain', $mods, true)) {
                $out .= self::frame($left, self::PAD, $w, $h);
            }
            $out .= self::cardInner($left, self::PAD, $w, $h, $mods);
        }

        return $out;
    }

    /** @param list<string> $mods */
    private static function row(int $count, array $mods): string
    {
        $count = max(2, min(6, $count));
        $gap = 2.5;
        $w = (self::W - self::PAD * 2 - $gap * ($count - 1)) / $count;
        $h = 12;
        $y = (self::H - $h) / 2;
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            $left = self::PAD + $i * ($w + $gap);
            $out .= in_array('frame', $mods, true) ? self::frame($left, $y, $w, $h) : self::box($left, $y, $w, $h, 0.25);
        }

        return $out;
    }

    /** @param list<string> $mods */
    private static function band(array $mods): string
    {
        $h = 16;
        $y = (self::H - $h) / 2;
        $out = self::box(0, $y, self::W, $h, 0.3, 0);
        if (in_array('icon', $mods, true)) {
            $out .= self::box(self::PAD + 2, $y + 5, 6, 6, 0.6, 1.5);
            $out .= self::line(self::PAD + 11, $y + 6, 22, 0.6);

            return $out;
        }
        $out .= self::line(self::PAD + 2, $y + 5, 26, 0.55);
        $out .= self::line(self::PAD + 2, $y + 10, 18, 0.35);

        return $out;
    }

    /** @param list<string> $mods */
    private static function list(int $count, array $mods): string
    {
        $count = max(2, min(5, $count));
        $gap = 2;
        $h = (self::H - self::PAD * 2 - $gap * ($count - 1)) / $count;
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            $top = self::PAD + $i * ($h + $gap);
            if (in_array('frame', $mods, true)) {
                $out .= self::frame(self::PAD, $top, self::W - self::PAD * 2, $h);
            }
            if (in_array('photo-left', $mods, true)) {
                // Строка-карточка: кадр слева, текст справа — так устроен
                // «список» у проектов и новостей.
                $out .= self::box(self::PAD, $top, $h * 1.4, $h, 0.3);
                $out .= self::line(self::PAD + $h * 1.4 + 2.5, $top + $h / 2 - 3, 20, 0.5);
                $out .= self::line(self::PAD + $h * 1.4 + 2.5, $top + $h / 2 + 1, self::W - self::PAD * 2 - $h * 1.4 - 5, 0.28);
                continue;
            }
            if (in_array('icon-left', $mods, true)) {
                $out .= self::box(self::PAD + 2, $top + ($h - 4) / 2, 4, 4, 0.55, 1);
                $out .= self::line(self::PAD + 8, $top + $h / 2 - 1, self::W - self::PAD * 2 - 12, 0.4);
                continue;
            }
            if (in_array('dot', $mods, true)) {
                $out .= self::box(self::PAD, $top + ($h - 3) / 2, 3, 3, 0.6, 1.5);
            }
            $out .= self::line(self::PAD + (in_array('dot', $mods, true) ? 5 : 2), $top + $h / 2 - 1, self::W - self::PAD * 2 - 8, 0.45);
        }

        return $out;
    }

    /**
     * Полоса с прокруткой: последняя карточка обрезана правым краем — именно
     * этим полоса и отличается от сетки, где ряд переносится.
     *
     * @param list<string> $mods
     */
    private static function track(int $count, array $mods): string
    {
        $count = max(2, min(5, $count));
        $gap = 2.5;
        // Карточка нарочно шире трети холста: обрез последней о правый край —
        // единственное, чем полоса отличается от сетки, и если все карточки
        // помещаются, миниатюра врёт.
        $w = 21;
        $h = self::H - self::PAD * 2;
        $out = '';
        for ($i = 0; $i < $count; $i++) {
            $left = self::PAD + $i * ($w + $gap);
            if ($left >= self::W) {
                break;
            }
            $visible = min($w, self::W - $left);
            $out .= self::frame($left, self::PAD, $visible, $h);
            $out .= self::cardInner($left, self::PAD, $visible, $h, $mods);
        }

        return $out;
    }

    /** @param list<string> $mods */
    private static function split(array $mods): string
    {
        $h = self::H - self::PAD * 2;
        $half = (self::W - self::PAD * 2 - 3) / 2;
        $mediaFirst = in_array('photo', $mods, true);
        $textX = $mediaFirst ? self::PAD + $half + 3 : self::PAD;
        $mediaX = $mediaFirst ? self::PAD : self::PAD + $half + 3;

        $out = self::box($mediaX, self::PAD, $half, $h, 0.3);
        $out .= self::line($textX, self::PAD + 4, $half * 0.8, 0.55);
        $out .= self::line($textX, self::PAD + 10, $half, 0.3);
        $out .= self::line($textX, self::PAD + 14, $half, 0.3);
        $out .= self::line($textX, self::PAD + 18, $half * 0.6, 0.3);

        return $out;
    }

    private static function mosaic(): string
    {
        $h = self::H - self::PAD * 2;
        $big = 30;
        $small = (self::W - self::PAD * 2 - $big - 2.5 - 2.5) / 2;
        $out = self::box(self::PAD, self::PAD, $big, $h, 0.32);
        $out .= self::line(self::PAD + 3, self::PAD + $h - 8, $big * 0.6, 0.6);
        $left = self::PAD + $big + 2.5;
        for ($i = 0; $i < 2; $i++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $left + $i * ($small + 2.5);
                $y = self::PAD + $j * ($h / 2 + 1) - ($j > 0 ? 1 : 0);
                $out .= self::frame($x, $y, $small, $h / 2 - 1);
                $out .= self::line($x + 2, $y + 4, $small - 4, 0.45);
                $out .= self::line($x + 2, $y + 8, $small - 6, 0.28);
            }
        }

        return $out;
    }

    private static function bars(): string
    {
        $out = '';
        $widths = [0.9, 0.65, 0.45];
        foreach ($widths as $i => $k) {
            $y = self::PAD + 3 + $i * 10;
            $out .= self::line(self::PAD, $y, 12, 0.35);
            $out .= self::box(self::PAD, $y + 3.5, (self::W - self::PAD * 2) * $k, 4, 0.55, 2);
        }

        return $out;
    }

    private static function stacked(): string
    {
        $y = self::H / 2 - 4;
        $parts = [0.45, 0.3, 0.15, 0.1];
        $x = self::PAD;
        $out = '';
        $opacity = 0.65;
        foreach ($parts as $part) {
            $w = (self::W - self::PAD * 2) * $part;
            $out .= self::box($x, $y, $w - 0.6, 8, $opacity, 1);
            $x += $w;
            $opacity -= 0.14;
        }

        return $out;
    }

    private static function meter(): string
    {
        $y = self::H / 2 - 3;
        $out = self::box(self::PAD, $y, self::W - self::PAD * 2, 6, 0.18, 3);
        $out .= self::box(self::PAD, $y, (self::W - self::PAD * 2) * 0.62, 6, 0.6, 3);
        $out .= self::line(self::PAD, $y - 7, 16, 0.4);

        return $out;
    }

    /** @param list<string> $mods */
    private static function rule(array $mods): string
    {
        $short = in_array('dot', $mods, true);
        $w = $short ? 18 : self::W - self::PAD * 2;
        $x = $short ? (self::W - $w) / 2 : self::PAD;

        return self::line($x, self::H / 2 - 1, $w, 0.5, 2);
    }

    private static function emblem(): string
    {
        $out = self::line(self::PAD, self::H / 2 - 1, 20, 0.3, 1.5);
        $out .= self::line(self::W - self::PAD - 20, self::H / 2 - 1, 20, 0.3, 1.5);
        $out .= '<path d="M' . self::n(self::W / 2) . ' ' . self::n(self::H / 2 - 5)
            . ' l4 3 -1.5 5 h-5 l-1.5 -5 z" fill="currentColor" opacity="0.6"/>';

        return $out;
    }

    private static function space(): string
    {
        return '<rect x="' . self::PAD . '" y="' . self::PAD . '" width="' . (self::W - self::PAD * 2)
            . '" height="' . (self::H - self::PAD * 2) . '" rx="2" fill="none" stroke="currentColor"'
            . ' stroke-width="1" stroke-dasharray="3 3" opacity="0.3"/>';
    }

    /** @param list<string> $mods */
    private static function text(int $count, array $mods): string
    {
        $count = max(2, min(6, $count));
        $out = '';
        if (in_array('icon', $mods, true)) {
            $out .= self::box(self::PAD, self::PAD, 6, 6, 0.55, 1.5);
        }
        $top = self::PAD + (in_array('icon', $mods, true) ? 9 : 0);
        if (in_array('frame', $mods, true)) {
            $out .= self::line(self::PAD, $top, 22, 0.6, 2.5);
            $top += 6;
        }
        $widths = [1, 0.95, 0.98, 0.6, 0.9, 0.5];
        for ($i = 0; $i < $count; $i++) {
            $out .= self::line(self::PAD, $top + $i * 5, (self::W - self::PAD * 2) * $widths[$i % 6], 0.3);
        }

        return $out;
    }

    private static function quote(): string
    {
        $h = self::H - self::PAD * 2;
        $textW = 34;
        $out = '';
        for ($i = 0; $i < 5; $i++) {
            $out .= self::line(self::PAD, self::PAD + 2 + $i * 5, $textW * ($i === 4 ? 0.6 : 1), 0.3);
        }
        $cardX = self::PAD + $textW + 3;
        $out .= self::box($cardX, self::PAD, self::W - self::PAD - $cardX, $h, 0.28);
        $out .= self::line($cardX + 2.5, self::PAD + 6, 12, 0.6, 2.5);
        $out .= self::line($cardX + 2.5, self::PAD + 13, 14, 0.4);
        $out .= self::line($cardX + 2.5, self::PAD + 17, 10, 0.4);

        return $out;
    }

    private static function timeline(int $count): string
    {
        $count = max(2, min(4, $count));
        $gap = 2;
        $h = (self::H - self::PAD * 2 - $gap * ($count - 1)) / $count;
        $out = '<rect x="' . self::n(self::PAD + 1) . '" y="' . self::PAD . '" width="1" height="'
            . (self::H - self::PAD * 2) . '" fill="currentColor" opacity="0.3"/>';
        for ($i = 0; $i < $count; $i++) {
            $top = self::PAD + $i * ($h + $gap);
            $out .= self::box(self::PAD, $top + $h / 2 - 1.5, 3, 3, 0.6, 1.5);
            $out .= self::line(self::PAD + 6, $top + $h / 2 - 3, 10, 0.55);
            $out .= self::line(self::PAD + 19, $top + $h / 2 - 2.5, self::W - self::PAD - 23, 0.3);
            $out .= self::line(self::PAD + 19, $top + $h / 2 + 1, self::W - self::PAD - 30, 0.3);
        }

        return $out;
    }

    /** @param list<string> $mods */
    private static function card(array $mods): string
    {
        $w = 34;
        $h = self::H - self::PAD * 2 - 4;
        $x = (self::W - $w) / 2;
        $y = self::PAD + 2;
        $out = in_array('photo', $mods, true)
            ? self::box($x, $y, $w, $h, 0.32)
            : self::frame($x, $y, $w, $h);
        $out .= self::line($x + 4, $y + 5, $w - 8, 0.55, 2.5);
        $out .= self::line($x + 4, $y + 11, $w - 8, 0.3);
        $out .= self::box($x + 4, $y + $h - 9, 14, 5, 0.5, 2);

        return $out;
    }

    /** @param list<string> $mods */
    private static function table(array $mods): string
    {
        $cols = 3;
        $rows = 4;
        $w = (self::W - self::PAD * 2) / $cols;
        $h = (self::H - self::PAD * 2) / $rows;
        $out = self::box(self::PAD, self::PAD, self::W - self::PAD * 2, $h, 0.45, 1);
        for ($r = 1; $r < $rows; $r++) {
            $top = self::PAD + $r * $h;
            if (in_array('striped', $mods, true) && $r % 2 === 1) {
                $out .= self::box(self::PAD, $top, self::W - self::PAD * 2, $h, 0.14, 0);
            }
            for ($c = 0; $c < $cols; $c++) {
                $out .= self::line(self::PAD + $c * $w + 1.5, $top + $h / 2 - 1, $w - 4, 0.3);
            }
        }
        if (in_array('bordered', $mods, true)) {
            $out .= self::frame(self::PAD, self::PAD, self::W - self::PAD * 2, self::H - self::PAD * 2, 1);
            for ($c = 1; $c < $cols; $c++) {
                $out .= '<rect x="' . self::n(self::PAD + $c * $w) . '" y="' . self::PAD . '" width="0.8" height="'
                    . (self::H - self::PAD * 2) . '" fill="currentColor" opacity="0.25"/>';
            }
        }

        return $out;
    }

    /** @param list<string> $mods */
    private static function tree(array $mods): string
    {
        $out = self::box(self::W / 2 - 8, self::PAD, 16, 7, 0.5, 2);
        if (in_array('spine', $mods, true)) {
            // Ось: руководитель сверху, ветки уходят вбок от одной линии.
            $out .= '<rect x="' . self::n(self::W / 2 - 0.5) . '" y="' . (self::PAD + 7)
                . '" width="1" height="' . (self::H - self::PAD * 2 - 7) . '" fill="currentColor" opacity="0.3"/>';
            for ($i = 0; $i < 3; $i++) {
                $y = self::PAD + 12 + $i * 7;
                $left = $i % 2 === 0;
                $out .= self::box($left ? self::PAD + 2 : self::W / 2 + 4, $y, 22, 5, 0.3, 1.5);
            }

            return $out;
        }
        $out .= '<rect x="' . self::n(self::W / 2 - 0.5) . '" y="' . (self::PAD + 7)
            . '" width="1" height="5" fill="currentColor" opacity="0.3"/>';
        $out .= '<rect x="' . (self::PAD + 6) . '" y="' . (self::PAD + 12) . '" width="'
            . (self::W - self::PAD * 2 - 12) . '" height="1" fill="currentColor" opacity="0.3"/>';
        for ($i = 0; $i < 3; $i++) {
            $w = 14;
            $x = self::PAD + 3 + $i * ($w + 3);
            $out .= '<rect x="' . self::n($x + $w / 2) . '" y="' . (self::PAD + 12)
                . '" width="1" height="4" fill="currentColor" opacity="0.3"/>';
            $out .= self::box($x, self::PAD + 16, $w, 8, 0.3, 1.5);
        }

        return $out;
    }

    /** Сетка, которая при переполнении становится полосой: стрелка справа. */
    private static function auto(): string
    {
        $gap = 2.5;
        $arrow = 7;
        $w = (self::W - self::PAD * 2 - $arrow - $gap * 3) / 3;
        $h = self::H - self::PAD * 2;
        $out = '';
        for ($i = 0; $i < 3; $i++) {
            $left = self::PAD + $i * ($w + $gap);
            $out .= self::frame($left, self::PAD, $w, $h);
            $out .= self::line($left + 2, self::PAD + 4, $w - 4, 0.45);
            $out .= self::line($left + 2, self::PAD + 8, $w - 5, 0.28);
        }
        $cx = self::W - self::PAD - 3;
        $cy = self::H / 2;
        $out .= '<path d="M' . self::n($cx - 2) . ' ' . self::n($cy - 4) . ' l4 4 -4 4" fill="none"'
            . ' stroke="currentColor" stroke-width="1.4" stroke-linecap="round" opacity="0.5"/>';

        return $out;
    }

    /** Короткая запись числа: без хвоста из нулей разметка миниатюр вдвое тяжелее. */
    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
