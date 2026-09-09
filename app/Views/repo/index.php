<?php

use App\Core\Csrf;
use App\Core\Format;

/** @var array $files */
/** @var list<array{id:int, parent_id:?int, label:string, files_count:int}> $categories */
/** @var string $query */
/** @var int $category выбранная категория (0 — все) */
/** @var string $ext */
/** @var string $sort */
/** @var array|null $repoUser */
/** @var int $totalCount */
/** @var array $popular */
/** @var array $latest */
$ext = $ext ?? '';
$sort = $sort ?? 'newest';
$totalCount = $totalCount ?? count($files);
$popular = $popular ?? [];
$latest = $latest ?? [];

$pageTitle = 'Репозиторий документов';
require __DIR__ . '/layout/top.php';

$docIcon = \App\Core\Icon::render('file-text', 26, 'ui-icon', 1.5);
$extBadge = static function (array $f): string {
    $ext = strtoupper((string) pathinfo((string) $f['original_name'], PATHINFO_EXTENSION));
    return $ext !== '' ? $ext : 'FILE';
};
?>
<div class="rd">
    <header class="rd-hero">
        <div class="rd-hero__info">
            <div class="rd-hero__badge"><?= \App\Core\Icon::render('lock', 16) ?> Защищённый цифровой архив</div>
            <h1 class="rd-hero__title">Репозиторий документов</h1>
            <p class="rd-hero__lead">Единая база официальных документов, стратегий, отчётов, исследований и аналитических материалов Агентства.</p>
            <form method="get" action="/repo" class="rd-search" role="search">
                <input type="search" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES) ?>" placeholder="Поиск по названию, теме или ключевому слову…" aria-label="Поиск по документам">
                <?php if ($category > 0): ?><input type="hidden" name="category" value="<?= (int) $category ?>"><?php endif; ?>
                <button type="submit" aria-label="Найти"><?= \App\Core\Icon::render('search', 18) ?></button>
            </form>
        </div>
        <div class="rd-hero__art" aria-hidden="true"><?= $docIcon ?></div>
    </header>

    <div class="rd-stats">
        <div class="rd-stat rd-stat--blue">
            <div class="rd-stat__icon" aria-hidden="true">
                <?= \App\Core\Icon::render('file', 22) ?>
            </div>
            <div class="rd-stat__body">
                <span class="rd-stat__num"><?= $totalCount ?></span>
                <span class="rd-stat__label">документов в базе</span>
            </div>
        </div>
        <div class="rd-stat rd-stat--teal">
            <div class="rd-stat__icon" aria-hidden="true">
                <?= \App\Core\Icon::render('folder', 22) ?>
            </div>
            <div class="rd-stat__body">
                <span class="rd-stat__num"><?= count($categories) ?></span>
                <span class="rd-stat__label">категорий</span>
            </div>
        </div>
        <div class="rd-stat rd-stat--emerald">
            <div class="rd-stat__icon" aria-hidden="true">
                <?= \App\Core\Icon::render('download', 22) ?>
            </div>
            <div class="rd-stat__body">
                <span class="rd-stat__num"><?= array_sum(array_map(static fn ($f) => (int) $f['download_count'], $popular)) ?></span>
                <span class="rd-stat__label">скачиваний популярных</span>
            </div>
        </div>
        <div class="rd-stat rd-stat--amber">
            <div class="rd-stat__icon" aria-hidden="true">
                <?= \App\Core\Icon::render('sparkles', 22) ?>
            </div>
            <div class="rd-stat__body">
                <span class="rd-stat__num"><?= count($latest) ?></span>
                <span class="rd-stat__label">новых публикаций</span>
            </div>
        </div>
    </div>

    <details class="rd-upload">
        <summary class="rd-btn rd-btn--primary">+ Предложить документ</summary>
        <form method="post" action="/repo/upload" enctype="multipart/form-data" class="rd-upload__form">
            <?= Csrf::field() ?>
            <label>Название<br><input type="text" name="title" required maxlength="255"></label>
            <label>Категория<br>
                <select name="category_id">
                    <option value="0">— Без категории —</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"><?= htmlspecialchars((string) $cat['label'], ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Описание (необязательно)<br><textarea name="description" rows="2"></textarea></label>
            <label>Файл (PDF, Office, изображения, ZIP — до 100 МБ)<br><input type="file" name="file" required></label>
            <p class="rd-upload__hint">Документ появится на портале после проверки и одобрения администратором.</p>
            <button type="submit" class="rd-btn rd-btn--primary">Отправить на модерацию</button>
        </form>
    </details>

    <?php if (!empty($categories)): ?>
        <section class="rd-cats">
            <div class="rd-section-head"><h2>Категории документов</h2></div>
            <div class="rd-cats__grid">
                <a class="rd-cat<?= $category === 0 ? ' is-active' : '' ?>" href="/repo<?= $query !== '' ? '?q=' . rawurlencode($query) : '' ?>">
                    <span class="rd-cat__icon"><?= $docIcon ?></span>
                    <span class="rd-cat__name">Все документы</span>
                    <span class="rd-cat__count"><?= $totalCount ?></span>
                </a>
                <?php foreach ($categories as $cat): ?>
                    <a class="rd-cat<?= (int) $cat['id'] === $category ? ' is-active' : '' ?>" href="/repo?category=<?= (int) $cat['id'] ?><?= $query !== '' ? '&q=' . rawurlencode($query) : '' ?>">
                        <span class="rd-cat__icon"><?= $docIcon ?></span>
                        <span class="rd-cat__name"><?= htmlspecialchars((string) $cat['label'], ENT_QUOTES) ?></span>
                        <span class="rd-cat__arrow">→</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="rd-list">
        <div class="rd-section-head">
            <h2><?= ($query !== '' || $category > 0 || $ext !== '') ? 'Найдено: ' . count($files) : 'Все документы' ?></h2>
            
            <div class="rd-view-options">
                <?php if ($query !== '' || $category > 0 || $ext !== ''): ?>
                    <a class="rd-reset" href="/repo"><?= \App\Core\Icon::render('refresh', 15) ?> Сбросить фильтры</a>
                <?php endif; ?>

                <!-- Переключатель режима вида (Сетка / Таблица) -->
                <div class="rd-view-toggle" role="group" aria-label="Вид отображения">
                    <button type="button" class="rd-view-btn is-active" data-view-mode="grid" title="Вид плиткой">
                        <?= \App\Core\Icon::render('layout-grid', 16) ?>
                    </button>
                    <button type="button" class="rd-view-btn" data-view-mode="table" title="Вид списком">
                        <?= \App\Core\Icon::render('list', 16) ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Быстрые фильтры по формату файлов и сортировка -->
        <div class="rd-filter-bar">
            <!-- Фильтры форматов -->
            <div class="rd-format-pills">
                <span class="rd-filter-label">Формат:</span>
                <?php
                $exts = [
                    '' => 'Все',
                    'pdf' => 'PDF',
                    'docx' => 'Word',
                    'xlsx' => 'Excel',
                    'zip' => 'Архивы',
                    'png' => 'Изображения'
                ];
                foreach ($exts as $eKey => $eLabel):
                    $urlParams = array_filter(['q' => $query, 'category' => $category > 0 ? $category : null, 'ext' => $eKey, 'sort' => $sort !== 'newest' ? $sort : null]);
                    $url = '/repo' . (!empty($urlParams) ? '?' . http_build_query($urlParams) : '');
                    $isActive = ($ext === $eKey);
                ?>
                    <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" class="rd-pill<?= $isActive ? ' is-active' : '' ?>"><?= $eLabel ?></a>
                <?php endforeach; ?>
            </div>

            <!-- Сортировка -->
            <?php
            // Сортировка — обычная GET-форма: адрес собирает браузер, а не JS.
            // Раньше в option лежал готовый URL, и скрипт переходил по нему —
            // переход по адресу из разметки это открытый редирект.
            $sortKeep = array_filter([
                'q' => $query,
                'category' => $category > 0 ? $category : null,
                'ext' => $ext !== '' ? $ext : null,
            ]);
            $sorts = [
                'newest' => 'Сначала новые',
                'popular' => 'Популярные (скачивания)',
                'name' => 'По названию (А-Я)',
                'size' => 'По размеру файла'
            ];
            ?>
            <form class="rd-sort-group" method="get" action="/repo">
                <span class="rd-filter-label">Сортировка:</span>
                <?php foreach ($sortKeep as $keepKey => $keepValue): ?>
                    <input type="hidden" name="<?= htmlspecialchars((string) $keepKey, ENT_QUOTES) ?>" value="<?= htmlspecialchars((string) $keepValue, ENT_QUOTES) ?>">
                <?php endforeach; ?>
                <select class="rd-sort-select" name="sort" data-autosubmit>
                    <?php foreach ($sorts as $sKey => $sLabel): ?>
                        <option value="<?= htmlspecialchars($sKey, ENT_QUOTES) ?>"<?= $sort === $sKey ? ' selected' : '' ?>><?= $sLabel ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="rd-pill">Применить</button></noscript>
            </form>
        </div>

        <?php if (empty($files)): ?>
            <div class="rd-empty-card">
                <div class="rd-empty-card__icon" aria-hidden="true">
                    <?= \App\Core\Icon::render('file-off', 44, 'ui-icon', 1.5) ?>
                </div>
                <h3 class="rd-empty-card__title">
                    <?= ($query !== '' || $category > 0 || $ext !== '') ? 'Документы не найдены' : 'Хранилище документов пока пусто' ?>
                </h3>
                <p class="rd-empty-card__desc">
                    <?= ($query !== '' || $category > 0 || $ext !== '') 
                        ? 'По вашим критериям фильтрации или поисковому запросу не найдено ни одного файла. Попробуйте сбросить параметры.' 
                        : 'В защищённом репозитории агентства пока нет опубликованных файлов. Вы можете предложить первый документ на модерацию.' ?>
                </p>
                <div class="rd-empty-card__actions">
                    <?php if ($query !== '' || $category > 0 || $ext !== ''): ?>
                        <a href="/repo" class="rd-btn rd-btn--primary">
                            <?= \App\Core\Icon::render('refresh', 16) ?> Сбросить фильтры
                        </a>
                    <?php else: ?>
                        <button type="button" class="rd-btn rd-btn--primary" data-open-details=".rd-upload">
                            + Предложить документ
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Панель пакетных действий -->
            <form id="rd-batch-form" action="/repo/download-zip" method="POST">
                <?= Csrf::field() ?>
                <div class="rd-batch-bar u-inline-f2892a2e8a" id="rd-batch-bar">
                    <span class="rd-batch-count">Выбрано файлов: <strong id="rd-selected-count">0</strong></span>
                    <button type="submit" class="rd-btn rd-btn--primary rd-btn--sm">
                        <?= \App\Core\Icon::render('archive', 16) ?> Скачать выбранные в ZIP
                    </button>
                </div>

                <!-- Табличный вид -->
                <div class="rd-table-wrapper" id="rd-table-view">
                    <table class="repo-table">
                        <thead>
                            <tr>
                                <th width="40"><input type="checkbox" id="rd-select-all" title="Выбрать все"></th>
                                <th>Формат</th>
                                <th>Название документа</th>
                                <th>Категория</th>
                                <th>Размер</th>
                                <th>Дата</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($files as $f): ?>
                                <?php 
                                $extName = htmlspecialchars($extBadge($f), ENT_QUOTES); 
                                $canPreview = in_array(mb_strtolower($extBadge($f)), ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'webp'], true);
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" class="rd-file-check" name="ids[]" value="<?= (int) $f['id'] ?>">
                                    </td>
                                    <td class="rd-table__ext-col">
                                        <span class="rd-doc__ext" data-ext="<?= $extName ?>"><?= $extName ?></span>
                                    </td>
                                    <td>
                                        <a class="repo-file-title" href="/repo/download/<?= (int) $f['id'] ?>" target="_blank"><?= htmlspecialchars((string) $f['title'], ENT_QUOTES) ?></a>
                                        <?php if (!empty($f['description'])): ?>
                                            <div class="repo-file-desc"><?= htmlspecialchars(mb_substr((string) $f['description'], 0, 100), ENT_QUOTES) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($f['category'])): ?>
                                            <span class="rd-doc__cat"><?= htmlspecialchars((string) $f['category'], ENT_QUOTES) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="repo-meta"><?= htmlspecialchars(Format::fileSize((int) $f['size']), ENT_QUOTES) ?></td>
                                    <td class="repo-meta"><?= htmlspecialchars(\App\Core\DateFormatter::format((string) $f['created_at'], 'd.m.Y'), ENT_QUOTES) ?></td>
                                    <td>
                                        <div class="rd-table-actions">
                                            <?php if ($canPreview): ?>
                                                <button type="button" class="rd-btn rd-btn--ghost rd-btn--sm rd-preview-btn" data-preview-id="<?= (int) $f['id'] ?>" data-preview-title="<?= htmlspecialchars((string) $f['title'], ENT_QUOTES) ?>" data-preview-ext="<?= mb_strtolower($extBadge($f)) ?>" title="Быстрый онлайн-просмотр">
                                                    <?= \App\Core\Icon::render('eye', 16) ?>
                                                </button>
                                            <?php endif; ?>
                                            <div class="rd-lang-pills">
                                                <a class="rd-lang-pill" href="/repo/download/<?= (int) $f['id'] ?>" title="Скачать (RU)">
                                                    <span>RU</span>
                                                    <?= \App\Core\Icon::render('download', 11, 'ui-icon', 2.5) ?>
                                                </a>
                                                <a class="rd-lang-pill" href="/repo/download/<?= (int) $f['id'] ?>" title="Скачать (UZ)">
                                                    <span>UZ</span>
                                                    <?= \App\Core\Icon::render('download', 11, 'ui-icon', 2.5) ?>
                                                </a>
                                                <a class="rd-lang-pill" href="/repo/download/<?= (int) $f['id'] ?>" title="Download (EN)">
                                                    <span>EN</span>
                                                    <?= \App\Core\Icon::render('download', 11, 'ui-icon', 2.5) ?>
                                                </a>
                                            </div>
                                            <button type="button" class="rd-btn rd-btn--ghost rd-btn--sm" data-copy-link="/repo/download/<?= (int) $f['id'] ?>" title="Копировать ссылку"><?= \App\Core\Icon::render('copy', 16) ?></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Вид плиткой (Grid) -->
                <div class="rd-grid" id="rd-grid-view">
                    <?php foreach ($files as $f): ?>
                        <?php 
                        $extName = htmlspecialchars($extBadge($f), ENT_QUOTES); 
                        $canPreview = in_array(mb_strtolower($extBadge($f)), ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'webp'], true);
                        ?>
                        <article class="rd-doc">
                            <div class="rd-doc__head">
                                <label class="rd-checkbox-label">
                                    <input type="checkbox" class="rd-file-check" name="ids[]" value="<?= (int) $f['id'] ?>">
                                    <span class="rd-doc__ext" data-ext="<?= $extName ?>"><?= $extName ?></span>
                                </label>
                                <?php if (!empty($f['category'])): ?><span class="rd-doc__cat"><?= htmlspecialchars((string) $f['category'], ENT_QUOTES) ?></span><?php endif; ?>
                            </div>
                            <time class="rd-doc__date"><?= htmlspecialchars(\App\Core\DateFormatter::format((string) $f['created_at'], 'd.m.Y'), ENT_QUOTES) ?></time>
                            <h3 class="rd-doc__title"><?= htmlspecialchars((string) $f['title'], ENT_QUOTES) ?></h3>
                            <div class="rd-doc__meta"><?= htmlspecialchars($extBadge($f) . ' · ' . Format::fileSize((int) $f['size']), ENT_QUOTES) ?><?= (int) $f['download_count'] > 0 ? ' · скачано ' . (int) $f['download_count'] : '' ?></div>
                            <?php if (!empty($f['description'])): ?>
                                <p class="rd-doc__desc"><?= htmlspecialchars(mb_substr((string) $f['description'], 0, 120), ENT_QUOTES) ?></p>
                            <?php endif; ?>
                            <div class="rd-doc__actions">
                                <?php if ($canPreview): ?>
                                    <button type="button" class="rd-btn rd-btn--ghost rd-btn--sm rd-preview-btn" data-preview-id="<?= (int) $f['id'] ?>" data-preview-title="<?= htmlspecialchars((string) $f['title'], ENT_QUOTES) ?>" data-preview-ext="<?= mb_strtolower($extBadge($f)) ?>" title="Быстрый просмотр">
                                        <?= \App\Core\Icon::render('eye', 16) ?>
                                    </button>
                                <?php endif; ?>
                                <div class="rd-lang-pills">
                                    <a class="rd-lang-pill" href="/repo/download/<?= (int) $f['id'] ?>" title="Скачать (RU)">
                                        <span>RU</span> <?= \App\Core\Icon::render('download', 11, 'ui-icon', 2.5) ?>
                                    </a>
                                    <a class="rd-lang-pill" href="/repo/download/<?= (int) $f['id'] ?>" title="Скачать (UZ)">
                                        <span>UZ</span> <?= \App\Core\Icon::render('download', 11, 'ui-icon', 2.5) ?>
                                    </a>
                                    <a class="rd-lang-pill" href="/repo/download/<?= (int) $f['id'] ?>" title="Download (EN)">
                                        <span>EN</span> <?= \App\Core\Icon::render('download', 11, 'ui-icon', 2.5) ?>
                                    </a>
                                </div>
                                <button type="button" class="rd-btn rd-btn--ghost rd-btn--icon" data-copy-link="/repo/download/<?= (int) $f['id'] ?>" title="Копировать ссылку"><?= \App\Core\Icon::render('copy', 16) ?></button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <?php if (!empty($popular) || !empty($latest)): ?>
        <div class="rd-columns">
            <?php if (!empty($popular)): ?>
                <section class="rd-col">
                    <h2 class="rd-col__title">Популярные документы</h2>
                    <ol class="rd-col__list rd-col__list--num">
                        <?php foreach ($popular as $i => $f): ?>
                            <li>
                                <span class="rd-col__num"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                                <span class="rd-col__body">
                                    <a href="/repo/download/<?= (int) $f['id'] ?>"><?= htmlspecialchars((string) $f['title'], ENT_QUOTES) ?></a>
                                    <span class="rd-col__meta">Скачано <?= (int) $f['download_count'] ?> раз</span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>
            <?php if (!empty($latest)): ?>
                <section class="rd-col">
                    <h2 class="rd-col__title">Последние публикации</h2>
                    <ol class="rd-col__list">
                        <?php foreach ($latest as $f): ?>
                            <li>
                                <span class="rd-col__date"><span><?= htmlspecialchars(\App\Core\DateFormatter::format((string) $f['created_at'], 'd'), ENT_QUOTES) ?></span><?= htmlspecialchars(mb_strtoupper(['ЯНВ','ФЕВ','МАР','АПР','МАЙ','ИЮН','ИЮЛ','АВГ','СЕН','ОКТ','НОЯ','ДЕК'][(int) \App\Core\DateFormatter::format((string) $f['created_at'], 'n') - 1]), ENT_QUOTES) ?></span>
                                <span class="rd-col__body">
                                    <a href="/repo/download/<?= (int) $f['id'] ?>"><?= htmlspecialchars((string) $f['title'], ENT_QUOTES) ?></a>
                                    <span class="rd-col__meta"><?= htmlspecialchars($extBadge($f) . ' · ' . Format::fileSize((int) $f['size']), ENT_QUOTES) ?></span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Модальное окно предпросмотра документов -->
