<?php

/**
 * Конструктор блоков: список блоков записи, вложенные колонки и вкладки,
 * готовые сборки и добавление блока.
 *
 * Подключается формой страницы и формой проекта — проект это страница с
 * подтипом, и содержимое у него собирается тем же конструктором. Ожидает
 * $page (строка pages), $blocks, $blockLang, $defaultCode и $usingFallback.
 */

use App\Core\Csrf;
use App\Core\BlockTypeRegistry;

/** @var array $page */
/** @var array $blocks */
/** @var string $blockLang */
/** @var string $defaultCode */

$blockEditorTitle = $blockEditorTitle ?? 'Блоки страницы';
$defaultCode = $defaultCode ?? \App\Models\Language::defaultCode();
$blockLang = $blockLang ?? (string) ($page['lang'] ?? $defaultCode);

// Форма страницы передаёт блоки готовыми (там же выбирается язык стека).
// Форма проекта их не готовит — грузим сами, с тем же откатом на основной язык.
if (!isset($blocks)) {
    $blocks = \App\Models\Block::forPage((int) $page['id'], $blockLang);
    $usingFallback = false;
    if (empty($blocks) && $blockLang !== $defaultCode) {
        $blocks = \App\Models\Block::forPage((int) $page['id'], $defaultCode);
        $usingFallback = !empty($blocks);
    }
}
$blockTypeLabels = BlockTypeRegistry::editorLabels();

