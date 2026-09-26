<?php

declare(strict_types=1);

namespace App\Core\BlockData;

use App\Core\BlockVisibility;
use App\Core\MediaPosition;
use App\Core\UrlGuard;

/**
 * Общие настройки внешнего вида и условий показа для любого типа блока.
 */
final class BlockPresentationNormalizer
{
    /** @var list<string> */
    /**
     * Ритм секции: шесть ступеней от «нет» до «максимального». Прежде их было
     * четыре, и между «Малым» (14–24px) и «Премиумом» (28–56px) лежала
     * двукратная ступень — а ритм, ради которого отступы и настраиваются
     * («маленький снизу притягивает заголовок к следующему блоку, крупный
     * сверху отделяет тяжёлую секцию»), живёт как раз между ними.
     *
     * Имена значений оставлены прежними (`small`, `premium`, `max`): они
     * лежат в данных блоков, в шаблонах страниц и в классах уже собранных
     * страниц, а переименование ради красоты ряда потребовало бы миграции
     * JSON и сброса кэша ради подписи в форме. Подписи для редактора — в
     * SPACING_LABELS, порядок ряда задаёт сам список.
     *
     * @var list<string>
     */
    public const SPACING = ['none', 'xs', 'small', 'mid', 'premium', 'max'];

    /**
     * Подписи ступеней и та же шкала для полей «Отступ сверху/снизу».
     * Объявлены здесь, потому что форма блока, нормализатор и рендерер
     * читают один и тот же ряд: три списка разъехались бы при первом же
     * добавлении ступени.
     *
     * @var array<string, string>
     */
    public const SPACING_LABELS = [
        'none' => 'Нет',
        'xs' => 'Компактный',
        'small' => 'Малый',
        'mid' => 'Средний',
        'premium' => 'Большой',
        'max' => 'Максимальный',
    ];

    /**
     * Ступень → переменная ритма секций (см. frontend.css). Читают рендерер
     * (для --block-pad-top/bottom) и правила .cms-block--space-*.
     *
     * @var array<string, string>
     */
    public const SPACING_VARS = [
        'none' => '0',
        'xs' => 'var(--section-space-xs)',
        'small' => 'var(--section-space-s)',
        'mid' => 'var(--section-space-m)',
        'premium' => 'var(--section-space-l)',
        'max' => 'var(--section-space-xl)',
    ];

    /**
     * Прежние значения полей «Отступ сверху/снизу». Ряд у них был свой
     * (`small`/`medium`/`large`), и те же три величины назывались в «воздухе»
     * иначе (`small`/`premium`/`max`) — одно и то же слово значило в двух
     * полях разное. Теперь ряд один, а прежние имена приводятся к нему на
     * входе, а не переписываются в базе (тем же приёмом, каким переехали типы
     * блоков): величина при этом сохраняется, поэтому вид уже собранных
     * страниц не меняется. Средняя ступень названа `mid` именно потому, что
     * слово `medium` занято прежним смыслом и переиспользовать его значило бы
     * молча уменьшить отступ там, где редактор когда-то выбрал «Средний».
     *
     * @var array<string, string>
     */
    private const PADDING_LEGACY = ['medium' => 'premium', 'large' => 'max'];

    /** @var list<string> */
    private const REVEAL_TYPES = ['fade', 'slide-up', 'slide-left', 'slide-right', 'zoom-in', 'stagger'];

    /** @var list<string> */
    private const BACKGROUNDS = ['none', 'light', 'tint', 'navy'];

    /** @var list<string> */
    private const SURFACES = ['flat', 'card'];

    /** @var list<string> */
    /** @var list<string> */
    private const PADDINGS = ['default', 'none', 'xs', 'small', 'mid', 'premium', 'max'];

    /** Минимальная высота секции: пусто — по содержимому. */
    private const MIN_HEIGHTS = ['small', 'medium', 'large', 'screen'];

    /** Привязка фоновой надписи к краю; точное место доводится смещением. */
    private const WATERMARK_X = ['left', 'center', 'right'];

    private const WATERMARK_Y = ['top', 'middle', 'bottom'];



    /** Способ залить фон секции: пресет темы, свой цвет, градиент, фото, узор. */
    private const BACKGROUND_MODES = ['preset', 'color', 'gradient', 'image', 'pattern'];