<dialog class="rd-modal" id="rd-preview-modal">
    <div class="rd-modal__card">
        <div class="rd-modal__head">
            <h3 id="rd-preview-title">Предпросмотр документа</h3>
            <button type="button" class="rd-modal__close" data-close-dialog="rd-preview-modal" aria-label="Закрыть"><?= \App\Core\Icon::render('x', 18) ?></button>
        </div>
        <div class="rd-modal__body" id="rd-preview-body">
            <div class="rd-preview-loader">Загрузка документа...</div>
        </div>
    </div>
</dialog>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var selectAll = document.getElementById('rd-select-all');
    var fileChecks = document.querySelectorAll('.rd-file-check');
    var batchBar = document.getElementById('rd-batch-bar');
    var countEl = document.getElementById('rd-selected-count');

    function updateBatchCount() {
        var checked = document.querySelectorAll('.rd-file-check:checked');
        var count = checked.length;
        if (countEl) countEl.textContent = count;
        if (batchBar) batchBar.style.display = count > 0 ? 'flex' : 'none';
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            fileChecks.forEach(function (cb) { cb.checked = selectAll.checked; });
            updateBatchCount();
        });
    }

    fileChecks.forEach(function (cb) {
        cb.addEventListener('change', updateBatchCount);
    });

    var previewModal = document.getElementById('rd-preview-modal');
    var previewTitle = document.getElementById('rd-preview-title');
    var previewBody = document.getElementById('rd-preview-body');

    document.querySelectorAll('.rd-preview-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-preview-id');
            var title = btn.getAttribute('data-preview-title');
            var ext = btn.getAttribute('data-preview-ext');
            var url = '/repo/preview/' + id;

            if (previewTitle) previewTitle.textContent = title;
            if (previewBody) {
                if (ext === 'pdf') {
                    previewBody.innerHTML = '<iframe class="u-inline-e9ae41a1b8" src="' + url + '" width="100%" height="550"></iframe>';
                } else if (['png', 'jpg', 'jpeg', 'webp'].indexOf(ext) !== -1) {
                    previewBody.innerHTML = '<img class="u-inline-9635e07f50" src="' + url + '" alt="' + title + '">';
                } else {
                    previewBody.innerHTML = '<iframe class="u-inline-93420d7878" src="' + url + '" width="100%" height="400"></iframe>';
                }
            }
            if (previewModal && typeof previewModal.showModal === 'function') {
                previewModal.showModal();
            }
        });
    });
});
</script>

<?php require __DIR__ . '/layout/bottom.php'; ?>
