<?php
/**
 * Структура организации: оргсхема с коллегиальным органом над руководителем,
 * советниками сбоку, ветками заместителей и их подразделениями.
 * Чистый CSS без JS; на мобильных ветки складываются в столбец.
 *
 * Разметка списков (подразделения, органы при руководителе, примечания)
 * разбирает App\Core\OrgTree: отступ = уровень вложенности (до четырёх),
 * `|` — ссылка, `*` — акцентный пункт, `-` в начале строки — прежний способ
 * вложить пункт в предыдущий.
 *
 * @var array $data
 */

use App\Core\Icon;
use App\Core\UrlGuard;

$orgParseLines = static fn (string $raw): array => \App\Core\OrgTree::parse($raw);

/** Пункт списка: ссылка, если задан безопасный URL, иначе текст. */
$orgItemInner = static function (array $item): string {
    $label = htmlspecialchars((string) $item['label'], ENT_QUOTES);

    return ($item['url'] ?? '') !== ''
        ? '<a class="orgstruct__link" href="' . htmlspecialchars((string) $item['url'], ENT_QUOTES) . '">' . $label . '</a>'
        : $label;
};

/**
 * Список подразделений любой глубины. Уровень идёт классом, а не отступом
 * в разметке: вложенность должна читаться и в свёрнутом виде, и на печати.
 */
