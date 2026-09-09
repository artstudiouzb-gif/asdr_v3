<?php

use App\Core\Csrf;
use App\Models\Widget;

$isEdit = !empty($widget['id']);
$pageTitle = $isEdit ? 'Редактирование виджета' : 'Новый виджет';
$activeNav = 'widgets';
require __DIR__ . '/../layout/header.php';

/** @var array|null $widget */
/** @var array $data */
/** @var array $languages */
/** @var string|null $error */

$action = $isEdit ? '/admin/widgets/' . (int) $widget['id'] . '/edit' : '/admin/widgets/create';
$currentType = $widget['type'] ?? 'latest_news';
?>
<div class="form-card">
    <?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
    <form method="post" action="<?= $action ?>" class="form-grid">
        <?= Csrf::field() ?>

        <div class="form-field">
            <label for="type">Тип виджета</label>
            <?php if ($isEdit): ?>
                <input type="text" value="<?= htmlspecialchars(Widget::TYPE_LABELS[$currentType] ?? $currentType, ENT_QUOTES) ?>" disabled>
            <?php else: ?>
                <select id="type" name="type" data-widget-type-select>
                    <?php foreach (Widget::TYPE_LABELS as $val => $label): ?>
                        <option value="<?= $val ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>

        <div class="form-field">
            <label for="title">Заголовок виджета (основной)</label>
            <input type="text" id="title" name="title" value="<?= htmlspecialchars($widget['title'] ?? '', ENT_QUOTES) ?>" placeholder="Например: Последние новости">
            <span class="form-hint">Если указан системный заголовок, он переводится автоматически. Ниже можно задать точный текст для каждого языка.</span>
        </div>

        <?php
        $activeLangs = \App\Models\Language::active();
        $savedTitles = is_array($data['titles'] ?? null) ? $data['titles'] : [];
        ?>
        <?php if (count($activeLangs) > 1): ?>
            <fieldset class="u-inline-1acd52fd8a">
                <legend class="u-inline-d9d85faa60">Перевод заголовка на языки сайта</legend>
                <div class="u-inline-c37fe6a935">
                    <?php foreach ($activeLangs as $al): ?>
                        <?php $acode = (string) $al['code']; ?>
                        <div class="form-field u-inline-1da9facb4d">
                            <label class="u-inline-e8d4cc3d50" for="title_<?= $acode ?>"><?= htmlspecialchars($al['name'], ENT_QUOTES) ?> (<?= strtoupper($acode) ?>)</label>
                            <input class="u-inline-6735d86a52" type="text" id="title_<?= $acode ?>" name="titles[<?= $acode ?>]" value="<?= htmlspecialchars((string) ($savedTitles[$acode] ?? ''), ENT_QUOTES) ?>" placeholder="<?= htmlspecialchars((string) ($widget['title'] ?? ''), ENT_QUOTES) ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endif; ?>

        <div class="form-field">
            <label for="sidebar">Колонка</label>
            <select id="sidebar" name="sidebar">
                <option value="left" <?= ($widget['sidebar'] ?? 'left') === 'left' ? 'selected' : '' ?>>Левая</option>
                <option value="right" <?= ($widget['sidebar'] ?? '') === 'right' ? 'selected' : '' ?>>Правая</option>
            </select>
        </div>

        <div class="form-field">
            <label for="lang">Язык</label>
            <select id="lang" name="lang">
                <option value="">Все языки</option>
                <?php foreach ($languages as $l): ?>
                    <option value="<?= htmlspecialchars($l['code'], ENT_QUOTES) ?>" <?= ($widget['lang'] ?? '') === $l['code'] ? 'selected' : '' ?>><?= htmlspecialchars($l['name'], ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php $showType = static fn (string $t) => $isEdit ? ($currentType === $t) : true; ?>

        <!-- Настройки: количество (latest_news / projects_list / team_list) -->
        <?php foreach (['latest_news', 'projects_list', 'team_list'] as $countType): ?>
            <?php if ($showType($countType)): ?>
                <div class="form-field<?= (!$isEdit && $countType !== 'latest_news') ? ' is-hidden' : '' ?>" data-wtype="<?= $countType ?>">
                    <label>Сколько элементов показывать</label>
                    <input type="number" name="count" value="<?= (int) ($data['count'] ?? 5) ?>" min="1" max="20">
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <!-- Настройки: latest_news (Превью картинки) -->
        <?php if ($showType('latest_news')): ?>
            <div class="form-field form-field--checkbox<?= (!$isEdit || $currentType === 'latest_news') ? '' : ' is-hidden' ?>" data-wtype="latest_news">
                <input type="checkbox" id="show_thumb" name="show_thumb" value="1" <?= !empty($data['show_thumb']) ? 'checked' : '' ?>>
                <label for="show_thumb">Отображать миниатюры фотографий к новостям</label>
            </div>
        <?php endif; ?>

        <!-- Настройки: section_menu -->
        <?php if ($showType('section_menu')): ?>
            <div class="form-field<?= (!$isEdit) ? ' is-hidden' : '' ?>" data-wtype="section_menu">
                <span class="form-hint">Настроек нет: виджет сам находит раздел основного меню, внутри которого лежит открытая страница, и показывает его страницы. На странице вне разделов виджет не выводится. Заголовок можно не задавать — им служит название раздела.</span>
            </div>
        <?php endif; ?>

        <!-- Настройки: contacts -->
        <?php if ($showType('contacts')): ?>
            <div class="form-field form-field--checkbox<?= (!$isEdit) ? ' is-hidden' : '' ?>" data-wtype="contacts">
                <input type="checkbox" id="show_socials" name="show_socials" value="1" <?= !empty($data['show_socials']) ? 'checked' : '' ?>>
                <label for="show_socials">Показывать иконки соцсетей (из настроек шапки)</label>
            </div>
        <?php endif; ?>

        <!-- Настройки: custom_html -->
        <?php if ($showType('custom_html')): ?>
            <div class="form-field<?= (!$isEdit) ? ' is-hidden' : '' ?>" data-wtype="custom_html">
                <label>HTML-код виджета</label>
                <textarea class="u-inline-f86bea4b15" name="html"><?= htmlspecialchars($data['html'] ?? '', ENT_QUOTES) ?></textarea>
            </div>
        <?php endif; ?>

        <!-- Настройки: subscribe -->
        <?php if ($showType('subscribe')): ?>
            <div class="form-field<?= (!$isEdit) ? ' is-hidden' : '' ?>" data-wtype="subscribe">
                <label>Подзаголовок формы подписки (необязательно)</label>
                <input type="text" name="text" value="<?= htmlspecialchars((string) ($data['text'] ?? 'Eng muhim yangiliklar va tahliliy materiallarni pochtangizga oling.'), ENT_QUOTES) ?>">
            </div>
        <?php endif; ?>

        <!-- Настройки: photo_slider -->
        <?php if ($showType('photo_slider')): ?>
            <?php $sliderSlides = (array) ($data['slides'] ?? []); ?>
            <?php $sliderSource = (string) ($data['source'] ?? 'manual'); ?>
            <div class="form-field<?= (!$isEdit) ? ' is-hidden' : '' ?>" data-wtype="photo_slider">
                <label for="source">Откуда брать фотографии</label>
                <select id="source" name="source">
                    <?php foreach (\App\Core\WidgetRenderer::SLIDER_SOURCES as $val => $label): ?>
                        <option value="<?= htmlspecialchars((string) $val, ENT_QUOTES) ?>" <?= $sliderSource === (string) $val ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="form-hint">
                    «Случайная цель» — виджет берёт одну цель из раздела «Цели» и листает
                    её снимки; у каждого посетителя цель своя. Список ниже тогда не
                    используется.
                </span>
            </div>
            <div class="form-field<?= (!$isEdit) ? ' is-hidden' : '' ?>" data-wtype="photo_slider">
                <label>Фотографии карусели</label>
                <span class="form-hint">
                    Подписей и ссылок у этого виджета нет — только снимки. Текст в поле
                    «Описание для незрячих» на экране не показывается: его читает диктор,
                    и без него карусель не проходит проверку доступности.
                </span>
                <div data-repeater="slides">
                    <?php foreach ($sliderSlides as $i => $row): ?>
                        <div class="repeater-row">
                            <?= \App\Core\AdminUi::imageField('slides[' . $i . '][image]', (string) ($row['image'] ?? ''), ['label' => 'Фотография']) ?>
                            <div class="form-field">
                                <label>Описание для незрячих</label>
                                <input type="text" name="slides[<?= $i ?>][alt]" maxlength="200" value="<?= htmlspecialchars((string) ($row['alt'] ?? ''), ENT_QUOTES) ?>">
                            </div>
                            <button type="button" class="btn btn--small btn--danger" data-repeater-remove>Удалить</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <template data-repeater-template="slides">
                    <?= \App\Core\AdminUi::imageField('slides[__INDEX__][image]', '', ['label' => 'Фотография']) ?>
                    <div class="form-field">
                        <label>Описание для незрячих</label>
                        <input type="text" name="slides[__INDEX__][alt]" maxlength="200">
                    </div>
                    <button type="button" class="btn btn--small btn--danger" data-repeater-remove>Удалить</button>
                </template>
                <button type="button" class="btn btn--small" data-repeater-add="slides">+ Добавить фотографию</button>
            </div>
            <div class="form-field form-field--checkbox<?= (!$isEdit) ? ' is-hidden' : '' ?>" data-wtype="photo_slider">
                <input type="checkbox" id="shuffle" name="shuffle" value="1" <?= !empty($data['shuffle']) ? 'checked' : '' ?>>
                <label for="shuffle">Случайный порядок фотографий</label>
                <span class="form-hint">
                    Порядок выбирается заново у каждого посетителя, в браузере: страницы
                    кэшируются, и порядок, выбранный на сервере, был бы одинаковым для всех
                    до сброса кэша. При источнике «Случайная цель» не действует — порядок
                    кадров внутри цели авторский.
                </span>
            </div>
            <div class="form-field<?= (!$isEdit) ? ' is-hidden' : '' ?>" data-wtype="photo_slider">
                <label for="ratio">Соотношение сторон кадра</label>
                <select id="ratio" name="ratio">
                    <?php $currentRatio = \App\Core\SliderRatio::normalize($data['ratio'] ?? null); ?>
                    <?php foreach (\App\Core\SliderRatio::ALL as $val => $label): ?>
                        <option value="<?= htmlspecialchars((string) $val, ENT_QUOTES) ?>" <?= $currentRatio === (string) $val ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field<?= (!$isEdit) ? ' is-hidden' : '' ?>" data-wtype="photo_slider">
                <label for="autoplay">Автопрокрутка, секунд</label>
                <input type="number" id="autoplay" name="autoplay" min="0" max="30" value="<?= (int) ($data['autoplay'] ?? 0) ?>">
                <span class="form-hint">0 — переключать только вручную. Показ встаёт на паузу при наведении и при включённой настройке «остановка анимаций».</span>
            </div>
        <?php endif; ?>

        <?php $design = \App\Core\WidgetRenderer::normalizeDesign($data); ?>
        <fieldset class="u-inline-c00ceb2874">
            <legend class="u-inline-1204c3c3eb">Оформление</legend>
            <div class="form-field">
                <label for="design_style">Стиль</label>
                <select id="design_style" name="design_style">
                    <option value="default" <?= $design['style'] === 'default' ? 'selected' : '' ?>>Стандарт (без фона)</option>
                    <option value="card" <?= $design['style'] === 'card' ? 'selected' : '' ?>>Карточка (рамка и фон)</option>
                    <option value="tinted" <?= $design['style'] === 'tinted' ? 'selected' : '' ?>>Подложка (лёгкий фон)</option>
                    <option value="navy" <?= $design['style'] === 'navy' ? 'selected' : '' ?>>Тёмный (акцентный)</option>
                </select>
            </div>
            <div class="form-field">
                <label for="design_pad">Внутренние отступы</label>
                <select id="design_pad" name="design_pad">
                    <option value="compact" <?= $design['pad'] === 'compact' ? 'selected' : '' ?>>Компактные</option>
                    <option value="normal" <?= $design['pad'] === 'normal' ? 'selected' : '' ?>>Обычные</option>
                    <option value="spacious" <?= $design['pad'] === 'spacious' ? 'selected' : '' ?>>Просторные</option>
                </select>
            </div>
            <div class="form-field form-field--checkbox">
                <input type="checkbox" id="design_accent" name="design_accent" value="1" <?= $design['accent'] ? 'checked' : '' ?>>
                <label for="design_accent">Акцентная линия под заголовком</label>
            </div>
        </fieldset>

        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="is_active" name="is_active" value="1" <?= (!$isEdit || !empty($widget['is_active'])) ? 'checked' : '' ?>>
            <label for="is_active">Активен</label>
        </div>

        <div class="form-actions form-actions--sticky">
            <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить</button>
            <a href="/admin/widgets" class="btn">Отмена</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>