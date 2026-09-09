<?php

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Core\Hero\HeroPresets;
use App\Core\Hero\HeroSlideData;
use App\Models\HeroSlide;
use App\Models\Language;

/** @var array $hero */
/** @var array<string, mixed> $settings */
/** @var array $slides */
/** @var array<int, list<string>> $langMap */
/** @var array $usage */

$heroId = (int) $hero['id'];
$pageTitle = 'Обложка: ' . $hero['name'];
$activeNav = 'heroes';
require __DIR__ . '/../layout/header.php';

$siteLangs = array_map(static fn (array $l): string => (string) $l['code'], Language::active());

/** Значение для input[type=datetime-local] из хранимого DATETIME. */
$dt = static fn (mixed $value): string => trim((string) $value) === ''
    ? ''
    : str_replace(' ', 'T', substr((string) $value, 0, 16));

/** Пара «число + единица» из готовой CSS-длины. */
$splitLength = static function (string $value, string $unitDefault): array {
    return preg_match('/^(\d+(?:\.\d+)?)(px|vh|dvh|rem|%|vw)$/', $value, $m) === 1
        ? [$m[1], $m[2]]
        : ['', $unitDefault];
};
[$heightValue, $heightUnit] = $splitLength((string) $settings['height_value'], 'px');
[$heightMobileValue, $heightMobileUnit] = $splitLength((string) $settings['height_mobile_value'], 'px');
[$textWidthValue, $textWidthUnit] = $splitLength((string) $settings['text_width'], 'px');

$select = static function (string $name, string $label, array $options, string $current, string $hint = ''): string {
    $html = '<div class="form-field"><label for="hero_' . $name . '">' . htmlspecialchars($label, ENT_QUOTES) . '</label>'
        . '<select id="hero_' . $name . '" name="' . $name . '">';
    foreach ($options as $value => $option) {
        $html .= '<option value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '"'
            . ((string) $value === $current ? ' selected' : '') . '>'
            . htmlspecialchars((string) $option, ENT_QUOTES) . '</option>';
    }
    $html .= '</select>';
    if ($hint !== '') {
        $html .= '<span class="form-hint">' . htmlspecialchars($hint, ENT_QUOTES) . '</span>';
    }

    return $html . '</div>';
};

$checkbox = static function (string $name, string $label, bool $checked, string $hint = ''): string {
    $html = '<div class="form-field form-field--checkbox">'
        . '<input type="checkbox" id="hero_' . $name . '" name="' . $name . '" value="1"' . ($checked ? ' checked' : '') . '>'
        . '<label for="hero_' . $name . '">' . htmlspecialchars($label, ENT_QUOTES) . '</label>';
    if ($hint !== '') {
        $html .= '<span class="form-hint">' . htmlspecialchars($hint, ENT_QUOTES) . '</span>';
    }

    return $html . '</div>';
};

/**
 * Свёрнутая группа настроек: заголовок, пояснение и текущее значение.
 *
 * Настроек у обложки четыре десятка, и развёрнутыми они дают полотно, в
 * котором ничего не найти. Свёрнутая группа экономит место, но прячет
 * состояние — поэтому в заголовке показываем то, что сейчас выбрано.
 */
$group = static function (string $title, string $hint, string $state, string $body): string {
    $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES);

    return '<details class="form-section"><summary>' . $esc($title)
        . ($hint !== '' ? ' <span class="form-section__hint">' . $esc($hint) . '</span>' : '')
        . ($state !== '' ? '<span class="form-section__state">' . $esc($state) . '</span>' : '')
        . '</summary><div class="form-section__body form-section__body--grid">' . $body . '</div></details>';
};

