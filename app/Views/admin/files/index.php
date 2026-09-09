<?php

use App\Core\Csrf;
use App\Core\Format;
use App\Core\AdminUi;
use App\Models\FileEntry;

$pageTitle = 'Медиабиблиотека';
$activeNav = 'files';
require __DIR__ . '/../layout/header.php';

/** @var array $items */
/** @var array $availableDates */
/** @var bool $canManageProtected */
$selectedType = (string) ($_GET['type'] ?? '');
$selectedDate = (string) ($_GET['date'] ?? '');
$selectedSort = (string) ($_GET['sort'] ?? 'date_desc');
$searchQuery = (string) ($_GET['q'] ?? '');
?>
<div class="media-lib">
    <!-- Драг-н-Дроп зона для загрузки файлов -->
    <div class="media-upload-drawer" id="media_upload_drawer">
        <form method="post" action="/admin/files/upload" enctype="multipart/form-data" id="upload_form">
            <?= Csrf::field() ?>
            <div class="media-dropzone__title">Перетащите файлы сюда</div>
            <div class="media-dropzone__sub">или нажмите кнопку ниже для выбора на диске</div>
            <div class="u-inline-bea13c7a75">
                <input class="u-inline-c8be1ccba6" type="file" id="media_file_input" name="file" required>
                <button type="button" class="btn btn--primary" data-file-pick="media_file_input">Выберите файлы</button>
                <?php if ($canManageProtected): ?>
                    <select class="u-inline-732d6ab846" name="access_type">
                        <option value="public">Открытый доступ</option>
                        <option value="protected">Защищённый доступ</option>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="access_type" value="public">
                <?php endif; ?>
            </div>
            <div class="u-inline-783c7afd53" id="upload_filename_preview"></div>
            <div class="u-inline-ba853ea68c" id="upload_action_row">
                <button type="submit" class="btn btn--success">Загрузить файл на сервер</button>
            </div>
        </form>
    </div>

    <!-- Верхний тулбар управления медиабиблиотекой -->
    <form method="get" action="/admin/files" class="media-toolbar" id="media_filter_form">
        <div class="media-toolbar__left">
            <button type="button" class="btn btn--primary" id="btn_toggle_upload">
                <?= AdminUi::icon('plus', 16, 'btn__icon', 2.5) ?>
                Добавить медиафайл
            </button>

            <!-- Фильтр по типам медиафайлов -->
            <select name="type" data-autosubmit>
                <option value="">Все медиафайлы</option>
                <option value="image" <?= $selectedType === 'image' ? 'selected' : '' ?>>Изображения</option>
                <option value="video" <?= $selectedType === 'video' ? 'selected' : '' ?>>Видео</option>
                <option value="document" <?= $selectedType === 'document' ? 'selected' : '' ?>>Документы</option>
            </select>

            <!-- Фильтр по дате создания -->
            <select name="date" data-autosubmit>
                <option value="">Все даты</option>
                <?php foreach (($availableDates ?? []) as $dVal): ?>
                    <?php 
                    $monthStart = $dVal . '-01';
                    $monthsRu = ['01'=>'Январь','02'=>'Февраль','03'=>'Март','04'=>'Апрель','05'=>'Май','06'=>'Июнь','07'=>'Июль','08'=>'Август','09'=>'Сентябрь','10'=>'Октябрь','11'=>'Ноябрь','12'=>'Декабрь'];
                    $mNum = \App\Core\DateFormatter::format($monthStart, 'm');
                    $dLabel = trim(($monthsRu[$mNum] ?? \App\Core\DateFormatter::format($monthStart, 'F'))
                        . ' ' . \App\Core\DateFormatter::format($monthStart, 'Y'));
                    ?>
                    <option value="<?= htmlspecialchars($dVal, ENT_QUOTES) ?>" <?= $selectedDate === $dVal ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dLabel, ENT_QUOTES) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <!-- Режим множественного выбора -->
            <button type="button" class="btn" id="btn_bulk_toggle">
                Множественный выбор
            </button>
        </div>

        <div class="media-toolbar__right">
            <!-- Поиск файлов -->
            <input type="search" name="q" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES) ?>" placeholder="Поиск медиафайлов…" id="media_search_input">

            <!-- Сортировка -->
            <select name="sort" data-autosubmit>
                <option value="date_desc" <?= $selectedSort === 'date_desc' ? 'selected' : '' ?>>Сначала новые</option>
                <option value="date_asc" <?= $selectedSort === 'date_asc' ? 'selected' : '' ?>>Сначала старые</option>
                <option value="name_asc" <?= $selectedSort === 'name_asc' ? 'selected' : '' ?>>По имени (А-Я)</option>
                <option value="size_desc" <?= $selectedSort === 'size_desc' ? 'selected' : '' ?>>Крупные</option>
            </select>

            <!-- Переключатель режима: Список / Сетка -->
            <button type="button" class="btn btn--small is-active u-inline-d1df8577ab" id="view_mode_grid" title="Сетка">
                <?= AdminUi::icon('grid', 18) ?>
            </button>
            <button type="button" class="btn btn--small u-inline-d1df8577ab" id="view_mode_list" title="Список">
                <?= AdminUi::icon('list', 18) ?>
            </button>
        </div>
    </form>

    <!-- Панель массовых действий -->
    <div class="media-bulk-bar" id="media_bulk_bar">
        <div class="media-bulk-bar__actions">
            <button type="button" class="btn btn--small" id="btn_bulk_select_all">Выбрать все</button>
            <span>Выбрано элементов: <strong id="bulk_count">0</strong></span>
        </div>
        <form method="post" action="/admin/files/bulk-delete" id="bulk_delete_form">
            <?= Csrf::field() ?>
            <input type="hidden" name="ids" id="bulk_ids_input" value="[]">
            <button type="submit" class="btn btn--small btn--danger" data-confirm="Удалить выбранные файлы?">Удалить выбранные</button>
        </form>
    </div>

    <!-- Основной контейнер материалов -->
    <div id="files_container" class="files-view-grid">
        <!-- Квадратная Сетка Медиафайлов (Square Grid) -->
        <div class="media-grid">
            <?php if (empty($items)): ?>
                <div class="u-inline-4c7fa37fe5">Медиафайлы не найдены.</div>
            <?php endif; ?>
            <?php foreach ($items as $item): ?>
                <?php 
                $isImg = str_starts_with((string) $item['mime_type'], 'image/');
                $isVideo = str_starts_with((string) $item['mime_type'], 'video/');
                $url = FileEntry::publicUrl($item);
                if ($item['access_type'] !== 'public') {
                    $url = '/download.php?file_id=' . (int) $item['id'] . '&token=' . htmlspecialchars((string) $item['access_token'], ENT_QUOTES);
                }
                $ext = pathinfo((string) $item['original_name'], PATHINFO_EXTENSION);
                ?>
                <div class="media-card" 
                     data-id="<?= (int) $item['id'] ?>"
                     data-name="<?= htmlspecialchars((string) $item['original_name'], ENT_QUOTES) ?>"
                     data-url="<?= htmlspecialchars($url, ENT_QUOTES) ?>"
                     data-size="<?= htmlspecialchars(Format::fileSize((int) $item['size']), ENT_QUOTES) ?>"
                     data-mime="<?= htmlspecialchars((string) $item['mime_type'], ENT_QUOTES) ?>"
                     data-date="<?= htmlspecialchars(\App\Core\DateFormatter::format((string) $item['created_at'], 'd.m.Y H:i'), ENT_QUOTES) ?>"
                     data-access="<?= htmlspecialchars((string) $item['access_type'], ENT_QUOTES) ?>"
                     data-token="<?= htmlspecialchars((string) $item['access_token'], ENT_QUOTES) ?>"
                     data-is-img="<?= $isImg ? '1' : '0' ?>"
                     data-is-video="<?= $isVideo ? '1' : '0' ?>">

                    <div class="media-card__check">
                        <?= AdminUi::icon('check', 14, 'btn__icon', 3) ?>
                    </div>

                    <?php if ($isImg): ?>
                        <?php // Карточка ~135px, а тянулся оригинал целиком: при 2560px
                              // это сотни килобайт на каждую из трёхсот карточек страницы. ?>
                        <img src="<?= htmlspecialchars(\App\Core\Media::thumbUrl($url), ENT_QUOTES) ?>" class="media-card__thumb" alt="" loading="lazy">
                    <?php else: ?>
                        <div class="media-card__icon-wrap">
                            <?= AdminUi::icon('document', 36, 'media-card__file-icon', 1.8) ?>
                            <span class="media-card__ext"><?= htmlspecialchars($ext, ENT_QUOTES) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="media-card__caption" title="<?= htmlspecialchars((string) $item['original_name'], ENT_QUOTES) ?>">
                        <?= htmlspecialchars((string) $item['original_name'], ENT_QUOTES) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Табличное отображение (Список) -->
        <table class="data-table files-table">
            <thead>
                <tr>
                    <th class="files-table__check"><input type="checkbox" id="table_bulk_check_all" title="Выбрать все" aria-label="Выбрать все"></th>
                    <th>Имя файла</th>
                    <th>Тип</th>
                    <th>Размер</th>
                    <th>Доступ</th>
                    <th>Ссылка</th>
                    <th class="data-table__action-cell">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="7" class="data-table__empty">Файлов пока нет.</td></tr>
                <?php endif; ?>
                <?php foreach ($items as $item): ?>
                    <?php 
                    $url = FileEntry::publicUrl($item);
                    if ($item['access_type'] !== 'public') {
                        $url = '/download.php?file_id=' . (int) $item['id'] . '&token=' . htmlspecialchars((string) $item['access_token'], ENT_QUOTES);
                    }
                    ?>
                    <tr data-id="<?= (int) $item['id'] ?>" data-name="<?= htmlspecialchars((string) $item['original_name'], ENT_QUOTES) ?>">
                        <td class="files-table__check">
                            <input type="checkbox" class="file-row-check" data-id="<?= (int) $item['id'] ?>" aria-label="Выбрать файл">
                        </td>
                        <td>
                            <div class="file-cell">
                                <span class="file-name"><?= htmlspecialchars((string) $item['original_name'], ENT_QUOTES) ?></span>
                            </div>
                        </td>
                        <td><?= htmlspecialchars((string) $item['mime_type'], ENT_QUOTES) ?></td>
                        <td><?= Format::fileSize((int) $item['size']) ?></td>
                        <td>
                            <span class="badge badge--<?= $item['access_type'] === 'public' ? 'published' : 'draft' ?>">
                                <?= $item['access_type'] === 'public' ? 'Открытый' : 'Защищённый' ?>
                            </span>
                        </td>
                        <td class="u-inline-bbfba05910">
                            <code><?= htmlspecialchars($url, ENT_QUOTES) ?></code>
                        </td>
                        <td class="data-table__action-cell">
                            <div class="data-table__actions">
                                <button type="button" class="btn btn--small" data-copy-link="<?= htmlspecialchars($url, ENT_QUOTES) ?>">Ссылка</button>
                                <form method="post" action="/admin/files/<?= (int) $item['id'] ?>/delete" data-confirm="Удалить файл «<?= htmlspecialchars((string) $item['original_name'], ENT_QUOTES) ?>»?">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn--small btn--danger">Удалить</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Модальная инспекционная панель свойств медиафайла -->
