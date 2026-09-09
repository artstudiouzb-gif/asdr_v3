<?php

declare(strict_types=1);

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Core\HeaderConfig;

$pageTitle = 'Конструктор шапки сайта';
$activeNav = 'header';
require __DIR__ . '/../layout/header.php';

/** @var array $config */
$networks = ['telegram' => 'Telegram', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'whatsapp' => 'WhatsApp'];

$elements = HeaderConfig::ELEMENTS;
$elementIcons = [];
foreach (array_keys($elements) as $elementType) {
    $iconName = [
        'menu' => 'layout',
        'logo' => 'image',
        'language' => 'globe',
    ][$elementType] ?? $elementType;
    $elementIcons[$elementType] = AdminUi::icon($iconName, 18, 'hb-el__icon-svg', 1.6);
}

$renderChip = function (string $type) use ($elements, $elementIcons): string {
    $label = $elements[$type] ?? $type;
    return '<span class="hdr-chip hb-el" draggable="true" data-el="' . htmlspecialchars($type, ENT_QUOTES) . '"'
        . ' title="' . htmlspecialchars($label, ENT_QUOTES) . '">'
        . '<span class="hb-el__grip" aria-hidden="true">' . AdminUi::icon('grip', 14, 'hb-el__grip-icon', 0) . '</span>'
        . '<span class="hb-el__icon">' . ($elementIcons[$type] ?? '') . '</span>'
        . '<span class="hb-el__label">' . htmlspecialchars($label, ENT_QUOTES) . '</span>'
        . '<button type="button" class="hb-el__remove hdr-chip__remove" aria-label="Убрать" title="Убрать">'
        . \App\Core\Icon::render('x', 14) . '</button>'
        . '</span>';
};

$renderZones = function (array $placed, string $inputName) use ($renderChip): string {
    ob_start(); ?>
    <div class="hb-zones">
        <?php foreach (['left' => 'Слева', 'center' => 'Центр', 'right' => 'Справа'] as $zone => $zoneLabel): ?>
            <div class="hb-zone">
                <div class="hb-zone__label"><?= $zoneLabel ?></div>
                <div class="hb-zone__drop hdr-builder__dropzone" data-hdr-zone="<?= $zone ?>"
                     role="group" tabindex="0" aria-label="Зона: <?= $zoneLabel ?>">
                    <?php foreach ($placed[$zone] ?? [] as $type): ?>
                        <?= $renderChip($type) ?>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="<?= $inputName ?>[<?= $zone ?>]" data-hdr-input="<?= $zone ?>" value="<?= htmlspecialchars(implode(',', $placed[$zone] ?? []), ENT_QUOTES) ?>">
            </div>
        <?php endforeach; ?>
    </div>
    <?php return (string) ob_get_clean();
};

// Подписи набирает редактор, поэтому битая кодировка в одной из них не должна
// закрывать весь конструктор: `JSON_INVALID_UTF8_SUBSTITUTE` оставляет строку
// читаемой, подменив негодный байт. Запасное `[]` — на случай отказа по другой
// причине: пустой атрибут уронил бы `JSON.parse` в скрипте, то есть отняло бы
// у конструктора подписи целиком вместо одной испорченной.
$labelsJson = htmlspecialchars(
    json_encode($elements, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]',
    ENT_QUOTES
);

