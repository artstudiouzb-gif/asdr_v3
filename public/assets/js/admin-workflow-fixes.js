(function () {
    'use strict';

    function iconMarkup(name, size) {
        if (window.asdrTablerIcon) {
            return window.asdrTablerIcon(name, size || 18);
        }
        return '';
    }

    // ---------------------------------------------------------------------
    // News gallery editor.
    // Media selection itself belongs exclusively to admin.js / MediaPicker.
    // This layer only improves the editor around the selected photographs.
    // ---------------------------------------------------------------------
    function wrapExistingGalleryImages(gallery) {
        gallery.querySelectorAll('.news-gallery-admin > div').forEach(function (card) {
            var image = card.querySelector('img');
            if (!image) { return; }
            image.loading = 'lazy';
            image.decoding = 'async';
            if (image.parentElement && image.parentElement.classList.contains('news-gallery-admin__thumb')) {
                return;
            }
            var thumb = document.createElement('div');
            thumb.className = 'news-gallery-admin__thumb';
            image.parentNode.insertBefore(thumb, image);
            thumb.appendChild(image);
        });
    }

    function galleryCount(gallery) {
        var persisted = gallery.querySelectorAll('.news-gallery-admin > div').length;
        var selected = gallery.querySelectorAll('[data-media-gallery-selection] .file-preview__item').length;
        return persisted + selected;
    }

    function enhanceNewsGallery(gallery) {
        if (!gallery || gallery.dataset.workflowEnhanced === '1') { return; }
        var pick = gallery.querySelector('[data-media-gallery-pick]');
        var originalLabel = gallery.querySelector(':scope > label');
        if (!pick || !originalLabel) { return; }

        gallery.dataset.workflowEnhanced = '1';
        gallery.classList.add('news-gallery-editor');
        originalLabel.classList.add('news-gallery-editor__title');

        var head = document.createElement('div');
        head.className = 'news-gallery-editor__head';
        var heading = document.createElement('div');
        heading.className = 'news-gallery-editor__heading';
        var count = document.createElement('span');
        count.className = 'news-gallery-editor__count';
        count.setAttribute('data-news-gallery-count', '');

        originalLabel.parentNode.insertBefore(head, originalLabel);
        heading.appendChild(originalLabel);
        heading.appendChild(count);
        head.appendChild(heading);

        pick.classList.remove('btn--secondary', 'btn--small');
        pick.classList.add('btn--primary', 'news-gallery-editor__add');
        pick.innerHTML = iconMarkup('photo-plus', 16) + '<span>Добавить фотографии</span>';
        head.appendChild(pick);

        var empty = document.createElement('div');
        empty.className = 'news-gallery-editor__empty';
        empty.setAttribute('data-news-gallery-empty', '');
        var emptyText = document.createElement('div');
        var emptyStrong = document.createElement('strong');
        emptyStrong.textContent = 'Фотографии ещё не добавлены';
        var emptyHint = document.createElement('span');
        emptyHint.textContent = 'Выберите фотографии из медиабиблиотеки или загрузите новые. Они сразу сохранятся в библиотеке — новость предварительно сохранять не нужно.';
        emptyText.appendChild(emptyStrong);
        emptyText.appendChild(emptyHint);
        empty.appendChild(emptyText);
        head.insertAdjacentElement('afterend', empty);

        var helper = document.createElement('p');
        helper.className = 'news-gallery-editor__helper';
        helper.textContent = 'Превью используются в пропорции 16:9. Подпись, источник, alt-текст и точку фокуса можно заполнить в медиабиблиотеке.';
        var previewWrap = gallery.querySelector('[data-file-preview]');
        if (previewWrap) {
            previewWrap.insertBefore(helper, previewWrap.firstChild);
        }

        gallery.querySelectorAll('.form-hint').forEach(function (hint) {
            if (/Сохраните новость/i.test(hint.textContent || '')) {
                hint.textContent = 'Фотографии можно добавить сразу — сохранять новость заранее не требуется.';
            }
        });

        function refresh() {
            wrapExistingGalleryImages(gallery);
            gallery.querySelectorAll('.file-preview__item img').forEach(function (image) {
                image.loading = 'lazy';
                image.decoding = 'async';
            });
            var total = galleryCount(gallery);
            count.textContent = String(total);
            count.title = total ? 'Фотографий в статье: ' + total : 'Фотографий пока нет';
            empty.hidden = total > 0;
        }

        var observer = new MutationObserver(function () {
            window.requestAnimationFrame(refresh);
        });
        observer.observe(gallery, { childList: true, subtree: true });
        refresh();
    }

    document.querySelectorAll('[data-media-gallery]').forEach(enhanceNewsGallery);

    // ---------------------------------------------------------------------
    // Backup restore UI. Uses the existing protected POST /admin/backup route.
    // ---------------------------------------------------------------------
    function humanBytes(bytes) {
        var value = Number(bytes || 0);
        if (!value) { return '0 Б'; }
        var units = ['Б', 'КБ', 'МБ', 'ГБ'];
        var index = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)));
        return (value / Math.pow(1024, index)).toFixed(index ? 1 : 0) + ' ' + units[index];
    }

    function enhanceBackupSection() {
        var section = document.getElementById('backup-section');
        if (!section || section.dataset.restoreEnhanced === '1') { return; }
        var downloadForm = section.querySelector('form[action="/admin/backup"]');
        var sourceToken = downloadForm ? downloadForm.querySelector('input[name="csrf_token"]') : null;
        if (!downloadForm || !sourceToken || !sourceToken.value) { return; }

        section.dataset.restoreEnhanced = '1';
        var panel = document.createElement('div');
        panel.className = 'backup-restore-panel';

        var head = document.createElement('div');
        head.className = 'backup-restore-panel__head';
        var headText = document.createElement('div');
        var title = document.createElement('h3');
        title.textContent = 'Восстановление из резервной копии';
        var description = document.createElement('p');
        description.textContent = 'Загрузите ZIP, ранее скачанный из этого раздела. Перед восстановлением система автоматически сохранит свежую страховочную копию текущего сайта.';
        headText.appendChild(title);
        headText.appendChild(description);
        head.appendChild(headText);
        panel.appendChild(head);

        var form = document.createElement('form');
        form.method = 'post';
        form.action = '/admin/backup';
        form.enctype = 'multipart/form-data';
        form.setAttribute('data-backup-restore-form', '');

        var csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf_token';
        csrf.value = sourceToken.value;
        form.appendChild(csrf);

        var action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'backup_action';
        action.value = 'restore';
        form.appendChild(action);

        var grid = document.createElement('div');
        grid.className = 'backup-restore-panel__grid';

        var fileField = document.createElement('div');
        fileField.className = 'backup-restore-panel__file';
        var fileLabel = document.createElement('label');
        fileLabel.setAttribute('for', 'backup_restore_file');
        fileLabel.textContent = 'ZIP-файл резервной копии';
        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.id = 'backup_restore_file';
        fileInput.name = 'backup_file';
        fileInput.accept = '.zip,application/zip,application/x-zip-compressed';
        fileInput.required = true;
        var fileStatus = document.createElement('div');
        fileStatus.className = 'backup-restore-panel__status';
        fileStatus.setAttribute('data-backup-file-status', '');
        fileStatus.textContent = 'Выберите файл backup_YYYY-MM-DD_HHMMSS.zip.';
        fileField.appendChild(fileLabel);
        fileField.appendChild(fileInput);
        fileField.appendChild(fileStatus);

        var codeField = document.createElement('div');
        codeField.className = 'form-field';
        var codeLabel = document.createElement('label');
        codeLabel.setAttribute('for', 'backup_restore_code');
        codeLabel.textContent = 'Код подтверждения';
        var code = document.createElement('input');
        code.type = 'text';
        code.id = 'backup_restore_code';
        code.name = 'restore_confirm_code';
        code.autocomplete = 'off';
        code.spellcheck = false;
        code.placeholder = 'Введите RESTORE';
        code.required = true;
        var codeHint = document.createElement('span');
        codeHint.className = 'form-hint';
        codeHint.textContent = 'Для защиты от случайного запуска введите RESTORE.';
        codeField.appendChild(codeLabel);
        codeField.appendChild(code);
        codeField.appendChild(codeHint);

        grid.appendChild(fileField);
        grid.appendChild(codeField);
        form.appendChild(grid);

        var confirmWrap = document.createElement('div');
        confirmWrap.className = 'backup-restore-panel__confirm';
        var confirmLabel = document.createElement('label');
        var confirm = document.createElement('input');
        confirm.type = 'checkbox';
        confirm.name = 'restore_ack';
        confirm.value = '1';
        var confirmText = document.createElement('span');
        confirmText.textContent = 'Я понимаю, что база данных и загруженные файлы сайта будут заменены содержимым выбранной резервной копии.';
        confirmLabel.appendChild(confirm);
        confirmLabel.appendChild(confirmText);
        confirmWrap.appendChild(confirmLabel);
        form.appendChild(confirmWrap);

        var actions = document.createElement('div');
        actions.className = 'backup-restore-panel__actions';
        var safety = document.createElement('span');
        safety.className = 'backup-restore-panel__safety';
        safety.textContent = 'Страховочная копия текущего состояния останется на сервере после успешного восстановления.';
        var submit = document.createElement('button');
        submit.type = 'submit';
        submit.className = 'btn btn--danger';
        submit.disabled = true;
        submit.textContent = 'Восстановить сайт из ZIP';
        actions.appendChild(safety);
        actions.appendChild(submit);
        form.appendChild(actions);
        panel.appendChild(form);
        section.appendChild(panel);

        function valid() {
            var file = fileInput.files && fileInput.files[0];
            var isZip = file && /\.zip$/i.test(file.name || '');
            var codeOk = String(code.value || '').trim().toUpperCase() === 'RESTORE';
            submit.disabled = !(isZip && codeOk && confirm.checked);
        }

        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            if (!file) {
                fileStatus.textContent = 'Выберите файл backup_YYYY-MM-DD_HHMMSS.zip.';
            } else if (!/\.zip$/i.test(file.name || '')) {
                fileStatus.textContent = 'Нужен ZIP-файл резервной копии.';
            } else {
                fileStatus.textContent = file.name + ' · ' + humanBytes(file.size);
            }
            valid();
        });
        code.addEventListener('input', valid);
        confirm.addEventListener('change', valid);

        form.addEventListener('submit', function (event) {
            valid();
            if (submit.disabled) {
                event.preventDefault();
                return;
            }
            var ok = window.confirm(
                'Восстановить сайт из выбранного ZIP?\n\n'
                + 'Перед операцией будет создана страховочная копия текущего состояния. '
                + 'Во время восстановления база данных и загруженные файлы будут заменены.'
            );
            if (!ok) {
                event.preventDefault();
                return;
            }
            submit.disabled = true;
            submit.textContent = 'Восстановление… не закрывайте страницу';
        });
    }

    enhanceBackupSection();
})();

// The slider settings enhancer is intentionally loaded from the already
// fingerprinted admin workflow layer. This keeps the stable admin loader and
// PHP asset contracts untouched while still cache-busting the new files.
(function () {
    'use strict';

    var current = document.currentScript;
    var currentUrl = current && current.src ? current.src : '/assets/js/admin-workflow-fixes.js';
    var version = '';
    try {
        version = new URL(currentUrl, document.baseURI).search || '';
    } catch (error) {}

    function siblingAsset(relativePath) {
        try {
            var url = new URL(relativePath, currentUrl);
            url.search = version.replace(/^\?/, '');
            return url.toString();
        } catch (error) {
            return relativePath + version;
        }
    }

    if (!document.querySelector('link[data-admin-slider-settings-layout]')) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = siblingAsset('../css/admin-slider-settings-layout.css');
        link.setAttribute('data-admin-slider-settings-layout', '');
        document.head.appendChild(link);
    }

    if (!document.querySelector('script[data-admin-slider-settings-layout]')) {
        var script = document.createElement('script');
        script.src = siblingAsset('admin-slider-settings-layout.js');
        script.async = false;
        script.setAttribute('data-admin-slider-settings-layout', '');
        document.head.appendChild(script);
    }
})();
