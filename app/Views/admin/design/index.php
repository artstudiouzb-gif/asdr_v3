<?php

use App\Core\Csrf;

$pageTitle = 'Дизайн сайта';
$activeNav = 'design';
require __DIR__ . '/../layout/header.php';

/** @var array $options */
/** @var array $presets */
/** @var array $userPresets */
/** @var array $values */
/** @var string $activePreset */

// Группируем опции точной настройки по разделам.
$grouped = [];
foreach ($options as $key => $opt) {
    $grouped[$opt['group']][$key] = $opt;
}
?>
<p class="form-hint">Глобальные цвета, типографика, сетка и компоненты сайта. Шапка и подвал настраиваются в собственных конструкторах и больше не конфликтуют с этим разделом.</p>

<!-- Live Component Preview Deck -->
<div class="live-deck">
    <div class="live-deck__head">
        <span class="live-deck__title">
            <?= \App\Core\AdminUi::icon('eye', 18) ?>
            Предпросмотр компонентов
        </span>
        <span class="form-hint u-inline-abed5788ce">Цвета, шрифты, размеры, скругления и тени обновляются без сохранения</span>
    </div>
    <div class="live-deck__canvas" id="liveDeckCanvas">
        <div class="live-deck__grid">
            <div class="live-deck__card">
                <h1 class="live-deck__h1">Заголовок H1</h1>
                <h2 class="live-deck__h2">Раздел H2</h2>
                <h3 class="live-deck__h3">Карточка H3</h3>
                <p class="live-deck__p">Пример текста: выбранные цвета, шрифты и отступы подставляются динамически.</p>
                <div class="u-inline-1e90930a6f">
                    <button type="button" class="live-deck__btn-primary">Основная кнопка</button>
                    <button type="button" class="live-deck__btn-accent">Акцентная кнопка</button>
                </div>
            </div>
            <div class="live-deck__card">
                <h3 class="live-deck__h3">Информационный блок</h3>
                <p class="live-deck__p">Показывает скругления углов, глубину тени карточек и гармонию темы.</p>
                <div class="u-inline-7ce543f1f8">
                    <span class="u-inline-ef1c305505"></span>
                    <span class="u-inline-e378797d0c">Состояние системы: Активна</span>
                </div>
            </div>
        </div>
    </div>
</div>

<details class="design-page-preview">
    <summary>
        <?= \App\Core\AdminUi::icon('browser', 18) ?>
        <span>
            <strong>Предпросмотр страницы</strong>
            <small>Проверка текущих параметров на реальной главной странице без сохранения</small>
        </span>
    </summary>
    <div class="design-preview" data-design-page-preview-shell>
        <div class="design-preview__bar">
            <div class="design-preview__sizes" role="group" aria-label="Ширина предпросмотра">
                <button type="button" class="btn btn--small is-active" data-design-preview-width="100%" aria-pressed="true">Компьютер</button>
                <button type="button" class="btn btn--small" data-design-preview-width="820px" aria-pressed="false">Планшет</button>
                <button type="button" class="btn btn--small" data-design-preview-width="390px" aria-pressed="false">Телефон</button>
            </div>
            <button type="button" class="btn btn--small btn--primary design-preview__refresh" data-design-preview-refresh>
                <?= \App\Core\AdminUi::icon('refresh', 16) ?>Обновить предпросмотр
            </button>
            <a class="btn btn--small" href="/admin/design/preview" target="_blank" rel="noopener">Открыть отдельно</a>
        </div>
        <div class="design-preview__stage">
            <iframe
                class="design-preview__frame"
                data-design-page-preview
                data-preview-base="/admin/design/preview"
                src="/admin/design/preview"
                title="Предпросмотр главной страницы"
                loading="lazy"
            ></iframe>
        </div>
    </div>
</details>

<!-- Tab Navigation -->
<div class="admin-tab-nav" role="tablist" aria-label="Разделы дизайна" data-design-tabs>
    <button type="button" class="admin-tab-btn is-active" id="design-tab-presets" role="tab"
            aria-selected="true" aria-controls="tab-presets" tabindex="0" data-tab-target="tab-presets">
        <?= \App\Core\AdminUi::icon('grid') ?>Конфигурации
    </button>
    <button type="button" class="admin-tab-btn" id="design-tab-layout" role="tab"
            aria-selected="false" aria-controls="tab-layout" tabindex="-1" data-tab-target="tab-layout">
        <?= \App\Core\AdminUi::icon('layout') ?>Макет и сетка
    </button>
    <button type="button" class="admin-tab-btn" id="design-tab-colors" role="tab"
            aria-selected="false" aria-controls="tab-colors" tabindex="-1" data-tab-target="tab-colors">
        <?= \App\Core\AdminUi::icon('palette') ?>Цвета
    </button>
    <button type="button" class="admin-tab-btn" id="design-tab-typography" role="tab"
            aria-selected="false" aria-controls="tab-typography" tabindex="-1" data-tab-target="tab-typography">
        <?= \App\Core\AdminUi::icon('type') ?>Типографика
    </button>
    <button type="button" class="admin-tab-btn" id="design-tab-components" role="tab"
            aria-selected="false" aria-controls="tab-components" tabindex="-1" data-tab-target="tab-components">
        <?= \App\Core\AdminUi::icon('sliders') ?>Компоненты
    </button>
</div>

