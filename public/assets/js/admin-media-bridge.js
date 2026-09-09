(function () {
    'use strict';

    var MAX_BYTES = 200 * 1024 * 1024;
    var CHUNK_BYTES = 1024 * 1024;

    function csrfToken(node) {
        var form = node && node.closest ? node.closest('form') : null;
        var formToken = form ? form.querySelector('input[name="csrf_token"]') : null;
        if (formToken && formToken.value) { return formToken.value; }

        var mediaUpload = document.querySelector('[data-media-upload]');
        return mediaUpload ? (mediaUpload.getAttribute('data-csrf') || '') : '';
    }

    function uploadId() {
        var value = '';
        if (window.crypto && window.crypto.getRandomValues) {
            var bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            for (var i = 0; i < bytes.length; i++) {
                value += bytes[i].toString(16).padStart(2, '0');
            }
            return value;
        }
        for (var j = 0; j < 32; j++) {
            value += Math.floor(Math.random() * 16).toString(16);
        }
        return value;
    }

    function uploadToLibrary(file, token, progress) {
        return new Promise(function (resolve, reject) {
            if (!file || !file.size) {
                reject(new Error('Файл пустой.'));
                return;
            }
            if (file.size > MAX_BYTES) {
                reject(new Error('Файл превышает 200 МБ.'));
                return;
            }
            if (!token) {
                reject(new Error('Не найден CSRF-токен. Обновите страницу и повторите загрузку.'));
                return;
            }

            var total = Math.ceil(file.size / CHUNK_BYTES);
            var id = uploadId();

            function send(index) {
                var fd = new FormData();
                fd.append('csrf_token', token);
                fd.append('upload_id', id);
                fd.append('index', String(index));
                fd.append('total', String(total));
                fd.append('name', file.name);
                fd.append('access_type', 'public');
                fd.append('chunk', file.slice(index * CHUNK_BYTES, Math.min((index + 1) * CHUNK_BYTES, file.size)));

                fetch('/admin/files/chunk', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (response) {
                    return response.json().catch(function () {
                        return { ok: false, error: 'HTTP ' + response.status };
                    });
                }).then(function (result) {
                    if (!result.ok) {
                        throw new Error(result.error || 'Ошибка загрузки.');
                    }
                    if (result.done) {
                        if (!result.url) {
                            throw new Error('Файл загружен, но сервер не вернул публичный адрес.');
                        }
                        if (progress) { progress(100); }
                        resolve(result);
                        return;
                    }

                    var percent = Math.round(((index + 1) / total) * 100);
                    if (progress) { progress(Math.min(99, percent)); }
                    send(index + 1);
                }).catch(reject);
            }

            if (progress) { progress(0); }
            send(0);
        });
    }

    function setFormUploading(form, delta) {
        if (!form) { return; }
        var count = Math.max(0, Number(form.dataset.mediaUploadCount || 0) + delta);
        form.dataset.mediaUploadCount = String(count);
        form.classList.toggle('is-media-uploading', count > 0);
    }

    function statusElement(container) {
        if (!container) { return null; }
        var status = container.querySelector('[data-media-auto-upload-status]');
        if (status) { return status; }

        status = document.createElement('span');
        status.className = 'form-hint';
        status.setAttribute('data-media-auto-upload-status', '');
        status.setAttribute('aria-live', 'polite');
        container.appendChild(status);
        return status;
    }

    function setStatus(status, text, isError) {
        if (!status) { return; }
        status.textContent = text || '';
        status.classList.toggle('is-error', Boolean(isError));
    }

    function pickerButtonFor(preview) {
        var field = preview ? preview.closest('[data-image-field]') : null;
        if (field) {
            return field.querySelector('[data-media-pick]');
        }
        var formField = preview ? preview.closest('.form-field') : null;
        return formField ? formField.querySelector('[data-media-pick]') : null;
    }

    function preparePreview(preview) {
        if (!preview || preview.dataset.mediaLibraryTrigger === '1') { return; }
        if (!pickerButtonFor(preview)) { return; }
        preview.dataset.mediaLibraryTrigger = '1';
        preview.setAttribute('role', 'button');
        preview.setAttribute('tabindex', '0');
        preview.setAttribute('aria-label', 'Открыть медиабиблиотеку');
        if (!preview.getAttribute('title')) {
            preview.setAttribute('title', 'Открыть медиабиблиотеку');
        }
        preview.style.cursor = 'pointer';
    }

    function prepareAllPreviews(root) {
        (root || document).querySelectorAll('[data-image-preview]').forEach(preparePreview);
    }

    prepareAllPreviews(document);

    // Поля могут добавляться конструктором блоков без перезагрузки страницы.
    if (window.MutationObserver) {
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (!node || node.nodeType !== 1) { return; }
                    if (node.matches && node.matches('[data-image-preview]')) { preparePreview(node); }
                    prepareAllPreviews(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    // Главное UX-правило: клик по самой фотографии/пустой области изображения
    // открывает медиабиблиотеку. Нативный выбор файла остаётся отдельным действием.
    document.addEventListener('click', function (event) {
        var preview = event.target.closest('[data-image-preview]');
        if (!preview || event.target.closest('button, a, input, select, textarea, label')) { return; }
        var button = pickerButtonFor(preview);
        if (!button) { return; }
        event.preventDefault();
        button.click();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') { return; }
        var preview = event.target.closest('[data-image-preview][data-media-library-trigger="1"]');
        if (!preview) { return; }
        var button = pickerButtonFor(preview);
        if (!button) { return; }
        event.preventDefault();
        button.click();
    });

    function applyUploadedImage(field, fileInput, result) {
        var urlInput = field ? field.querySelector('[data-image-input]') : null;
        if (!urlInput || !result.url) { return false; }

        urlInput.value = result.url;
        urlInput.dispatchEvent(new Event('input', { bubbles: true }));
        urlInput.dispatchEvent(new Event('change', { bubbles: true }));
        if (fileInput) { fileInput.value = ''; }
        return true;
    }

    function uploadSingleImage(fileInput, file) {
        var field = fileInput.closest('[data-image-field]');
        var urlInput = field ? field.querySelector('[data-image-input]') : null;
        if (!field || !urlInput) { return; }

        var form = fileInput.closest('form');
        var status = statusElement(field);
        var token = csrfToken(fileInput);
        fileInput.disabled = true;
        setFormUploading(form, 1);

        uploadToLibrary(file, token, function (percent) {
            setStatus(status, 'Загрузка в медиабиблиотеку… ' + percent + '%', false);
        }).then(function (result) {
            if (!applyUploadedImage(field, fileInput, result)) {
                throw new Error('Не удалось привязать загруженный файл к полю.');
            }
            setStatus(status, 'Сохранено в медиабиблиотеке.', false);
        }).catch(function (error) {
            // Файл оставляем выбранным: если пользователь всё же сохранит форму,
            // старый серверный fallback не потеряет его.
            setStatus(status, 'Ошибка загрузки в медиабиблиотеку: ' + error.message, true);
        }).finally(function () {
            fileInput.disabled = false;
            setFormUploading(form, -1);
        });
    }

    // Любое локально выбранное изображение в стандартном image-field сначала
    // попадает в медиабиблиотеку и только затем становится значением формы.
    document.addEventListener('change', function (event) {
        var input = event.target.closest('[data-image-file]');
        if (!input || input.dataset.mediaBridgeIgnore === '1') { return; }
        if (!input.files || !input.files[0]) { return; }
        uploadSingleImage(input, input.files[0]);
    });

    // Drag & drop в область фото ведёт тем же путём: файл регистрируется в
    // медиабиблиотеке сразу, даже когда сама новость ещё ни разу не сохранена.
    document.addEventListener('dragover', function (event) {
        var preview = event.target.closest('[data-image-preview]');
        if (!preview || !pickerButtonFor(preview)) { return; }
        event.preventDefault();
        if (event.dataTransfer) { event.dataTransfer.dropEffect = 'copy'; }
    });

    document.addEventListener('drop', function (event) {
        var preview = event.target.closest('[data-image-preview]');
        if (!preview || !pickerButtonFor(preview) || !event.dataTransfer || !event.dataTransfer.files.length) { return; }
        var field = preview.closest('[data-image-field]');
        var fileInput = field ? field.querySelector('[data-image-file]') : null;
        if (!field || !field.querySelector('[data-image-input]')) { return; }

        event.preventDefault();
        var file = event.dataTransfer.files[0];
        var form = field.closest('form');
        var status = statusElement(field);
        var token = csrfToken(field);
        setFormUploading(form, 1);

        uploadToLibrary(file, token, function (percent) {
            setStatus(status, 'Загрузка в медиабиблиотеку… ' + percent + '%', false);
        }).then(function (result) {
            if (!applyUploadedImage(field, fileInput, result)) {
                throw new Error('Не удалось привязать загруженный файл к полю.');
            }
            setStatus(status, 'Сохранено в медиабиблиотеке.', false);
        }).catch(function (error) {
            setStatus(status, 'Ошибка загрузки в медиабиблиотеку: ' + error.message, true);
        }).finally(function () {
            setFormUploading(form, -1);
        });
    });

    function gallerySelection(gallery) {
        return gallery ? gallery.querySelector('[data-media-gallery-selection]') : null;
    }

    function addGalleryLibraryItem(gallery, url, displayName) {
        var selection = gallerySelection(gallery);
        if (!selection || !url) { return false; }

        var duplicate = Array.prototype.some.call(
            selection.querySelectorAll('input[name="gallery_library[]"]'),
            function (input) { return input.value === url; }
        );
        if (duplicate) { return false; }

        var item = document.createElement('div');
        item.className = 'file-preview__item';

        var image = document.createElement('img');
        image.src = encodeURI(url);
        image.alt = '';
        item.appendChild(image);

        var name = document.createElement('span');
        name.className = 'file-preview__name';
        name.textContent = displayName || (url.split('/').pop() || url);
        item.appendChild(name);

        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'gallery_library[]';
        input.value = url;
        item.appendChild(input);

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'file-preview__drop';
        remove.setAttribute('data-media-gallery-drop', '');
        remove.setAttribute('aria-label', 'Убрать фотографию из выбора');
        remove.textContent = '×';
        item.appendChild(remove);

        selection.appendChild(item);
        selection.hidden = false;
        return true;
    }

    function rerenderNativeGalleryInput(input) {
        input.dataset.mediaBridgeIgnore = '1';
        input.dispatchEvent(new Event('change', { bubbles: true }));
        delete input.dataset.mediaBridgeIgnore;
    }

    function uploadGalleryFiles(input, files) {
        var gallery = input.closest('[data-media-gallery]');
        if (!gallery || !files.length) { return; }

        var form = input.closest('form');
        var status = statusElement(gallery);
        var token = csrfToken(input);
        var failed = [];
        var uploaded = 0;
        var index = 0;
        input.disabled = true;
        setFormUploading(form, 1);

        function next() {
            if (index >= files.length) {
                if (failed.length && window.DataTransfer) {
                    var remaining = new DataTransfer();
                    failed.forEach(function (file) { remaining.items.add(file); });
                    input.files = remaining.files;
                } else if (!failed.length) {
                    input.value = '';
                }
                rerenderNativeGalleryInput(input);
                input.disabled = false;
                setFormUploading(form, -1);

                if (failed.length) {
                    setStatus(
                        status,
                        'В медиабиблиотеку загружено: ' + uploaded + '. Не удалось: ' + failed.length + '. Повторите загрузку оставшихся файлов.',
                        true
                    );
                } else {
                    setStatus(status, 'Все фото сохранены в медиабиблиотеке.', false);
                }
                return;
            }

            var file = files[index];
            var position = index + 1;
            uploadToLibrary(file, token, function (percent) {
                setStatus(status, 'Загрузка фото ' + position + ' из ' + files.length + '… ' + percent + '%', false);
            }).then(function (result) {
                addGalleryLibraryItem(gallery, result.url, file.name);
                uploaded++;
            }).catch(function () {
                failed.push(file);
            }).finally(function () {
                index++;
                next();
            });
        }

        next();
    }

    // Галерея новости раньше держала локальные файлы в форме до первого Save.
    // Теперь каждый выбранный файл сразу становится записью files, а в новость
    // отправляется уже ссылка gallery_library[]. Это работает и для новой новости.
    document.addEventListener('change', function (event) {
        var input = event.target.closest('[data-media-gallery] [data-file-preview-input]');
        if (!input || input.dataset.mediaBridgeIgnore === '1') { return; }
        var files = Array.prototype.slice.call(input.files || []);
        if (!files.length) { return; }
        uploadGalleryFiles(input, files);
    });

    // Не позволяем сохранить материал посередине чанковой загрузки: иначе форма
    // уйдёт без уже выбранного медиа и редактор решит, что файл потерян.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || Number(form.dataset.mediaUploadCount || 0) <= 0) { return; }
        event.preventDefault();
        event.stopPropagation();
        if (window.adminAlert) {
            window.adminAlert('Дождитесь завершения загрузки медиа в медиабиблиотеку.');
        }
    }, true);
})();
