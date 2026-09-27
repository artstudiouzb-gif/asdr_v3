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
        'hero-tiles' => 'крупный кадр и мелкие рядом',
        'overlap' => 'элементы с наложением по диагонали',
        'callout' => 'кадр с вырезом в углу, в вырезе карточка, печать на краю',
        'checker' => 'клетки кадров и текста через одну, печать на стыке',
        'portrait' => 'портрет с карточкой чисел и цитата рядом',
        'pair' => 'два кадра внахлёст через полосу фона, карточка на втором',
        'notch' => 'широкий кадр с крупным вырезом вверху справа под справку',
        'diagonal' => 'два кадра через косую полосу, печать на разрезе',
        'bars' => 'горизонтальные полосы',
        'stacked' => 'одна полоса из долей',
        'meter' => 'шкала выполнения',
        'axis' => 'точки на линии от базы к цели',
        'goal' => 'числа с полосой пути к цели',
        'bleed' => 'текст и фото, уходящее за край',
        'photo-card' => 'фото и карточка текста поверх него',
        'wide' => 'фото во всю ширину, текст под ним со сдвигом',
        'rule' => 'горизонтальная линия',
        'emblem' => 'знак по центру',
        'space' => 'пустое место',
        'text' => 'текстовые строки',
        'quote' => 'текст и карточка цитаты',
        'timeline' => 'годы слева, события справа',
        'qa' => 'вопросы строками, ответ раскрывается',
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
        'frame', 'plain', 'dot', 'accent', 'striped', 'bordered', 'spine', 'aside',
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
            'hero-tiles' => self::heroTiles(),
            'overlap' => self::overlap(),
            'callout' => self::callout(),
            'checker' => self::checker(),
            'portrait' => self::portrait(),
            'pair' => self::pair(),
            'notch' => self::notch(),
            'diagonal' => self::diagonal(),
            'bars' => self::bars(),
            'stacked' => self::stacked(),
            'meter' => self::meter(),
            'axis' => self::axis(),
            'goal' => self::goal(),
            'bleed' => self::bleed(),
            'photo-card' => self::photoCard(),
            'wide' => self::wide(),
            'rule' => self::rule($mods),
            'emblem' => self::emblem(),
            'space' => self::space(),
            'text' => self::text($count, $mods),
            'quote' => self::quote(),
            'timeline' => self::timeline($count),
            'qa' => self::qa($mods),
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

    /** Крупный кадр слева и колонка спутников справа — без текстовых строк: в коллаже их нет. */
    private static function heroTiles(): string
    {
        $h = self::H - self::PAD * 2;
        $big = 34;
        $smallX = self::PAD + $big + 2.5;
        $smallW = self::W - self::PAD - $smallX;
        $smallH = ($h - 2.5 * 2) / 3;

        $out = self::box(self::PAD, self::PAD, $big, $h, 0.32);
        for ($i = 0; $i < 3; $i++) {
            $out .= self::box($smallX, self::PAD + $i * ($smallH + 2.5), $smallW, $smallH, 0.2);
        }

        return $out;
    }

    /** Наложение: три пятна по диагонали, пересечения видно по разной плотности. */
    private static function overlap(): string
    {
        $out = self::box(self::PAD, self::PAD, 30, 20, 0.3);
        $out .= self::box(self::PAD + 14, self::PAD + 8, 30, 20, 0.22);
        $out .= self::box(self::PAD + 6, self::PAD + 16, 22, 14, 0.34);

        return $out;
    }

    /** Круг печати. */
    private static function disc(float $cx, float $cy, float $r, float $opacity = 0.6): string
    {
        return '<circle cx="' . self::n($cx) . '" cy="' . self::n($cy) . '" r="' . self::n($r)
            . '" fill="currentColor" opacity="' . self::n($opacity) . '"/>';
    }

    /**
     * Кадр и выноска: снимок без нижнего левого угла, в вырезе — карточка,
     * печать на верхнем крае. Вырез нарисован просто отсутствием заливки.
     */
    private static function callout(): string
    {
        $w = self::W - self::PAD * 2;
        $h = self::H - self::PAD * 2;
        $cw = 20;
        $ch = 13;
        $out = self::box(self::PAD, self::PAD, $w, $h - $ch - 1.5, 0.28);
        $out .= self::box(self::PAD + $cw + 1.5, self::PAD + $h - $ch - 3, $w - $cw - 1.5, $ch + 3, 0.28);
        $out .= self::box(self::PAD, self::PAD + $h - $ch + 0.5, $cw - 1, $ch - 0.5, 0.6);
        $out .= self::line(self::PAD + 3, self::PAD + $h - 5, 9, 0.9);
        $out .= self::disc(self::W - self::PAD - 9, self::PAD + 1.5, 5);

        return $out;
    }

    /** Шахматка: три на два, кадры через один с текстом, печать на стыке. */
    private static function checker(): string
    {
        $gap = 2;
        $cw = (self::W - self::PAD * 2 - $gap * 2) / 3;
        $ch = (self::H - self::PAD * 2 - $gap) / 2;
        $out = '';
        for ($r = 0; $r < 2; $r++) {
            for ($c = 0; $c < 3; $c++) {
                $x = self::PAD + $c * ($cw + $gap);
                $y = self::PAD + $r * ($ch + $gap);
                if (($r + $c) % 2 === 0) {
                    $out .= self::box($x, $y, $cw, $ch, 0.28);
                } else {
                    $out .= self::frame($x, $y, $cw, $ch);
                    $out .= self::line($x + 2, $y + $ch - 6, $cw * 0.55, 0.6);
                }
            }
        }
        $out .= self::disc(self::PAD + $cw + $gap / 2, self::PAD + $ch + $gap / 2, 4.5);

        return $out;
    }

    /** Портрет и слово: книжный кадр с карточкой внизу, справа строки цитаты. */
    private static function portrait(): string
    {
        $h = self::H - self::PAD * 2;
        $pw = 22;
        $out = self::box(self::PAD, self::PAD, $pw, $h, 0.28);
        $out .= self::box(self::PAD + 2, self::PAD + $h - 9, $pw - 4, 7, 0.55);
        $out .= self::disc(self::PAD + $pw, self::PAD + 7, 4);
        $x = self::PAD + $pw + 8;
        $out .= self::line($x, self::PAD + 9, 26, 0.6, 2.5);
        $out .= self::line($x, self::PAD + 14, 30, 0.6, 2.5);
        $out .= self::line($x, self::PAD + 19, 20, 0.6, 2.5);
        $out .= self::line($x, self::PAD + 26, 14, 0.3);

        return $out;
    }

    /** Два кадра внахлёст: второй заходит на угол первого, между ними полоса фона. */
    private static function pair(): string
    {
        $h = self::H - self::PAD * 2;
        $out = self::box(self::PAD, self::PAD, 32, $h * 0.62, 0.28);
        $out .= self::box(self::PAD + 32 * 0.45, self::PAD + $h * 0.62, 32 * 0.55 - 2, $h * 0.38, 0.28);
        $out .= self::box(self::PAD + 32 * 0.45 + 2, self::PAD + $h * 0.62 * 0.55 + 2, 32 * 0.55 + 14, $h * 0.45 - 2, 0.2);
        $out .= self::box(self::PAD + 32 * 0.45 + 12, self::PAD + $h - 8, 22, 6, 0.6);
        $out .= self::disc(self::PAD + 8, self::PAD + $h * 0.62, 4);

        return $out;
    }

    /** Карточка в вырезе: широкий кадр, крупный вырез вверху справа, в нём строки справки. */
    private static function notch(): string
    {
        $w = self::W - self::PAD * 2;
        $h = self::H - self::PAD * 2;
        $cw = 22;
        $ch = 16;
        $out = self::box(self::PAD, self::PAD, $w - $cw - 1.5, $h, 0.28);
        $out .= self::box(self::PAD + $w - $cw - 3, self::PAD + $ch + 1.5, $cw + 3, $h - $ch - 1.5, 0.28);
        $x = self::PAD + $w - $cw + 1;
        $out .= self::frame($x, self::PAD, $cw - 1, $ch - 0.5);
        $out .= self::line($x + 2, self::PAD + 4, 10, 0.7);
        $out .= self::line($x + 2, self::PAD + 8.5, $cw - 5, 0.35);
        $out .= self::line($x + 2, self::PAD + 12, $cw - 5, 0.35);

        return $out;
    }

    /** Диагональный разрез: два кадра через косую полосу, печать на разрезе. */
    private static function diagonal(): string
    {
        $x0 = self::PAD;
        $y0 = self::PAD;
        $x1 = self::W - self::PAD;
        $y1 = self::H - self::PAD;
        $top = $x0 + 30;
        $bottom = $x0 + 18;
        $left = '<path d="M' . self::n($x0) . ' ' . self::n($y0) . 'H' . self::n($top - 1.5) . 'L' . self::n($bottom - 1.5) . ' ' . self::n($y1)
            . 'H' . self::n($x0) . 'Z" fill="currentColor" opacity="0.28"/>';
        $right = '<path d="M' . self::n($top + 1.5) . ' ' . self::n($y0) . 'H' . self::n($x1) . 'V' . self::n($y1) . 'H' . self::n($bottom + 1.5)
            . 'Z" fill="currentColor" opacity="0.18"/>';

        return $left . $right . self::disc(($top + $bottom) / 2, ($y0 + $y1) / 2, 4.5);
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

    /** Текст слева, фото справа во всю высоту и до самого края холста. */
    private static function bleed(): string
    {
        $mid = self::W / 2;
        $out = self::box($mid + 2, 0, self::W - $mid - 2, self::H, 0.3, 0);
        $out .= self::line(self::PAD, self::PAD + 8, $mid - self::PAD - 6, 0.55);
        $out .= self::line(self::PAD, self::PAD + 15, $mid - self::PAD - 4, 0.3);
        $out .= self::line(self::PAD, self::PAD + 19, $mid - self::PAD - 4, 0.3);
        $out .= self::line(self::PAD, self::PAD + 23, ($mid - self::PAD) * 0.6, 0.3);

        return $out;
    }

    /** Фото справа, карточка текста заходит на него слева. */
    private static function photoCard(): string
    {
        $out = self::box(self::PAD + 20, self::PAD, self::W - self::PAD * 2 - 20, self::H - self::PAD * 2, 0.3);
        // Карточка лежит поверх фото, поэтому под полупрозрачной заливкой —
        // непрозрачная подложка цветом плитки: иначе фото просвечивает, и
        // рисунок читается как «текст под фото».
        $card = 'x="' . self::n(self::PAD) . '" y="' . self::n(self::PAD + 8) . '" width="30" height="18" rx="2"';
        $out .= '<rect class="variant-card__solid" ' . $card . ' fill="currentColor" fill-opacity="0"/>';
        $out .= '<rect ' . $card . ' fill="currentColor" opacity="0.08" stroke="currentColor" stroke-opacity="0.55" stroke-width="1"/>';
        $out .= self::line(self::PAD + 3, self::PAD + 12, 20, 0.55);
        $out .= self::line(self::PAD + 3, self::PAD + 17, 24, 0.3);
        $out .= self::line(self::PAD + 3, self::PAD + 21, 16, 0.3);

        return $out;
    }

    /** Фото во всю ширину, под ним подпись слева и текст со сдвигом. */
    private static function wide(): string
    {
        $w = self::W - self::PAD * 2;
        $out = self::box(self::PAD, self::PAD, $w, 18, 0.3);
        $out .= self::line(self::PAD, self::PAD + 23, $w * 0.2, 0.3);
        $out .= self::line(self::PAD + $w * 0.35, self::PAD + 23, $w * 0.45, 0.55);
        $out .= self::line(self::PAD + $w * 0.35, self::PAD + 28, $w * 0.6, 0.3);

        return $out;
    }

    /** Три показателя: крупное число, под ним полоса пройденного пути и черта цели. */
    private static function goal(): string
    {
        $out = '';
        $gap = 4;
        $w = (self::W - self::PAD * 2 - $gap * 2) / 3;
        foreach ([0.7, 0.45, 0.85] as $i => $share) {
            $x = self::PAD + $i * ($w + $gap);
            $out .= self::box($x, self::PAD + 6, $w * 0.6, 7, 0.65, 1);
            $out .= self::line($x, self::PAD + 16, $w * 0.8, 0.3);
            $out .= self::box($x, self::PAD + 23, $w, 3, 0.18, 1.5);
            $out .= self::box($x, self::PAD + 23, $w * $share, 3, 0.7, 1.5);
            $out .= self::box($x + $w - 0.8, self::PAD + 21, 0.8, 7, 0.55, 0);
        }

        return $out;
    }

    /** Линия от базы к цели: пройденная часть, текущая точка и подписи под точками. */
    private static function axis(): string
    {
        $out = '';
        foreach ([self::PAD + 6, self::H / 2 + 8] as $y) {
            $width = self::W - self::PAD * 2;
            $out .= self::box(self::PAD, $y, $width, 2, 0.2, 1);
            $out .= self::box(self::PAD, $y, $width * 0.6, 2, 0.6, 1);
            foreach ([0.0, 0.6, 1.0] as $i => $at) {
                $x = self::PAD + $width * $at;
                $out .= '<circle cx="' . self::n($x) . '" cy="' . self::n($y + 1) . '" r="2.2" fill="currentColor" opacity="'
                    . ($i === 1 ? '0.9' : '0.4') . '"/>';
            }
            $out .= self::line(self::PAD, $y + 5, 8, 0.3);
            $out .= self::line(self::W - self::PAD - 8, $y + 5, 8, 0.3);
        }

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

    /**
     * Вопросы строками с «+» у правого края; с `aside` слева колонка
     * заголовка и пояснения, закреплённая при прокрутке.
     *
     * @param list<string> $mods
     */
    private static function qa(array $mods): string
    {
        $out = '';
        $left = self::PAD;
        if (in_array('aside', $mods, true)) {
            $out .= self::line(self::PAD, self::PAD + 1, 18, 0.6, 2.5);
            $out .= self::line(self::PAD, self::PAD + 7, 20, 0.3);
            $out .= self::line(self::PAD, self::PAD + 11, 14, 0.3);
            $left = self::PAD + 26;
        }
        $right = self::W - self::PAD;
        $rows = 4;
        $step = (self::H - self::PAD * 2) / $rows;
        for ($i = 0; $i < $rows; $i++) {
            $top = self::PAD + $i * $step;
            $out .= '<rect x="' . self::n($left) . '" y="' . self::n($top) . '" width="' . self::n($right - $left)
                . '" height="0.6" fill="currentColor" opacity="0.25"/>';
            $out .= self::line($left, $top + $step / 2 - 1, ($right - $left) * ($i % 2 === 0 ? 0.62 : 0.5), 0.5);
            $out .= self::line($right - 4, $top + $step / 2 - 1, 4, 0.6);
            $out .= '<rect x="' . self::n($right - 2.6) . '" y="' . self::n($top + $step / 2 - 2.4) . '" width="1.2" height="4" fill="currentColor" opacity="0.6"/>';
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
        // Заливка карточки различает виды: рамка — как в теме, плотная —
        // тёмная, самая плотная — цвет акцента, едва заметная с обводкой —
        // светлая. Без этого четыре плитки выбора были бы одинаковыми.
        $out = match (true) {
            in_array('accent', $mods, true) => self::box($x, $y, $w, $h, 0.62),
            in_array('photo', $mods, true) => self::box($x, $y, $w, $h, 0.32),
            in_array('bordered', $mods, true) => self::box($x, $y, $w, $h, 0.08) . self::frame($x, $y, $w, $h),
            default => self::frame($x, $y, $w, $h),
        };
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