<!-- TAB 1: PRESETS -->
<div class="admin-tab-content is-active" id="tab-presets" role="tabpanel"
     aria-labelledby="design-tab-presets" tabindex="0">
    <section class="design-section" id="design-presets">
        <h2 class="design-section__title">Мои конфигурации</h2>
        <?php if (!empty($userPresets)): ?>
            <div class="design-presets u-inline-79a1c5a5db">
                <?php foreach ($userPresets as $uslug => $upreset): ?>
                    <div class="design-preset design-preset--user<?= $activePreset === 'user:' . $uslug ? ' is-active' : '' ?>">
                        <div class="design-preset__head">
                            <strong><?= htmlspecialchars((string) $upreset['label'], ENT_QUOTES) ?></strong>
                            <?php if ($activePreset === 'user:' . $uslug): ?><span class="design-preset__badge">Активна</span><?php endif; ?>
                        </div>
                        <p class="design-preset__desc">Сохранённая вами конфигурация.</p>
                        <div class="u-inline-b9bbe540d3">
                            <form class="u-inline-1da9facb4d" method="post" action="/admin/design/preset">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="preset" value="user:<?= htmlspecialchars($uslug, ENT_QUOTES) ?>">
                                <button type="submit" class="btn btn--small btn--primary">Применить</button>
                            </form>
                            <form class="u-inline-1da9facb4d" method="post" action="/admin/design/preset/delete" data-confirm="Удалить конфигурацию «<?= htmlspecialchars((string) $upreset['label'], ENT_QUOTES) ?>»?">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="slug" value="<?= htmlspecialchars($uslug, ENT_QUOTES) ?>">
                                <button type="submit" class="btn btn--small btn--danger"><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="form-hint">Сохранённых конфигураций пока нет. Настройте параметры и сохраните текущий дизайн — это способ легко возвращаться к рабочим вариантам.</p>
        <?php endif; ?>
        <form method="post" action="/admin/design/preset/save" class="design-save-preset">
            <?= Csrf::field() ?>
            <input type="text" name="name" maxlength="40" placeholder="Название конфигурации (напр. «Новый бренд 2026»)" required>
            <button type="submit" class="btn">Сохранить текущие настройки</button>
        </form>
    </section>
</div>

