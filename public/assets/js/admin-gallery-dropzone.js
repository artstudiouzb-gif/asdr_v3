(function () {
    'use strict';

    function imageFiles(fileList) {
        return Array.prototype.slice.call(fileList || []).filter(function (file) {
            return file && (/^image\//i.test(file.type || '') || /\.(jpe?g|png|gif|webp|avif|bmp|svg)$/i.test(file.name || ''));
        });
    }

    function setFiles(input, files) {
        if (!input || !files.length || !window.DataTransfer) { return false; }
        var transfer = new DataTransfer();
        files.forEach(function (file) { transfer.items.add(file); });
        input.files = transfer.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    function removeDuplicateHints(gallery) {
        var helper = gallery.querySelector('.news-gallery-editor__helper');
        if (helper) { helper.remove(); }

        gallery.querySelectorAll('.form-hint').forEach(function (hint) {
            if (hint.hasAttribute('data-media-auto-upload-status')) { return; }
            var text = String(hint.textContent || '').trim();
            if (/^(Фотографии можно добавить сразу|Фотографии сначала загружаются|Сохраните новость|Превью используются)/i.test(text)) {
                hint.remove();
            }
        });
    }

    function enhance(gallery) {
        if (!gallery || gallery.dataset.dropzoneEnhanced === '1') { return; }

        var empty = gallery.querySelector('[data-news-gallery-empty]');
        var pick = gallery.querySelector('[data-media-gallery-pick]');
        var input = gallery.querySelector('[data-file-preview-input]');
        if (!empty || !pick || !input) { return; }

        gallery.dataset.dropzoneEnhanced = '1';
        removeDuplicateHints(gallery);

        var strong = empty.querySelector('strong');
        var hint = empty.querySelector('span');
        if (strong) { strong.textContent = 'Добавьте фотографии'; }
        if (hint) { hint.textContent = 'Нажмите, чтобы выбрать из медиабиблиотеки, или перетащите фото сюда.'; }

        empty.setAttribute('role', 'button');
        empty.setAttribute('tabindex', '0');
        empty.setAttribute('aria-label', 'Добавить фотографии в галерею');
        empty.setAttribute('title', 'Открыть медиабиблиотеку или перетащить фотографии');
        empty.style.cursor = 'pointer';

        function setDragState(active) {
            empty.classList.toggle('is-dragover', active);
            empty.style.background = active ? 'rgba(37, 99, 235, .06)' : '';
            empty.style.borderColor = active ? 'rgba(37, 99, 235, .65)' : '';
        }

        function openLibrary(event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            pick.click();
        }

        empty.addEventListener('click', openLibrary);
        empty.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') { openLibrary(event); }
        });

        empty.addEventListener('dragenter', function (event) {
            event.preventDefault();
            setDragState(true);
        });
        empty.addEventListener('dragover', function (event) {
            event.preventDefault();
            if (event.dataTransfer) { event.dataTransfer.dropEffect = 'copy'; }
            setDragState(true);
        });
        empty.addEventListener('dragleave', function (event) {
            if (!empty.contains(event.relatedTarget)) { setDragState(false); }
        });
        empty.addEventListener('drop', function (event) {
            event.preventDefault();
            event.stopPropagation();
            setDragState(false);

            var files = imageFiles(event.dataTransfer && event.dataTransfer.files);
            if (!files.length) {
                if (window.adminAlert) { window.adminAlert('Перетащите сюда файлы изображений.'); }
                return;
            }
            if (!setFiles(input, files) && window.adminAlert) {
                window.adminAlert('Не удалось принять перетащённые файлы. Используйте кнопку «Добавить фотографии».');
            }
        });
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('[data-media-gallery]').forEach(enhance);
    }

    scan(document);
    if (window.MutationObserver) {
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (!node || node.nodeType !== 1) { return; }
                    if (node.matches && node.matches('[data-media-gallery]')) { enhance(node); }
                    scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