    /** Встроенные узоры: рисуются градиентами и маской, файлов не требуют. */
    private const PATTERNS = ['dots', 'grid', 'diagonal', 'emblem'];

    /**
     * Заливка секции: пресет темы, свой цвет, градиент или фотография.
     *
     * Режимы взаимно исключают друг друга — два фона на одной секции дают
     * кашу, и предугадать, какой из них «главный», нельзя. Поэтому режим
     * один, а лишние значения в данные блока просто не попадают.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    /** Целое в границах; пусто — умолчание, а не край диапазона. */
    private static function ranged(mixed $value, int $min, int $max, int $default): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    public static function background(array $input): array
    {
        $mode = self::scalarString($input['bg_mode'] ?? null, 'preset');
        if (!in_array($mode, self::BACKGROUND_MODES, true)) {
            $mode = 'preset';
        }

        // У CTA и Hero есть собственное поле `bg_color`. Общая заливка
        // секции приходит под отдельным именем, иначе два одноимённых input
        // в форме перезаписывают друг друга ещё до нормализации PHP. Старое
        // имя принимаем для импортов, API и ранее написанных тестов.
        $colorField = array_key_exists('section_bg_color', $input)
            || array_key_exists('section_bg_color_off', $input)
            ? 'section_bg_color'
            : 'bg_color';
        $color = BlockDataInput::optionalColor($input, $colorField);
        $from = self::color($input['bg_gradient_from'] ?? null);
        $to = self::color($input['bg_gradient_to'] ?? null);
        $image = trim(self::scalarString($input['bg_image'] ?? null));
        if ($image !== '' && !UrlGuard::isSafeMedia($image)) {
            $image = '';
        }

        // Режим без своего значения — обычный пресет: пустая секция с
        // включённым «градиентом» без цветов выглядела бы сломанной.
        if (($mode === 'color' && $color === '')
            || ($mode === 'gradient' && ($from === '' || $to === ''))
            || ($mode === 'image' && $image === '')) {
            $mode = 'preset';
        }

        // Цвет текста от режима фона не зависит: он нужен и пресету navy, и
        // секции вовсе без своей заливки. Раньше этот код стоял после выхода
        // по «preset» и до таких секций просто не доходил — из-за чего у navy
        // цвет текста было нечем настроить.
        $colors = self::sectionColors($input);

        if ($mode === 'preset') {
            return ['_bg_mode' => 'preset'] + $colors;
        }

        $data = ['_bg_mode' => $mode];
        if ($mode === 'color') {
            $data['_bg_color'] = $color;
        }
        if ($mode === 'gradient') {
            $data['_bg_gradient_from'] = $from;
            $data['_bg_gradient_to'] = $to;
            $data['_bg_gradient_angle'] = max(0, min(360, (int) ($input['bg_gradient_angle'] ?? 135)));
        }
        if ($mode === 'image') {
            $data['_bg_image'] = $image;
            // Загруженный файл бывает и снимком, и плиткой узора: у плитки
            // важен размер повтора, у снимка — какая часть кадра видна.
            $repeat = self::scalarString($input['bg_repeat'] ?? null, 'cover');
            $data['_bg_repeat'] = $repeat === 'tile' ? 'tile' : 'cover';
            if ($data['_bg_repeat'] === 'tile') {
                $data['_bg_tile_size'] = max(16, min(600, (int) ($input['bg_tile_size'] ?? 120)));
                // Затемнение — приём для фотографии; поверх плитки-узора оно
                // просто гасит секцию, поэтому по умолчанию его нет.
                $data['_bg_overlay'] = max(0, min(80, (int) ($input['bg_overlay'] ?? 0)));
            } else {
                $data['_bg_overlay'] = max(0, min(80, (int) ($input['bg_overlay'] ?? 45)));
                $data['_bg_position'] = MediaPosition::normalize($input['bg_position'] ?? null);
                $data['_bg_fixed'] = !empty($input['bg_fixed']);
            }
        }
        if ($mode === 'pattern') {
            $pattern = self::scalarString($input['bg_pattern'] ?? null, 'dots');
            $data['_bg_pattern'] = in_array($pattern, self::PATTERNS, true) ? $pattern : 'dots';
            // Узор лежит на заливке: без неё он висел бы на фоне страницы и
            // «полноширинная» секция теряла бы границы.
            $data['_bg_color'] = $color;
            $data['_bg_pattern_color'] = self::color($input['bg_pattern_color'] ?? null);
            $data['_bg_pattern_opacity'] = max(3, min(60, (int) ($input['bg_pattern_opacity'] ?? 22)));
            $data['_bg_pattern_size'] = max(8, min(240, (int) ($input['bg_pattern_size'] ?? 28)));
        }
        return $data + $colors;
    }