$sizeOptions = ['s' => 'Мелкий', 'm' => 'Средний', 'l' => 'Крупный', 'xl' => 'Очень крупный'];
$subtitleSizes = ['s' => 'Мелкий', 'm' => 'Средний', 'l' => 'Крупный'];
$posOptions = ['left' => 'Слева', 'center' => 'По центру', 'right' => 'Справа'];
$yOptions = ['top' => 'Сверху', 'center' => 'По центру', 'bottom' => 'Снизу'];
$heightOptions = [
    'compact' => 'Невысокая',
    'regular' => 'Обычная',
    'tall' => 'Высокая',
    'full' => 'На весь экран',
    'custom' => 'Своя высота',
];
$overlayDirections = [
    'auto' => 'Автоматически (от стороны с текстом)',
    'to_right' => 'Слева направо', 'to_left' => 'Справа налево',
    'to_bottom' => 'Сверху вниз', 'to_top' => 'Снизу вверх',
    'to_bottom_right' => 'В правый нижний угол', 'to_bottom_left' => 'В левый нижний угол',
    'to_top_right' => 'В правый верхний угол', 'to_top_left' => 'В левый верхний угол',
];
?>
<p><a href="/admin/heroes" class="btn btn--small">← Все обложки</a></p>

<div class="form-card">
    <?= AdminUi::cardHeader('Слайды', 'layout-cards') ?>
    <p class="form-hint">
        Порядок слайдов задаётся перетаскиванием и в точности повторяется на сайте.
        Выключенный слайд остаётся в списке, но не показывается посетителям.
    </p>

    <?php if (empty($slides)): ?>
        <p class="form-hint">Слайдов пока нет. Обложка без слайдов на странице не выводится.</p>
    <?php else: ?>
        <ol class="hero-slides" data-hero-sortable data-hero-id="<?= $heroId ?>" data-csrf="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
            <?php foreach ($slides as $index => $slide): ?>
                <?php
                $slideId = (int) $slide['id'];
                $data = $slide['data'];
                $preview = HeroSlideData::fallbackImage($data);
                $active = (int) $slide['is_active'] === 1;
                $mediaLabels = ['image' => 'Фото', 'video' => 'Видео', 'youtube' => 'YouTube', 'none' => 'Без фона'];
                $editUrl = '/admin/heroes/' . $heroId . '/slides/' . $slideId . '/edit';
                ?>
                <li class="hero-slide-item<?= $active ? '' : ' is-off' ?>" draggable="true" data-hero-slide-id="<?= $slideId ?>">
                    <span class="hero-slide-item__grip" aria-hidden="true"><?= AdminUi::icon('grip-vertical') ?></span>
                    <span class="hero-slide-item__preview">
                        <?php if ($preview !== ''): ?>
                            <img src="<?= htmlspecialchars($preview, ENT_QUOTES) ?>" alt="" loading="lazy" decoding="async">
                        <?php else: ?>
                            <span class="hero-slide-item__placeholder" aria-hidden="true"><?= AdminUi::icon('photo', 22) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="hero-slide-item__num"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <span class="hero-slide-item__body">
                        <a class="hero-slide-item__title" href="<?= $editUrl ?>">
                            <?= htmlspecialchars(trim((string) $slide['title']) !== '' ? (string) $slide['title'] : 'Без заголовка', ENT_QUOTES) ?>
                        </a>
                        <span class="hero-slide-item__meta">
                            <?php
                            // Схему тут не показываем: она принадлежит обложке
                            // и у всех слайдов одна — строка повторялась бы в
                            // каждой и ничего не различала.
                            ?>
                            <?= htmlspecialchars($mediaLabels[(string) $data['media_type']] ?? '—', ENT_QUOTES) ?>
                            <?php if (!$active): ?> · выключен<?php endif; ?>
                            <?php if ($data['_visible_from'] !== '' || $data['_visible_to'] !== ''): ?> · по расписанию<?php endif; ?>
                        </span>
                        <span class="hero-slide-item__langs"><?= \App\Core\View::renderPartial('admin/layout/lang_badges', [
                            'siteLangs' => $siteLangs,
                            'has' => $langMap[$slideId] ?? [],
                            'translationEditUrl' => $editUrl . '#translations',
                            'translationDefaultCode' => Language::defaultCode(),
                        ]) ?></span>
                    </span>
                    <span class="hero-slide-item__actions">
                        <a class="btn btn--small" href="<?= $editUrl ?>">Изменить</a>
                        <form class="u-inline-0cd28ce9ba" method="post" action="/admin/heroes/<?= $heroId ?>/slides/<?= $slideId ?>/toggle">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small"><?= $active ? 'Выключить' : 'Включить' ?></button>
                        </form>
                        <form class="u-inline-0cd28ce9ba" method="post" action="/admin/heroes/<?= $heroId ?>/slides/<?= $slideId ?>/duplicate">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small">Дублировать</button>
                        </form>
                        <form class="u-inline-0cd28ce9ba" method="post" action="/admin/heroes/<?= $heroId ?>/slides/<?= $slideId ?>/delete"
                              data-confirm="Удалить слайд?">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--danger" aria-label="Удалить слайд"><?= AdminUi::icon('trash') ?></button>
                        </form>
                    </span>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>

    <form method="post" action="/admin/heroes/<?= $heroId ?>/slides/create">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn--primary" <?= count($slides) >= HeroSlide::MAX_SLIDES ? 'disabled' : '' ?>>
            + Добавить слайд
        </button>
        <?php if (count($slides) >= HeroSlide::MAX_SLIDES): ?>
            <span class="form-hint">Достигнут предел — <?= HeroSlide::MAX_SLIDES ?> слайдов.</span>
        <?php endif; ?>
    </form>