<div class="media-modal" id="media_modal">
    <div class="media-modal__dialog">
        <button type="button" class="media-modal__close" id="media_modal_close" aria-label="Закрыть"><?= \App\Core\AdminUi::icon('x', 18) ?></button>
        <div class="media-modal__preview-side" id="modal_preview_container">
            <!-- Превью картинка/видео вставляется JS -->
        </div>
        <div class="media-modal__details-side">
            <h3 class="media-modal__title" id="modal_file_name">--</h3>
            <div class="media-modal__meta-list">
                <div>Загружен: <strong id="modal_file_date">--</strong></div>
                <div>Размер: <strong id="modal_file_size">--</strong></div>
                <div>MIME-тип: <strong id="modal_file_mime">--</strong></div>
                <div>Доступ: <strong id="modal_file_access">--</strong></div>
            </div>

            <div class="media-modal__field">
                <label>Прямая ссылка на файл</label>
                <div class="media-modal__input-group">
                    <input type="text" id="modal_file_url" readonly>
                    <button type="button" class="btn btn--primary" id="modal_copy_btn">Копировать</button>
                </div>
            </div>

            <div class="u-inline-ffed13198a">
                <form method="post" action="" id="modal_delete_form" data-confirm="Вы уверены, что хотите навсегда удалить этот медиафайл?">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn--danger">Удалить навсегда</button>
                </form>
                <button type="button" class="btn" data-close-target="media_modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= \App\Core\SecurityHeaders::nonce() ?>">