    /**
     * Цвет текста секции и цвет вложенных карточек — два независимых уровня.
     *
     * Схема секции красит только её собственный текст; карточка со своей
     * поверхностью объявляет цвета заново (App\Core\SectionColors::SURFACES),
     * поэтому белый блок внутри тёмной секции остаётся с тёмным текстом.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function sectionColors(array $input): array
    {
        // По фону угадать нельзя: фотография бывает и светлой, и тёмной.
        $textScheme = self::scalarString($input['bg_text_scheme'] ?? null, 'auto');
        $textScheme = in_array($textScheme, \App\Core\SectionColors::TEXT_SCHEMES, true) ? $textScheme : 'auto';

        $cardScheme = self::scalarString($input['bg_card_scheme'] ?? null, 'auto');
        $cardScheme = in_array($cardScheme, \App\Core\SectionColors::CARD_SCHEMES, true) ? $cardScheme : 'auto';

        return [
            '_bg_text_scheme' => $textScheme,
            '_bg_text_color' => self::color($input['bg_text_color'] ?? null),
            '_bg_card_scheme' => $cardScheme,
            // Прежний флажок «Светлый текст» держим в синхроне: на него смотрят
            // страницы, сохранённые до появления настройки.
            '_bg_light_text' => $textScheme === 'light',
        ];
    }

    /** Цвет из формы: принимаем только #rrggbb, остальное — «не задан». */
    private static function color(mixed $value): string
    {
        $value = trim(self::scalarString($value));

        return preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 ? strtolower($value) : '';
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    /**
     * Оформление, с которым создаётся новый блок: пустое.
     *
     * Появления при прокрутке по умолчанию нет — его включает редактор там,
     * где блок нужно показать. Правило выведено из замера: умолчание
     * «появление у всех, кроме первого и вложенного» давало на демо-главной
     * пять въезжающих секций из шести, а это ровно тот приём, по которому
     * страница читается шаблонной (навык frontend-design, DESIGN_PLAN 4.3).
     * Демо и сборки получают одно появление на страницу (PagePresets).
     *
     * @return array<string, mixed>
     */
    public static function newBlockPresentation(bool $isFirstOnPage, bool $nested): array
    {
        // Появление при прокрутке — решение редактора, а не умолчание: одно
        // продуманное появление на странице сильнее пяти одинаковых, а
        // «каждая секция въезжает» — первый признак шаблонной страницы
        // (DESIGN_PLAN 4.3, навык frontend-design). Прежде умолчание включало
        // его всем блокам, кроме первого и вложенных, и на демо-главной
        // анимировались пять секций из шести. Параметры остаются: исключения
        // понадобятся, если умолчание когда-нибудь вернётся.
        unset($isFirstOnPage, $nested);

        return [];
    }

    public static function normalize(array $input): array
    {
        $anchor = self::normalizeAnchor(self::scalarString($input['anchor'] ?? null));
        $spacing = self::scalarString($input['spacing'] ?? null, 'premium');
        $revealType = self::scalarString($input['reveal_type'] ?? null);
        $background = self::scalarString($input['bg'] ?? null, 'none');
        $surface = self::scalarString($input['surface'] ?? null, 'flat');
        $padTop = self::padding(self::scalarString($input['pad_top'] ?? null, 'default'));
        $padBottom = self::padding(self::scalarString($input['pad_bottom'] ?? null, 'default'));
        $device = self::scalarString($input['visible_device'] ?? null);

        $normalized = [
            '_anchor' => $anchor,
            '_spacing' => self::spacing($spacing),
            '_reveal' => in_array($revealType, self::REVEAL_TYPES, true)
                ? ['enabled' => true, 'type' => $revealType]
                : ['enabled' => false, 'type' => 'fade'],
            '_bg' => in_array($background, self::BACKGROUNDS, true) ? $background : 'none',
            '_surface' => in_array($surface, self::SURFACES, true) ? $surface : 'flat',
            '_fullwidth' => !empty($input['fullwidth']),
            '_pad_top' => $padTop,
            '_pad_bottom' => $padBottom,
            '_visible_from' => BlockVisibility::normalize(self::scalarString($input['visible_from'] ?? null)),
            '_visible_to' => BlockVisibility::normalize(self::scalarString($input['visible_to'] ?? null)),
            '_visible_device' => in_array($device, ['desktop', 'mobile'], true) ? $device : '',
        ];

        // Фоновая надпись секции — крупное слово за содержимым. Текст лежит
        // в данных блока, а блоки у каждого языка свои, поэтому отдельной
        // таблицы перевода тут не нужно: узбекская версия страницы правит
        // свою надпись сама.
        $watermark = trim(self::scalarString($input['watermark'] ?? null));
        if ($watermark !== '') {
            $normalized['_watermark'] = mb_substr($watermark, 0, 120);
            // Размер и место — числами, а не пресетами: нужный кегль зависит
            // от длины слова, и угадать его заранее нельзя.
            $x = self::scalarString($input['watermark_x'] ?? null, 'center');
            $y = self::scalarString($input['watermark_y'] ?? null, 'middle');
            $normalized['_watermark_size'] = self::ranged($input['watermark_size'] ?? null, 2, 60, 22);
            $normalized['_watermark_x'] = in_array($x, self::WATERMARK_X, true) ? $x : 'center';
            $normalized['_watermark_y'] = in_array($y, self::WATERMARK_Y, true) ? $y : 'middle';
            $normalized['_watermark_opacity'] = self::ranged($input['watermark_opacity'] ?? null, 0, 100, 12);
        }

        // Короткая секция обрезает фотографию-фон до полоски, поэтому высоту
        // можно задать отдельно от отступов.
        $minHeight = self::scalarString($input['min_height'] ?? null);
        if (in_array($minHeight, self::MIN_HEIGHTS, true)) {
            $normalized['_min_height'] = $minHeight;
        }

        $normalized += self::background($input);

        return $normalized;
    }

    /**
     * Пользовательский якорь блока: в форме можно вставить и `forma`, и
     * привычное `#forma`. Ограниченный slug безопасен для HTML id и URL.
     */
    public static function normalizeAnchor(string $value): string
    {
        $value = mb_strtolower(ltrim(trim($value), '#'));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
        $value = trim($value, '-_');

        // Технические id секций имеют вид block-123. Не даём пользовательскому
        // якорю случайно создать второй такой же id на странице.
        if ($value === '' || preg_match('/^block-[0-9]+$/', $value) === 1) {
            return '';
        }

        return mb_substr($value, 0, 80);
    }

    /** @param array<string, mixed> $data */
    public static function hasInvalidVisibilityWindow(array $data): bool
    {
        $from = BlockVisibility::parse($data['_visible_from'] ?? '');
        $to = BlockVisibility::parse($data['_visible_to'] ?? '');

        return $from !== null && $to !== null && $to <= $from;
    }

    /**
     * Ступень «воздуха» секции. Отличается от padding() только запасным
     * значением: у «воздуха» ступени `default` нет — не выбрана ни одна,
     * значит действует прежнее умолчание `premium`.
     */
    public static function spacing(string $value): string
    {
        $value = self::PADDING_LEGACY[$value] ?? $value;

        return in_array($value, self::SPACING, true) ? $value : 'premium';
    }

    /**
     * Ступень отступа сверху/снизу: прежние имена приводятся к текущему ряду,
     * неизвестное значение — «по умолчанию» (то есть пресет «воздуха»).
     *
     * Публичный, потому что читателя два: форма (через normalize) и вывод —
     * данные блока приезжают из базы и из файла шаблона страницы, где лежат
     * имена, сохранённые до появления общего ряда.
     */
    public static function padding(string $value): string
    {
        $value = self::PADDING_LEGACY[$value] ?? $value;

        return in_array($value, self::PADDINGS, true) ? $value : 'default';
    }

    private static function scalarString(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
