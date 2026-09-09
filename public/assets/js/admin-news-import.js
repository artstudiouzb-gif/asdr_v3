(function () {
    'use strict';

    var root = document.querySelector('[data-news-import]');
    if (!root) return;

    var endpoint = root.getAttribute('data-endpoint') || '/admin/news/import';
    var form = root.querySelector('[data-upload-form]');
    var fileInput = root.querySelector('[data-file-input]');
    var dropZone = root.querySelector('[data-drop-zone]');
    var uploadButton = root.querySelector('[data-upload-button]');
    var resetButton = root.querySelector('[data-reset-file]');
    var fileSummary = root.querySelector('[data-file-summary]');
    var uploadProgress = root.querySelector('[data-upload-progress]');
    var uploadBar = root.querySelector('[data-upload-bar]');
    var uploadPercent = root.querySelector('[data-upload-percent]');
    var reportStage = root.querySelector('[data-stage="report"]');
    var importStage = root.querySelector('[data-stage="import"]');
    var doneStage = root.querySelector('[data-stage="done"]');
    var errorToast = root.querySelector('[data-error-toast]');
    var token = '';
    var selectedFile = null;
    var report = null;
    var busy = false;
    var runStatus = 'draft';
    var csrfInput = form ? form.querySelector('input[type="hidden"]') : null;

    function csrf(formData) {
        if (csrfInput && csrfInput.name) formData.append(csrfInput.name, csrfInput.value);
        return formData;
    }

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' Б';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' КБ';
        return (bytes / 1048576).toFixed(1) + ' МБ';
    }

    function showError(message) {
        if (!errorToast) return;
        errorToast.textContent = message || 'Неизвестная ошибка.';
        // Ошибку по таймеру не гасим: в ней написано, что делать дальше.
        errorToast.hidden = false;
    }

    function clearError() {
        if (errorToast) errorToast.hidden = true;
    }

    function describeBadResponse(response, raw) {
        var text = String(raw || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        var code = 'HTTP ' + response.status + (response.statusText ? ' ' + response.statusText : '');
        if (text === '') {
            return 'Сервер оборвал ответ (' + code + '). Обычно это лимит времени PHP или сбой соединения с базой '
                + 'на длинной пачке. Импорт можно продолжить — уже загруженные новости не потеряются.';
        }
        if (text.length > 300) text = text.slice(0, 300) + '…';
        return 'Сервер ответил не JSON (' + code + '): ' + text;
    }

    async function jsonPost(action, data) {
        var response = await fetch(endpoint + '/' + encodeURIComponent(action), {
            method: 'POST',
            body: csrf(data || new FormData()),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        var raw = await response.text();
        var payload = null;
        try {
            payload = JSON.parse(raw);
        } catch (e) {
            // Ответ не JSON — значит запрос умер до кода контроллера (лимит
            // времени, обрыв соединения с БД, 502 от хостинга). Показываем код
            // и первые строки ответа: без них редактор видел только «некорректный
            // ответ» и не понимал, что случилось.
            throw new Error(describeBadResponse(response, raw));
        }
        if (!response.ok || !payload.ok) throw new Error(payload.error || 'Операция не выполнена.');
        return payload;
    }

    function setFile(file) {
        if (!file) return;
        var maxMb = Number(root.getAttribute('data-max-mb') || 256);
        if (!/\.xml$/i.test(file.name)) {
            showError('Выберите WXR/XML-файл с расширением .xml.');
            return;
        }
        if (file.size < 1 || file.size > maxMb * 1048576) {
            showError('Размер XML должен быть не больше ' + maxMb + ' МБ.');
            return;
        }
        clearError();
        selectedFile = file;
        token = '';
        report = null;
        root.querySelector('[data-file-name]').textContent = file.name;
        root.querySelector('[data-file-size]').textContent = formatBytes(file.size);
        fileSummary.hidden = false;
        uploadButton.disabled = false;
        reportStage.hidden = true;
        importStage.hidden = true;
        doneStage.hidden = true;
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            setFile(fileInput.files && fileInput.files[0]);
        });
    }

    if (dropZone) {
        ['dragenter', 'dragover'].forEach(function (name) {
            dropZone.addEventListener(name, function (event) {
                event.preventDefault();
                dropZone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            dropZone.addEventListener(name, function (event) {
                event.preventDefault();
                dropZone.classList.remove('is-dragover');
            });
        });
        dropZone.addEventListener('drop', function (event) {
            var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
            setFile(file);
        });
    }

    if (resetButton) {
        resetButton.addEventListener('click', async function () {
            if (token) {
                var fd = new FormData();
                fd.append('token', token);
                try { await jsonPost('discard', fd); } catch (e) {}
            }
            selectedFile = null;
            token = '';
            report = null;
            if (fileInput) fileInput.value = '';
            fileSummary.hidden = true;
            uploadProgress.hidden = true;
            reportStage.hidden = true;
            importStage.hidden = true;
            doneStage.hidden = true;
            uploadButton.disabled = true;
        });
    }

    async function uploadFile(file) {
        var chunkSize = 1024 * 1024;
        var total = Math.ceil(file.size / chunkSize);
        var currentToken = '';
        uploadProgress.hidden = false;
        uploadButton.disabled = true;

        for (var index = 0; index < total; index++) {
            var chunk = file.slice(index * chunkSize, Math.min(file.size, (index + 1) * chunkSize));
            var fd = new FormData();
            fd.append('index', String(index));
            fd.append('total', String(total));
            fd.append('file_size', String(file.size));
            fd.append('file_name', file.name);
            fd.append('token', currentToken);
            fd.append('chunk', chunk, file.name + '.part');
            var result = await jsonPost('upload', fd);
            currentToken = result.token;
            var percent = Math.round(((index + 1) / total) * 100);
            uploadBar.value = percent;
            uploadPercent.textContent = percent + '%';
        }
        return currentToken;
    }

    function fillReport(value) {
        report = value;
        reportStage.hidden = false;
        root.querySelector('[data-report-site]').textContent = value.site || 'WXR/XML';
        Object.keys(value).forEach(function (key) {
            var field = root.querySelector('[data-stat="' + key + '"]');
            if (field) field.textContent = String(value[key]);
        });
        Object.keys(value.languages || {}).forEach(function (lang) {
            var field = root.querySelector('[data-lang="' + lang + '"]');
            if (field) field.textContent = String(value.languages[lang]);
        });

        var okBox = root.querySelector('[data-report-ok]');
        var errorBox = root.querySelector('[data-report-error]');
        var options = root.querySelector('[data-import-options]');
        var actions = root.querySelector('[data-import-actions]');
        var errors = Array.isArray(value.errors) ? value.errors : [];
        var safe = Number(value.unresolved || 0) === 0 && errors.length === 0;
        okBox.hidden = !safe;
        errorBox.hidden = safe;
        options.hidden = !safe;
        actions.hidden = !safe;
        if (!safe) {
            root.querySelector('[data-report-error-text]').textContent = errors.length
                ? errors.join(' ')
                : 'Есть неопределённые переводы: ' + value.unresolved + '. Импорт не будет запущен.';
        }
        reportStage.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    if (form) {
        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (busy || !selectedFile) return;
            clearError();
            busy = true;
            try {
                token = await uploadFile(selectedFile);
                var inspectData = new FormData();
                inspectData.append('token', token);
                var inspected = await jsonPost('inspect', inspectData);
                fillReport(inspected.report);
            } catch (e) {
                showError(e.message);
                uploadButton.disabled = false;
            } finally {
                busy = false;
            }
        });
    }

    function appendLog(text, error) {
        var log = root.querySelector('[data-import-log]');
        var row = document.createElement('div');
        row.className = error ? 'is-error' : '';
        row.textContent = text;
        log.appendChild(row);
        log.scrollTop = log.scrollHeight;
    }

    function updateRun(summary) {
        ['imported', 'translations', 'images', 'redirects'].forEach(function (key) {
            var node = root.querySelector('[data-run="' + key + '"]');
            if (node) node.textContent = String(summary[key] || 0);
        });
        var target = Math.max(0, Number(summary.target || 0));
        var imported = Math.max(0, Number(summary.imported || 0));
        var percent = target === 0 ? 100 : Math.min(100, Math.round((imported / target) * 100));
        root.querySelector('[data-import-bar]').value = percent;
        root.querySelector('[data-import-percent]').textContent = percent + '%';
        root.querySelector('[data-import-status-text]').textContent = target === 0
            ? 'Новых записей нет'
            : 'Импортировано ' + imported + ' из ' + target;
    }

    async function runBatches(status) {
        while (true) {
            var fd = new FormData();
            fd.append('token', token);
            fd.append('status', status);
            var result = await jsonPost('run', fd);
            var batchErrors = result.batch && Array.isArray(result.batch.errors) ? result.batch.errors : [];
            if (batchErrors.length) {
                batchErrors.forEach(function (message) { appendLog(message, true); });
                throw new Error('Импорт остановлен из-за ошибки записи. Исправьте причину и запустите импорт повторно.');
            }
            updateRun(result.summary || {});
            appendLog('Пакет обработан: +' + Number((result.batch || {}).imported || 0) + ' новостей, +' + Number((result.batch || {}).translations || 0) + ' переводов.');
            if (result.done) {
                var summary = result.summary || {};
                var text = 'Создано новостей: ' + Number(summary.imported || 0)
                    + ', переводов: ' + Number(summary.translations || 0)
                    + ', перенесено изображений: ' + Number(summary.images || 0)
                    + ', редиректов: ' + Number(summary.redirects || 0) + '.';
                if (summary.backup) text += ' Резервная копия: ' + summary.backup + '.';
                root.querySelector('[data-done-text]').textContent = text;
                importStage.hidden = true;
                doneStage.hidden = false;
                doneStage.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }
        }
    }

    var resumeActions = root.querySelector('[data-resume-actions]');

    // Обрыв пакета не отменяет импорт: курсор живёт на сервере, поэтому
    // продолжение — это тот же вызов run, а не проход с начала. Прежде
    // единственным выходом было вернуться к отчёту и запустить всё заново.
    function offerResume(message) {
        showError(message);
        appendLog(message, true);
        if (resumeActions) resumeActions.hidden = false;
    }

    async function drive(status) {
        if (resumeActions) resumeActions.hidden = true;
        busy = true;
        try {
            await runBatches(status);
        } catch (e) {
            offerResume(e.message);
        } finally {
            busy = false;
        }
    }

    var startButton = root.querySelector('[data-start-import]');
    if (startButton) {
        startButton.addEventListener('click', async function () {
            if (busy || !token || !report) return;
            runStatus = root.querySelector('[data-import-status]').value;
            var backup = root.querySelector('[data-import-backup]').checked;
            if (runStatus === 'published' && !window.confirm('Новые записи будут опубликованы сразу. Продолжить?')) return;
            clearError();
            startButton.disabled = true;
            reportStage.hidden = true;
            importStage.hidden = false;
            importStage.scrollIntoView({ behavior: 'smooth', block: 'start' });

            if (backup) {
                // Копия — свой запрос: её отказ не должен выглядеть как отказ
                // импорта, а её время не должно тратиться из бюджета пакета.
                busy = true;
                appendLog('Снимается резервная копия базы…');
                try {
                    var done = await jsonPost('backup', (function () {
                        var fd = new FormData();
                        fd.append('token', token);
                        return fd;
                    })());
                    appendLog(done.backup ? ('Резервная копия готова: ' + done.backup) : 'Резервная копия уже снята.');
                } catch (e) {
                    busy = false;
                    offerResume('Резервная копия не создана: ' + e.message
                        + ' Импорт можно продолжить без неё или снять копию в разделе «Базы данных».');
                    return;
                }
                busy = false;
            }

            await drive(runStatus);
        });
    }

    var resumeButton = root.querySelector('[data-resume-import]');
    if (resumeButton) {
        resumeButton.addEventListener('click', async function () {
            if (busy || !token || !report) return;
            clearError();
            appendLog('Продолжаем с того места, где импорт остановился.');
            await drive(runStatus);
        });
    }

    var discard = root.querySelector('[data-discard]');
    if (discard) {
        discard.addEventListener('click', async function () {
            if (token) {
                var fd = new FormData();
                fd.append('token', token);
                try { await jsonPost('discard', fd); } catch (e) {}
            }
            window.location.href = '/admin/news';
        });
    }
})();
