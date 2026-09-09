<?php

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Models\Language;

$pageTitle = 'Конструктор меню';
$activeNav = 'menu';
$pageActions = '<a href="#menu-add" class="btn btn--primary">' . AdminUi::icon('plus') . 'Добавить пункт</a>';
require __DIR__ . '/../layout/header.php';

/** @var array<int,array<string,mixed>> $tree */
/** @var array<int,array<string,mixed>> $items */
/** @var array<int,array<string,mixed>> $pages */
/** @var array<int,array<string,mixed>> $projects */
/** @var array<int,array<string,mixed>> $languages */

$urlTypeLabels = [
    'page' => 'Страница',
    'news_index' => 'Новости',
    'custom' => 'Ссылка',
];
$parentCandidates = array_values(array_filter(
    $items,
    static fn (array $row): bool => $row['parent_id'] === null && empty($row['is_divider'])
));
$hasChildrenById = [];
foreach ($items as $menuItem) {
    if ($menuItem['parent_id'] !== null) {
        $hasChildrenById[(int) $menuItem['parent_id']] = true;
    }
}

/**
 * Поля создания и редактирования. Основные настройки остаются на виду,
 * оформление и мега-меню находятся в раскрываемом блоке.
 */
$projects = $projects ?? [];
$renderFields = static function (?array $item, bool $isCreate = false) use (
    $pages,
    $projects,
    $parentCandidates
): string {
    $item ??= [];
    $id = isset($item['id']) ? (int) $item['id'] : 0;
    $prefix = $isCreate ? 'menu_new' : 'menu_' . $id;
    $urlType = (string) ($item['url_type'] ?? 'page');
    $urlValue = (string) ($item['url_value'] ?? '');
    $langCode = (string) ($item['lang'] ?? Language::defaultCode());
    $parentId = isset($item['parent_id']) ? (int) $item['parent_id'] : 0;
    $isDivider = !empty($item['is_divider']);
    $iconValue = trim((string) ($item['icon_svg'] ?? ''));
    $selectedIconKey = \App\Core\Icon::cleanName($iconValue);
    $badgeColor = (string) ($item['badge_color'] ?? 'red');
    $badgePos = (string) ($item['badge_pos'] ?? 'right');
    $mega = (int) ($item['mega_columns'] ?? 0);
    ob_start();
    ?>
    <div class="menu-form-fields" data-menu-link-form>
        <input type="hidden" name="lang" value="<?= htmlspecialchars($langCode, ENT_QUOTES) ?>" data-menu-lang-select>

        <?php if ($isCreate): ?>
            <div class="form-field">
                <label for="<?= $prefix ?>_kind">Что добавить</label>
                <select id="<?= $prefix ?>_kind" data-menu-create-kind>
                    <option value="page">Страницу сайта</option>
                    <option value="news_index">Раздел новостей</option>
                    <option value="custom">Произвольную ссылку</option>
                    <option value="divider">Разделитель</option>
                </select>
            </div>
            <input type="hidden" name="url_type" value="page" data-menu-url-type>
            <input type="hidden" name="is_divider" value="0" data-menu-divider>
        <?php else: ?>
            <div class="form-field form-field--checkbox menu-primary-toggle">
                <input type="checkbox" id="<?= $prefix ?>_divider" name="is_divider" value="1"
                       data-menu-divider<?= $isDivider ? ' checked' : '' ?>>
                <label for="<?= $prefix ?>_divider">Разделитель без ссылки</label>
            </div>
        <?php endif; ?>

        <div class="form-field">
            <label for="<?= $prefix ?>_title">Название</label>
            <input type="text" id="<?= $prefix ?>_title" name="title"
                   value="<?= htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES) ?>"
                   placeholder="Например: О компании">
            <span class="form-hint" data-menu-divider-title-hint hidden>Можно оставить пустым для обычной линии.</span>
        </div>

        <?php if (!$isCreate): ?>
            <div class="form-field" data-menu-link-only>
                <label for="<?= $prefix ?>_type">Куда ведёт пункт</label>
                <select id="<?= $prefix ?>_type" name="url_type" data-menu-url-type>
                    <option value="page"<?= $urlType === 'page' ? ' selected' : '' ?>>Страница сайта</option>
                    <option value="news_index"<?= $urlType === 'news_index' ? ' selected' : '' ?>>Раздел новостей</option>
                    <option value="custom"<?= $urlType === 'custom' ? ' selected' : '' ?>>Произвольный URL</option>
                </select>
            </div>
        <?php endif; ?>

        <div class="form-field" data-menu-url-field="page">
            <label for="<?= $prefix ?>_page">Страница</label>
            <select id="<?= $prefix ?>_page" name="page_slug" data-menu-page-select>
                <option value="">— выберите страницу —</option>
                <?php
                // Проект — такая же запись, только с адресом /projects/…, поэтому
                // он выбирается здесь же, отдельной группой.
                $pageGroups = ['Страницы' => $pages, 'Проекты' => $projects];
                foreach ($pageGroups as $groupLabel => $groupItems):
                    if ($groupItems === []) { continue; }
                ?>
                <optgroup label="<?= htmlspecialchars($groupLabel, ENT_QUOTES) ?>">
                    <?php foreach ($groupItems as $page): ?>
                        <?php
                        $pageLang = (string) ($page['lang'] ?? Language::defaultCode());
                        $isProject = (string) ($page['entity_type'] ?? 'page') === 'project';
                        $optionValue = ($isProject ? 'projects/' : '') . (string) $page['slug'];
                        ?>
                        <option value="<?= htmlspecialchars($optionValue, ENT_QUOTES) ?>"
                                data-title="<?= htmlspecialchars((string) $page['title'], ENT_QUOTES) ?>"
                                data-lang="<?= htmlspecialchars($pageLang, ENT_QUOTES) ?>"
                                <?= $urlType === 'page'
                                    && $urlValue === $optionValue
                                    && $pageLang === $langCode
                                    ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $page['title'], ENT_QUOTES) ?>
                            — /<?= htmlspecialchars($optionValue, ENT_QUOTES) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
            <span class="form-hint">Только опубликованные страницы выбранного языка.</span>
        </div>

        <div class="form-field" data-menu-url-field="custom">
            <label for="<?= $prefix ?>_url">Адрес ссылки</label>
            <input type="text" id="<?= $prefix ?>_url" name="custom_url"
                   value="<?= $urlType === 'custom' ? htmlspecialchars($urlValue, ENT_QUOTES) : '' ?>"
                   placeholder="/contacts или https://example.com">
        </div>

        <div class="form-field" data-menu-parent-field>
            <label for="<?= $prefix ?>_parent">Расположение</label>
            <select id="<?= $prefix ?>_parent" name="parent_id" data-menu-parent-select>
                <option value="">Верхний уровень</option>
                <?php foreach ($parentCandidates as $candidate): ?>
                    <?php if ($id > 0 && (int) $candidate['id'] === $id) { continue; } ?>
                    <option value="<?= (int) $candidate['id'] ?>"
                            data-lang="<?= htmlspecialchars((string) $candidate['lang'], ENT_QUOTES) ?>"
                            <?= $parentId === (int) $candidate['id'] ? 'selected' : '' ?>>
                        Внутри «<?= htmlspecialchars((string) $candidate['title'], ENT_QUOTES) ?>»
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="form-hint">Также можно изменить вложенность перетаскиванием в дереве.</span>
        </div>

        <div class="form-field form-field--checkbox menu-primary-toggle">
            <input type="checkbox" id="<?= $prefix ?>_active" name="is_active" value="1"
                <?= !isset($item['is_active']) || !empty($item['is_active']) ? ' checked' : '' ?>>
            <label for="<?= $prefix ?>_active">Показывать на сайте</label>
        </div>

        <details class="menu-advanced" data-menu-link-only>
            <summary><?= AdminUi::icon('sliders') ?>Оформление и дополнительные настройки</summary>
            <div class="menu-advanced__body">
                <div class="form-field" data-menu-top-only>
                    <label for="<?= $prefix ?>_mega">Формат подменю</label>
                    <select id="<?= $prefix ?>_mega" name="mega_columns">
                        <option value="0" <?= $mega === 0 ? 'selected' : '' ?>>Обычное подменю</option>
                        <option value="2" <?= $mega === 2 ? 'selected' : '' ?>>Мега-меню — 2 колонки</option>
                        <option value="3" <?= $mega === 3 ? 'selected' : '' ?>>Мега-меню — 3 колонки</option>
                        <option value="4" <?= $mega === 4 ? 'selected' : '' ?>>Мега-меню — 4 колонки</option>
                    </select>
                </div>

                <?= AdminUi::iconField('icon_svg', $selectedIconKey, [
                    'id' => $prefix . '_icon',
                    'label' => 'Иконка',
                    'hint' => 'Полный локальный каталог Tabler Icons. В базе сохраняется только имя.',
                ]) ?>

                <div class="form-field form-field--checkbox">
                    <input type="checkbox" id="<?= $prefix ?>_hide_title" name="hide_title" value="1"
                        <?= !empty($item['hide_title']) ? ' checked' : '' ?>>
                    <label for="<?= $prefix ?>_hide_title">Показывать только иконку</label>
                </div>

                <div class="form-field">
                    <label for="<?= $prefix ?>_badge_text">Текст бейджа</label>
                    <input type="text" id="<?= $prefix ?>_badge_text" name="badge_text"
                           value="<?= htmlspecialchars((string) ($item['badge_text'] ?? ''), ENT_QUOTES) ?>"
                           placeholder="NEW, TOP, Актуально">
                </div>

                <div class="menu-form-row">
                    <div class="form-field">
                        <label for="<?= $prefix ?>_badge_color">Цвет бейджа</label>
                        <select id="<?= $prefix ?>_badge_color" name="badge_color">
                            <?php foreach (['red' => 'Красный', 'green' => 'Зелёный', 'blue' => 'Синий', 'orange' => 'Оранжевый', 'purple' => 'Фиолетовый'] as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $badgeColor === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="<?= $prefix ?>_badge_pos">Позиция</label>
                        <select id="<?= $prefix ?>_badge_pos" name="badge_pos">
                            <option value="right" <?= $badgePos === 'right' ? 'selected' : '' ?>>Справа</option>
                            <option value="center" <?= $badgePos === 'center' ? 'selected' : '' ?>>По центру</option>
                            <option value="left" <?= $badgePos === 'left' ? 'selected' : '' ?>>Слева</option>
                        </select>
                    </div>
                </div>
            </div>
        </details>
    </div>
    <?php

    return (string) ob_get_clean();
};