// Дочерние блоки контейнеров (колонки, вкладки): подгружаем детей каждого.
$columnsChildren = [];
foreach ($blocks as $b) {
    if (BlockTypeRegistry::isContainer((string) $b['type'])) {
        $columnsChildren[(int) $b['id']] = \App\Models\Block::childrenOf((int) $b['id']);
    }
}
?>
    <h2 class="u-inline-9f7cb1fbb6"><?= htmlspecialchars($blockEditorTitle, ENT_QUOTES) ?></h2>
    <?php
    // Переключатель языка стека — только у страниц. У проекта языковая версия
    // это отдельная запись, и переключают её кнопкой в сайдбаре: два разных
    // переключателя на одной форме путали бы.
    $isPageRecord = (string) ($page['entity_type'] ?? 'page') === 'page';
    $blockLanguages = $isPageRecord ? \App\Models\Language::active() : [];
    ?>
    <?php if (count($blockLanguages) > 1): ?>
        <div class="block-lang-switch">
            <span class="form-hint">Язык блоков:</span>
            <?php foreach ($blockLanguages as $blockLanguage): ?>
                <?php $code = (string) $blockLanguage['code']; ?>
                <a class="btn btn--small<?= $code === $blockLang ? ' btn--primary' : ' btn--secondary' ?>"
                   href="/admin/pages/<?= (int) $page['id'] ?>/edit?block_lang=<?= urlencode($code) ?>"
                   <?= $code === $blockLang ? 'aria-current="true"' : '' ?>>
                    <?= strtoupper(htmlspecialchars($code, ENT_QUOTES)) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <p class="form-hint">У каждого языка свой независимый стек блоков. Если стек языка пуст, на сайте показывается стек основного языка.</p>

    <?php if (!empty($usingFallback)): ?>
        <div class="alert alert--info u-inline-f83af69e78">
            <span>ℹ️ На языке <strong><?= strtoupper(htmlspecialchars($blockLang, ENT_QUOTES)) ?></strong> ещё нет собственных блоков. Ниже пока отображаются блоки основного языка (<strong><?= strtoupper(htmlspecialchars($defaultCode, ENT_QUOTES)) ?></strong>).</span>
            <form class="u-inline-0cd28ce9ba" method="post" action="/admin/pages/<?= (int) $page['id'] ?>/copy-language-blocks">
                <?= Csrf::field() ?>
                <input type="hidden" name="from_lang" value="<?= htmlspecialchars($defaultCode, ENT_QUOTES) ?>">
                <input type="hidden" name="to_lang" value="<?= htmlspecialchars($blockLang, ENT_QUOTES) ?>">
                <button type="submit" class="btn btn--small btn--primary"><?= \App\Core\AdminUi::icon('copy', 15) ?> Скопировать блоки из <?= strtoupper(htmlspecialchars($defaultCode, ENT_QUOTES)) ?> для перевода на <?= strtoupper(htmlspecialchars($blockLang, ENT_QUOTES)) ?></button>
            </form>
        </div>
    <?php endif; ?>

    <?php if (empty($blocks)): ?>
        <p class="form-hint">На этом языке блоков пока нет.</p>
    <?php endif; ?>

    <?php if (!empty($blocks)): ?>
        <p class="form-hint">Перетаскивайте блоки за значок ⠿ для изменения порядка (сохраняется автоматически).</p>
    <?php endif; ?>
    <div class="block-list" data-block-sortable
         data-page-id="<?= (int) $page['id'] ?>"
         data-block-lang="<?= htmlspecialchars($blockLang, ENT_QUOTES) ?>"
         data-csrf="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
    <?php foreach ($blocks as $index => $block): ?>
        <?php $blockActive = (int) ($block['is_active'] ?? 1) === 1; ?>
        <div class="block-list-item<?= $blockActive ? '' : ' block-list-item--off' ?>" draggable="true" data-block-id="<?= (int) $block['id'] ?>">
            <span class="block-list-item__handle" title="Зажмите и перетащите для изменения порядка" aria-hidden="true">⠿</span>
            <div class="block-list-item__icon" title="Тип: <?= htmlspecialchars($blockTypeLabels[$block['type']] ?? $block['type'], ENT_QUOTES) ?>">
                <?= \App\Core\AdminUi::blockIcon($block['type']) ?>
            </div>
            <div class="block-list-item__meta">
                <div class="block-list-item__head">
                    <strong><?= htmlspecialchars($block['title'] ?: ('Блок #' . $block['id']), ENT_QUOTES) ?></strong>
                    <span class="block-list-item__num">#<?= (int) $block['id'] ?></span>
                </div>
                <div class="block-list-item__sub">
                    <span class="block-list-item__type"><?= htmlspecialchars($blockTypeLabels[$block['type']] ?? $block['type'], ENT_QUOTES) ?></span>
                    <?php if (!$blockActive): ?><span class="block-list-item__badge block-list-item__badge--off">Скрыт</span><?php endif; ?>
                    <?php
                    // Условия показа: редактор должен видеть, почему блока нет на
                    // сайте, не заходя внутрь блока.
                    $blockData = json_decode((string) ($block['data'] ?? '{}'), true) ?: [];
                    $visLabel = \App\Core\BlockVisibility::label($blockData);
                    ?>
                    <?php if ($visLabel !== ''): ?><span class="block-list-item__badge block-list-item__badge--vis" title="Условия показа"><?= htmlspecialchars($visLabel, ENT_QUOTES) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="block-list-item__actions">
                <form method="post" action="/admin/blocks/<?= (int) $block['id'] ?>/move">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="direction" value="up">
                    <button type="submit" class="btn btn--small btn--icon-only" title="Поднять выше" <?= $index === 0 ? 'disabled' : '' ?>><?= \App\Core\AdminUi::icon('arrow-up') ?></button>
                </form>
                <form method="post" action="/admin/blocks/<?= (int) $block['id'] ?>/move">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="direction" value="down">
                    <button type="submit" class="btn btn--small btn--icon-only" title="Опустить ниже" <?= $index === count($blocks) - 1 ? 'disabled' : '' ?>><?= \App\Core\AdminUi::icon('arrow-down') ?></button>
                </form>
                <form method="post" action="/admin/blocks/<?= (int) $block['id'] ?>/toggle" title="<?= $blockActive ? 'Отключить (скрыть на сайте)' : 'Включить (показать на сайте)' ?>">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn--small<?= $blockActive ? '' : ' btn--warning' ?>"><?= $blockActive ? \App\Core\AdminUi::icon('eye-off') . 'Скрыть' : \App\Core\AdminUi::icon('eye') . 'Показать' ?></button>
                </form>
                <a class="btn btn--small btn--secondary" href="/admin/blocks/<?= (int) $block['id'] ?>/edit"><?= \App\Core\AdminUi::icon('edit') ?>Редактировать</a>
                <form method="post" action="/admin/blocks/<?= (int) $block['id'] ?>/delete" data-confirm="Удалить блок «<?= htmlspecialchars($block['title'] ?: ('Блок #' . $block['id']), ENT_QUOTES) ?>»?">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn--small btn--danger"><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
                </form>
            </div>
        </div>
        <?php if (BlockTypeRegistry::isContainer((string) $block['type'])):
            $cdata = json_decode((string) $block['data'], true) ?: [];
            // Ячейка наполнения — колонка у «Колонок» и вкладка у «Вкладок»:
            // хранятся они одинаково (column_index), различаются подписи.
            $cellTitles = [];
            if ($block['type'] === 'tabs') {
                foreach ((array) ($cdata['items'] ?? []) as $tab) {
                    $tabTitle = trim((string) ($tab['title'] ?? ''));
                    if ($tabTitle !== '') { $cellTitles[] = $tabTitle; }
                }
                $cellTitles = array_slice($cellTitles, 0, 10);
            } else {
                $colCount = (int) ($cdata['columns'] ?? 2);
                if ($colCount < 2 || $colCount > 4) { $colCount = 2; }
                for ($ci = 0; $ci < $colCount; $ci++) { $cellTitles[] = 'Колонка ' . ($ci + 1); }
            }
            $colCount = count($cellTitles);
            $kids = $columnsChildren[(int) $block['id']] ?? [];
        ?>
        <div class="columns-editor u-inline-8bccce3cd1">
            <?php if ($colCount === 0): ?>
            <p class="form-hint">Вкладок пока нет: добавьте их в настройках блока — после этого здесь появится место для наполнения каждой вкладки.</p>
            <?php endif; ?>
            <div class="columns-editor__grid columns-editor__grid--<?= max(2, min(4, $colCount)) ?>">
                <?php for ($ci = 0; $ci < $colCount; $ci++): ?>
                    <div class="columns-editor__col">
                        <div class="columns-editor__col-title"><?= htmlspecialchars($cellTitles[$ci], ENT_QUOTES) ?></div>
                        <?php foreach ($kids as $kid): if ((int) $kid['column_index'] !== $ci) { continue; } ?>
                            <div class="columns-editor__child">
                                <span><?= htmlspecialchars($kid['title'] ?: ($blockTypeLabels[$kid['type']] ?? $kid['type']), ENT_QUOTES) ?></span>
                                <span class="columns-editor__child-actions">
                                    <a class="btn btn--small" href="/admin/blocks/<?= (int) $kid['id'] ?>/edit" title="Редактировать" aria-label="Редактировать"><?= \App\Core\AdminUi::icon('edit') ?></a>
                                    <form method="post" action="/admin/blocks/<?= (int) $kid['id'] ?>/delete" data-confirm="Удалить вложенный блок?">
                                        <?= Csrf::field() ?><button class="btn btn--small btn--danger" title="Удалить" aria-label="Удалить"><?= \App\Core\AdminUi::icon('trash') ?></button>
                                    </form>
                                </span>
                            </div>
                        <?php endforeach; ?>
                        <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/blocks/add" class="columns-editor__add">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="block_lang" value="<?= htmlspecialchars($blockLang, ENT_QUOTES) ?>">
                            <input type="hidden" name="parent_block_id" value="<?= (int) $block['id'] ?>">
                            <input type="hidden" name="column_index" value="<?= $ci ?>">
                            <select name="type" aria-label="Тип вложенного блока">
                                <?php foreach ($blockTypeLabels as $t => $lbl):
                                    if (BlockTypeRegistry::isContainer($t)) { continue; } // контейнер в контейнер не кладём
                                    if ($t === 'html' && !\App\Core\Auth::isSuperAdmin()) { continue; }
                                ?>
                                    <option value="<?= htmlspecialchars($t, ENT_QUOTES) ?>"><?= htmlspecialchars($lbl, ENT_QUOTES) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn--small"><?= \App\Core\AdminUi::icon('plus') ?>блок</button>
                        </form>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endforeach; ?>
    </div>