$heightSelect = function (string $name, string $current): string {
    $out = '<select name="' . $name . '" class="hb-select" aria-label="Высота секции">';
    foreach (['slim' => 'Компактная', 'normal' => 'Обычная', 'tall' => 'Высокая'] as $v => $l) {
        $out .= '<option value="' . $v . '"' . ($current === $v ? ' selected' : '') . '>' . $l . '</option>';
    }
    return $out . '</select>';
};
?>
<div class="admin-builder-workspace">
    <!-- Workspace Header Banner -->
    <div class="hdr-workspace-header">
        <div>
            <h2 class="hdr-workspace-header__title">
                <?= AdminUi::icon('sliders', 24) ?> Дизайн и структура шапки
            </h2>
            <p class="hdr-workspace-header__desc">
                Настройте структуру, меню, элементы, цвета и поведение шапки на разных устройствах.
            </p>
        </div>
        <div>
            <a class="btn btn--outline u-inline-608586e10d" href="/admin/menu" target="_blank">
                <?= AdminUi::icon('edit') ?> Пункты меню →
            </a>
        </div>
    </div>

    <!-- Live Header Preview Bar -->
    <div class="hdr-live-preview">
        <div class="hdr-live-preview__head">
            <span><?= AdminUi::icon('eye', 16) ?> Схематичный предпросмотр шапки</span>
            <span class="u-inline-2921120983">Основные параметры обновляются без сохранения</span>
        </div>
        <div class="hdr-live-preview__box">
            <div class="hdr-live-preview__top" id="prevTopbar">
                <span>+998 71 203 10 00 | info@strategy.uz</span>
                <span>RU | UZ | EN</span>
            </div>
            <div class="hdr-live-preview__main" id="prevMiddlebar">
                <span class="hdr-live-preview__logo">ЛОГОТИП САЙТА</span>
                <div class="hdr-live-preview__nav">
                    <span class="hdr-live-preview__nav-item is-active"><?= htmlspecialchars(t('Главная'), ENT_QUOTES) ?></span>
                    <span class="hdr-live-preview__nav-item">Новости</span>
                    <span class="hdr-live-preview__nav-item">Проекты</span>
                    <span class="hdr-live-preview__nav-item">Контакты</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Навигация по разделам конструктора -->
    <div class="hdr-tabs-nav" role="tablist" aria-label="Разделы настройки шапки">
        <button type="button" class="hdr-tab-btn is-active" id="hdr-tab-builder" role="tab"
                aria-selected="true" aria-controls="tab-hdr-builder" data-hdr-tab-target="tab-hdr-builder">
            Структура и зоны
        </button>
        <button type="button" class="hdr-tab-btn" id="hdr-tab-menu" role="tab"
                aria-selected="false" aria-controls="tab-hdr-menu" data-hdr-tab-target="tab-hdr-menu">
            Главное меню
        </button>
        <button type="button" class="hdr-tab-btn" id="hdr-tab-elements" role="tab"
                aria-selected="false" aria-controls="tab-hdr-elements" data-hdr-tab-target="tab-hdr-elements">
            Элементы
        </button>
        <button type="button" class="hdr-tab-btn" id="hdr-tab-logos" role="tab"
                aria-selected="false" aria-controls="tab-hdr-logos" data-hdr-tab-target="tab-hdr-logos">
            Логотипы
        </button>
        <button type="button" class="hdr-tab-btn" id="hdr-tab-styles" role="tab"
                aria-selected="false" aria-controls="tab-hdr-styles" data-hdr-tab-target="tab-hdr-styles">
            Цвета и контакты
        </button>
        <button type="button" class="hdr-tab-btn" id="hdr-tab-arch" role="tab"
                aria-selected="false" aria-controls="tab-hdr-arch" data-hdr-tab-target="tab-hdr-arch">
            Поведение и эффекты
        </button>
    </div>

    <form method="post" action="/admin/header" class="form-grid" enctype="multipart/form-data">
        <?= Csrf::field() ?>

        <!-- MODULE 1: ARCHITECTURE & GLASSMORPHISM -->
        <div class="admin-tab-content" id="tab-hdr-arch" role="tabpanel" aria-labelledby="hdr-tab-arch">
            <!-- Container Mode Card Selector -->
            <div class="header-builder__group form-card u-inline-8cddc29a69">
                <h3 class="u-inline-0e0c39e056">
                    1. Ширина и форма контейнера
                </h3>
                <p class="form-hint u-inline-291b7bbb01">Выберите архитектуру отображения шапки относительно краев экрана.</p>

                <div class="hdr-card-grid">
                    <?php
                    $cmode = $config['container_mode'] ?? 'full';
                    // Ширина контейнера задаётся в «Дизайне сайта»; раньше здесь
                    // стояло жёстко вписанное «1280px», которое не совпадало ни
                    // с одним пресетом.
                    $containerWidthValue = \App\Core\DesignSettings::containerWidth();
                    $containerWidthLabel = $containerWidthValue === 'none'
                        ? 'на всю ширину'
                        : $containerWidthValue;
                    ?>
                    <label class="hdr-select-card <?= $cmode === 'full' ? 'is-selected' : '' ?>">
                        <input type="radio" name="container_mode" value="full" <?= $cmode === 'full' ? 'checked' : '' ?>>
                        <span class="hdr-select-card__title">На всю ширину</span>
                        <span class="hdr-select-card__desc">Шапка и фоны растягиваются на 100% ширины экрана. Элементы выровнены по сетке.</span>
                    </label>

                    <label class="hdr-select-card <?= $cmode === 'container' ? 'is-selected' : '' ?>">
                        <input type="radio" name="container_mode" value="container" <?= $cmode === 'container' ? 'checked' : '' ?>>
                        <span class="hdr-select-card__title">По ширине контента</span>
                        <span class="hdr-select-card__desc">Вся шапка с фоном ограничена рамкой контента (<?= htmlspecialchars($containerWidthLabel, ENT_QUOTES) ?>) и центрируется на странице.</span>
                    </label>

                    <label class="hdr-select-card <?= $cmode === 'floating' ? 'is-selected' : '' ?>">
                        <input type="radio" name="container_mode" value="floating" <?= $cmode === 'floating' ? 'checked' : '' ?>>
                        <span class="hdr-select-card__title">Парящая шапка</span>
                        <span class="hdr-select-card__desc">Парящая стеклянная капсула над контентом с размытием фона и эффектом Glassmorphism.</span>
                    </label>
                </div>
            </div>

            <!-- Glassmorphic Engine Controls -->
            <div class="header-builder__group form-card u-inline-108a780011" data-hdr-container-only="floating">
                <h3 class="u-inline-39a35c5e86">
                    <?= AdminUi::icon('sliders', 20, 'text-primary') ?> 2. Эффект парящей шапки
                </h3>
                <p class="form-hint u-inline-291b7bbb01">Настройте прозрачность, скругление, матовость и направление градиента парящей капсулы.</p>

                <div class="hb-inline-grid">
                    <div class="form-field">
                        <label for="styles_floating_radius">Скругление углов (Floating Radius)</label>
                        <?php $flRad = (int) ($config['styles']['floating_radius'] ?? 18); ?>
                        <select id="styles_floating_radius" name="styles_floating_radius">
                            <option value="6" <?= $flRad === 6 ? 'selected' : '' ?>>6px (Минимальное скругление)</option>
                            <option value="12" <?= $flRad === 12 ? 'selected' : '' ?>>12px (Компактное скругление)</option>
                            <option value="18" <?= $flRad === 18 ? 'selected' : '' ?>>18px (Стандартный парящий стеклянный)</option>
                            <option value="24" <?= $flRad === 24 ? 'selected' : '' ?>>24px (Выразительное скругление)</option>
                            <option value="32" <?= $flRad === 32 ? 'selected' : '' ?>>32px (Ультра-скругление)</option>
                            <option value="50" <?= $flRad === 50 ? 'selected' : '' ?>>50px (Полная овальная капсула / Pill)</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="styles_floating_opacity">Прозрачность стекла (%)</label>
                        <?php $flOp = (int) ($config['styles']['floating_opacity'] ?? 25); ?>
                        <select id="styles_floating_opacity" name="styles_floating_opacity">
                            <option value="10" <?= $flOp === 10 ? 'selected' : '' ?>>10% (Ультра-прозрачное)</option>
                            <option value="18" <?= $flOp === 18 ? 'selected' : '' ?>>18% (Легчайшее стекло)</option>
                            <option value="25" <?= $flOp === 25 ? 'selected' : '' ?>>25% (Стандартный воздушный стеклянный)</option>
                            <option value="35" <?= $flOp === 35 ? 'selected' : '' ?>>35% (Среднее мягкое стекло)</option>
                            <option value="50" <?= $flOp === 50 ? 'selected' : '' ?>>50% (Полупрозрачное плотное)</option>
                            <option value="75" <?= $flOp === 75 ? 'selected' : '' ?>>75% (Плотное стекло)</option>
                            <option value="90" <?= $flOp === 90 ? 'selected' : '' ?>>90% (Почти сплошной фон)</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="styles_floating_gradient_angle">Угол градиента стекла</label>
                        <?php $flAng = (int) ($config['styles']['floating_gradient_angle'] ?? 135); ?>
                        <select id="styles_floating_gradient_angle" name="styles_floating_gradient_angle">
                            <option value="90" <?= $flAng === 90 ? 'selected' : '' ?>>90° (Слева направо)</option>
                            <option value="135" <?= $flAng === 135 ? 'selected' : '' ?>>135° (Диагональный из угла в угол)</option>
                            <option value="180" <?= $flAng === 180 ? 'selected' : '' ?>>180° (Сверху вниз)</option>
                            <option value="225" <?= $flAng === 225 ? 'selected' : '' ?>>225° (Обратная диагональ)</option>
                            <option value="0" <?= $flAng === 0 ? 'selected' : '' ?>>0° (Снизу вверх)</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="styles_floating_blur">Уровень матовости (Backdrop Blur)</label>
                        <?php $flBlur = (int) ($config['styles']['floating_blur'] ?? 14); ?>
                        <select id="styles_floating_blur" name="styles_floating_blur">
                            <option value="0" <?= $flBlur === 0 ? 'selected' : '' ?>>0px (Чистый глянец без размытия)</option>
                            <option value="8" <?= $flBlur === 8 ? 'selected' : '' ?>>8px (Легкая матовость)</option>
                            <option value="14" <?= $flBlur === 14 ? 'selected' : '' ?>>14px (Стандартное стеклянное размытие)</option>
                            <option value="20" <?= $flBlur === 20 ? 'selected' : '' ?>>20px (Глубокое размытие)</option>
                            <option value="30" <?= $flBlur === 30 ? 'selected' : '' ?>>30px (Ультра-матовый Frosted Glass)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Scroll Behavior Switches -->
            <div class="header-builder__group form-card u-inline-d40eaf045d">
                <h3 class="u-inline-0e0c39e056">
                    3. Поведение при прокрутке
                </h3>
                <div class="hb-behavior__options u-inline-9374e84210">
                    <label class="hb-behavior-card">
                        <span class="hb-switch">
                            <input type="checkbox" name="header_sticky" value="1" <?= !empty($config['sticky']) ? 'checked' : '' ?>>
                            <span class="hb-switch__track"></span>
                            <span class="hb-behavior-card__title">Липкая шапка (Sticky Header)</span>
                        </span>
                        <span class="hb-behavior-card__hint">Шапка плавно зафиксирована вверху при прокрутке страницы.</span>
                    </label>

                    <label class="hb-behavior-card">
                        <span class="hb-switch">
                            <input type="checkbox" name="header_sticky_full_width" value="1" <?= !empty($config['sticky_full_width']) ? 'checked' : '' ?>>
                            <span class="hb-switch__track"></span>
                            <span class="hb-behavior-card__title">Расширять до 100% при прокрутке</span>
                        </span>
                        <span class="hb-behavior-card__hint">Парящая или боксовая шапка становится во всю ширину экрана при скролле.</span>
                    </label>

                    <label class="hb-behavior-card">
                        <span class="hb-switch">
                            <input type="checkbox" name="header_transparent" value="1" <?= !empty($config['transparent']) ? 'checked' : '' ?>>
                            <span class="hb-switch__track"></span>
                            <span class="hb-behavior-card__title">Прозрачная шапка на главной</span>
                        </span>
                        <span class="hb-behavior-card__hint">Накладывается поверх первого экрана/слайдера на главной странице.</span>
                    </label>

                    <label class="hb-behavior-card">
                        <span class="hb-switch">
                            <input type="checkbox" name="header_shadow" value="1" <?= !empty($config['shadow']['enabled']) ? 'checked' : '' ?>>
                            <span class="hb-switch__track"></span>
                            <span class="hb-behavior-card__title">Тень под шапкой</span>
                        </span>
                        <span class="hb-behavior-card__hint">Отделяет шапку от контента на светлом фоне.</span>
                    </label>
                </div>

                <div class="hb-inline-grid">
                    <div class="form-field">
                        <label for="header_shadow_size">Мягкость тени, px</label>
                        <input id="header_shadow_size" name="header_shadow_size" type="number" min="2" max="60" step="1"
                               value="<?= (int) ($config['shadow']['size'] ?? 14) ?>">
                    </div>
                </div>
            </div>

            <!-- Element Spacing Density Control -->
            <div class="header-builder__group form-card u-inline-d40eaf045d">
                <h3 class="u-inline-39a35c5e86">
                    <?= AdminUi::icon('maximize', 20, 'text-primary') ?> 4. Расстояния между элементами
                </h3>
                <p class="form-hint u-inline-291b7bbb01">Управляйте отступами между логотипом, элементами, поиском и кнопками в зонах шапки.</p>

                <?php $elGap = $config['styles']['elements_gap'] ?? 'normal'; ?>
                <div class="hb-inline-grid u-inline-8a359a76eb">
                    <div class="form-field">
                        <label for="styles_elements_gap">Отступ между элементами в зонах</label>
                        <select id="styles_elements_gap" name="styles_elements_gap">
                            <option value="ultra_compact" <?= $elGap === 'ultra_compact' ? 'selected' : '' ?>>Ультра-плотный (6px)</option>
                            <option value="compact" <?= $elGap === 'compact' ? 'selected' : '' ?>>Компактный (10px)</option>
                            <option value="normal" <?= $elGap === 'normal' ? 'selected' : '' ?>>Стандартный (18px)</option>
                            <option value="spacious" <?= $elGap === 'spacious' ? 'selected' : '' ?>>Просторный (28px)</option>
                            <option value="loose" <?= $elGap === 'loose' ? 'selected' : '' ?>>Широкий (38px)</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODULE 2: MENU & INDICATORS -->
        <div class="admin-tab-content" id="tab-hdr-menu" role="tabpanel" aria-labelledby="hdr-tab-menu">
            <div class="header-builder__group form-card u-inline-8cddc29a69">
                <h3 class="u-inline-0e0c39e056">
                    Вид и адаптивность главного меню
                </h3>
                <p class="form-hint u-inline-291b7bbb01">Выберите стиль индикации активного раздела, иконок и разделителей.</p>

                <?php $st = $config['styles'] ?? []; ?>
                <div class="hb-inline-grid u-inline-8a359a76eb">
                    <div class="form-field">
                        <label for="menu_position">Выравнивание пунктов меню</label>
                        <select id="menu_position" name="menu_position">
                            <option value="left" <?= ($config['menu_position'] ?? 'center') === 'left' ? 'selected' : '' ?>>Слева</option>
                            <option value="center" <?= ($config['menu_position'] ?? 'center') === 'center' ? 'selected' : '' ?>>По центру</option>
                            <option value="right" <?= ($config['menu_position'] ?? 'center') === 'right' ? 'selected' : '' ?>>Справа</option>
                        </select>
                        <small class="form-hint">Выравнивает пункты внутри доступной ширины зоны. Саму зону меню можно изменить в конструкторе шапки.</small>
                    </div>

                    <div class="form-field">
                        <label for="styles_nav_style_type">Индикатор активности / наведения</label>
                        <select id="styles_nav_style_type" name="styles_nav_style_type">
                            <option value="underline" <?= ($st['nav_style_type'] ?? 'underline') === 'underline' ? 'selected' : '' ?>>Неоновый световой луч с прозрачными краями (Neon Light Beam)</option>
                            <option value="dot" <?= ($st['nav_style_type'] ?? 'underline') === 'dot' ? 'selected' : '' ?>>Неоновая точка под текстом (Glowing Dot)</option>
                            <option value="pill" <?= ($st['nav_style_type'] ?? 'underline') === 'pill' ? 'selected' : '' ?>>Залитая плашка (Capsule pill)</option>
                            <option value="glow" <?= ($st['nav_style_type'] ?? 'underline') === 'glow' ? 'selected' : '' ?>>Свечение текста</option>
                            <option value="minimal" <?= ($st['nav_style_type'] ?? 'underline') === 'minimal' ? 'selected' : '' ?>>Простой цвет текста</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="styles_nav_icon_pos">Расположение иконки пункта</label>
                        <select id="styles_nav_icon_pos" name="styles_nav_icon_pos">
                            <option value="left" <?= ($st['nav_icon_pos'] ?? 'left') === 'left' ? 'selected' : '' ?>>Слева от текста (Горизонтально)</option>
                            <option value="top" <?= ($st['nav_icon_pos'] ?? 'left') === 'top' ? 'selected' : '' ?>>Сверху над текстом (Вертикально)</option>
                        </select>
                    </div>

                    <div class="form-field" data-hdr-nav-style-only="pill">
                        <label for="styles_nav_pill_bg">Фон активной плашки</label>
                        <div class="color-picker-group">
                            <input type="color" id="styles_nav_pill_bg" name="styles_nav_pill_bg"
                                   value="<?= htmlspecialchars($st['nav_pill_bg'] ?: '#2563eb', ENT_QUOTES) ?>">
                            <label><input type="checkbox" name="styles_nav_pill_bg_use" value="1" <?= $st['nav_pill_bg'] !== '' ? 'checked' : '' ?>> Свой цвет</label>
                        </div>
                        <small class="form-hint">Используется для варианта индикатора «Залитая плашка».</small>
                    </div>
                </div>

                <div class="u-inline-d4e35c613b">
                    <h4 class="u-inline-2e67dd3b15">Цвета меню (Обычная и Прозрачная шапка)</h4>
                    <p class="form-hint">Задайте индивидуальные цвета текста и наведения для пунктов меню в обычной и прозрачной шапке.</p>

                    <div class="hb-inline-grid">
                        <div class="form-field">
                            <label for="styles_nav_color">Текст (Обычная шапка)</label>
                            <div class="color-picker-group">
                                <input type="color" id="styles_nav_color" name="styles_nav_color"
                                       value="<?= htmlspecialchars(($st['nav_color'] ?? '') ?: '#1e293b', ENT_QUOTES) ?>">
                                <label><input type="checkbox" name="styles_nav_color_use" value="1" <?= !empty($st['nav_color']) ? 'checked' : '' ?>> Свой цвет</label>
                            </div>
                        </div>

                        <div class="form-field">
                            <label for="styles_nav_hover">Ховер (Обычная шапка)</label>
                            <div class="color-picker-group">
                                <input type="color" id="styles_nav_hover" name="styles_nav_hover"
                                       value="<?= htmlspecialchars(($st['nav_hover'] ?? '') ?: '#0284c7', ENT_QUOTES) ?>">
                                <label><input type="checkbox" name="styles_nav_hover_use" value="1" <?= !empty($st['nav_hover']) ? 'checked' : '' ?>> Свой цвет</label>
                            </div>
                        </div>

                        <div class="form-field">
                            <label for="styles_nav_color_transparent">Текст (Прозрачная шапка)</label>
                            <div class="color-picker-group">
                                <input type="color" id="styles_nav_color_transparent" name="styles_nav_color_transparent"
                                       value="<?= htmlspecialchars(($st['nav_color_transparent'] ?? '') ?: '#ffffff', ENT_QUOTES) ?>">
                                <label><input type="checkbox" name="styles_nav_color_transparent_use" value="1" <?= !empty($st['nav_color_transparent']) ? 'checked' : '' ?>> Свой цвет</label>
                            </div>
                        </div>

                        <div class="form-field">
                            <label for="styles_nav_hover_transparent">Ховер (Прозрачная шапка)</label>
                            <div class="color-picker-group">
                                <input type="color" id="styles_nav_hover_transparent" name="styles_nav_hover_transparent"
                                       value="<?= htmlspecialchars(($st['nav_hover_transparent'] ?? '') ?: '#38bdf8', ENT_QUOTES) ?>">
                                <label><input type="checkbox" name="styles_nav_hover_transparent_use" value="1" <?= !empty($st['nav_hover_transparent']) ? 'checked' : '' ?>> Свой цвет</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="u-inline-d4e35c613b">
                    <h4 class="u-inline-2e67dd3b15">Расстояния и разделители главного меню</h4>
                    <p class="form-hint">Настройки применяются к верхнему уровню меню. На мобильном разделители скрываются, а пункты остаются удобными для касания.</p>

                    <label class="form-field form-field--checkbox">
                        <input type="checkbox" name="styles_nav_item_dividers" value="1" <?= !empty($st['nav_item_dividers']) ? 'checked' : '' ?>>
                        <span>Показывать вертикальные разделители между пунктами</span>
                    </label>

                    <div class="hb-inline-grid">
                        <div class="form-field">
                            <label for="styles_nav_gap">Расстояние между пунктами, px</label>
                            <input type="number" id="styles_nav_gap" name="styles_nav_gap" min="0" max="64" step="1"
                                   value="<?= (int) ($st['nav_gap'] ?? 18) ?>" inputmode="numeric">
                            <small class="form-hint">0–64 px. При включённых разделителях линия располагается по центру этого промежутка.</small>
                        </div>

                        <div class="form-field">
                            <label for="styles_nav_overflow">Если пункты не помещаются</label>
                            <select id="styles_nav_overflow" name="styles_nav_overflow">
                                <option value="adaptive" <?= ($st['nav_overflow'] ?? 'adaptive') === 'adaptive' ? 'selected' : '' ?>>Лишние пункты перенести в меню «Ещё»</option>
                                <option value="wrap" <?= ($st['nav_overflow'] ?? 'adaptive') === 'wrap' ? 'selected' : '' ?>>Перенести пункты на следующую строку</option>
                            </select>
                            <small class="form-hint">Основные пункты остаются горизонтальными, а меню «Ещё» появляется последним пунктом строки.</small>
                        </div>

                        <div class="form-field">
                            <label for="styles_nav_divider_width">Толщина разделителя, px</label>
                            <input type="number" id="styles_nav_divider_width" name="styles_nav_divider_width" min="1" max="10" step="1"
                                   value="<?= (int) ($st['nav_divider_width'] ?? 1) ?>" inputmode="numeric">
                        </div>

                        <div class="form-field">
                            <label for="styles_nav_divider_height">Высота разделителя, px</label>
                            <input type="number" id="styles_nav_divider_height" name="styles_nav_divider_height" min="4" max="64" step="1"
                                   value="<?= (int) ($st['nav_divider_height'] ?? 18) ?>" inputmode="numeric">
                        </div>

                        <div class="form-field">
                            <label for="styles_nav_divider_color">Цвет в обычной шапке</label>
                            <input type="color" id="styles_nav_divider_color" name="styles_nav_divider_color"
                                   value="<?= htmlspecialchars($st['nav_divider_color'] ?: '#94a3b8', ENT_QUOTES) ?>">
                            <label><input type="checkbox" name="styles_nav_divider_color_use" value="1" <?= $st['nav_divider_color'] !== '' ? 'checked' : '' ?>> Свой цвет</label>
                        </div>

                        <div class="form-field">
                            <label for="styles_nav_divider_color_transparent">Цвет в прозрачной шапке</label>
                            <input type="color" id="styles_nav_divider_color_transparent" name="styles_nav_divider_color_transparent"
                                   value="<?= htmlspecialchars($st['nav_divider_color_transparent'] ?: '#ffffff', ENT_QUOTES) ?>">
                            <label><input type="checkbox" name="styles_nav_divider_color_transparent_use" value="1" <?= $st['nav_divider_color_transparent'] !== '' ? 'checked' : '' ?>> Свой цвет</label>
                        </div>
                    </div>
                </div>

                <!-- Настройки неоновой линии подчеркивания меню (Hoverline) -->
                <div class="u-inline-d4e35c613b" data-hdr-nav-style-only="underline">
                    <h4 class="u-inline-2e67dd3b15">
                        Параметры линии подчеркивания меню (Hoverline)
                    </h4>
                    <div class="hb-inline-grid">
                        <div class="form-field">
                            <label for="styles_hoverline_length">Длина линии (по горизонтали)</label>
                            <select id="styles_hoverline_length" name="styles_hoverline_length">
                                <option value="compact" <?= ($st['hoverline_length'] ?? 'normal') === 'compact' ? 'selected' : '' ?>>Короткая / сжатая к центру (12px)</option>
                                <option value="normal" <?= ($st['hoverline_length'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Стандартная (4px от краев)</option>
                                <option value="full" <?= ($st['hoverline_length'] ?? 'normal') === 'full' ? 'selected' : '' ?>>Полноширинная (0px / во всю ширину)</option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label for="styles_hoverline_offset">Отступ от текста (по вертикали)</label>
                            <select id="styles_hoverline_offset" name="styles_hoverline_offset">
                                <option value="close" <?= ($st['hoverline_offset'] ?? 'normal') === 'close' ? 'selected' : '' ?>>Высокая / вплотную к букве (1px)</option>
                                <option value="normal" <?= ($st['hoverline_offset'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Стандартная (4px под текстом)</option>
                                <option value="far" <?= ($st['hoverline_offset'] ?? 'normal') === 'far' ? 'selected' : '' ?>>Низкая / глубокий отступ (8px)</option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label for="styles_hoverline_thickness">Толщина линии (высота в px)</label>
                            <select id="styles_hoverline_thickness" name="styles_hoverline_thickness">
                                <option value="1px" <?= in_array($st['hoverline_thickness'] ?? '2px', ['thin', '1px'], true) ? 'selected' : '' ?>>Нить — 1px</option>
                                <option value="2px" <?= in_array($st['hoverline_thickness'] ?? '2px', ['normal', '2px'], true) ? 'selected' : '' ?>>Тонкая — 2px (По умолчанию)</option>
                                <option value="3px" <?= in_array($st['hoverline_thickness'] ?? '2px', ['thick', '3px'], true) ? 'selected' : '' ?>>Стандартная — 3px</option>
                                <option value="4px" <?= in_array($st['hoverline_thickness'] ?? '2px', ['heavy', '4px'], true) ? 'selected' : '' ?>>Выразительная — 4px</option>
                                <option value="5px" <?= ($st['hoverline_thickness'] ?? '2px') === '5px' ? 'selected' : '' ?>>Утолщённая — 5px</option>
                                <option value="6px" <?= ($st['hoverline_thickness'] ?? '2px') === '6px' ? 'selected' : '' ?>>Массивная — 6px</option>
                                <option value="8px" <?= ($st['hoverline_thickness'] ?? '2px') === '8px' ? 'selected' : '' ?>>Капсула — 8px</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="hb-inline-grid u-inline-c90ff0dd8c">
                    <div class="form-field">
                        <label for="styles_nav_font_size">Размер шрифта пунктов</label>
                        <select id="styles_nav_font_size" name="styles_nav_font_size">
                            <option value="compact" <?= ($st['nav_font_size'] ?? 'normal') === 'compact' ? 'selected' : '' ?>>Сжатый (12px / 13px)</option>
                            <option value="normal" <?= ($st['nav_font_size'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Обычный (14px / 15px)</option>
                            <option value="large" <?= ($st['nav_font_size'] ?? 'normal') === 'large' ? 'selected' : '' ?>>Крупный (16px)</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="styles_nav_transform">Регистр букв</label>
                        <select id="styles_nav_transform" name="styles_nav_transform">
                            <option value="uppercase" <?= ($st['nav_transform'] ?? 'uppercase') === 'uppercase' ? 'selected' : '' ?>>ЗАГЛАВНЫЕ</option>
                            <option value="capitalize" <?= ($st['nav_transform'] ?? 'uppercase') === 'capitalize' ? 'selected' : '' ?>>С Заглавной Буквы</option>
                            <option value="none" <?= ($st['nav_transform'] ?? 'uppercase') === 'none' ? 'selected' : '' ?>>Обычный текст</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="styles_nav_padding">Плотность отступов</label>
                        <select id="styles_nav_padding" name="styles_nav_padding">
                            <option value="compact" <?= ($st['nav_padding'] ?? 'normal') === 'compact' ? 'selected' : '' ?>>Компактная</option>
                            <option value="normal" <?= ($st['nav_padding'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Стандартная</option>
                            <option value="spacious" <?= ($st['nav_padding'] ?? 'normal') === 'spacious' ? 'selected' : '' ?>>Просторная</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="styles_nav_letter_spacing">Межбуквенный интервал</label>
                        <select id="styles_nav_letter_spacing" name="styles_nav_letter_spacing">
                            <option value="normal" <?= ($st['nav_letter_spacing'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Обычный (0)</option>
                            <option value="wide" <?= ($st['nav_letter_spacing'] ?? 'normal') === 'wide' ? 'selected' : '' ?>>Расширенный (0.05em)</option>
                            <option value="wider" <?= ($st['nav_letter_spacing'] ?? 'normal') === 'wider' ? 'selected' : '' ?>>Широкий (0.1em)</option>
                        </select>
                    </div>
                </div>

                <div class="u-inline-d4e35c613b">
                    <h4 class="u-inline-2e67dd3b15">Дизайн подменю</h4>
                    <p class="form-hint">Настройте панель, тип пунктов, разделители и цвета выпадающего меню.</p>

                    <div class="hb-inline-grid">
                        <div class="form-field">
                            <label for="styles_submenu_style">Стиль пунктов</label>
                            <select id="styles_submenu_style" name="styles_submenu_style">
                                <option value="lines" <?= ($st['submenu_style'] ?? 'lines') === 'lines' ? 'selected' : '' ?>>Разделительные линии</option>
                                <option value="minimal" <?= ($st['submenu_style'] ?? 'lines') === 'minimal' ? 'selected' : '' ?>>Минимальный — только текст</option>
                                <option value="cards" <?= ($st['submenu_style'] ?? 'lines') === 'cards' ? 'selected' : '' ?>>Мягкие плашки</option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label for="styles_submenu_width">Ширина панели</label>
                            <select id="styles_submenu_width" name="styles_submenu_width">
                                <option value="compact" <?= ($st['submenu_width'] ?? 'normal') === 'compact' ? 'selected' : '' ?>>Компактная — 220px</option>
                                <option value="normal" <?= ($st['submenu_width'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Стандартная — 260px</option>
                                <option value="wide" <?= ($st['submenu_width'] ?? 'normal') === 'wide' ? 'selected' : '' ?>>Широкая — 320px</option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label for="styles_submenu_font_size">Размер текста</label>
                            <input id="styles_submenu_font_size" name="styles_submenu_font_size" type="number"
                                   min="10" max="24" step="0.1" inputmode="decimal"
                                   value="<?= htmlspecialchars((string) ($st['submenu_font_size'] ?? '13.8'), ENT_QUOTES) ?>">
                            <small class="form-hint">Любое значение от 10 до 24 px, например 14.4.</small>
                        </div>

                        <div class="form-field">
                            <label for="styles_submenu_padding">Плотность пунктов</label>
                            <select id="styles_submenu_padding" name="styles_submenu_padding">
                                <option value="compact" <?= ($st['submenu_padding'] ?? 'normal') === 'compact' ? 'selected' : '' ?>>Компактная</option>
                                <option value="normal" <?= ($st['submenu_padding'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Стандартная</option>
                                <option value="spacious" <?= ($st['submenu_padding'] ?? 'normal') === 'spacious' ? 'selected' : '' ?>>Просторная</option>
                            </select>
                        </div>
                    </div>

                    <div class="hb-inline-grid u-inline-c90ff0dd8c">
                        <div class="form-field">
                            <label for="styles_submenu_transform">Регистр текста</label>
                            <select id="styles_submenu_transform" name="styles_submenu_transform">
                                <option value="none" <?= ($st['submenu_transform'] ?? 'none') === 'none' ? 'selected' : '' ?>>Обычный текст</option>
                                <option value="uppercase" <?= ($st['submenu_transform'] ?? 'none') === 'uppercase' ? 'selected' : '' ?>>ЗАГЛАВНЫЕ</option>
                                <option value="capitalize" <?= ($st['submenu_transform'] ?? 'none') === 'capitalize' ? 'selected' : '' ?>>С Заглавной Буквы</option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label for="styles_submenu_radius">Скругление панели</label>
                            <select id="styles_submenu_radius" name="styles_submenu_radius">
                                <option value="none" <?= ($st['submenu_radius'] ?? 'soft') === 'none' ? 'selected' : '' ?>>Без скругления</option>
                                <option value="soft" <?= ($st['submenu_radius'] ?? 'soft') === 'soft' ? 'selected' : '' ?>>Мягкое — 10px</option>
                                <option value="rounded" <?= ($st['submenu_radius'] ?? 'soft') === 'rounded' ? 'selected' : '' ?>>Выраженное — 16px</option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label for="styles_submenu_shadow">Тень панели</label>
                            <select id="styles_submenu_shadow" name="styles_submenu_shadow">
                                <option value="none" <?= ($st['submenu_shadow'] ?? 'soft') === 'none' ? 'selected' : '' ?>>Без тени</option>
                                <option value="soft" <?= ($st['submenu_shadow'] ?? 'soft') === 'soft' ? 'selected' : '' ?>>Мягкая</option>
                                <option value="deep" <?= ($st['submenu_shadow'] ?? 'soft') === 'deep' ? 'selected' : '' ?>>Глубокая</option>
                            </select>
                        </div>

                        <div class="form-field">
                            <label for="styles_submenu_divider">Разделительные линии</label>
                            <select id="styles_submenu_divider" name="styles_submenu_divider">
                                <option value="subtle" <?= ($st['submenu_divider'] ?? 'subtle') === 'subtle' ? 'selected' : '' ?>>Нейтральные</option>
                                <option value="accent" <?= ($st['submenu_divider'] ?? 'subtle') === 'accent' ? 'selected' : '' ?>>Акцентные</option>
                                <option value="none" <?= ($st['submenu_divider'] ?? 'subtle') === 'none' ? 'selected' : '' ?>>Без линий</option>
                            </select>
                        </div>
                    </div>

                    <div class="hb-inline-grid u-inline-c90ff0dd8c">
                        <div class="form-field">
                            <label>Фон подменю</label>
                            <div class="color-picker-group">
                                <input type="color" name="styles_submenu_bg" value="<?= htmlspecialchars($st['submenu_bg'] ?: '#ffffff', ENT_QUOTES) ?>">
                                <label><input type="checkbox" name="styles_submenu_bg_use" value="1" <?= $st['submenu_bg'] !== '' ? 'checked' : '' ?>> Свой цвет</label>
                            </div>
                        </div>

                        <div class="form-field">
                            <label>Цвет текста</label>
                            <div class="color-picker-group">
                                <input type="color" name="styles_submenu_color" value="<?= htmlspecialchars($st['submenu_color'] ?: '#1e293b', ENT_QUOTES) ?>">
                                <label><input type="checkbox" name="styles_submenu_color_use" value="1" <?= $st['submenu_color'] !== '' ? 'checked' : '' ?>> Свой цвет</label>
                            </div>
                        </div>

                        <div class="form-field">
                            <label>Цвет при наведении</label>
                            <div class="color-picker-group">
                                <input type="color" name="styles_submenu_hover" value="<?= htmlspecialchars($st['submenu_hover'] ?: '#0284c7', ENT_QUOTES) ?>">
                                <label><input type="checkbox" name="styles_submenu_hover_use" value="1" <?= $st['submenu_hover'] !== '' ? 'checked' : '' ?>> Свой цвет</label>
                            </div>
                        </div>

                        <div class="form-field">
                            <label>Цвет разделителей</label>
                            <div class="color-picker-group">
                                <input type="color" name="styles_submenu_divider_color" value="<?= htmlspecialchars($st['submenu_divider_color'] ?: '#e2e8f0', ENT_QUOTES) ?>">
                                <label><input type="checkbox" name="styles_submenu_divider_color_use" value="1" <?= $st['submenu_divider_color'] !== '' ? 'checked' : '' ?>> Свой цвет</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODULE 3: VISUAL ZONE BUILDER -->
        <div class="admin-tab-content is-active" id="tab-hdr-builder" role="tabpanel" aria-labelledby="hdr-tab-builder">
            <div class="header-builder__group form-card u-inline-8cddc29a69">
                <h3 class="u-inline-0e0c39e056">1. Элементы по зонам</h3>
                <p class="form-hint u-inline-291b7bbb01">Перетащите элемент из палитры или выберите зону и нажмите нужный элемент. Для удаления используйте крестик на карточке.</p>

                <!-- Palette of available elements -->
                <div class="hb-palette u-inline-8a359a76eb">
                    <div class="hb-palette__title">Доступные элементы</div>
                    <div class="hb-palette__items hdr-builder__palette" data-hdr-zone="palette">
                        <?php foreach (array_keys($elements) as $type): ?>
                            <?= $renderChip($type) ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="hdr-tabs" data-hdr-tabs role="tablist" aria-label="Версия шапки">
                    <button type="button" class="hdr-tabs__tab is-active" role="tab" aria-selected="true" data-hdr-tab="desktop">Компьютер</button>
                    <button type="button" class="hdr-tabs__tab" role="tab" aria-selected="false" data-hdr-tab="mobile">Телефон и планшет</button>
                </div>

                <div class="hdr-tabs__panel is-active" data-hdr-panel="desktop">
                    <!-- Main Middlebar Zone Editor -->
                    <div class="hb-section u-inline-9eb125f52f" data-hdr-builder>
                        <div class="hb-section__head">
                            <div class="hb-section__title">Основная секция</div>
                            <div class="hb-section__controls">
                                <label class="hb-color-control">
                                    <input type="checkbox" name="middlebar_bg_use" value="1" <?= ($config['middlebar']['bg'] ?? '') !== '' ? 'checked' : '' ?>>
                                    Свой фон
                                </label>
                                <input type="color" name="middlebar_bg" aria-label="Фон основной секции"
                                       value="<?= htmlspecialchars(($config['middlebar']['bg'] ?? '') ?: '#ffffff', ENT_QUOTES) ?>">
                                <div class="hb-section__height">Высота: <?= $heightSelect('middlebar_height', $config['middlebar']['height'] ?? 'normal') ?></div>
                            </div>
                        </div>
                        <?= $renderZones($config['elements'], 'elements') ?>
                    </div>

                    <!-- Topbar Zone Editor -->
                    <div class="hb-section u-inline-9eb125f52f" data-hdr-builder>
                        <div class="hb-section__head">
                            <div class="hb-section__title">Верхняя полоса</div>
                            <div class="hb-section__controls">
                                <label><input type="checkbox" name="topbar_enabled" value="1" <?= !empty($config['topbar']['enabled']) ? 'checked' : '' ?>> Включить</label>
                                <label><input type="checkbox" name="topbar_mobile" value="1" <?= !empty($config['topbar']['show_mobile']) ? 'checked' : '' ?>> Показывать на мобильных</label>
                                <label><input type="checkbox" name="topbar_border" value="1" <?= !empty($config['topbar']['show_border']) ? 'checked' : '' ?>> Граница</label>
                                <select name="topbar_style" class="hb-select" aria-label="Стиль верхней полосы">
                                    <?php foreach (['navy' => 'Тёмно-синяя', 'light' => 'Светлая', 'teal' => 'Бирюзовая'] as $barStyle => $barLabel): ?>
                                        <option value="<?= $barStyle ?>" <?= ($config['topbar']['style'] ?? 'navy') === $barStyle ? 'selected' : '' ?>><?= $barLabel ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="hb-section__height">Высота: <?= $heightSelect('topbar_height', $config['topbar']['height'] ?? 'normal') ?></div>
                            </div>
                        </div>
                        <?= $renderZones($config['topbar']['zones'] ?? [], 'topbar_zones') ?>
                    </div>

                    <!-- Bottombar Zone Editor -->
                    <div class="hb-section u-inline-9eb125f52f" data-hdr-builder>
                        <div class="hb-section__head">
                            <div class="hb-section__title">Нижняя полоса</div>
                            <div class="hb-section__controls">
                                <label class="hb-color-control">
                                    <input type="checkbox" name="bottombar_bg_use" value="1" <?= ($config['bottombar']['bg'] ?? '') !== '' ? 'checked' : '' ?>>
                                    Свой фон
                                </label>
                                <input type="color" name="bottombar_bg" aria-label="Фон нижней полосы"
                                       value="<?= htmlspecialchars(($config['bottombar']['bg'] ?? '') ?: '#ffffff', ENT_QUOTES) ?>">
                                <div class="hb-section__height">Высота: <?= $heightSelect('bottombar_height', $config['bottombar']['height'] ?? 'normal') ?></div>
                            </div>
                        </div>
                        <?= $renderZones($config['bottombar']['zones'] ?? [], 'bottombar_zones') ?>
                    </div>
                </div>

                <div class="hdr-tabs__panel" data-hdr-panel="mobile" hidden>
                    <p class="hb-note">Мобильная раскладка управляет компактной строкой шапки. Полное меню открывается отдельной кнопкой.</p>
                    <div class="hb-section u-inline-9eb125f52f" data-hdr-builder>
                        <div class="hb-section__head">
                            <div class="hb-section__title">Мобильная строка</div>
                        </div>
                        <?= $renderZones($config['elements_mobile'] ?? [], 'elements_mobile') ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODULE 4: ELEMENT CONTROLS & TOOLS SETUP -->
        <div class="admin-tab-content" id="tab-hdr-elements" role="tabpanel" aria-labelledby="hdr-tab-elements">
            <!-- 1. Language Switcher Controls -->
            <div class="header-builder__group form-card u-inline-8cddc29a69">
                <h3 class="u-inline-39a35c5e86">
                    <?= AdminUi::icon('globe', 20, 'text-primary') ?> 1. Переключатель языков
                </h3>
                <p class="form-hint u-inline-291b7bbb01">Управляйте видом, форматом и поведением отображения переключателя языков в шапке.</p>

                <?php $lsCfg = $config['language_switcher'] ?? []; ?>
                <div class="hb-inline-grid u-inline-8a359a76eb">
                    <div class="form-field">
                        <label for="ls_format">Формат подписи языка</label>
                        <select id="ls_format" name="ls_format">
                            <option value="code" <?= ($lsCfg['format'] ?? 'code') === 'code' ? 'selected' : '' ?>>Код языка (RU, UZ, EN)</option>
                            <option value="name" <?= ($lsCfg['format'] ?? 'code') === 'name' ? 'selected' : '' ?>>Полное имя (Русский, O'zbekcha, English)</option>
                            <option value="flag" <?= ($lsCfg['format'] ?? 'code') === 'flag' ? 'selected' : '' ?>>Флаг страны (🇷🇺, 🇺🇿, 🇬🇧)</option>
                            <option value="code_flag" <?= ($lsCfg['format'] ?? 'code') === 'code_flag' ? 'selected' : '' ?>>Флаг + Код (🇷🇺 RU, 🇺🇿 UZ, 🇬🇧 EN)</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="ls_style">Вид отображения</label>
                        <select id="ls_style" name="ls_style">
                            <option value="dropdown" <?= ($lsCfg['style'] ?? 'dropdown') === 'dropdown' ? 'selected' : '' ?>>Выпадающее меню (Dropdown)</option>
                            <option value="pills" <?= ($lsCfg['style'] ?? 'dropdown') === 'pills' ? 'selected' : '' ?>>Кнопки-плашки в ряд (Pills)</option>
                        </select>
                    </div>
                </div>

                <div class="hb-behavior__options u-inline-8a359a76eb">
                    <label class="hb-behavior-card">
                        <span class="hb-switch">
                            <input type="checkbox" name="ls_enabled" value="1" <?= !empty($lsCfg['enabled']) ? 'checked' : '' ?>>
                            <span class="hb-switch__track"></span>
                            <span class="hb-behavior-card__title">Включить переключатель языков</span>
                        </span>
                        <span class="hb-behavior-card__hint">Отображать переключатель языков, когда он помещен в одну из зон шапки.</span>
                    </label>

                    <label class="hb-behavior-card">
                        <span class="hb-switch">
                            <input type="checkbox" name="ls_always_show" value="1" <?= !empty($lsCfg['always_show']) ? 'checked' : '' ?>>
                            <span class="hb-switch__track"></span>
                            <span class="hb-behavior-card__title">Отображать даже если 1 активный язык</span>
                        </span>
                        <span class="hb-behavior-card__hint">Показывает активный язык в шапке сайта всегда (удобно для тестирования и эстетики).</span>
                    </label>
                </div>
            </div>

            <!-- 2. CTA Button Controls -->
            <div class="header-builder__group form-card u-inline-d40eaf045d">
                <h3 class="u-inline-0e0c39e056">
                    2. Акцентная кнопка
                </h3>
                <p class="form-hint u-inline-291b7bbb01">Настройте текст, ссылку, стиль и иконку яркой кнопки в шапке.</p>

                <?php $ctaCfg = $config['cta'] ?? []; ?>
                <div class="hb-inline-grid u-inline-8a359a76eb">
                    <div class="form-field">
                        <label for="cta_text">Текст на кнопке</label>
                        <input id="cta_text" name="cta_text" type="text" placeholder="Например: Записаться" value="<?= htmlspecialchars($ctaCfg['text'] ?? '', ENT_QUOTES) ?>">
                    </div>

                    <div class="form-field">
                        <label for="cta_url">Ссылка кнопки (URL)</label>
                        <input id="cta_url" name="cta_url" type="text" placeholder="/contacts или https://..." value="<?= htmlspecialchars($ctaCfg['url'] ?? '', ENT_QUOTES) ?>">
                    </div>

                    <div class="form-field">
                        <label for="cta_style">Вариант дизайна кнопки</label>
                        <select id="cta_style" name="cta_style">
                            <option value="filled" <?= ($ctaCfg['style'] ?? 'filled') === 'filled' ? 'selected' : '' ?>>Залитая кнопка (Filled Accent)</option>
                            <option value="outline" <?= ($ctaCfg['style'] ?? 'filled') === 'outline' ? 'selected' : '' ?>>Контурная кнопка (Outline)</option>
                            <option value="glass" <?= ($ctaCfg['style'] ?? 'filled') === 'glass' ? 'selected' : '' ?>>Полупрозрачное стекло (Glassmorphic)</option>
                            <option value="neon" <?= ($ctaCfg['style'] ?? 'filled') === 'neon' ? 'selected' : '' ?>>Неоновый градиент с подсветкой</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="cta_icon">Иконка перед текстом</label>
                        <select id="cta_icon" name="cta_icon">
                            <option value="none" <?= ($ctaCfg['icon'] ?? 'none') === 'none' ? 'selected' : '' ?>>Без иконки</option>
                            <option value="phone" <?= ($ctaCfg['icon'] ?? 'none') === 'phone' ? 'selected' : '' ?>>Телефон</option>
                            <option value="calendar" <?= ($ctaCfg['icon'] ?? 'none') === 'calendar' ? 'selected' : '' ?>>Календарь / Запись</option>
                            <option value="send" <?= ($ctaCfg['icon'] ?? 'none') === 'send' ? 'selected' : '' ?>>Отправить</option>
                            <option value="arrow" <?= ($ctaCfg['icon'] ?? 'none') === 'arrow' ? 'selected' : '' ?>>Стрелка</option>
                        </select>
                    </div>
                </div>

                <div class="hb-behavior__options u-inline-8a359a76eb">
                    <label class="hb-behavior-card">
                        <span class="hb-switch">
                            <input type="checkbox" name="cta_enabled" value="1" <?= !empty($ctaCfg['enabled']) ? 'checked' : '' ?>>
                            <span class="hb-switch__track"></span>
                            <span class="hb-behavior-card__title">Включить кнопку CTA</span>
                        </span>
                        <span class="hb-behavior-card__hint">Показывает кнопку, если элемент «Кнопка (CTA)» перетащен в одну из зон.</span>
                    </label>
                </div>
            </div>

            <!-- 3. Search & Social Controls -->
            <div class="header-builder__group form-card u-inline-d40eaf045d">
                <h3 class="u-inline-0e0c39e056">
                    3. Поиск и социальные сети
                </h3>

                <?php $srchCfg = $config['search'] ?? []; ?>
                <div class="hb-inline-grid u-inline-8a359a76eb">
                    <div class="form-field">
                        <label for="search_style">Стиль поля поиска</label>
                        <select id="search_style" name="search_style">
                            <option value="inline" <?= ($srchCfg['style'] ?? 'inline') === 'inline' ? 'selected' : '' ?>>Выезжающее поле поиска в шапке (Inline Slide)</option>
                            <option value="modal" <?= ($srchCfg['style'] ?? 'inline') === 'modal' ? 'selected' : '' ?>>Полноэкранный оверлей (Modal Overlay)</option>
                        </select>
                    </div>

                    <div class="form-field">
                        <label for="search_placeholder">Текст-подсказка в поле поиска</label>
                        <input id="search_placeholder" name="search_placeholder" type="text" value="<?= htmlspecialchars($srchCfg['placeholder'] ?? 'Поиск по сайту...', ENT_QUOTES) ?>">
                    </div>

                    <div class="form-field">
                        <label for="social_style">Стиль иконок социальных сетей</label>
                        <select id="social_style" name="social_style">
                            <option value="monochrome" <?= ($config['social_style'] ?? 'monochrome') === 'monochrome' ? 'selected' : '' ?>>Элегантный монохром</option>
                            <option value="colored" <?= ($config['social_style'] ?? 'monochrome') === 'colored' ? 'selected' : '' ?>>Фирменные цвета соцсетей</option>
                            <option value="outline" <?= ($config['social_style'] ?? 'monochrome') === 'outline' ? 'selected' : '' ?>>Контурные кружки</option>
                        </select>
                    </div>
                </div>

                <div class="u-inline-c90ff0dd8c">
                    <h4 class="u-inline-2e67dd3b15">Ссылки на социальные сети</h4>
                    <p class="form-hint">Ссылки отображаются, если элемент «Соцсети» размещён в одной из зон.</p>
                    <div data-repeater="header-social" data-repeater-next-index="<?= count($config['social_buttons'] ?? []) ?>" class="hb-social-list">
                        <?php foreach (($config['social_buttons'] ?? []) as $socialIndex => $socialButton): ?>
                            <div class="repeater-row hb-social-row">
                                <div class="form-field">
                                    <label>Сеть</label>
                                    <select name="social[<?= (int) $socialIndex ?>][network]">
                                        <?php foreach ($networks as $networkKey => $networkLabel): ?>
                                            <option value="<?= $networkKey ?>" <?= ($socialButton['network'] ?? '') === $networkKey ? 'selected' : '' ?>><?= $networkLabel ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-field hb-social-row__url">
                                    <label>Ссылка</label>
                                    <input type="url" name="social[<?= (int) $socialIndex ?>][url]"
                                           value="<?= htmlspecialchars($socialButton['url'] ?? '', ENT_QUOTES) ?>" placeholder="https://">
                                </div>
                                <button type="button" class="btn btn--small btn--danger" data-repeater-remove>Удалить</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <template data-repeater-template="header-social">
                        <div class="form-field">
                            <label>Сеть</label>
                            <select name="social[__INDEX__][network]">
                                <?php foreach ($networks as $networkKey => $networkLabel): ?>
                                    <option value="<?= $networkKey ?>"><?= $networkLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-field hb-social-row__url">
                            <label>Ссылка</label>
                            <input type="url" name="social[__INDEX__][url]" placeholder="https://">
                        </div>
                        <button type="button" class="btn btn--small btn--danger" data-repeater-remove>Удалить</button>
                    </template>
                    <div class="repeater-actions">
                        <button type="button" class="btn btn--small btn--secondary" data-repeater-add="header-social">
                            <?= AdminUi::icon('plus') ?> Добавить ссылку
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODULE 5: LOGOS & BRANDING -->
        <div class="admin-tab-content" id="tab-hdr-logos" role="tabpanel" aria-labelledby="hdr-tab-logos">
            <div class="header-builder__group form-card u-inline-8cddc29a69">
                <h3 class="u-inline-0e0c39e056">
                    Логотипы
                </h3>

                <div class="hb-inline-grid u-inline-8a359a76eb">
                    <div class="form-field">
                        <label for="logo_position">Позиционирование логотипа</label>
                        <select id="logo_position" name="logo_position">
                            <option value="left" <?= $config['logo_position'] === 'left' ? 'selected' : '' ?>>Слева</option>
                            <option value="center" <?= $config['logo_position'] === 'center' ? 'selected' : '' ?>>По центру</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="logo_width">Ширина логотипа, px</label>
                        <input id="logo_width" name="logo_width" type="number" min="40" max="600" step="1" value="<?= (int) ($config['logo_width'] ?? 240) ?>">
                    </div>
                    <div class="form-field">
                        <label for="logo_height">Высота логотипа, px</label>
                        <input id="logo_height" name="logo_height" type="number" min="20" max="200" step="1" value="<?= (int) ($config['logo_height'] ?? 48) ?>">
                    </div>
                </div>

                <div class="hb-behavior__media u-inline-9eb125f52f">
                    <?= AdminUi::imageField('logo_light', $config['logo_light'] ?? '', [
                        'label' => 'Светлый (белый) логотип для прозрачной шапки над баннером',
                        'file' => 'logo_light_file',
                    ]) ?>
                </div>

                <?php $hdrLangs = \App\Models\Language::active(); ?>
                <?php if (count($hdrLangs) > 1): ?>
                    <div class="u-inline-c90ff0dd8c">
                        <label class="form-label u-inline-758a887326">Логотипы по языкам</label>
                        <?php foreach ($hdrLangs as $hlang): $hc = htmlspecialchars((string) $hlang['code'], ENT_QUOTES); ?>
                            <div class="u-inline-43a8ae377d">
                                <div class="u-inline-78a7b9be58"><?= htmlspecialchars((string) $hlang['name'], ENT_QUOTES) ?> (<?= $hc ?>)</div>
                                <div class="hb-inline-grid">
                                    <?= AdminUi::imageField('logo_lang_' . $hc, (string) ($config['logo_by_lang'][$hlang['code']] ?? ''), [
                                        'label' => 'Логотип',
                                        'file' => 'logo_lang_' . $hc . '_file',
                                    ]) ?>
                                    <?= AdminUi::imageField('logo_light_lang_' . $hc, (string) ($config['logo_light_by_lang'][$hlang['code']] ?? ''), [
                                        'label' => 'Светлый логотип',
                                        'file' => 'logo_light_lang_' . $hc . '_file',
                                    ]) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- MODULE 5: COLORS & UTILITIES -->
        <div class="admin-tab-content" id="tab-hdr-styles" role="tabpanel" aria-labelledby="hdr-tab-styles">
            <div class="header-builder__group form-card u-inline-8cddc29a69">
                <h3 class="u-inline-0e0c39e056">
                    Цвета меню, полос и границ
                </h3>

                <div class="hb-inline-grid u-inline-8a359a76eb">
                    <div class="form-field">
                        <label>Фон верхней полосы (Topbar)</label>
                        <div class="color-picker-group">
                            <input type="color" name="styles_topbar_bg" value="<?= htmlspecialchars($st['topbar_bg'] ?: '#173a63', ENT_QUOTES) ?>">
                            <label><input type="checkbox" name="styles_topbar_bg_use" value="1" <?= $st['topbar_bg'] !== '' ? 'checked' : '' ?>> Свой цвет</label>
                        </div>
                    </div>
                    <div class="form-field">
                        <label>Цвет текста верхней полосы</label>
                        <div class="color-picker-group">
                            <input type="color" name="styles_topbar_text" value="<?= htmlspecialchars($st['topbar_text'] ?: '#ffffff', ENT_QUOTES) ?>">
                            <label><input type="checkbox" name="styles_topbar_text_use" value="1" <?= $st['topbar_text'] !== '' ? 'checked' : '' ?>> Свой цвет</label>
                        </div>
                    </div>
                    <div class="form-field">
                        <label>Цвет пунктов меню (по умолчанию)</label>
                        <div class="color-picker-group">
                            <input type="color" name="styles_nav_color" value="<?= htmlspecialchars($st['nav_color'] ?: '#1e293b', ENT_QUOTES) ?>">
                            <label><input type="checkbox" name="styles_nav_color_use" value="1" <?= $st['nav_color'] !== '' ? 'checked' : '' ?>> Свой цвет</label>
                        </div>
                    </div>
                    <div class="form-field">
                        <label>Цвет пунктов при наведении</label>
                        <div class="color-picker-group">
                            <input type="color" name="styles_nav_hover" value="<?= htmlspecialchars($st['nav_hover'] ?: '#0284c7', ENT_QUOTES) ?>">
                            <label><input type="checkbox" name="styles_nav_hover_use" value="1" <?= $st['nav_hover'] !== '' ? 'checked' : '' ?>> Свой цвет</label>
                        </div>
                    </div>
                    <div class="form-field">
                        <label>Цвет активного пункта (Active)</label>
                        <div class="color-picker-group">
                            <input type="color" name="styles_nav_active" value="<?= htmlspecialchars($st['nav_active'] ?: '#0284c7', ENT_QUOTES) ?>">
                            <label><input type="checkbox" name="styles_nav_active_use" value="1" <?= $st['nav_active'] !== '' ? 'checked' : '' ?>> Свой цвет</label>
                        </div>
                    </div>
                </div>

                <div class="form-field hb-divider-field u-inline-c90ff0dd8c">
                    <label for="borders">Разделительные линии секций</label>
                    <select id="borders" name="borders">
                        <option value="full" <?= ($config['borders'] ?? 'full') === 'full' ? 'selected' : '' ?>>Во всю ширину экрана</option>
                        <option value="container" <?= ($config['borders'] ?? '') === 'container' ? 'selected' : '' ?>>По ширине контента</option>
                        <option value="none" <?= ($config['borders'] ?? '') === 'none' ? 'selected' : '' ?>>Без линий</option>
                    </select>
                </div>

                <div class="hb-inline-grid">
                    <div class="form-field">
                        <label for="styles_border_width">Толщина линий</label>
                        <select id="styles_border_width" name="styles_border_width">
                            <option value="" <?= ($st['border_width'] ?? '') === '' ? 'selected' : '' ?>>По умолчанию</option>
                            <option value="none" <?= ($st['border_width'] ?? '') === 'none' ? 'selected' : '' ?>>Без линии</option>
                            <option value="thin" <?= ($st['border_width'] ?? '') === 'thin' ? 'selected' : '' ?>>Тонкая</option>
                            <option value="thick" <?= ($st['border_width'] ?? '') === 'thick' ? 'selected' : '' ?>>Выраженная</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="styles_border_color">Цвет линий</label>
                        <div class="color-picker-group">
                            <input type="color" id="styles_border_color" name="styles_border_color"
                                   value="<?= htmlspecialchars($st['border_color'] ?: '#e2e8f0', ENT_QUOTES) ?>">
                            <label><input type="checkbox" name="styles_border_color_use" value="1" <?= $st['border_color'] !== '' ? 'checked' : '' ?>> Свой цвет</label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contacts & Snippet -->
            <div class="header-builder__group form-card u-inline-d40eaf045d">
                <h3 class="u-inline-0e0c39e056">
                    Контакты и дополнительный HTML
                </h3>
                <div class="hb-inline-grid u-inline-8a359a76eb">
                    <div class="form-field">
                        <label for="contact_phone">Телефон шапки</label>
                        <input id="contact_phone" name="contact_phone" type="text" value="<?= htmlspecialchars($config['contacts']['phone'] ?? '', ENT_QUOTES) ?>">
                    </div>
                    <div class="form-field">
                        <label for="contact_email">E-mail шапки</label>
                        <input id="contact_email" name="contact_email" type="text" value="<?= htmlspecialchars($config['contacts']['email'] ?? '', ENT_QUOTES) ?>">
                    </div>
                </div>

                <div class="form-field u-inline-8a359a76eb">
                    <label for="snippet">Произвольный HTML-сниппет (отображается в утилитах)</label>
                    <textarea id="snippet" name="snippet" rows="3" class="form-control u-inline-8ff9961267"><?= htmlspecialchars($config['snippet'] ?? '', ENT_QUOTES) ?></textarea>
                </div>
            </div>
        </div>

        <div class="form-actions form-actions--sticky">
            <button type="submit" class="btn btn--primary">
                <?= AdminUi::icon('device-floppy', 18) ?> Сохранить конфигурацию шапки
            </button>
            <span class="form-hint">Изменения структуры и стилей шапки применятся ко всему сайту.</span>
        </div>
    </form>
</div>

<script nonce="<?= \App\Core\SecurityHeaders::nonce() ?>">
(function () {
    'use strict';

    // Доступные вкладки с сохранением последнего открытого раздела.
    var hdrTabs = document.querySelectorAll('[data-hdr-tab-target]');
    var hdrPanels = document.querySelectorAll('.admin-tab-content');
    var activateTab = function (btn, remember) {
        if (!btn) { return; }
        var targetId = btn.getAttribute('data-hdr-tab-target');
        hdrTabs.forEach(function (item) {
            var active = item === btn;
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-selected', active ? 'true' : 'false');
            item.tabIndex = active ? 0 : -1;
        });
        hdrPanels.forEach(function (panel) {
            var active = panel.id === targetId;
            panel.classList.toggle('is-active', active);
            panel.hidden = !active;
        });
        if (remember) {
            try { localStorage.setItem('asdr-header-active-tab', targetId); } catch (error) {}
        }
    };

    hdrTabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            activateTab(btn, true);
        });
        btn.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') { return; }
            event.preventDefault();
            var list = Array.prototype.slice.call(hdrTabs);
            var index = list.indexOf(btn);
            var next = event.key === 'ArrowRight'
                ? (index + 1) % list.length
                : (index - 1 + list.length) % list.length;
            activateTab(list[next], true);
            list[next].focus();
        });
    });

    var savedTab = '';
    try { savedTab = localStorage.getItem('asdr-header-active-tab') || ''; } catch (error) {}
    var savedTabButton = savedTab
        ? document.querySelector('[data-hdr-tab-target="' + savedTab + '"]')
        : null;
    activateTab(savedTabButton || document.querySelector('[data-hdr-tab-target].is-active'), false);

    // Состояние карточек выбора контейнера.
    document.querySelectorAll('input[name="container_mode"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            document.querySelectorAll('.hdr-select-card').forEach(function (option) {
                var optionInput = option.querySelector('input[type="radio"]');
                option.classList.toggle('is-selected', !!optionInput && optionInput.checked);
            });
        });
    });

    // Схематичный предпросмотр ключевых параметров.
    function updateHdrPreview() {
        var valueOf = function (selector, fallback) {
            var field = document.querySelector(selector);
            return field ? field.value : fallback;
        };
        var checked = function (selector) {
            var field = document.querySelector(selector);
            return !!field && field.checked;
        };
        var customColor = function (name, fallback) {
            return checked('input[name="' + name + '_use"]')
                ? valueOf('input[name="' + name + '"]', fallback)
                : fallback;
        };

        var topbarBg = customColor('styles_topbar_bg', '#173a63');
        var topbarText = customColor('styles_topbar_text', '#ffffff');
        var navColor = customColor('styles_nav_color', '#1e293b');
        var navActive = customColor('styles_nav_active', '#0284c7');
        var middlebarBg = customColor('middlebar_bg', '#ffffff');
        var menuPosition = valueOf('select[name="menu_position"]', 'center');
        var navGap = valueOf('input[name="styles_nav_gap"]', '18');
        var navStyle = valueOf('select[name="styles_nav_style_type"]', 'underline');
        var containerMode = valueOf('input[name="container_mode"]:checked', 'full');

        document.querySelectorAll('[data-hdr-container-only]').forEach(function (section) {
            section.hidden = section.getAttribute('data-hdr-container-only') !== containerMode;
        });
        document.querySelectorAll('[data-hdr-nav-style-only]').forEach(function (section) {
            section.hidden = section.getAttribute('data-hdr-nav-style-only') !== navStyle;
        });

        var previewBox = document.querySelector('.hdr-live-preview__box');
        var prevTop = document.getElementById('prevTopbar');
        var prevMain = document.getElementById('prevMiddlebar');

        if (prevTop) {
            prevTop.hidden = !checked('input[name="topbar_enabled"]');
            prevTop.style.background = topbarBg;
            prevTop.style.color = topbarText;
        }
        if (prevMain) {
            prevMain.style.background = middlebarBg;
            prevMain.style.setProperty('--prev-nav-color', navColor);
            prevMain.style.setProperty('--prev-nav-active', navActive);
        }
        if (previewBox) {
            previewBox.setAttribute('data-preview-container', containerMode);
        }
        var previewNav = document.querySelector('.hdr-live-preview__nav');
        if (previewNav) {
            previewNav.style.justifyContent = menuPosition === 'right'
                ? 'flex-end'
                : (menuPosition === 'left' ? 'flex-start' : 'center');
            previewNav.style.gap = Math.max(0, Math.min(64, Number(navGap) || 18)) + 'px';
        }
    }

    document.querySelectorAll('input, select').forEach(function (field) {
        field.addEventListener('input', updateHdrPreview);
        field.addEventListener('change', updateHdrPreview);
    });
    updateHdrPreview();
})();
</script>
<?php require __DIR__ . '/../layout/footer.php'; ?>
