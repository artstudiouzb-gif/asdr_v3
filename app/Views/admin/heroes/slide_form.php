<?php

declare(strict_types=1);

use App\Core\AdminUi;
use App\Core\BlockVisibility;
use App\Core\Csrf;
use App\Models\Language;

/** @var array $hero */
/** @var array<string, mixed> $settings */
/** @var array $slide */
/** @var array<string, mixed> $data */
/** @var array<string, array<string, mixed>> $translations */

$heroId = (int) $hero['id'];
$slideId = (int) $slide['id'];
$pageTitle = 'Редактирование слайда — «' . $hero['name'] . '»';
$activeNav = 'heroes';
require __DIR__ . '/../layout/header.php';

$defaultCode = Language::defaultCode();
$translationLangs = array_values(array_filter(
    Language::active(),
    static fn (array $l): bool => (string) $l['code'] !== $defaultCode
));

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);

$select = static function (string $name, string $label, array $options, string $current, string $hint = '', string $colClass = 'col-6') use ($esc): string {
    $html = '<div class="form-field ' . $esc($colClass) . '"><label for="slide_' . $name . '">' . $esc($label) . '</label>'
        . '<select id="slide_' . $name . '" name="' . $name . '">';
    foreach ($options as $value => $option) {
        $html .= '<option value="' . $esc($value) . '"' . ((string) $value === $current ? ' selected' : '') . '>'
            . $esc($option) . '</option>';
    }
    $html .= '</select>';
    if ($hint !== '') {
        $html .= '<span class="form-hint">' . $esc($hint) . '</span>';
    }

    return $html . '</div>';
};

$checkbox = static function (string $name, string $label, bool $checked, string $hint = '', string $colClass = 'col-12') use ($esc): string {
    $html = '<div class="form-field form-field--checkbox ' . $esc($colClass) . '">'
        . '<input type="checkbox" id="slide_' . $name . '" name="' . $name . '" value="1"' . ($checked ? ' checked' : '') . '>'
        . '<label for="slide_' . $name . '">' . $esc($label) . '</label>';
    if ($hint !== '') {
        $html .= '<span class="form-hint">' . $esc($hint) . '</span>';
    }

    return $html . '</div>';
};

$inherit = ['' => 'Использовать общую настройку обложки'];
$posOptions = ['left' => 'Слева', 'center' => 'По центру', 'right' => 'Справа'];
$yOptions = ['top' => 'Сверху', 'center' => 'По центру', 'bottom' => 'Снизу'];
$sizeOptions = ['s' => 'Мелкий', 'm' => 'Средний', 'l' => 'Крупный', 'xl' => 'Очень крупный'];
$subtitleSizes = ['s' => 'Мелкий', 'm' => 'Средний', 'l' => 'Крупный'];
$ctaStyles = [
    'primary' => 'Основная (заливка акцентом)',
    'secondary' => 'Вторичная (светлая заливка)',
    'ghost' => 'Контурная',
    'link' => 'Ссылка',
];
$cropOptions = [
    'left-top' => 'Слева сверху',
    'center-top' => 'По центру сверху',
    'right-top' => 'Справа сверху',
    'left-center' => 'Слева',
    'center-center' => 'По центру',
    'right-center' => 'Справа',
    'left-bottom' => 'Слева снизу',
    'center-bottom' => 'По центру снизу',
    'right-bottom' => 'Справа снизу',
];

$globalDuration = max(1, (int) ($settings['autoplay_interval'] ?? 6));
$customDuration = (int) $data['duration'];
?>