</div>

<div class="form-card">
    <?= AdminUi::cardHeader('Пресеты', 'wand') ?>
    <p class="form-hint">
        Пресет разом ставит набор настроек оформления. Это разовое действие, а не режим:
        после применения любую настройку можно поправить вручную, ничего не блокируется.
    </p>
    <form method="post" action="/admin/heroes/<?= $heroId ?>/preset" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="preset">Пресет</label>
            <select id="preset" name="preset">
                <?php foreach (HeroPresets::PRESETS as $key => $preset): ?>
                    <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                            <?= (string) $hero['preset'] === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) $preset['label'], ENT_QUOTES) ?> — <?= htmlspecialchars((string) $preset['hint'], ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Применить пресет</button>
        </div>
    </form>
</div>

<form method="post" action="/admin/heroes/<?= $heroId ?>/update">
    <?= Csrf::field() ?>

    <div class="form-card">
        <?= AdminUi::cardHeader('Публикация', 'calendar-event') ?>
        <div class="form-grid form-grid--dense">
            <div class="form-field">
                <label for="hero_name">Название</label>
                <input type="text" id="hero_name" name="name" value="<?= htmlspecialchars((string) $hero['name'], ENT_QUOTES) ?>" required>
                <span class="form-hint">Видно только в админке.</span>
            </div>
            <?= $select('status', 'Статус', [
                'draft' => 'Черновик',
                'published' => 'Опубликована',
                'scheduled' => 'По расписанию',
            ], (string) $hero['status'], 'Черновик и обложка вне своего окна показа на сайт не попадают: блок в этом случае просто ничего не выводит.') ?>
            <div class="form-field">
                <label for="published_from">Показывать с</label>
                <input type="datetime-local" id="published_from" name="published_from" value="<?= htmlspecialchars($dt($hero['published_from']), ENT_QUOTES) ?>">
            </div>
            <div class="form-field">
                <label for="published_to">Показывать до</label>
                <input type="datetime-local" id="published_to" name="published_to" value="<?= htmlspecialchars($dt($hero['published_to']), ENT_QUOTES) ?>">
                <span class="form-hint">Даты работают только при статусе «По расписанию». Обе пустые — обложка не покажется.</span>
            </div>
            <div class="form-field">
                <label for="priority">Приоритет</label>
                <input type="number" id="priority" name="priority" min="-999" max="999" value="<?= (int) $hero['priority'] ?>">
                <span class="form-hint">Подсказка редактору при выборе обложки в блоке: чем больше, тем выше в списке.</span>
            </div>
        </div>
        <?php if (!empty($usage)): ?>
            <p class="form-hint">
                Обложка стоит на страницах:
                <?php foreach ($usage as $i => $place): ?>
                    <?= $i > 0 ? ', ' : '' ?>
                    <a href="/admin/pages/<?= (int) $place['page_id'] ?>/edit"><?= htmlspecialchars((string) ($place['title'] ?? ('#' . $place['page_id'])), ENT_QUOTES) ?></a>
                <?php endforeach; ?>
            </p>
        <?php endif; ?>
    </div>

    <?php
    // Короткие сводки текущих значений — их видно, не открывая группу.
    $widthLabels = ['full' => 'Во всю ширину', 'standard' => 'По контейнеру'];
    $navLabels = [
        'none' => 'без индикатора', 'dots' => 'точки', 'counter' => 'счётчик',
        'progress' => 'полоса', 'counter_progress' => 'счётчик и полоса', 'thumbs' => 'миниатюры',
    ];
    $transitionLabels = [
        'fade' => 'Fade', 'slide' => 'Slide', 'fade_slide' => 'Fade + Slide', 'kenburns' => 'Ken Burns',
    ];
    $overlayLabels = ['none' => 'без наложения', 'solid' => 'сплошное', 'gradient' => 'градиент'];
    ?>
    <div class="form-card">
        <?= AdminUi::cardHeader('Оформление', 'palette') ?>
        <p class="form-hint">
            Группы свёрнуты, чтобы форму можно было окинуть взглядом: рядом с названием
            каждой видно текущее значение. Открывайте ту, которую правите — остальные
            настройки при сохранении не теряются.
        </p>

        <?php
        ob_start(); ?>
            <?= $select('width', 'Ширина секции', ['full' => 'Во всю ширину', 'standard' => 'По ширине контейнера'], (string) $settings['width'],
                'Во всю ширину — фон уходит за края экрана, текст остаётся в рамках сайта.') ?>
            <?= $select('height', 'Высота', $heightOptions, (string) $settings['height']) ?>
            <div class="form-field">
                <label for="height_value">Своя высота</label>
                <span class="image-field__controls">
                    <input type="number" id="height_value" name="height_value" min="1" step="1" value="<?= htmlspecialchars($heightValue, ENT_QUOTES) ?>">
                    <select name="height_unit" aria-label="Единица высоты">
                        <?php foreach (['px', 'vh', 'dvh', 'rem'] as $unit): ?>
                            <option value="<?= $unit ?>" <?= $heightUnit === $unit ? 'selected' : '' ?>><?= $unit ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
                <span class="form-hint">Действует при высоте «Своя высота».</span>
            </div>
            <?= $select('height_mobile', 'Высота на телефоне', ['' => 'Как на десктопе'] + $heightOptions, (string) $settings['height_mobile'],
                'Широкий кадр на узком экране режется до полоски: своя высота лечит это, не трогая десктоп.') ?>
            <div class="form-field">
                <label for="height_mobile_value">Своя высота на телефоне</label>
                <span class="image-field__controls">
                    <input type="number" id="height_mobile_value" name="height_mobile_value" min="1" step="1" value="<?= htmlspecialchars($heightMobileValue, ENT_QUOTES) ?>">
                    <select name="height_mobile_unit" aria-label="Единица высоты на телефоне">
                        <?php foreach (['px', 'vh', 'dvh', 'rem'] as $unit): ?>
                            <option value="<?= $unit ?>" <?= $heightMobileUnit === $unit ? 'selected' : '' ?>><?= $unit ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
            </div>
        <?php echo $group('Геометрия', 'ширина и высота, отдельно для телефона',
            ($widthLabels[$settings['width']] ?? '') . ' · ' . ($heightOptions[$settings['height']] ?? ''),
            (string) ob_get_clean()); ?>

        <?php
        ob_start(); ?>
            <?= $select('text_position', 'Текст по горизонтали', $posOptions, (string) $settings['text_position']) ?>
            <?= $select('text_align_y', 'Текст по вертикали', $yOptions, (string) $settings['text_align_y']) ?>
            <div class="form-field">
                <label for="text_width_value">Ширина текстовой колонки</label>
                <span class="image-field__controls">
                    <input type="number" id="text_width_value" name="text_width_value" min="1" step="1" value="<?= htmlspecialchars($textWidthValue, ENT_QUOTES) ?>">
                    <select name="text_width_unit" aria-label="Единица ширины">
                        <?php foreach (['px', '%', 'vw'] as $unit): ?>
                            <option value="<?= $unit ?>" <?= $textWidthUnit === $unit ? 'selected' : '' ?>><?= $unit ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
                <span class="form-hint">Пусто — ширина темы.</span>
            </div>
            <?= $select('title_size', 'Размер заголовка', $sizeOptions, (string) $settings['title_size']) ?>
            <?= $select('subtitle_size', 'Размер описания', $subtitleSizes, (string) $settings['subtitle_size']) ?>
            <?php
            // Отступ всего блока сверху — отдельно от промежутков между
            // частями: те у первой части не действуют, и опустить колонку
            // целиком ими нельзя.
            ?>
            <div class="form-field">
                <label for="text_offset_top">Отступ текста сверху, px</label>
                <input type="number" id="text_offset_top" name="text_offset_top" min="0" max="200" step="1"
                       value="<?= (int) $settings['text_offset_top'] ?>">
                <span class="form-hint">
                    Опускает весь текстовый блок. 0 — как раньше. На телефоне
                    отступ не занимает больше десятой части высоты экрана.
                </span>
            </div>
        <?php echo $group('Контент и типографика', 'положение текста и размеры',
            ($posOptions[$settings['text_position']] ?? '') . ' · заголовок ' . mb_strtolower((string) ($sizeOptions[$settings['title_size']] ?? '')),
            (string) ob_get_clean()); ?>

        <?php
        ob_start(); ?>
            <div class="form-field form-field--wide">
                <span class="form-hint">
                    Схема обложки задаёт её фон и цвет её собственного текста. На вложенные
                    компоненты со своей поверхностью она не распространяется: белая карточка
                    внутри тёмной обложки сохраняет тёмный текст.
                </span>
            </div>
            <?= $select('scheme', 'Схема обложки', [
                'light' => 'Light — светлый фон',
                'dark' => 'Dark — тёмный фон',
                'navy' => 'Navy — фирменный тёмно-синий',
                'custom' => 'Custom — свои цвета',
            ], (string) $settings['scheme']) ?>
            <?= $select('content_scheme', 'Цвет текста', [
                'auto' => 'Auto — по фону и наложению',
                'light' => 'Light — светлый текст',
                'dark' => 'Dark — тёмный текст',
            ], (string) $settings['content_scheme'],
                'Считается отдельно от схемы обложки: на фотографии цвет текста выбирается по наложению — под тёмной вуалью светлый, под светлой тёмный.') ?>
            <div class="form-field">
                <label for="scheme_bg">Свой фон (Custom)</label>
                <input type="color" id="scheme_bg" name="scheme_bg" value="<?= htmlspecialchars((string) $settings['scheme_bg'], ENT_QUOTES) ?>">
            </div>
            <div class="form-field">
                <label for="scheme_text">Свой цвет текста (Custom)</label>
                <input type="color" id="scheme_text" name="scheme_text" value="<?= htmlspecialchars((string) $settings['scheme_text'], ENT_QUOTES) ?>">
            </div>
            <?= AdminUi::colorField('scheme_accent', (string) $settings['scheme_accent'], 'Цвет основной кнопки', '#173a63', 'Акцент из «Дизайна»') ?>
        <?php echo $group('Цветовая схема', 'фон обложки и цвет её текста',
            ucfirst((string) $settings['scheme']) . ' · текст ' . (string) $settings['content_scheme'],
            (string) ob_get_clean()); ?>

        <?php
        ob_start(); ?>
            <div class="form-field form-field--wide">
                <span class="form-hint">Наложение лежит между фоном и текстом и отвечает за читаемость поверх любой фотографии. Цвет решает, что делать с кадром: тёмный затемняет, светлый осветляет. От плотности 25 % и выше по нему же выбирается цвет текста.</span>
            </div>
            <?= $select('overlay', 'Наложение на фон', [
                'none' => 'Нет', 'solid' => 'Сплошное', 'gradient' => 'Градиент',
            ], (string) $settings['overlay']) ?>
            <div class="form-field">
                <label for="overlay_color">Цвет наложения</label>
                <input type="color" id="overlay_color" name="overlay_color" value="<?= htmlspecialchars((string) $settings['overlay_color'], ENT_QUOTES) ?>">
            </div>
            <div class="form-field">
                <label for="overlay_opacity">Плотность наложения, %</label>
                <input type="number" id="overlay_opacity" name="overlay_opacity" min="0" max="100" value="<?= (int) $settings['overlay_opacity'] ?>">
            </div>
            <?= $select('overlay_direction', 'Направление градиента', $overlayDirections, (string) $settings['overlay_direction']) ?>
            <?= $checkbox('panel', 'Подложка под текстом', (bool) $settings['panel']) ?>
            <div class="form-field">
                <label for="panel_color">Цвет подложки</label>
                <input type="color" id="panel_color" name="panel_color" value="<?= htmlspecialchars((string) $settings['panel_color'], ENT_QUOTES) ?>">
            </div>
            <div class="form-field">
                <label for="panel_opacity">Плотность подложки, %</label>
                <input type="number" id="panel_opacity" name="panel_opacity" min="0" max="100" value="<?= (int) $settings['panel_opacity'] ?>">
            </div>
        <?php echo $group('Наложение и подложка', 'затемнить или осветлить кадр ради читаемости',
            ($overlayLabels[$settings['overlay']] ?? '') . ((string) $settings['overlay'] !== 'none' ? ' ' . (int) $settings['overlay_opacity'] . '%' : ''),
            (string) ob_get_clean()); ?>

        <?php
        ob_start(); ?>
            <div class="form-field form-field--wide">
                <span class="form-hint">Навигация занимает собственную полосу внизу и не перекрывает текст и кнопки. Стрелки проявляются по наведению, а на сенсорном экране видны всегда.</span>
            </div>
            <?= $checkbox('nav_arrows', 'Стрелки «назад» и «вперёд»', (bool) $settings['nav_arrows']) ?>
            <?= $checkbox('nav_arrows_mobile', 'Стрелки на телефоне', (bool) $settings['nav_arrows_mobile']) ?>
            <?= $checkbox('nav_swipe', 'Свайп на сенсорном экране', (bool) $settings['nav_swipe']) ?>
            <?= $select('nav_indicator', 'Индикатор', [
                'none' => 'Нет',
                'dots' => 'Точки',
                'counter' => 'Счётчик 01 / 05',
                'progress' => 'Полоса прогресса',
                'counter_progress' => 'Счётчик и полоса (рекомендуется)',
                'thumbs' => 'Миниатюры',
            ], (string) $settings['nav_indicator']) ?>
        <?php echo $group('Навигация', 'стрелки, индикатор, свайп',
            ($settings['nav_arrows'] ? 'стрелки, ' : '') . ($navLabels[$settings['nav_indicator']] ?? ''),
            (string) ob_get_clean()); ?>

        <?php
        ob_start(); ?>
            <div class="form-field form-field--wide">
                <span class="form-hint">
                    Показ сам останавливается при наведении курсора и после любого действия
                    посетителя, а через десять секунд бездействия продолжается; в навигации
                    появляется кнопка паузы — остановить показ должен уметь любой посетитель.
                    На телефоне автопрокрутки нет: там важнее свайп и заряд батареи.
                    Отдельному слайду можно задать свою длительность в его форме.
                </span>
            </div>
            <?= $checkbox('autoplay', 'Включить автопрокрутку', (bool) $settings['autoplay']) ?>
            <div class="form-field">
                <label for="autoplay_interval">Интервал, секунд</label>
                <input type="number" id="autoplay_interval" name="autoplay_interval" min="3" max="30" value="<?= (int) $settings['autoplay_interval'] ?>">
                <span class="form-hint">Разумный диапазон — 5–7 секунд.</span>
            </div>
        <?php echo $group('Автопрокрутка', 'смена слайдов по таймеру',
            $settings['autoplay'] ? 'каждые ' . (int) $settings['autoplay_interval'] . ' с' : 'выключена',
            (string) ob_get_clean()); ?>

        <?php
        ob_start(); ?>
            <?= $select('transition', 'Анимация', [
                'fade' => 'Fade — проявление',
                'slide' => 'Slide — сдвиг',
                'fade_slide' => 'Fade + Slide (рекомендуется)',
                'kenburns' => 'Ken Burns — медленный наезд на фото',
            ], (string) $settings['transition'],
                'При системной настройке «меньше движения» и при включённой остановке анимаций слайды меняются мгновенно.') ?>
            <div class="form-field">
                <label for="transition_duration">Длительность, мс</label>
                <input type="number" id="transition_duration" name="transition_duration" min="150" max="2000" step="50" value="<?= (int) $settings['transition_duration'] ?>">
            </div>
        <?php echo $group('Переход между слайдами', 'анимация смены',
            ($transitionLabels[$settings['transition']] ?? '') . ' · ' . (int) $settings['transition_duration'] . ' мс',
            (string) ob_get_clean()); ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn--primary">Сохранить обложку</button>
    </div>
</form>

<?php require __DIR__ . '/../layout/footer.php'; ?>