$renderNode = static function (array $item) use ($urlTypeLabels): string {
    $id = (int) $item['id'];
    $isDivider = !empty($item['is_divider']);
    $title = $isDivider ? ((string) $item['title'] === '—' ? 'Разделитель' : (string) $item['title']) : (string) $item['title'];
    $destination = $isDivider
        ? 'Без ссылки'
        : ($urlTypeLabels[(string) $item['url_type']] ?? 'Ссылка')
            . ((string) ($item['url_value'] ?? '') !== '' ? ' · ' . (string) $item['url_value'] : '');
    $rawIcon = trim((string) ($item['icon_svg'] ?? ''));
    ob_start();
    ?>
    <div class="menu-node__row">
        <button type="button" class="menu-node__handle" draggable="true"
                aria-label="Перетащить пункт «<?= htmlspecialchars($title, ENT_QUOTES) ?>»"
                title="Перетащите для изменения порядка">
            <?= AdminUi::icon('grip', 18) ?>
        </button>
        <button type="button" class="menu-node__select" data-menu-inspect="<?= $id ?>">
            <span class="menu-node__type-icon" aria-hidden="true">
                <?php if ($isDivider): ?>
                    <?= AdminUi::icon('divider', 18) ?>
                <?php elseif ($rawIcon !== ''): ?>
                    <?= AdminUi::icon($rawIcon, 18) ?>
                <?php else: ?>
                    <?= AdminUi::icon((string) $item['url_type'] === 'page' ? 'pages' : 'external', 18) ?>
                <?php endif; ?>
            </span>
            <span class="menu-node__content">
                <span class="menu-node__title"><?= htmlspecialchars($title, ENT_QUOTES) ?></span>
                <span class="menu-node__meta"><?= htmlspecialchars($destination, ENT_QUOTES) ?></span>
            </span>
            <span class="menu-node__badges">
                <?php if (empty($item['is_active'])): ?><span class="badge badge--draft">Скрыт</span><?php endif; ?>
                <?php if ((int) ($item['mega_columns'] ?? 0) > 0): ?>
                    <span class="badge">Мега <?= (int) $item['mega_columns'] ?></span>
                <?php endif; ?>
            </span>
            <?= AdminUi::icon('arrow-right', 16, 'menu-node__chevron') ?>
        </button>
        <div class="menu-node__mobile-actions">
            <form method="post" action="/admin/menu/<?= $id ?>/move">
                <?= Csrf::field() ?><input type="hidden" name="direction" value="up">
                <button type="submit" class="btn btn--small btn--icon menu-node__move"
                        aria-label="Переместить вверх"><?= AdminUi::icon('arrow-up') ?></button>
            </form>
            <form method="post" action="/admin/menu/<?= $id ?>/move">
                <?= Csrf::field() ?><input type="hidden" name="direction" value="down">
                <button type="submit" class="btn btn--small btn--icon menu-node__move"
                        aria-label="Переместить вниз"><?= AdminUi::icon('arrow-down') ?></button>
            </form>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
};