<div class="admin-main">
    <p>
        <a href="/admin/heroes/<?= $heroId ?>/edit" class="btn btn--small">← К обложке «<?= $esc($hero['name']) ?>»</a>
    </p>

    <form method="post" action="/admin/heroes/<?= $heroId ?>/slides/<?= $slideId ?>/update">
        <?= Csrf::field() ?>

        <!-- 1. Текстовый контент слайда -->
        <div class="settings-card">
            <div class="settings-card__header">
                <span class="settings-card__icon"><?= AdminUi::icon('file-text', 20) ?></span>
                <div>
                    <h2 class="settings-card__title">Текстовый контент слайда</h2>
                    <p class="settings-card__subtitle">Заголовок, надзаголовок и анонс, отображаемые на первом экране.</p>
                </div>
            </div>
            <div class="form-grid-12">
                <div class="form-field col-12">
                    <label for="eyebrow">Надзаголовок</label>
                    <input type="text" id="eyebrow" name="eyebrow" value="<?= $esc($data['eyebrow']) ?>" placeholder="Например: Национальная программа развития">
                </div>
                <div class="form-field col-12">
                    <label for="title">Главный заголовок</label>
                    <input type="text" id="title" name="title" value="<?= $esc($data['title']) ?>" placeholder="Введите заголовок слайда">
                </div>
                <div class="form-field col-12">
                    <label for="subtitle">Описание / подзаголовок</label>
                    <textarea id="subtitle" name="subtitle" rows="3" placeholder="Краткое пояснение или вступительный текст..."><?= $esc($data['subtitle']) ?></textarea>
                </div>
                <?php
                // Фоновая надпись — крупное слово за содержимым. Поля пропали
                // при перестройке этой формы, а данные, перевод и стили
                // остались: настройка была в базе, но задать её было нечем.
                ?>
                <div class="form-field col-12">
                    <label for="watermark">Фоновая надпись</label>
                    <input type="text" id="watermark" name="watermark" value="<?= $esc($data['watermark']) ?>" maxlength="120" placeholder="Например: aerion">
                    <span class="form-hint">
                        Крупное слово за содержимым, цветом текста обложки.
                        Диктор его не читает, кликам оно не мешает. Переводится
                        наравне с заголовком.
                    </span>
                </div>
                <div data-watermark-group class="form-grid-12 col-12"<?= trim((string) $data['watermark']) === '' ? ' hidden' : '' ?>>
                    <div class="form-field col-3">
                        <label for="watermark_size">Размер, % ширины экрана</label>
                        <input type="number" id="watermark_size" name="watermark_size" min="2" max="60" step="1" value="<?= (int) $data['watermark_size'] ?>">
                        <span class="form-hint">Числом: нужный кегль зависит от длины слова.</span>
                    </div>
                    <div class="form-field col-3">
                        <label for="watermark_opacity">Прозрачность, %</label>
                        <input type="number" id="watermark_opacity" name="watermark_opacity" min="0" max="100" step="1" value="<?= (int) $data['watermark_opacity'] ?>">
                    </div>
                    <?= $select('watermark_x', 'По горизонтали', [
                        'left' => 'К левому краю', 'center' => 'По центру', 'right' => 'К правому краю',
                    ], (string) $data['watermark_x'], '', 'col-3') ?>
                    <?= $select('watermark_y', 'По вертикали', [
                        'top' => 'К верху', 'middle' => 'По центру', 'bottom' => 'К низу',
                    ], (string) $data['watermark_y'], '', 'col-3') ?>
                </div>
            </div>
        </div>

        <!-- 2. Фон слайда (Медиа) -->
        <div class="settings-card">
            <div class="settings-card__header">
                <span class="settings-card__icon"><?= AdminUi::icon('photo', 20) ?></span>
                <div>
                    <h2 class="settings-card__title">Фон слайда</h2>
                    <p class="settings-card__subtitle">Полноэкранное фоновое изображение, видео или ролик YouTube.</p>
                </div>
            </div>
            <div class="form-grid-12">
                <?= $select('media_type', 'Тип фонового медиа', [
                    'none' => 'Без фона',
                    'image' => 'Изображение (фотография)',
                    'video' => 'Загруженное видео (MP4)',
                    'youtube' => 'Видео с YouTube',
                ], (string) $data['media_type'], '', 'col-6') ?>

                <?= $select('image_fit', 'Масштабирование изображения', [
                    'cover' => 'Заполнить область (Cover, обрезать лишнее)',
                    'contain' => 'Показать целиком (Contain)',
                ], (string) $data['image_fit'], '', 'col-6') ?>

                <div class="col-12">
                    <?= AdminUi::imageField('image', (string) $data['image'], [
                        'label' => 'Фоновое изображение для компьютера',
                        'hint' => 'Рекомендуемый размер 1920×1080 px. Служит запасным кадром, если видео недоступно.',
                    ]) ?>
                </div>

                <?= $select('image_position', 'Точка фокусировки (Кадрирование)', $cropOptions, (string) $data['image_position'], '', 'col-6') ?>

                <div class="form-field col-6">
                    <label for="video_url">Видео MP4 (прямой URL)</label>
                    <input type="text" id="video_url" name="video_url" value="<?= $esc($data['video_url']) ?>" placeholder="/uploads/public/hero.mp4">
                    <span class="form-hint">Воспроизводится в фоновом режиме без звука.</span>
                </div>

                <div class="form-field col-6">
                    <label for="youtube_url">Ссылка на YouTube</label>
                    <input type="text" id="youtube_url" name="youtube_url" value="<?= $esc($data['youtube_url']) ?>" placeholder="https://www.youtube.com/watch?v=XXXXXXXXXXX">
                    <?php if ($data['youtube_id'] !== ''): ?>
                        <span class="form-hint">Распознан ID видео: <code><?= $esc($data['youtube_id']) ?></code></span>
                    <?php endif; ?>
                </div>

                <div class="col-12">
                    <?= AdminUi::imageField('poster', (string) $data['poster'], [
                        'label' => 'Кадр-заставка (Poster для видео)',
                        'hint' => 'Отображается до запуска видео и в режиме экономии трафика.',
                    ]) ?>
                </div>
            </div>
        </div>

        <!-- 3. Логотип / Иллюстрация поверх фона -->
        <div class="settings-card">
            <div class="settings-card__header">
                <span class="settings-card__icon"><?= AdminUi::icon('sparkles', 20) ?></span>
                <div>
                    <h2 class="settings-card__title">Логотип / Картинка поверх фона</h2>
                    <p class="settings-card__subtitle">Эмблема программы, герб или графический знак рядом с заголовком.</p>
                </div>
            </div>
            <div class="form-grid-12">
                <div class="col-12">
                    <?= AdminUi::imageField('art_image', (string) $data['art_image'], [
                        'label' => 'Файл логотипа или иллюстрации (PNG / SVG)',
                        'hint' => 'Рекомендуется использовать прозрачный PNG или векторный SVG.',
                    ]) ?>
                </div>
                <div class="form-field col-6">
                    <label for="art_alt">Описание картинки (Alt-текст)</label>
                    <input type="text" id="art_alt" name="art_alt" value="<?= $esc($data['art_alt']) ?>" placeholder="Например: Логотип проекта">
                </div>
                <?= $select('art_position', 'Расположение относительно текста', [
                    'above' => 'Над текстом',
                    'left' => 'Слева от текста',
                    'right' => 'Справа от текста',
                ], (string) $data['art_position'], '', 'col-3') ?>
                <?= $select('art_size', 'Размер логотипа', [
                    'small' => 'Маленькая — 120 px',
                    'medium' => 'Средняя — 220 px',
                    'large' => 'Крупная — 360 px',
                    'custom' => 'Свой размер',
                ], (string) $data['art_size'], '', 'col-3') ?>
                <div class="form-field col-6">
                    <label for="art_width">Своя ширина логотипа, px</label>
                    <input type="number" id="art_width" name="art_width" min="40" max="1200" step="1" value="<?= (int) $data['art_width'] ?>">
                    <span class="form-hint">Применяется только при выборе «Свой размер».</span>
                </div>
            </div>
        </div>

        <!-- 4. Кнопки действия (CTA) и ссылки -->
        <div class="settings-card">
            <div class="settings-card__header">
                <span class="settings-card__icon"><?= AdminUi::icon('link', 20) ?></span>
                <div>
                    <h2 class="settings-card__title">Кнопки действия (CTA)</h2>
                    <p class="settings-card__subtitle">Интерактивные кнопки перехода и прямые ссылки с первого экрана.</p>
                </div>
            </div>
            <div class="form-grid-12">
                <!-- Основная кнопка -->
                <?= $checkbox('cta_enabled', 'Включить основную кнопку', (bool) $data['cta_enabled'], '', 'col-12') ?>
                <div class="form-field col-6">
                    <label for="cta_text">Текст основной кнопки</label>
                    <input type="text" id="cta_text" name="cta_text" value="<?= $esc($data['cta_text']) ?>" placeholder="Подробнее">
                </div>
                <div class="form-field col-6">
                    <label for="cta_url">Ссылка основной кнопки</label>
                    <input type="text" id="cta_url" name="cta_url" value="<?= $esc($data['cta_url']) ?>" placeholder="/projects">
                </div>
                <?= $select('cta_style', 'Стиль кнопки', $ctaStyles, (string) $data['cta_style'], '', 'col-4') ?>
                <div class="col-4">
                    <?= AdminUi::iconField('cta_icon', (string) $data['cta_icon'], ['label' => 'Иконка кнопки']) ?>
                </div>
                <?= $checkbox('cta_new_tab', 'Открывать в новой вкладке', (bool) $data['cta_new_tab'], '', 'col-4') ?>

                <!-- Дополнительная кнопка -->
                <div class="col-12"><hr class="form-divider"></div>
                <?= $checkbox('cta2_enabled', 'Включить дополнительную кнопку', (bool) $data['cta2_enabled'], '', 'col-12') ?>
                <div class="form-field col-6">
                    <label for="cta2_text">Текст дополнительной кнопки</label>
                    <input type="text" id="cta2_text" name="cta2_text" value="<?= $esc($data['cta2_text']) ?>" placeholder="Контакты">
                </div>
                <div class="form-field col-6">
                    <label for="cta2_url">Ссылка дополнительной кнопки</label>
                    <input type="text" id="cta2_url" name="cta2_url" value="<?= $esc($data['cta2_url']) ?>" placeholder="/contacts">
                </div>
                <?= $select('cta2_style', 'Стиль дополнительной кнопки', $ctaStyles, (string) $data['cta2_style'], '', 'col-4') ?>
                <div class="col-4">
                    <?= AdminUi::iconField('cta2_icon', (string) $data['cta2_icon'], ['label' => 'Иконка дополнительной кнопки']) ?>
                </div>
                <?= $checkbox('cta2_new_tab', 'Открывать в новой вкладке', (bool) $data['cta2_new_tab'], '', 'col-4') ?>

                <!-- Ссылка со всего слайда -->
                <div class="col-12"><hr class="form-divider"></div>
                <div class="form-field col-8">
                    <label for="link_url">Ссылка со всей площади слайда</label>
                    <input type="text" id="link_url" name="link_url" value="<?= $esc($data['link_url']) ?>" placeholder="https://...">
                    <span class="form-hint">Если заполнено, клик по любой свободной части слайда открывает эту ссылку.</span>
                </div>
                <?= $checkbox('link_new_tab', 'Открывать ссылку слайда в новой вкладке', (bool) $data['link_new_tab'], '', 'col-4') ?>
            </div>
        </div>

        <!-- 5. Наложение и цвет текста -->
        <div class="settings-card">
            <div class="settings-card__header">
                <span class="settings-card__icon"><?= AdminUi::icon('palette', 20) ?></span>
                <div>
                    <h2 class="settings-card__title">Наложение и цвет текста</h2>
                    <p class="settings-card__subtitle">Всё, что зависит от самого кадра: вуаль поверх фотографии и цвет текста на ней. Раскладка, размеры и палитра общие для обложки — они в её настройках.</p>
                </div>
            </div>
            <div class="form-grid-12">
                <?= $select('content_scheme', 'Цвет текста', $inherit + [
                    'auto' => 'Auto — автоматически',
                    'light' => 'Light — светлый',
                    'dark' => 'Dark — тёмный',
                ], (string) $data['content_scheme'], '', 'col-6') ?>

                <?= $select('overlay', 'Наложение на фон', $inherit + [
                    'none' => 'Без наложения',
                    'solid' => 'Сплошная заливка',
                    'gradient' => 'Градиент',
                ], (string) $data['overlay'], 'Тёмный цвет затемняет кадр, светлый осветляет.', 'col-4') ?>

                <div class="form-field col-4">
                    <label for="overlay_opacity">Плотность наложения (%)</label>
                    <input type="number" id="overlay_opacity" name="overlay_opacity" min="0" max="100"
                           value="<?= (int) $data['overlay_opacity'] >= 0 ? (int) $data['overlay_opacity'] : '' ?>" placeholder="общая настройка">
                </div>

                <?= $select('overlay_direction', 'Направление градиента', $inherit + [
                    'auto' => 'Автоматически',
                    'to_right' => 'Слева направо',
                    'to_left' => 'Справа налево',
                    'to_bottom' => 'Сверху вниз',
                    'to_top' => 'Снизу вверх',
                ], (string) $data['overlay_direction'], '', 'col-4') ?>

                <?php // Цвет наложения и подложка у слайда работали и раньше,
                      // но задать их можно было только у обложки — на кадре с
                      // другим настроением приходилось менять её целиком. ?>
                <div class="col-4">
                    <?= AdminUi::colorField('overlay_color', (string) $data['overlay_color'], 'Цвет наложения', '#0b1a30', 'Использовать общую настройку обложки') ?>
                </div>
            </div>
        </div>

        <!-- 6. Мобильная версия (Смартфоны) -->
        <div class="settings-card">
            <div class="settings-card__header">
                <span class="settings-card__icon"><?= AdminUi::icon('device-mobile', 20) ?></span>
                <div>
                    <h2 class="settings-card__title">Мобильная версия (Смартфоны)</h2>
                    <p class="settings-card__subtitle">Индивидуальные настройки отображения кадра на мобильных экранах.</p>
                </div>
            </div>
            <div class="form-grid-12">
                <div class="col-12">
                    <?= AdminUi::imageField('image_mobile', (string) $data['image_mobile'], [
                        'label' => 'Вертикальное фото для смартфонов (Mobile Hero)',
                        'hint' => 'Если не указано, автоматически используется десктопная фотография.',
                    ]) ?>
                </div>
                <?= $select('image_position_mobile', 'Кадрирование на телефоне', $cropOptions, (string) $data['image_position_mobile'], '', 'col-6') ?>
                <?= $select('mobile_media', 'Поведение видео на телефоне', [
                    'image' => 'Заменить изображением (рекомендуется для скорости)',
                    'desktop' => 'Проигрывать то же видео',
                    'mobile_video' => 'Проигрывать отдельное мобильное видео',
                ], (string) $data['mobile_media'], '', 'col-6') ?>
            </div>
        </div>

        <!-- 7. Время показа и расписание -->
        <div class="settings-card">
            <div class="settings-card__header">
                <span class="settings-card__icon"><?= AdminUi::icon('calendar-event', 20) ?></span>
                <div>
                    <h2 class="settings-card__title">Время показа и расписание</h2>
                    <p class="settings-card__subtitle">Автопрокрутка и период активности слайда на сайте.</p>
                </div>
            </div>
            <div class="form-grid-12">
                <div class="form-field col-4">
                    <label for="duration">Длительность показа слайда (сек)</label>
                    <input type="number" id="duration" name="duration" min="0" max="120" step="1"
                           value="<?= $customDuration > 0 ? $customDuration : '' ?>"
                           placeholder="<?= $globalDuration ?> — по умолчанию">
                    <span class="form-hint">0 или пусто — общее время обложки (<?= $globalDuration ?> с).</span>
                </div>
                <div class="form-field col-4">
                    <label for="_visible_from">Показывать с (Дата/время)</label>
                    <input type="datetime-local" id="_visible_from" name="_visible_from" value="<?= $esc(BlockVisibility::forInput($data['_visible_from'])) ?>">
                </div>
                <div class="form-field col-4">
                    <label for="_visible_to">Показывать до (Дата/время)</label>
                    <input type="datetime-local" id="_visible_to" name="_visible_to" value="<?= $esc(BlockVisibility::forInput($data['_visible_to'])) ?>">
                </div>
            </div>
        </div>

        <!-- 8. Переводы на другие языки -->
        <?php if ($translationLangs !== []): ?>
            <div class="settings-card">
                <div class="settings-card__header">
                    <span class="settings-card__icon"><?= AdminUi::icon('globe', 20) ?></span>
                    <div>
                        <h2 class="settings-card__title">Языковые версии (Переводы)</h2>
                        <p class="settings-card__subtitle">Текст, описание и кнопки для дополнительных языков интерфейса.</p>
                    </div>
                </div>
                <div class="form-grid-12">
                    <?php foreach ($translationLangs as $language): ?>
                        <?php
                        $code = (string) $language['code'];
                        $tr = $translations[$code] ?? [];
                        $key = 'translations[' . $code . ']';
                        $id = 'tr-' . preg_replace('/[^a-z0-9_-]/i', '', $code) . '-';
                        ?>
                        <div class="col-12">
                            <h3 class="form-subtitle"><?= $esc($language['name'] ?? strtoupper($code)) ?> (<?= strtoupper($code) ?>)</h3>
                        </div>
                        <div class="form-field col-6">
                            <label for="<?= $id ?>eyebrow">Надзаголовок (<?= strtoupper($code) ?>)</label>
                            <input type="text" id="<?= $id ?>eyebrow" name="<?= $key ?>[eyebrow]" value="<?= $esc($tr['eyebrow'] ?? '') ?>">
                        </div>
                        <div class="form-field col-6">
                            <label for="<?= $id ?>title">Заголовок (<?= strtoupper($code) ?>)</label>
                            <input type="text" id="<?= $id ?>title" name="<?= $key ?>[title]" value="<?= $esc($tr['title'] ?? '') ?>">
                            <span class="form-hint"><?= htmlspecialchars(\App\Core\BlockData\Field::TITLE_MARKUP_HINT, ENT_QUOTES) ?></span>
                        </div>
                        <div class="form-field col-12">
                            <label for="<?= $id ?>subtitle">Описание (<?= strtoupper($code) ?>)</label>
                            <textarea id="<?= $id ?>subtitle" name="<?= $key ?>[subtitle]" rows="2"><?= $esc($tr['subtitle'] ?? '') ?></textarea>
                        </div>
                        <div class="form-field col-12">
                            <label for="<?= $id ?>watermark">Фоновая надпись (<?= strtoupper($code) ?>)</label>
                            <input type="text" id="<?= $id ?>watermark" name="<?= $key ?>[watermark]" value="<?= $esc($tr['watermark'] ?? '') ?>" maxlength="120">
                        </div>
                        <div class="form-field col-6">
                            <label for="<?= $id ?>cta">Текст основной кнопки (<?= strtoupper($code) ?>)</label>
                            <input type="text" id="<?= $id ?>cta" name="<?= $key ?>[cta_text]" value="<?= $esc($tr['cta_text'] ?? '') ?>">
                        </div>
                        <div class="form-field col-6">
                            <label for="<?= $id ?>cta-url">Ссылка основной кнопки (<?= strtoupper($code) ?>)</label>
                            <input type="text" id="<?= $id ?>cta-url" name="<?= $key ?>[cta_url]" value="<?= $esc($tr['cta_url'] ?? '') ?>" placeholder="пусто — ссылка по умолчанию">
                        </div>
                        <div class="form-field col-6">
                            <label for="<?= $id ?>cta2">Текст дополнительной кнопки (<?= strtoupper($code) ?>)</label>
                            <input type="text" id="<?= $id ?>cta2" name="<?= $key ?>[cta2_text]" value="<?= $esc($tr['cta2_text'] ?? '') ?>">
                        </div>
                        <div class="form-field col-6">
                            <label for="<?= $id ?>cta2-url">Ссылка дополнительной кнопки (<?= strtoupper($code) ?>)</label>
                            <input type="text" id="<?= $id ?>cta2-url" name="<?= $key ?>[cta2_url]" value="<?= $esc($tr['cta2_url'] ?? '') ?>" placeholder="пусто — ссылка по умолчанию">
                        </div>
                        <div class="form-field col-12">
                            <label for="<?= $id ?>slide-url">Ссылка со всего слайда (<?= strtoupper($code) ?>)</label>
                            <input type="text" id="<?= $id ?>slide-url" name="<?= $key ?>[link_url]" value="<?= $esc($tr['link_url'] ?? '') ?>" placeholder="пусто — ссылка по умолчанию">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- 9. Для разработчика -->
        <div class="settings-card">
            <div class="settings-card__header">
                <span class="settings-card__icon"><?= AdminUi::icon('code', 20) ?></span>
                <div>
                    <h2 class="settings-card__title">Для разработчика</h2>
                    <p class="settings-card__subtitle">Пользовательский CSS-класс для индивидуальной стилизации слайда.</p>
                </div>
            </div>
            <div class="form-grid-12">
                <div class="form-field col-6">
                    <label for="css_class">CSS-класс слайда</label>
                    <input type="text" id="css_class" name="css_class" value="<?= $esc($data['css_class']) ?>" placeholder="hero-slide-special">
                </div>
            </div>
        </div>

        <!-- Нижняя стандартизированная панель сохранения -->
        <div class="form-actions form-actions--sticky">
            <button type="submit" class="btn btn--primary">Сохранить слайд</button>
            <a href="/admin/heroes/<?= $heroId ?>/edit" class="btn">К списку слайдов</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
