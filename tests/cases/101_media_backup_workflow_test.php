<?php

declare(strict_types=1);

test('Admin media: единый picker, 5x3 и progressive «Показать ещё»', function (): void {
    $workflowCss = file_get_contents(APP_ROOT . '/public/assets/css/admin-workflow-fixes.css');
    $unifiedCss = file_get_contents(APP_ROOT . '/public/assets/css/admin-media-unified.css');
    $workflowJs = file_get_contents(APP_ROOT . '/public/assets/js/admin-workflow-fixes.js');
    $adminJs = file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    $loadMore = file_get_contents(APP_ROOT . '/public/assets/js/admin-media-loadmore.js');
    $metadataLegacy = file_get_contents(APP_ROOT . '/public/assets/js/admin-media-metadata.js');
    $metadataLegacyCss = file_get_contents(APP_ROOT . '/public/assets/css/admin-media-metadata.css');
    $dropzone = file_get_contents(APP_ROOT . '/public/assets/js/admin-gallery-dropzone.js');

    assert_true(is_string($workflowCss));
    assert_true(is_string($unifiedCss));
    assert_true(is_string($workflowJs));
    assert_true(is_string($adminJs));
    assert_true(is_string($loadMore));
    assert_true(is_string($metadataLegacy));
    assert_true(is_string($metadataLegacyCss));
    assert_true(is_string($dropzone));

    assert_contains("image.loading = 'lazy'", $workflowJs);

    // Фотогалерея обязана использовать тот же MediaPicker, что одиночные поля.
    assert_contains('window.MediaPicker = {', $adminJs);
    assert_contains("pickMany: function (cb) { open(null, cb, 'image', true); }", $adminJs);
    assert_contains("var galleryPick = e.target.closest('[data-media-gallery-pick]')", $adminJs);
    assert_not_contains("className = 'gallery-media-modal'", $metadataLegacy);
    assert_not_contains('stopImmediatePropagation()', $metadataLegacy);
    assert_contains('Legacy compatibility shim', $metadataLegacy);
    assert_contains('Legacy compatibility shim', $metadataLegacyCss);
    assert_not_contains('.gallery-media-modal', $metadataLegacyCss);

    // Старый клиентский pager физически удалён из исполняемого workflow-кода.
    assert_not_contains('PAGE_SIZE = 15', $workflowJs);
    assert_not_contains('media-client-pager', $workflowJs);
    assert_not_contains('applyPagination(', $workflowJs);
    assert_not_contains('setupPagedGrid(', $workflowJs);

    // Workflow-слой больше вообще не вмешивается в геометрию media picker.
    assert_not_contains('.media-modal__grid', $workflowCss);
    assert_not_contains('.gallery-media-modal', $workflowCss);
    assert_not_contains('media-client-pager', $workflowCss);

    // Progressive loading применяется только к основной сетке media-modal.
    assert_contains('var STEP = 15', $loadMore);
    assert_contains("button.textContent = 'Показать ещё'", $loadMore);
    assert_contains("'Показано ' + shown + ' из ' + total", $loadMore);
    assert_contains("scope.querySelectorAll('.media-modal__grid').forEach(setup)", $loadMore);
    assert_not_contains('gallery-media-modal__grid', $loadMore);
    assert_not_contains('media-client-pager', $loadMore);

    // Единственный слой геометрии медиабиблиотеки.
    assert_contains('aspect-ratio: 16 / 10', $unifiedCss);
    assert_contains('grid-template-columns: repeat(5, minmax(0, 1fr))', $unifiedCss);
    assert_contains('grid-auto-rows: max-content', $unifiedCss);
    assert_contains('.media-loadmore-bar', $unifiedCss);
    assert_not_contains('media-client-pager', $unifiedCss);
    assert_not_contains('gallery-media-modal', $unifiedCss);

    assert_contains('news-gallery-editor', $workflowJs);
    assert_contains('Добавить фотографии', $workflowJs);
    assert_contains("empty.addEventListener('drop'", $dropzone);
    assert_contains("pick.click()", $dropzone);
    assert_contains('Нажмите, чтобы выбрать из медиабиблиотеки, или перетащите фото сюда.', $dropzone);
    assert_contains('removeDuplicateHints', $dropzone);
    assert_contains('setDragState(true)', $dropzone);
});

test('Admin backup: восстановление защищено подтверждением и страховочным бэкапом', function (): void {
    $controller = file_get_contents(APP_ROOT . '/app/Controllers/Admin/BackupController.php');
    $restore = file_get_contents(APP_ROOT . '/app/Core/BackupRestore.php');
    $workflow = file_get_contents(APP_ROOT . '/public/assets/js/admin-workflow-fixes.js');
    assert_true(is_string($controller));
    assert_true(is_string($restore));
    assert_true(is_string($workflow));

    assert_contains("backup_action'] ?? '') === 'restore'", $controller);
    assert_contains('BackupRestore::restoreUploaded', $controller);
    assert_contains("private const CONFIRM_CODE = 'RESTORE'", $restore);
    assert_contains('$safetyPath = Backup::create(false);', $restore);
    assert_contains('self::assertCompatibleArchive($incoming);', $restore);
    assert_contains('self::rollbackSwap($swap);', $restore);
    assert_contains("action.value = 'restore'", $workflow);
    assert_contains("codeOk = String(code.value || '').trim().toUpperCase() === 'RESTORE'", $workflow);
});

test('Admin assets: media workflow-слои входят в cache fingerprint', function (): void {
    $asset = file_get_contents(APP_ROOT . '/app/Core/Asset.php');
    $loader = file_get_contents(APP_ROOT . '/public/assets/js/admin-media-loader.js');
    assert_true(is_string($asset));
    assert_true(is_string($loader));

    assert_contains('/assets/js/admin-workflow-fixes.js', $asset);
    assert_contains('/assets/js/admin-gallery-dropzone.js', $asset);
    assert_contains('/assets/js/admin-media-loadmore.js', $asset);
    assert_contains('/assets/css/admin-workflow-fixes.css', $asset);
    assert_contains('/assets/css/admin-media-unified.css', $asset);
    assert_contains("loadStyle('admin-workflow-fixes.css', 'workflow')", $loader);
    assert_contains("loadStyle('admin-media-unified.css', 'media-unified')", $loader);
    assert_contains("load('admin-workflow-fixes.js'", $loader);
    assert_contains("load('admin-gallery-dropzone.js'", $loader);
    assert_contains("load('admin-media-loadmore.js'", $loader);
});