(function() {
    'use strict';

    // 1. Показ/Скрытие панели загрузки
    var btnToggleUpload = document.getElementById('btn_toggle_upload');
    var uploadDrawer = document.getElementById('media_upload_drawer');
    var fileInput = document.getElementById('media_file_input');
    var filenamePreview = document.getElementById('upload_filename_preview');
    var uploadActionRow = document.getElementById('upload_action_row');

    if (btnToggleUpload && uploadDrawer) {
        btnToggleUpload.addEventListener('click', function() {
            uploadDrawer.classList.toggle('is-open');
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function() {
            if (fileInput.files && fileInput.files[0]) {
                var f = fileInput.files[0];
                filenamePreview.textContent = 'Выбран файл: ' + f.name + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' МБ)';
                uploadActionRow.style.display = 'block';
            } else {
                filenamePreview.textContent = '';
                uploadActionRow.style.display = 'none';
            }
        });
    }

    // Drag and Drop прямо на дравер
    if (uploadDrawer) {
        ['dragenter', 'dragover'].forEach(function(ev) {
            document.body.addEventListener(ev, function(e) {
                e.preventDefault();
                uploadDrawer.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function(ev) {
            uploadDrawer.addEventListener(ev, function(e) {
                e.preventDefault();
                uploadDrawer.classList.remove('is-dragover');
            });
        });
    }

    // 2. Режим множественного выбора (Bulk Select)
    var isBulkMode = false;
    var selectedIds = new Set();
    var btnBulkToggle = document.getElementById('btn_bulk_toggle');
    var btnBulkSelectAll = document.getElementById('btn_bulk_select_all');
    var tableBulkAll = document.getElementById('table_bulk_check_all');
    var bulkBar = document.getElementById('media_bulk_bar');
    var bulkCountEl = document.getElementById('bulk_count');
    var bulkIdsInput = document.getElementById('bulk_ids_input');
    var filesTable = document.querySelector('.files-table');

    if (btnBulkToggle) {
        btnBulkToggle.addEventListener('click', function() {
            isBulkMode = !isBulkMode;
            btnBulkToggle.classList.toggle('btn--primary', isBulkMode);
            bulkBar.classList.toggle('is-active', isBulkMode);
            if (filesTable) {
                filesTable.classList.toggle('is-bulk-active', isBulkMode);
            }
            if (!isBulkMode) {
                selectedIds.clear();
                updateBulkUI();
            }
        });
    }

    if (btnBulkSelectAll) {
        btnBulkSelectAll.addEventListener('click', function() {
            var cards = document.querySelectorAll('.media-card');
            var visibleCards = Array.from(cards).filter(function(c) {
                return c.style.display !== 'none';
            });
            var allVisibleSelected = visibleCards.length > 0 && visibleCards.every(function(c) {
                return selectedIds.has(parseInt(c.getAttribute('data-id'), 10));
            });

            if (allVisibleSelected) {
                visibleCards.forEach(function(c) {
                    selectedIds.delete(parseInt(c.getAttribute('data-id'), 10));
                });
            } else {
                visibleCards.forEach(function(c) {
                    selectedIds.add(parseInt(c.getAttribute('data-id'), 10));
                });
            }
            updateBulkUI();
        });
    }

    if (tableBulkAll) {
        tableBulkAll.addEventListener('change', function() {
            var tableRows = document.querySelectorAll('.files-table tbody tr[data-id]');
            var visibleRows = Array.from(tableRows).filter(function(r) {
                return r.style.display !== 'none';
            });
            visibleRows.forEach(function(r) {
                var id = parseInt(r.getAttribute('data-id'), 10);
                if (tableBulkAll.checked) {
                    selectedIds.add(id);
                } else {
                    selectedIds.delete(id);
                }
            });
            updateBulkUI();
        });
    }

    document.querySelectorAll('.file-row-check').forEach(function(ch) {
        ch.addEventListener('change', function() {
            var id = parseInt(ch.getAttribute('data-id'), 10);
            if (ch.checked) {
                selectedIds.add(id);
            } else {
                selectedIds.delete(id);
            }
            updateBulkUI();
        });
    });

    function updateBulkUI() {
        var cards = document.querySelectorAll('.media-card');
        var visibleCards = [];
        cards.forEach(function(card) {
            var id = parseInt(card.getAttribute('data-id'), 10);
            var isSel = selectedIds.has(id);
            card.classList.toggle('is-selected', isSel);
            if (card.style.display !== 'none') {
                visibleCards.push(card);
            }
        });

        document.querySelectorAll('.file-row-check').forEach(function(ch) {
            var id = parseInt(ch.getAttribute('data-id'), 10);
            ch.checked = selectedIds.has(id);
        });

        var allVisibleSelected = visibleCards.length > 0 && visibleCards.every(function(c) {
            return selectedIds.has(parseInt(c.getAttribute('data-id'), 10));
        });

        if (tableBulkAll) {
            tableBulkAll.checked = allVisibleSelected;
        }

        if (btnBulkSelectAll) {
            btnBulkSelectAll.textContent = (allVisibleSelected && visibleCards.length > 0) ? 'Снять выбор' : 'Выбрать все';
        }

        if (bulkCountEl) bulkCountEl.textContent = selectedIds.size;
        if (bulkIdsInput) bulkIdsInput.value = JSON.stringify(Array.from(selectedIds));
    }

    // 3. Клик по карточке — инспектор или bulk select
    var modal = document.getElementById('media_modal');
    var modalClose = document.getElementById('media_modal_close');
    var previewContainer = document.getElementById('modal_preview_container');
    var modalFileName = document.getElementById('modal_file_name');
    var modalFileDate = document.getElementById('modal_file_date');
    var modalFileSize = document.getElementById('modal_file_size');
    var modalFileMime = document.getElementById('modal_file_mime');
    var modalFileAccess = document.getElementById('modal_file_access');
    var modalFileUrl = document.getElementById('modal_file_url');
    var modalCopyBtn = document.getElementById('modal_copy_btn');
    var modalDeleteForm = document.getElementById('modal_delete_form');

    document.querySelectorAll('.media-card').forEach(function(card) {
        card.addEventListener('click', function(e) {
            var id = parseInt(card.getAttribute('data-id'), 10);
            
            if (isBulkMode) {
                if (selectedIds.has(id)) {
                    selectedIds.delete(id);
                } else {
                    selectedIds.add(id);
                }
                updateBulkUI();
                return;
            }

            // Показ модального инспектора
            var name = card.getAttribute('data-name');
            var url = card.getAttribute('data-url');
            var size = card.getAttribute('data-size');
            var mime = card.getAttribute('data-mime');
            var date = card.getAttribute('data-date');
            var access = card.getAttribute('data-access');
            var isImg = card.getAttribute('data-is-img') === '1';
            var isVideo = card.getAttribute('data-is-video') === '1';

            var fullUrl = url.startsWith('/') ? window.location.origin + url : url;

            if (modalFileName) modalFileName.textContent = name;
            if (modalFileDate) modalFileDate.textContent = date;
            if (modalFileSize) modalFileSize.textContent = size;
            if (modalFileMime) modalFileMime.textContent = mime;
            if (modalFileAccess) modalFileAccess.textContent = access === 'public' ? 'Открытый' : 'Защищённый';
            if (modalFileUrl) modalFileUrl.value = fullUrl;
            if (modalDeleteForm) modalDeleteForm.action = '/admin/files/' + id + '/delete';

            if (previewContainer) {
                if (isImg) {
                    previewContainer.innerHTML = '<img src="' + url + '" alt="">';
                } else if (isVideo) {
                    previewContainer.innerHTML = '<video src="' + url + '" controls autoplay></video>';
                } else {
                    previewContainer.innerHTML = '<div class="u-inline-3c7c5ffa2b">'
                        + <?= json_encode(AdminUi::icon('document', 64, 'media-preview__file-icon', 1.8), JSON_UNESCAPED_SLASHES) ?>
                        + '<div class="u-inline-cca6ad4b4f">' + mime + '</div></div>';
                }
            }

            if (modal) modal.classList.add('is-open');
        });
    });

    if (modalClose) {
        modalClose.addEventListener('click', function() {
            if (modal) modal.classList.remove('is-open');
        });
    }

    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.classList.remove('is-open');
            }
        });
    }

    if (modalCopyBtn && modalFileUrl) {
        modalCopyBtn.addEventListener('click', function() {
            if (window.copyToClipboard) {
                window.copyToClipboard(modalFileUrl.value, modalCopyBtn);
            }
        });
    }

    // 4. Переключение режима Список / Сетка
    var container = document.getElementById('files_container');
    var btnList = document.getElementById('view_mode_list');
    var btnGrid = document.getElementById('view_mode_grid');

    if (container && btnList && btnGrid) {
        var savedMode = localStorage.getItem('artstudio:files-view-mode') || 'grid';
        setMode(savedMode);

        btnList.addEventListener('click', function() { setMode('list'); });
        btnGrid.addEventListener('click', function() { setMode('grid'); });

        function setMode(mode) {
            if (mode === 'list') {
                container.className = 'files-view-list';
                btnList.classList.add('is-active');
                btnGrid.classList.remove('is-active');
            } else {
                container.className = 'files-view-grid';
                btnGrid.classList.add('is-active');
                btnList.classList.remove('is-active');
            }
            try { localStorage.setItem('artstudio:files-view-mode', mode); } catch(e) {}
        }
    }

    // 5. Поиск по списку (live search)
    var searchInput = document.getElementById('media_search_input');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var term = searchInput.value.toLowerCase().trim();
            document.querySelectorAll('.media-card').forEach(function(card) {
                var name = (card.getAttribute('data-name') || '').toLowerCase();
                card.style.display = name.includes(term) ? '' : 'none';
            });
            document.querySelectorAll('.files-table tbody tr[data-name]').forEach(function(tr) {
                var name = (tr.getAttribute('data-name') || '').toLowerCase();
                tr.style.display = name.includes(term) ? '' : 'none';
            });
            updateBulkUI();
        });
    }
})();
</script>

<?php require __DIR__ . '/../layout/footer.php'; ?>