$groups = array_map(
    static fn (array $language): array => [
        'code' => (string) $language['code'],
        'name' => (string) $language['name'],
    ],
    $languages
);
$defaultLangCode = Language::defaultCode();
$syncTargetCode = '';
foreach ($groups as $group) {
    if ($group['code'] !== $defaultLangCode) {
        $syncTargetCode = $group['code'];
        break;
    }
}
?>

<div class="menu-builder" data-menu-builder>
    <header class="menu-builder__toolbar">
        <div class="menu-lang-tabs" role="tablist" aria-label="Язык меню">
            <?php foreach ($groups as $index => $group): ?>
                <?php $langCount = count(array_filter($items, static fn (array $item): bool => (string) $item['lang'] === $group['code'])); ?>
                <button type="button" role="tab" class="menu-lang-tab<?= $index === 0 ? ' is-active' : '' ?>"
                        data-menu-lang-tab="<?= htmlspecialchars($group['code'], ENT_QUOTES) ?>"
                        aria-selected="<?= $index === 0 ? 'true' : 'false' ?>">
                    <?= htmlspecialchars($group['name'], ENT_QUOTES) ?>
                    <span><?= $langCount ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <details class="menu-sync">
            <summary class="btn"><?= AdminUi::icon('languages') ?>Синхронизация</summary>
            <form method="post" action="/admin/menu/synchronize"
                  data-confirm="Меню языка назначения будет заменено. Продолжить?">
                <?= Csrf::field() ?>
                <p>Копирует структуру и автоматически связывает переведённые страницы.</p>
                <div class="menu-form-row">
                    <div class="form-field">
                        <label for="menu_sync_source">Из языка</label>
                        <select id="menu_sync_source" name="source_lang">
                            <?php foreach ($groups as $group): ?>
                                <option value="<?= htmlspecialchars($group['code'], ENT_QUOTES) ?>"
                                    <?= $group['code'] === $defaultLangCode ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($group['name'], ENT_QUOTES) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label for="menu_sync_target">В язык</label>
                        <select id="menu_sync_target" name="target_lang">
                            <?php foreach ($groups as $group): ?>
                                <option value="<?= htmlspecialchars($group['code'], ENT_QUOTES) ?>"
                                    <?= $group['code'] === $syncTargetCode ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($group['name'], ENT_QUOTES) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn--primary"<?= count($groups) < 2 ? ' disabled' : '' ?>>
                    Синхронизировать
                </button>
            </form>
        </details>
    </header>

    <div class="menu-workspace">
        <aside class="menu-library" id="menu-add" aria-labelledby="menu-add-title">
            <div class="menu-panel__head">
                <span class="menu-panel__eyebrow">Новый элемент</span>
                <h2 id="menu-add-title">Добавить в меню</h2>
                <p>Выберите страницу или создайте собственную ссылку.</p>
            </div>
            <form method="post" action="/admin/menu/create" class="menu-panel__body">
                <?= Csrf::field() ?>
                <?= $renderFields(null, true) ?>
                <button type="submit" class="btn btn--primary menu-submit-wide" data-menu-create-submit>
                    <?= AdminUi::icon('plus') ?><span data-menu-create-label>Добавить пункт</span>
                </button>
            </form>
        </aside>

        <section class="menu-canvas" aria-labelledby="menu-structure-title">
            <div class="menu-canvas__head">
                <div>
                    <span class="menu-panel__eyebrow">Структура</span>
                    <h2 id="menu-structure-title">Навигация сайта</h2>
                    <p>Перетащите пункт между строками или внутрь другого пункта.</p>
                </div>
                <span class="menu-save-status" data-menu-save-status aria-live="polite">Все изменения сохранены</span>
            </div>

            <?php foreach ($groups as $index => $group): ?>
                <?php $groupNodes = array_values(array_filter($tree, static fn (array $node): bool => (string) $node['lang'] === $group['code'])); ?>
                <div class="menu-lang-panel" data-menu-lang-panel="<?= htmlspecialchars($group['code'], ENT_QUOTES) ?>"
                    <?= $index === 0 ? '' : ' hidden' ?>>
                    <ul class="menu-tree" data-menu-sortable
                        data-menu-lang="<?= htmlspecialchars($group['code'], ENT_QUOTES) ?>"
                        data-csrf="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES) ?>">
                        <?php if ($groupNodes === []): ?>
                            <li class="menu-tree__empty">
                                <?= AdminUi::icon('menu', 28) ?>
                                <strong>Меню пока пустое</strong>
                                <span>Добавьте первый пункт с помощью панели слева.</span>
                            </li>
                        <?php endif; ?>
                        <?php foreach ($groupNodes as $node): ?>
                            <li class="menu-node<?= !empty($node['is_divider']) ? ' menu-node--divider' : '' ?>"
                                data-menu-id="<?= (int) $node['id'] ?>"
                                data-menu-active="<?= !empty($node['is_active']) ? '1' : '0' ?>"
                                data-menu-divider="<?= !empty($node['is_divider']) ? '1' : '0' ?>"
                                data-menu-lang="<?= htmlspecialchars((string) $node['lang'], ENT_QUOTES) ?>">
                                <?= $renderNode($node) ?>
                                <?php if (empty($node['is_divider'])): ?>
                                    <ul class="menu-node__children" data-menu-children
                                        aria-label="Вложенные пункты <?= htmlspecialchars((string) $node['title'], ENT_QUOTES) ?>">
                                        <?php foreach ($node['children'] ?? [] as $child): ?>
                                            <li class="menu-node menu-node--child<?= (string) $child['lang'] !== (string) $node['lang'] ? ' menu-node--language-error' : '' ?>"
                                                data-menu-id="<?= (int) $child['id'] ?>"
                                                data-menu-active="<?= !empty($child['is_active']) ? '1' : '0' ?>"
                                                data-menu-divider="<?= !empty($child['is_divider']) ? '1' : '0' ?>"
                                                data-menu-lang="<?= htmlspecialchars((string) $child['lang'], ENT_QUOTES) ?>">
                                                <?= $renderNode($child) ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($groupNodes !== []): ?>
                        <details class="menu-preview">
                            <summary><?= AdminUi::icon('eye') ?>Предпросмотр структуры</summary>
                            <div class="menu-preview__toolbar">
                                <button type="button" class="is-active" data-menu-preview-mode="desktop">Компьютер</button>
                                <button type="button" data-menu-preview-mode="mobile">Телефон</button>
                            </div>
                            <div class="menu-preview__viewport" data-menu-preview>
                                <?php foreach ($groupNodes as $node): ?>
                                    <?php if (!empty($node['is_divider']) || empty($node['is_active'])) { continue; } ?>
                                    <div class="menu-preview__item">
                                        <span><?= htmlspecialchars((string) $node['title'], ENT_QUOTES) ?></span>
                                        <?php $visibleChildren = array_filter($node['children'] ?? [], static fn (array $child): bool => empty($child['is_divider']) && !empty($child['is_active'])); ?>
                                        <?php if ($visibleChildren !== []): ?>
                                            <div class="menu-preview__submenu">
                                                <?php foreach ($visibleChildren as $child): ?>
                                                    <span><?= htmlspecialchars((string) $child['title'], ENT_QUOTES) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>

        <aside class="menu-inspector" aria-labelledby="menu-inspector-title">
            <div class="menu-inspector__empty" data-menu-inspector-empty>
                <?= AdminUi::icon('sliders', 28) ?>
                <h2 id="menu-inspector-title">Настройки пункта</h2>
                <p>Выберите элемент в дереве, чтобы изменить его название, ссылку или оформление.</p>
            </div>

            <?php foreach ($items as $item): ?>
                <?php
                $id = (int) $item['id'];
                $itemTitle = !empty($item['is_divider']) ? 'Разделитель' : (string) $item['title'];
                ?>
                <section class="menu-inspector__panel" data-menu-inspector="<?= $id ?>" hidden>
                    <div class="menu-panel__head">
                        <button type="button" class="menu-inspector__close" data-menu-inspector-close
                                aria-label="Закрыть настройки" title="Закрыть">
                            <?= AdminUi::icon('x', 18) ?>
                        </button>
                        <span class="menu-panel__eyebrow">Пункт #<?= $id ?></span>
                        <h2><?= htmlspecialchars($itemTitle, ENT_QUOTES) ?></h2>
                        <p><?= htmlspecialchars(strtoupper((string) $item['lang']), ENT_QUOTES) ?> · настройки элемента</p>
                    </div>
                    <form method="post" action="/admin/menu/<?= $id ?>/edit" class="menu-panel__body">
                        <?= Csrf::field() ?>
                        <?= $renderFields($item) ?>
                        <div class="menu-inspector__actions">
                            <button type="submit" class="btn btn--primary menu-submit-wide">
                                <?= AdminUi::icon('save') ?>Сохранить
                            </button>
                        </div>
                    </form>
                    <form method="post" action="/admin/menu/<?= $id ?>/delete"
                          class="menu-inspector__delete"
                          data-confirm="Удалить «<?= htmlspecialchars($itemTitle, ENT_QUOTES) ?>»<?= !empty($hasChildrenById[$id]) ? ' вместе с вложенными пунктами' : '' ?>?">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn--danger">
                            <?= AdminUi::icon('trash') ?>Удалить пункт
                        </button>
                    </form>
                </section>
            <?php endforeach; ?>
        </aside>
    </div>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