<form method="post" action="/admin/design" class="design-fine" data-design-form enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <input type="hidden" name="palette" value="custom">

    <!-- TAB 2: LAYOUT -->
    <div class="admin-tab-content" id="tab-layout" role="tabpanel"
         aria-labelledby="design-tab-layout" tabindex="0" hidden>
        <section class="design-section">
            <h2 class="design-section__title">Настройки макета и сетки</h2>
            <?php foreach ($grouped['Общие'] ?? [] as $key => $opt): ?>
                <?php // Палитра и шрифт — на своей вкладке, здесь только сетка. ?>
                <?php if (in_array($key, ['palette', 'font_style'], true)) { continue; } ?>
                <div class="design-opt">
                    <div class="design-opt__label">
                        <span><?= htmlspecialchars($opt['label'], ENT_QUOTES) ?></span>
                        <?php if (!empty($opt['hint'])): ?><small><?= htmlspecialchars($opt['hint'], ENT_QUOTES) ?></small><?php endif; ?>
                    </div>
                    <div class="design-opt__choices">
                        <?php foreach ($opt['choices'] as $val => $label): ?>
                            <label class="design-card">
                                <input type="radio" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>" value="<?= htmlspecialchars($val, ENT_QUOTES) ?>" <?= ($values[$key] ?? '') === $val ? 'checked' : '' ?> data-design-preview-field>
                                <span class="design-card__label"><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Точная ширина контейнера</span>
                    <small>Перекрывает выбор «Ширина контейнера». Напр. <code>1440px</code> или <code>90%</code>. Пусто — значение пресета.</small>
                </div>
                <div class="design-opt__choices">
                    <input class="u-inline-e73ebf4146" type="text" name="container_custom" value="<?= htmlspecialchars((string) \App\Models\Setting::get('design_container_custom', ''), ENT_QUOTES) ?>" placeholder="напр. 1440px" data-design-preview-field>
                </div>
            </div>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Точное скругление углов</span>
                    <small>От 0 до 48 px. Применяется к карточкам, полям ввода и кнопкам. Пусто — значение пресета.</small>
                </div>
                <div class="design-opt__choices">
                    <?php $radiusCustom = preg_replace('/px$/', '', \App\Core\DesignSettings::radiusCustom()); ?>
                    <input class="u-inline-e73ebf4146" type="number" name="radius_custom" min="0" max="48" step="0.5" inputmode="decimal"
                           value="<?= htmlspecialchars((string) $radiusCustom, ENT_QUOTES) ?>" placeholder="напр. 12" data-design-preview-field>
                </div>
            </div>
        </section>
    </div>

    <!-- TAB 3: COLORS -->
    <div class="admin-tab-content" id="tab-colors" role="tabpanel"
         aria-labelledby="design-tab-colors" tabindex="0" hidden>
        <section class="design-section">
            <h2 class="design-section__title">Цветовая система и темы</h2>
            <?php
            $customAppearance = \App\Core\DesignSettings::customAppearance();
            $primary = $customAppearance['color_primary'];
            $accent = $customAppearance['color_accent'];
            $semanticColors = \App\Core\DesignSettings::semanticColors();
            $semanticSpacings = \App\Core\DesignSettings::semanticSpacings();
            $defaultTheme = (string) \App\Models\Setting::get('default_theme', 'light');
            if (!in_array($defaultTheme, ['light', 'dark', 'auto'], true)) { $defaultTheme = 'light'; }
            ?>
            <div class="design-manual">
                <div class="design-manual__head">
                    <strong>Основные и системные цвета</strong>
                    <span>Выбранные цвета управляют кнопками, ссылками, акцентами и карточками сайта.</span>
                </div>
                <div class="design-manual__grid">
                    <div class="form-field">
                        <label for="design_color_primary">Основной бренд-цвет</label>
                        <input type="color" id="design_color_primary" name="color_primary" value="<?= htmlspecialchars($primary, ENT_QUOTES) ?>" data-design-preview-field>
                        <small class="form-hint">Переменные: <code>--color-primary</code>, <code>--gov-navy</code><br>Управляет: шапка, базовые кнопки, подвал, подложка Hero и бренд-акценты.</small>
                    </div>
                    <div class="form-field">
                        <label for="design_color_accent">Акцентный цвет</label>
                        <input type="color" id="design_color_accent" name="color_accent" value="<?= htmlspecialchars($accent, ENT_QUOTES) ?>" data-design-preview-field>
                        <small class="form-hint">Переменные: <code>--color-accent</code>, <code>--gov-teal</code> (заливки/кнопки) и <code>--gov-teal-text</code> (текст WCAG)<br>Управляет: ссылки, стрелки «→», бейджи статусов, обводка при наведении и фокус. Бэкенд автоматически вычисляет контрастный текстовый оттенок (WCAG 4.5:1).</small>
                    </div>
                    <?php foreach ([
                        'bg_primary' => ['label' => 'Фон страницы', 'vars' => '--bg-primary, --gov-bg', 'desc' => 'Задний общий фон подложки сайта и подложки под секциями.'],
                        'bg_surface' => ['label' => 'Фон карточек и поверхностей', 'vars' => '--bg-surface, --gov-surface', 'desc' => 'Фон карточек контента, выпадающих меню, панелей и таблиц.'],
                        'text_main' => ['label' => 'Основной цвет текста', 'vars' => '--text-main, --gov-title, --gov-ink', 'desc' => 'Заголовки H1–H6, названия карточек и основной читаемый текст.'],
                        'text_muted' => ['label' => 'Приглушённый цвет текста', 'vars' => '--text-muted, --gov-muted', 'desc' => 'Даты публикаций, просмотры, категории, подписи и иконки.'],
                        'border_color' => ['label' => 'Цвет границ и разделителей', 'vars' => '--border-color, --gov-border', 'desc' => 'Обводка карточек, разделители, рамки полей ввода и сетки.'],
                    ] as $colorKey => $cInfo): ?>
                        <div class="form-field">
                            <label for="design_<?= htmlspecialchars($colorKey, ENT_QUOTES) ?>"><?= htmlspecialchars($cInfo['label'], ENT_QUOTES) ?></label>
                            <input type="color" id="design_<?= htmlspecialchars($colorKey, ENT_QUOTES) ?>" name="<?= htmlspecialchars($colorKey, ENT_QUOTES) ?>" value="<?= htmlspecialchars($semanticColors[$colorKey], ENT_QUOTES) ?>" data-design-preview-field>
                            <small class="form-hint">Переменные: <code><?= htmlspecialchars($cInfo['vars'], ENT_QUOTES) ?></code><br>Управляет: <?= htmlspecialchars($cInfo['desc'], ENT_QUOTES) ?></small>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-field">
                        <label for="design_shadow_color">Цвет тени карточек</label>
                        <input type="color" id="design_shadow_color" name="shadow_color" value="<?= htmlspecialchars(\App\Core\DesignSettings::shadowColor(), ENT_QUOTES) ?>" data-design-preview-field>
                        <small class="form-hint">Переменная: <code>--card-shadow</code><br>Форму тени выбирает «Стиль карточек», а здесь — её цвет. На тёплой или тёмной палитре холодная серо-синяя тень читается грязным пятном.</small>
                    </div>
                    <div class="form-field">
                        <label for="design_shadow_strength">Сила тени, %</label>
                        <input type="number" id="design_shadow_strength" name="shadow_strength" min="0" max="300" step="5" value="<?= (int) \App\Core\DesignSettings::shadowStrength() ?>" data-design-preview-field>
                        <small class="form-hint">100 — как в теме, 0 — тени нет вовсе (то же, что «Плоские»), 300 — предел: дальше тень перестаёт быть тенью.</small>
                    </div>
                    <?php foreach ([
                        'space_small' => 'Малый отступ (space-small)',
                        'space_premium' => 'Премиальный отступ (space-premium)',
                        'space_max' => 'Максимальный отступ (space-max)',
                    ] as $spaceKey => $spaceLabel): ?>
                        <div class="form-field">
                            <label for="design_<?= htmlspecialchars($spaceKey, ENT_QUOTES) ?>"><?= htmlspecialchars($spaceLabel, ENT_QUOTES) ?></label>
                            <input type="text" id="design_<?= htmlspecialchars($spaceKey, ENT_QUOTES) ?>" name="<?= htmlspecialchars($spaceKey, ENT_QUOTES) ?>" value="<?= htmlspecialchars($semanticSpacings[$spaceKey], ENT_QUOTES) ?>" data-design-preview-field placeholder="clamp(...) или px/rem">
                        </div>
                    <?php endforeach; ?>
                    <?= \App\Core\AdminUi::imageField('emblem', (string) \App\Models\Setting::get('design_emblem', ''), [
                        'label' => 'Фирменная эмблема (SVG)',
                        'file' => 'emblem_file',
                        'accept' => 'image/svg+xml,image/*',
                        'hint' => 'Знак агентства: водяной знак на карточках актов, заглушки карточек без фото, значок перед названием в шапке, если логотип не загружен. Используется как трафарет — цвет берётся из темы, поэтому нужен одноцветный контурный SVG. Пусто — встроенная эмблема. Файл берётся из медиабиблиотеки этого сайта: ссылки на чужие домены не принимаются.',
                    ]) ?>
                    <?php
                    // Файл может лежать на диске и всё равно не рисоваться:
                    // маске нужен viewBox. Говорим об этом прямо у поля, а не
                    // оставляем редактора гадать, почему знак не появился.
                    $emblemCheck = \App\Core\Emblem::check((string) \App\Models\Setting::get('design_emblem', ''));
                    ?>
                    <?php if (!$emblemCheck['ok']): ?>
                        <div class="alert alert--error"><?= htmlspecialchars($emblemCheck['error'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                    <div class="form-field">
                        <label for="design_default_theme">Тема по умолчанию для посетителей</label>
                        <select id="design_default_theme" name="default_theme" data-design-preview-field>
                            <option value="light" <?= $defaultTheme === 'light' ? 'selected' : '' ?>>Светлая</option>
                            <option value="dark" <?= $defaultTheme === 'dark' ? 'selected' : '' ?>>Тёмная</option>
                            <option value="auto" <?= $defaultTheme === 'auto' ? 'selected' : '' ?>>Авто (системная)</option>
                        </select>
                    </div>
                </div>

                <div class="design-code-preview u-inline-50f7d15eb8">
                    <div class="u-inline-b5b5b4c4bc"><?= \App\Core\AdminUi::icon('code', 16) ?> Полное дерево выводимых CSS-переменных (:root)</div>
                    <div class="u-inline-b9f96fe18b">Все указанные выше 7 основных цветов и производные алиасы автоматически транслируются в следующий блок переменных:</div>
                    <pre class="u-inline-aad4dde478" data-design-code-preview>:root {
    --color-primary: <?= htmlspecialchars($primary, ENT_QUOTES) ?>;
    --color-accent: <?= htmlspecialchars($accent, ENT_QUOTES) ?>; /* Яркий фоновый акцент (кнопки, заливки) */
    --gov-navy: var(--color-primary);
    --gov-teal: var(--color-accent);
    --gov-teal-text: <?= htmlspecialchars(\App\Core\AccentContrast::onLight($accent, $semanticColors['bg_surface']), ENT_QUOTES) ?>; /* Контрастный текстовый оттенок (WCAG AA 4.5:1) */
    --gov-teal-on-dark: <?= htmlspecialchars(\App\Core\AccentContrast::onDark($accent), ENT_QUOTES) ?>; /* Оттенок акцента для тёмного фона */
    --bg-primary: <?= htmlspecialchars($semanticColors['bg_primary'], ENT_QUOTES) ?>;
    --bg-surface: <?= htmlspecialchars($semanticColors['bg_surface'], ENT_QUOTES) ?>;
    --text-main: <?= htmlspecialchars($semanticColors['text_main'], ENT_QUOTES) ?>;
    --text-muted: <?= htmlspecialchars($semanticColors['text_muted'], ENT_QUOTES) ?>;
    --border-color: <?= htmlspecialchars($semanticColors['border_color'], ENT_QUOTES) ?>;
    --gov-bg: var(--bg-primary);
    --gov-surface: var(--bg-surface);
    --gov-ink: var(--text-main);
    --gov-muted: var(--text-muted);
    --gov-border: var(--border-color);
}</pre>
                </div>
            </div>
        </section>
    </div>

    <!-- TAB 4: TYPOGRAPHY -->
    <div class="admin-tab-content" id="tab-typography" role="tabpanel"
         aria-labelledby="design-tab-typography" tabindex="0" hidden>
        <section class="design-section">
            <h2 class="design-section__title">Типографика и Шрифты</h2>
            <?php
            $fontSizePreset = ['sm' => '15', 'md' => '16', 'lg' => '17', 'xl' => '18'][$values['font_size'] ?? 'md'] ?? '16';
            $lineHeightPreset = ['tight' => '1.45', 'normal' => '1.6', 'relaxed' => '1.8'][$values['line_height'] ?? 'normal'] ?? '1.6';
            $fontSizeCustom = preg_replace('/px$/', '', \App\Core\DesignSettings::fontSizeCustom());
            $lineHeightCustom = \App\Core\DesignSettings::lineHeightCustom();
            $bodyFontChoice = \App\Core\DesignSettings::bodyFontChoice();
            $gHeading = (string) \App\Models\Setting::get('design_font_google_heading', '');
            $gScript = (string) \App\Models\Setting::get('design_font_script', '');
            ?>
            <div class="design-manual u-inline-7dde5e56b3">
                <div class="design-manual__head">
                    <strong>Семейства шрифтов</strong>
                </div>
                <div class="design-manual__grid">
                    <div class="form-field design-manual__wide">
                        <label for="design_font_body_choice">Основной шрифт текста</label>
                        <select id="design_font_body_choice" name="font_body_choice" data-design-preview-field data-font-body-choice>
                            <optgroup label="Локальные — без внешних запросов">
                                <?php foreach (\App\Core\DesignSettings::FONTS as $fontKey => $fontData): ?>
                                    <?php if ($fontKey === 'custom') { continue; } ?>
                                    <?php $choice = 'style:' . $fontKey; ?>
                                    <option value="<?= htmlspecialchars($choice, ENT_QUOTES) ?>" data-font-family="<?= htmlspecialchars($fontData[1], ENT_QUOTES) ?>"
                                            <?= $bodyFontChoice === $choice ? 'selected' : '' ?>><?= htmlspecialchars($fontData[0], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Каталог Google Fonts — локальные файлы">
                                <?php foreach (\App\Core\DesignSettings::GOOGLE_FONTS as $slug => $fontData): ?>
                                    <?php $choice = 'google:' . $slug; ?>
                                    <option value="<?= htmlspecialchars($choice, ENT_QUOTES) ?>"
                                            data-font-family="<?= htmlspecialchars($fontData[1], ENT_QUOTES) ?>"
                                            <?= $bodyFontChoice === $choice ? 'selected' : '' ?>><?= htmlspecialchars($fontData[0], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Собственный шрифт">
                                <option value="style:custom" data-font-family="<?= htmlspecialchars($customAppearance['font_family'], ENT_QUOTES) ?>"
                                        <?= $bodyFontChoice === 'style:custom' ? 'selected' : '' ?>>Свой CSS-стек или файл (.woff2)</option>
                            </optgroup>
                        </select>
                        <small class="form-hint">
                            При сохранении сервер скачивает только выбранные шрифты; посетители получают их с
                            вашего домена без внешних запросов. Для Ўў, Ғғ, Ққ, Ҳҳ проверяются нужные кириллические подмножества.
                        </small>
                    </div>
                    <div class="design-manual__custom-font design-manual__wide" data-custom-font-fields<?= $bodyFontChoice !== 'style:custom' ? ' hidden' : '' ?>>
                        <div class="form-field design-manual__wide">
                            <label for="design_font_family">Свой шрифт (CSS font-family)</label>
                            <input type="text" id="design_font_family" name="font_family" maxlength="200"
                                   value="<?= htmlspecialchars($customAppearance['font_family'], ENT_QUOTES) ?>"
                                   placeholder="'MyBrandFont', system-ui, sans-serif" data-design-preview-field>
                        </div>
                        <div class="form-field">
                            <label for="design_font_face_name">Имя семейства</label>
                            <input type="text" id="design_font_face_name" name="font_face_name" maxlength="80"
                                   value="<?= htmlspecialchars((string) \App\Models\Setting::get('font_face_name', ''), ENT_QUOTES) ?>"
                                   placeholder="MyBrandFont" data-design-preview-field>
                        </div>
                        <div class="form-field">
                            <label for="design_font_url">Файл .woff2</label>
                            <input type="text" id="design_font_url" name="font_url" maxlength="500"
                                   value="<?= htmlspecialchars((string) \App\Models\Setting::get('font_url', ''), ENT_QUOTES) ?>"
                                   placeholder="/uploads/public/font.woff2" data-design-preview-field>
                        </div>
                    </div>
                    <div class="form-field design-manual__wide">
                        <label for="design_font_heading">Шрифт заголовков</label>
                        <select id="design_font_heading" name="font_google_heading" data-design-preview-field>
                            <option value="">Как у основного текста (без отдельного шрифта)</option>
                            <optgroup label="Каталог Google Fonts — локальные файлы">
                                <?php foreach (\App\Core\DesignSettings::GOOGLE_FONTS as $slug => $fontData): ?>
                                    <option value="<?= htmlspecialchars($slug, ENT_QUOTES) ?>"
                                            data-font-family="<?= htmlspecialchars($fontData[1], ENT_QUOTES) ?>"
                                            <?= $gHeading === $slug ? 'selected' : '' ?>><?= htmlspecialchars($fontData[0], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="form-field design-manual__wide">
                        <label for="design_font_script">Рукописный шрифт</label>
                        <select id="design_font_script" name="font_script" data-design-preview-field>
                            <option value="">Не использовать (как у заголовков)</option>
                            <optgroup label="Каталог Google Fonts — локальные файлы">
                                <?php foreach (\App\Core\DesignSettings::SCRIPT_FONTS as $slug => $fontData): ?>
                                    <option value="<?= htmlspecialchars($slug, ENT_QUOTES) ?>"
                                            data-font-family="<?= htmlspecialchars($fontData[1], ENT_QUOTES) ?>"
                                            <?= $gScript === $slug ? 'selected' : '' ?>><?= htmlspecialchars($fontData[0], ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                        <small class="form-hint">
                            Им набираются короткие куски: выделенное звёздочками слово в заголовке
                            (при выделении «Рукописный шрифт») и подпись под текстом. Для основного
                            текста и заголовков он не используется.
                        </small>
                    </div>
                </div>
            </div>

            <?php $titleMarkOption = $options['title_mark']; ?>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span><?= htmlspecialchars($titleMarkOption['label'], ENT_QUOTES) ?></span>
                    <small><?= htmlspecialchars($titleMarkOption['hint'], ENT_QUOTES) ?></small>
                </div>
                <div class="design-opt__choices">
                    <?php foreach ($titleMarkOption['choices'] as $val => $label): ?>
                        <label class="design-card">
                            <input type="radio" name="title_mark" value="<?= htmlspecialchars($val, ENT_QUOTES) ?>"
                                   <?= ($values['title_mark'] ?? 'accent') === $val ? 'checked' : '' ?> data-design-preview-field>
                            <span class="design-card__label"><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php $titleRevealOption = \App\Core\DesignSettings::OPTIONS['title_reveal']; ?>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span><?= htmlspecialchars($titleRevealOption['label'], ENT_QUOTES) ?></span>
                    <small><?= htmlspecialchars($titleRevealOption['hint'], ENT_QUOTES) ?></small>
                </div>
                <div class="design-opt__choices">
                    <?php foreach ($titleRevealOption['choices'] as $val => $label): ?>
                        <label class="design-card">
                            <input type="radio" name="title_reveal" value="<?= htmlspecialchars($val, ENT_QUOTES) ?>"
                                   <?= ($values['title_reveal'] ?? 'off') === $val ? 'checked' : '' ?> data-design-preview-field>
                            <span class="design-card__label"><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php $fontSizeOption = \App\Core\DesignSettings::OPTIONS['font_size']; ?>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span><?= htmlspecialchars($fontSizeOption['label'], ENT_QUOTES) ?></span>
                    <small><?= htmlspecialchars($fontSizeOption['hint'], ENT_QUOTES) ?></small>
                </div>
                <div class="design-opt__choices">
                    <?php foreach ($fontSizeOption['choices'] as $val => $label): ?>
                        <label class="design-card">
                            <input type="radio" name="font_size" value="<?= htmlspecialchars($val, ENT_QUOTES) ?>"
                                   <?= ($values['font_size'] ?? 'md') === $val ? 'checked' : '' ?> data-design-preview-field>
                            <span class="design-card__label"><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Основной текст (p, span)</span>
                    <small>Базовый размер текста в px. Пусто — (<?= htmlspecialchars($fontSizePreset, ENT_QUOTES) ?> px).</small>
                </div>
                <div class="design-opt__choices">
                    <input class="u-inline-e73ebf4146" type="number" name="font_size_custom" min="12" max="24" step="0.5" inputmode="decimal"
                           value="<?= htmlspecialchars((string) $fontSizeCustom, ENT_QUOTES) ?>" placeholder="напр. <?= htmlspecialchars($fontSizePreset, ENT_QUOTES) ?>" data-design-preview-field>
                </div>
            </div>
            <?php $lineHeightOption = \App\Core\DesignSettings::OPTIONS['line_height']; ?>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span><?= htmlspecialchars($lineHeightOption['label'], ENT_QUOTES) ?></span>
                    <small><?= htmlspecialchars($lineHeightOption['hint'], ENT_QUOTES) ?></small>
                </div>
                <div class="design-opt__choices">
                    <?php foreach ($lineHeightOption['choices'] as $val => $label): ?>
                        <label class="design-card">
                            <input type="radio" name="line_height" value="<?= htmlspecialchars($val, ENT_QUOTES) ?>"
                                   <?= ($values['line_height'] ?? 'normal') === $val ? 'checked' : '' ?> data-design-preview-field>
                            <span class="design-card__label"><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Межстрочный интервал текста</span>
                    <small>Множитель высоты строки текста (от 1.0 до 2.5). Пусто — (<?= htmlspecialchars($lineHeightPreset, ENT_QUOTES) ?>).</small>
                </div>
                <div class="design-opt__choices">
                    <input class="u-inline-e73ebf4146" type="number" name="line_height_custom" min="1" max="2.5" step="0.05" inputmode="decimal"
                           value="<?= htmlspecialchars((string) $lineHeightCustom, ENT_QUOTES) ?>" placeholder="напр. <?= htmlspecialchars($lineHeightPreset, ENT_QUOTES) ?>" data-design-preview-field>
                </div>
            </div>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Межстрочный интервал заголовков</span>
                    <small>Базовая высота строки для H1–H6 и названий карточек.</small>
                </div>
                <div class="design-opt__choices">
                    <select class="u-inline-e73ebf4146" name="heading_line_height" data-design-preview-field>
                        <option value="tight" <?= ($values['heading_line_height'] ?? 'normal') === 'tight' ? 'selected' : '' ?>>Плотный (1.15)</option>
                        <option value="normal" <?= ($values['heading_line_height'] ?? 'normal') === 'normal' ? 'selected' : '' ?>>Стандарт (1.25)</option>
                        <option value="relaxed" <?= ($values['heading_line_height'] ?? 'normal') === 'relaxed' ? 'selected' : '' ?>>Просторный (1.35)</option>
                    </select>
                </div>
            </div>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Точное значение интервала заголовков</span>
                    <small>От 1.0 до 2.5. Если заполнено, имеет приоритет над вариантом выше.</small>
                </div>
                <div class="design-opt__choices">
                    <?php $headingLineHeightCustom = \App\Core\DesignSettings::headingLineHeightCustom(); ?>
                    <input class="u-inline-e73ebf4146" type="number" name="heading_line_height_custom" min="1" max="2.5" step="0.05" inputmode="decimal"
                           value="<?= htmlspecialchars((string) $headingLineHeightCustom, ENT_QUOTES) ?>" placeholder="напр. 1.25" data-design-preview-field>
                </div>
            </div>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Жирность заголовков</span>
                    <small>Начертание шрифта для H1–H6, названий карточек и цифр счётчиков.</small>
                </div>
                <div class="design-opt__choices">
                    <?php $hWeight = (string) \App\Models\Setting::get('design_heading_font_weight', '700'); ?>
                    <select class="u-inline-e73ebf4146" name="heading_font_weight" data-design-preview-field>
                        <option value="400" <?= $hWeight === '400' ? 'selected' : '' ?>>400 — Обычный (Regular)</option>
                        <option value="600" <?= $hWeight === '600' ? 'selected' : '' ?>>600 — Полужирный (SemiBold)</option>
                        <option value="700" <?= $hWeight === '700' ? 'selected' : '' ?>>700 — Жирный (Bold)</option>
                        <option value="800" <?= $hWeight === '800' ? 'selected' : '' ?>>800 — Сверхжирный (ExtraBold)</option>
                    </select>
                </div>
            </div>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Межбуквенный интервал заголовков</span>
                    <small>Расстояние между буквами в крупных заголовках.</small>
                </div>
                <div class="design-opt__choices">
                    <?php $hLetter = (string) \App\Models\Setting::get('design_heading_letter_spacing', 'normal'); ?>
                    <select class="u-inline-e73ebf4146" name="heading_letter_spacing" data-design-preview-field>
                        <option value="tight" <?= $hLetter === 'tight' ? 'selected' : '' ?>>Плотный (-0.03em)</option>
                        <option value="normal" <?= $hLetter === 'normal' ? 'selected' : '' ?>>Обычный (-0.02em)</option>
                        <option value="wide" <?= $hLetter === 'wide' ? 'selected' : '' ?>>Широкий (0em)</option>
                    </select>
                </div>
            </div>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Межбуквенный интервал метаданных</span>
                    <small>Для номеров документов, дат и служебных подписей. От -0.10 до 0.30 em; пусто — значение темы.</small>
                </div>
                <div class="design-opt__choices">
                    <?php $metaLetter = preg_replace('/em$/', '', \App\Core\DesignSettings::metaLetterSpacingCustom()); ?>
                    <input class="u-inline-e73ebf4146" type="number" name="meta_letter_spacing_custom" min="-0.10" max="0.30" step="0.01" inputmode="decimal"
                           value="<?= htmlspecialchars((string) $metaLetter, ENT_QUOTES) ?>" placeholder="как в теме" data-design-preview-field>
                </div>
            </div>

            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Метка заголовка секции</span>
                    <small>Знак перед заголовком секции на внутренних страницах. У заголовка по центру и справа метка не рисуется — она указывает на начало строки.</small>
                </div>
                <div class="design-opt__choices">
                    <?php $secMarker = (string) ($values['section_marker'] ?? 'line'); ?>
                    <select class="u-inline-e73ebf4146" name="section_marker" data-design-preview-field>
                        <?php foreach (\App\Core\DesignSettings::OPTIONS['section_marker']['choices'] as $val => $label): ?>
                            <option value="<?= htmlspecialchars((string) $val, ENT_QUOTES) ?>" <?= $secMarker === (string) $val ? 'selected' : '' ?>><?= htmlspecialchars((string) $label, ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Толщина метки</span>
                    <small>Ширина линии, 1–12px. Пусто — 3px. У знака-эмблемы ширину задаёт следующее поле.</small>
                </div>
                <div class="design-opt__choices">
                    <?php $markerThickness = preg_replace('/px$/', '', \App\Core\DesignSettings::sectionMarkerThickness()); ?>
                    <input class="u-inline-e73ebf4146" type="number" name="section_marker_thickness" min="1" max="12" step="0.5" inputmode="decimal"
                           value="<?= htmlspecialchars((string) $markerThickness, ENT_QUOTES) ?>" placeholder="напр. 3" data-design-preview-field>
                </div>
            </div>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Высота метки</span>
                    <small>4–80px. Пусто — метка тянется по высоте строки заголовка: у крупного заголовка длиннее, у мелкого короче.</small>
                </div>
                <div class="design-opt__choices">
                    <?php $markerHeight = preg_replace('/px$/', '', \App\Core\DesignSettings::sectionMarkerHeight()); ?>
                    <input class="u-inline-e73ebf4146" type="number" name="section_marker_height" min="4" max="80" step="1" inputmode="numeric"
                           value="<?= htmlspecialchars((string) $markerHeight, ENT_QUOTES) ?>" placeholder="по высоте строки" data-design-preview-field>
                </div>
            </div>

            <?php
            $currentScale = \App\Core\DesignSettings::typoScale();
            $scaleSizes = \App\Core\DesignSettings::scaleSizes();
            $overrides = \App\Core\DesignSettings::typographyOverrides();
            $hasOverrides = array_filter($overrides) !== [];
            $previewSizes = $scaleSizes;
            foreach (\App\Core\DesignSettings::TYPO_SIZES as $fsKey => $fsMeta) {
                if (($previewSizes[$fsKey] ?? '') === '') {
                    $previewSizes[$fsKey] = $fsMeta[2] . 'px';
                }
            }
            ?>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Шкала заголовков</span>
                    <small>Коэффициент пропорционального роста H1–H6 от базового текста.</small>
                </div>
                <div class="design-opt__choices typo-scale">
                    <?php foreach (\App\Core\DesignSettings::TYPO_SCALES as $scaleKey => $scaleMeta): ?>
                        <label class="typo-scale__opt<?= $currentScale === $scaleKey ? ' is-active' : '' ?>">
                            <input type="radio" name="typo_scale" value="<?= htmlspecialchars($scaleKey, ENT_QUOTES) ?>"
                                   <?= $currentScale === $scaleKey ? 'checked' : '' ?> data-design-preview-field>
                            <span class="typo-scale__name"><?= htmlspecialchars($scaleMeta[0], ENT_QUOTES) ?></span>
                            <span class="typo-scale__ratio">×<?= htmlspecialchars((string) $scaleMeta[1], ENT_QUOTES) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="typo-preview u-inline-8a359a76eb">
                <div class="typo-preview__head">Как это выглядит</div>
                <div class="typo-preview__row">
                    <?php foreach (['fs_h1' => 'H1', 'fs_h2' => 'H2', 'fs_h3' => 'H3', 'fs_h4' => 'H4', 'fs_h5' => 'H5', 'fs_h6' => 'H6'] as $k => $label): ?>
                        <div class="typo-preview__item">
                            <span class="typo-preview__sample" data-font-size="<?= htmlspecialchars($previewSizes[$k] ?? '16px', ENT_QUOTES) ?>"><?= $label ?></span>
                            <span class="typo-preview__size"><?= htmlspecialchars($previewSizes[$k] ?? '', ENT_QUOTES) ?><?= ($overrides[$k] ?? '') !== '' ? ' → ' . htmlspecialchars($overrides[$k], ENT_QUOTES) : '' ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <details class="design-manual u-inline-8a359a76eb" open>
                <summary><strong>Точные размеры типографики (в пикселях)</strong> <span class="form-hint">— индивидуальная настройка каждого уровня в px (переопределяет общую шкалу)</span></summary>
                <div class="design-manual__grid u-inline-9374e84210">
                    <?php foreach (\App\Core\DesignSettings::TYPO_SIZES as $fsKey => $fsMeta): ?>
                        <?php
                        $fromScale = $scaleSizes[$fsKey] ?? '';
                        // Пустое поле заголовков новостей означает «как у
                        // остальных карточек» — так было до того, как у них
                        // появился свой размер.
                        $fsPlaceholder = $fsKey === 'fs_news_title'
                            ? 'как у карточек'
                            : ($fromScale !== '' ? 'шкала: ' . rtrim($fromScale, 'px') : 'напр. ' . $fsMeta[2]);
                        ?>
                        <div class="form-field">
                            <label for="design_<?= htmlspecialchars($fsKey, ENT_QUOTES) ?>"><?= htmlspecialchars($fsMeta[0], ENT_QUOTES) ?>, px</label>
                            <input type="number" id="design_<?= htmlspecialchars($fsKey, ENT_QUOTES) ?>" name="<?= htmlspecialchars($fsKey, ENT_QUOTES) ?>"
                                   min="8" max="96" step="0.5" inputmode="decimal"
                                   value="<?= htmlspecialchars(preg_replace('/px$/', '', $overrides[$fsKey]) ?? '', ENT_QUOTES) ?>"
                                   placeholder="<?= htmlspecialchars($fsPlaceholder, ENT_QUOTES) ?>" data-design-preview-field>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        </section>
    </div>

    <!-- TAB 5: COMPONENTS -->
    <div class="admin-tab-content" id="tab-components" role="tabpanel"
         aria-labelledby="design-tab-components" tabindex="0" hidden>
        <section class="design-section">
            <h2 class="design-section__title">Настройки компонентов и страниц</h2>
            <?php
            $cardHoverLift = \App\Core\DesignSettings::cardHoverLift();
            $newsPaddingTop = preg_replace('/px$/', '', \App\Core\DesignSettings::newsDetailPaddingTop());
            $newsPaddingBottom = preg_replace('/px$/', '', \App\Core\DesignSettings::newsDetailPaddingBottom());
            ?>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Подъём карточек при наведении</span>
                    <small>Вертикальный подъём feature-card, проектов, новостей и медиатеки. 0 px отключает движение; текущее стандартное значение — 4 px.</small>
                </div>
                <div class="design-opt__choices">
                    <input class="u-inline-e73ebf4146" type="number" name="card_hover_lift" min="0" max="20" step="1" inputmode="numeric"
                           value="<?= htmlspecialchars((string) $cardHoverLift, ENT_QUOTES) ?>" data-design-preview-field>
                </div>
            </div>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Страница новости — отступ сверху</span>
                    <small>Расстояние от хлебных крошек до обычной новости и от premium-hero до текста. От 0 до 200 px; пусто — адаптивное значение темы.</small>
                </div>
                <div class="design-opt__choices">
                    <input class="u-inline-e73ebf4146" type="number" name="newsdetail_padding_top" min="0" max="200" step="1" inputmode="numeric"
                           value="<?= htmlspecialchars((string) $newsPaddingTop, ENT_QUOTES) ?>" placeholder="как в теме" data-design-preview-field>
                </div>
            </div>
            <div class="design-opt">
                <div class="design-opt__label">
                    <span>Страница новости — отступ снизу</span>
                    <small>Расстояние после последнего блока новости до нижней части страницы. От 0 до 200 px; пусто — адаптивное значение темы.</small>
                </div>
                <div class="design-opt__choices">
                    <input class="u-inline-e73ebf4146" type="number" name="newsdetail_padding_bottom" min="0" max="200" step="1" inputmode="numeric"
                           value="<?= htmlspecialchars((string) $newsPaddingBottom, ENT_QUOTES) ?>" placeholder="как в теме" data-design-preview-field>
                </div>
            </div>
            <div class="design-destination-links">
                <a href="/admin/header" class="design-destination-link">
                    <?= \App\Core\AdminUi::icon('layout-navbar', 20) ?>
                    <span><strong>Шапка и поиск</strong><small>Макет, фиксация, мобильные зоны и вид поиска</small></span>
                    <?= \App\Core\AdminUi::icon('arrow-right', 16) ?>
                </a>
                <a href="/admin/footer" class="design-destination-link">
                    <?= \App\Core\AdminUi::icon('layout-bottombar', 20) ?>
                    <span><strong>Подвал сайта</strong><small>Стиль, колонки, виджеты и нижняя строка</small></span>
                    <?= \App\Core\AdminUi::icon('arrow-right', 16) ?>
                </a>
            </div>
            <?php foreach ($grouped as $groupName => $groupOpts): ?>
                <?php // Шапка, подвал и мобильные зоны живут в своих конструкторах
                      // (ссылки выше), поэтому здесь только оставшиеся группы. ?>
                <?php if (in_array($groupName, ['Общие', 'Типографика', 'Цвета и шрифт'], true)) { continue; } ?>
                <?php foreach ($groupOpts as $key => $opt): ?>
                    <div class="design-opt">
                        <div class="design-opt__label">
                            <span><?= htmlspecialchars($opt['label'], ENT_QUOTES) ?></span>
                            <?php if (!empty($opt['hint'])): ?><small><?= htmlspecialchars($opt['hint'], ENT_QUOTES) ?></small><?php endif; ?>
                        </div>
                        <div class="design-opt__choices">
                            <?php foreach ($opt['choices'] as $val => $label): ?>
                                <label class="design-card">
                                    <input type="radio" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>" value="<?= htmlspecialchars($val, ENT_QUOTES) ?>" <?= ($values[$key] ?? '') === $val ? 'checked' : '' ?> data-design-preview-field>
                                    <span class="design-card__label"><?= htmlspecialchars($label, ENT_QUOTES) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </section>
    </div>

    <div class="form-actions form-actions--sticky" data-design-save-actions hidden>
        <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить настройки дизайна</button>
    </div>
</form>

<?php require __DIR__ . '/../layout/footer.php'; ?>