$orgRenderItems = static function (array $items, int $level, string $id = '', string $label = '') use (&$orgRenderItems, $orgItemInner): string {
    if ($items === []) {
        return '';
    }

    $listClass = $level === 0 ? 'orgstruct__units' : 'orgstruct__subunits';
    $itemClass = $level === 0 ? 'orgstruct__unit' : 'orgstruct__subunit';
    $html = '<ul class="' . $listClass . ($level > 1 ? ' orgstruct__subunits--deep' : '') . '"'
        . ($id !== '' ? ' id="' . htmlspecialchars($id, ENT_QUOTES) . '" data-org-units' : '')
        . ($label !== '' ? ' aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '"' : '')
        . ' role="list">';
    foreach ($items as $item) {
        $html .= '<li class="' . $itemClass . (!empty($item['accent']) ? ' ' . $itemClass . '--accent' : '') . '">'
            . $orgItemInner($item)
            . $orgRenderItems(is_array($item['children'] ?? null) ? $item['children'] : [], $level + 1)
            . '</li>';
    }

    return $html . '</ul>';
};

$title = trim((string) ($data['title'] ?? ''));
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$layout = (string) $data['layout'];
$columns = (int) $data['columns'];
$columns = max(2, min(4, $columns));
$council = $orgParseLines((string) ($data['council'] ?? ''));
$headTitle = trim((string) ($data['head_title'] ?? ''));
$headName = trim((string) ($data['head_name'] ?? ''));
$headUrl = trim((string) ($data['head_url'] ?? ''));
if ($headUrl !== '' && !UrlGuard::isSafeLink($headUrl)) {
    $headUrl = '';
}
$sideItems = $orgParseLines((string) ($data['side_items'] ?? ''));
$notes = $orgParseLines((string) ($data['notes'] ?? ''));
$footnote = trim((string) ($data['footnote'] ?? ''));
$collapsible = !empty($data['collapsible']);
$searchEnabled = !empty($data['search']);
$headTag = $headUrl !== '' ? 'a' : 'div';
// Схема без руководителя — обычное дело: совет и департаменты без общего
// начальника наверху. Пустая тёмная плашка в этом случае читалась как брак.
$hasHeadCard = $headTitle !== '' || $headName !== '';

// Ветки нормализуем заранее: пустые отбрасываем, чтобы ряды считались верно.
$branches = [];
foreach ((is_array($data['branches'] ?? null) ? $data['branches'] : []) as $branch) {
    $bTitle = trim((string) ($branch['title'] ?? ''));
    $bName = trim((string) ($branch['name'] ?? ''));
    $bUrl = trim((string) ($branch['url'] ?? ''));
    $units = $orgParseLines((string) ($branch['units'] ?? ''));
    if ($bTitle === '' && $bName === '' && $units === []) {
        continue;
    }
    if ($bUrl !== '' && !UrlGuard::isSafeLink($bUrl)) {
        $bUrl = '';
    }
    $branches[] = ['title' => $bTitle, 'name' => $bName, 'url' => $bUrl, 'units' => $units];
}

// Ряды по <= $columns веток: перекладина-соединитель считается внутри ряда,
// поэтому схема не ломается при пяти и более заместителях. Компактный макет
// идёт одним рядом — там колонок нет.
$rows = [];
if ($branches !== []) {
    if ($layout === 'tree') {
        // Ряды выравниваем по длине: пять веток при четырёх колонках дают
        // 3+2, а не 4+1 с одинокой карточкой во всю ширину.
        $rowCount = (int) ceil(count($branches) / $columns);
        $rows = array_chunk($branches, (int) ceil(count($branches) / $rowCount));
    } else {
        $rows = [$branches];
    }
}

// Число колонок ряда и размер шрифта уходят в scoped CSS блока.
$templateCss = '';
$fontSize = (int) ($data['font_size'] ?? 0);
if ($fontSize >= 10 && $fontSize <= 24) {
    $templateCss .= '#block-' . $blockId . ' .orgstruct{--org-font-size:' . $fontSize . 'px}';
}
foreach ($rows as $rowIndex => $row) {
    $templateCss .= '#block-' . $blockId . ' .orgstruct__row:nth-child(' . ($rowIndex + 1) . ')'
        . '{--orgstruct-cols:' . count($row) . '}';
}
$branchIndex = 0;
?>
<div class="block-orgstruct"<?= $searchEnabled ? ' data-org-structure' : '' ?>>
    <?php if ($title !== ''): ?>
        <div class="section-head"><h2 class="section-head__title"><?= \App\Core\TitleMarkup::html($title) ?></h2></div>
    <?php endif; ?>

    <?php // Поле приходит скрытым и открывается скриптом: без JS фильтровать
          // нечем, а пустая строка поиска на странице только сбивает. ?>
    <?php if ($searchEnabled): ?>
        <div class="orgstruct-search" data-org-search hidden>
            <label class="orgstruct-search__label" for="org-search-<?= (int) $blockId ?>"><?= htmlspecialchars(t('Поиск по схеме'), ENT_QUOTES) ?></label>
            <input type="search" id="org-search-<?= (int) $blockId ?>" class="orgstruct-search__input" placeholder="<?= htmlspecialchars(t('Название отдела или сектора'), ENT_QUOTES) ?>" autocomplete="off">
            <span class="orgstruct-search__status" data-org-search-status role="status" aria-live="polite"
                  data-found="<?= htmlspecialchars(t('Найдено: %d'), ENT_QUOTES) ?>"
                  data-empty="<?= htmlspecialchars(t('Ничего не найдено'), ENT_QUOTES) ?>"></span>
        </div>
    <?php endif; ?>

    <div class="orgstruct orgstruct--<?= $layout ?><?= $collapsible ? ' orgstruct--collapsible' : '' ?><?= $hasHeadCard ? '' : ' orgstruct--nohead' ?>"<?= $collapsible ? ' data-org-collapsible' : '' ?>>
        <?php if ($council !== []): ?>
            <ul class="orgstruct__council" role="list">
                <?php foreach ($council as $item): ?>
                    <li class="orgstruct__council-card"><?= $orgItemInner($item) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($hasHeadCard || $sideItems !== []): ?>
        <div class="orgstruct__top">
            <div class="orgstruct__head-row">
                <?php if ($hasHeadCard): ?>
                <<?= $headTag ?><?= $headUrl !== '' ? ' href="' . htmlspecialchars($headUrl, ENT_QUOTES) . '"' : '' ?> class="orgstruct__head">
                    <?php if ($headTitle !== ''): ?><span class="orgstruct__head-role"><?= htmlspecialchars($headTitle, ENT_QUOTES) ?></span><?php endif; ?>
                    <?php if ($headName !== ''): ?><span class="orgstruct__head-name"><?= htmlspecialchars($headName, ENT_QUOTES) ?></span><?php endif; ?>
                </<?= $headTag ?>>
                <?php endif; ?>
                <?php if ($sideItems !== []): ?>
                    <ul class="orgstruct__aside" role="list">
                        <?php foreach ($sideItems as $side): ?>
                            <li class="orgstruct__aside-item"><?= $orgItemInner($side) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($branches !== []): ?>
            <div class="orgstruct__branches">
                <?php foreach ($rows as $row): ?>
                    <ul class="orgstruct__row" role="list">
                        <?php foreach ($row as $branch): ?>
                            <?php
                            $branchIndex++;
                            $branchId = 'org-branch-' . (int) $blockId . '-' . $branchIndex;
                            $hasHead = $branch['title'] !== '' || $branch['name'] !== '';
                            ?>
                            <li class="orgstruct__branch<?= $hasHead ? '' : ' orgstruct__branch--headless' ?>" data-org-branch>
                                <?php if ($hasHead): ?>
                                    <div class="orgstruct__deputy">
                                        <?php if ($branch['url'] !== ''): ?><a class="orgstruct__deputy-link" href="<?= htmlspecialchars($branch['url'], ENT_QUOTES) ?>"><?php endif; ?>
                                            <?php if ($branch['title'] !== ''): ?><span class="orgstruct__deputy-role"><?= htmlspecialchars($branch['title'], ENT_QUOTES) ?></span><?php endif; ?>
                                            <?php if ($branch['name'] !== ''): ?><span class="orgstruct__deputy-name"><?= htmlspecialchars($branch['name'], ENT_QUOTES) ?></span><?php endif; ?>
                                        <?php if ($branch['url'] !== ''): ?></a><?php endif; ?>
                                        <?php if ($branch['units'] !== []): ?>
                                            <button type="button" class="orgstruct__toggle" data-org-toggle aria-expanded="true" aria-controls="<?= $branchId ?>">
                                                <span class="visually-hidden"><?= htmlspecialchars(t('Показать подразделения'), ENT_QUOTES) ?></span>
                                                <?= Icon::render('chevron-down', 18, '', 2) ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($branch['units'] !== []): ?>
                                    <?= $orgRenderItems($branch['units'], 0, $branchId, $hasHead ? trim($branch['title'] . ' ' . $branch['name']) : '') ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($notes !== []): ?>
        <ul class="orgstruct__notes" role="list">
            <?php foreach ($notes as $note): ?>
                <li class="orgstruct__note"><?= $orgItemInner($note) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($footnote !== ''): ?>
        <p class="orgstruct__footnote"><?= htmlspecialchars($footnote, ENT_QUOTES) ?></p>
    <?php endif; ?>
</div>