</form>

    <?php
    // Готовые сборки: страница собирается одним нажатием, с уже расставленными
    // фонами и отступами. Когда блоков ещё нет — это основной сценарий старта,
    // поэтому карточки раскрыты; на заполненной странице секция свёрнута.
    $presets = \App\Core\PagePresets::all($blockLang);
    ?>
    <details class="form-card preset-picker u-inline-8a359a76eb"<?= empty($blocks) ? ' open' : '' ?>>
        <summary><strong>Собрать страницу из готовой сборки</strong>
            <span class="form-hint">— <?= count($presets) ?> вариантов с готовой вёрсткой</span>
        </summary>
        <p class="form-hint u-inline-ceb7346533">
            Блоки добавятся с расставленными отступами, фонами и текстами-заготовками —
            останется заменить содержимое своим. Оформление берётся из настроек дизайна сайта.
            Режим «Заменить блоки этого языка» перед удалением сам сохранит текущие блоки в шаблон
            «Автокопия: …» — вернуть страницу можно через список шаблонов ниже.
        </p>
        <div class="preset-grid">
            <?php foreach ($presets as $presetId => $preset): ?>
                <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/presets/apply" class="preset-card"
                      data-confirm="Применить сборку «<?= htmlspecialchars($preset['name'], ENT_QUOTES) ?>» к этой странице?">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="block_lang" value="<?= htmlspecialchars($blockLang, ENT_QUOTES) ?>">
                    <input type="hidden" name="preset" value="<?= htmlspecialchars((string) $presetId, ENT_QUOTES) ?>">
                    <h4 class="preset-card__title"><?= htmlspecialchars($preset['name'], ENT_QUOTES) ?></h4>
                    <p class="preset-card__desc"><?= htmlspecialchars($preset['description'], ENT_QUOTES) ?></p>
                    <ol class="preset-card__outline">
                        <?php foreach ($preset['outline'] as $section): ?>
                            <li><?= htmlspecialchars($section, ENT_QUOTES) ?></li>
                        <?php endforeach; ?>
                    </ol>
                    <div class="preset-card__foot">
                        <select name="mode" aria-label="Как применить сборку">
                            <option value="append">Добавить к текущим</option>
                            <option value="replace">Заменить блоки <?= strtoupper(htmlspecialchars($blockLang, ENT_QUOTES)) ?></option>
                        </select>
                        <button type="submit" class="btn btn--small btn--primary">Применить</button>
                    </div>
                </form>
            <?php endforeach; ?>
        </div>
    </details>

    <?php
    // Библиотека шаблонов: если миграция block_snippets ещё не накатана,
    // не роняем весь редактор страницы 500-й — скрываем секцию и подсказываем
    // (тот же паттерн, что у ContentType::all() в layout/header.php).
    try {
        $snippets = \App\Models\BlockSnippet::all();
    } catch (\Throwable $snippetError) {
        $snippets = null;
    }
    ?>
    <?php if ($snippets === null): ?>
    <div class="form-card u-inline-8a359a76eb">
        <h3 class="u-inline-291b7bbb01">Шаблоны страницы</h3>
        <p class="form-hint">Раздел недоступен: не применена миграция базы данных. Выполните <code>php database/migrate.php</code> на сервере.</p>
    </div>
    <?php else: ?>
    <div class="form-card u-inline-8a359a76eb">
        <h3 class="u-inline-291b7bbb01">Шаблоны страницы</h3>
        <p class="form-hint">Шаблон сохраняет все блоки этого языка, включая содержимое колонок. Его можно применить к любой странице: добавить к текущим блокам или полностью заменить их. Перед заменой прежние блоки автоматически сохраняются как «Автокопия: …» — хранятся последние 5.</p>
        <div class="snippet-tools">
            <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/snippets/save" class="snippet-tools__row">
                <?= Csrf::field() ?>
                <input type="hidden" name="block_lang" value="<?= htmlspecialchars($blockLang, ENT_QUOTES) ?>">
                <input type="text" name="snippet_name" placeholder="Название шаблона" required>
                <button type="submit" class="btn btn--small"><?= \App\Core\AdminUi::icon('save') ?>Сохранить страницу как шаблон</button>
            </form>
            <?php if (!empty($snippets)): ?>
                <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/snippets/insert" class="snippet-tools__row" data-snippet-insert>
                    <?= Csrf::field() ?>
                    <input type="hidden" name="block_lang" value="<?= htmlspecialchars($blockLang, ENT_QUOTES) ?>">
                    <select name="snippet_id" required>
                        <option value="">— выберите шаблон —</option>
                        <?php foreach ($snippets as $s): ?>
                            <?php // Показываем состав: по одному названию не понять, что применится. ?>
                            <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars((string) $s['name'], ENT_QUOTES) ?><?= ($s['summary'] ?? '') !== '' ? ' — ' . htmlspecialchars((string) $s['summary'], ENT_QUOTES) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="mode">
                        <option value="append">Добавить к текущим</option>
                        <option value="replace">Заменить текущие блоки</option>
                    </select>
                    <button type="submit" class="btn btn--small"><?= \App\Core\AdminUi::icon('layout') ?>Применить шаблон</button>
                </form>
            <?php else: ?>
                <p class="form-hint">Пока нет сохранённых шаблонов.</p>
            <?php endif; ?>

            <?php
            // Обмен файлами: шаблон переносится на другой сайт или приходит
            // готовым. Без этого сборка жила только в базе своего сайта.
            ?>
            <?php if (!empty($snippets)): ?>
                <form method="get" action="/admin/snippets/export" class="snippet-tools__row">
                    <select name="id" required aria-label="Шаблон для скачивания">
                        <option value="">— выберите шаблон —</option>
                        <?php foreach ($snippets as $s): ?>
                            <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars((string) $s['name'], ENT_QUOTES) ?><?= ($s['summary'] ?? '') !== '' ? ' — ' . htmlspecialchars((string) $s['summary'], ENT_QUOTES) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn--small"><?= \App\Core\AdminUi::icon('download') ?>Скачать файлом</button>
                </form>
            <?php endif; ?>
            <form method="post" action="/admin/snippets/import" enctype="multipart/form-data" class="snippet-tools__row">
                <?= Csrf::field() ?>
                <input type="file" name="template" accept="application/json,.json" required aria-label="Файл шаблона">
                <input type="text" name="snippet_name" placeholder="Название (пусто — из файла)">
                <button type="submit" class="btn btn--small"><?= \App\Core\AdminUi::icon('upload') ?>Загрузить из файла</button>
            </form>
            <p class="form-hint">
                Файл — JSON с пометкой <code>artstudio.page-template</code>. При загрузке
                проверяются типы блоков и их поля: что не подошло, попадёт в сообщение,
                а не пропадёт молча. Загруженный шаблон появляется в списке выше — оттуда
                его и применяют к странице.
                <?php if (!\App\Core\Auth::isSuperAdmin()): ?>
                    Блок «HTML-код» и «Свой CSS» из файла не принимаются — их правит только супер-администратор.
                <?php endif; ?>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <div class="form-card u-inline-9eb125f52f">
        <form method="post" action="/admin/pages/<?= (int) $page['id'] ?>/blocks/add" class="form-grid">
            <?= Csrf::field() ?>
            <input type="hidden" name="block_lang" value="<?= htmlspecialchars($blockLang, ENT_QUOTES) ?>">
            <div class="form-field">
                <label for="type">Добавить блок (язык: <?= htmlspecialchars($blockLang, ENT_QUOTES) ?>)</label>
                <select id="type" name="type">
                    <?php foreach ($blockTypeLabels as $type => $label): ?>
                        <?php // Блок сырого HTML доступен только супер-администратору. ?>
                        <?php if ($type === 'html' && !\App\Core\Auth::isSuperAdmin()) { continue; } ?>
                        <option value="<?= $type ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label for="block_title">Внутреннее название блока (необязательно)</label>
                <input type="text" id="block_title" name="title" placeholder="например: Слайдер на главной">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('plus') ?>Добавить блок</button>
            </div>
        </form>
    </div>
