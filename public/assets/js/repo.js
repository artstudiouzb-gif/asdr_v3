/**
 * Портал репозитория файлов: обработчики элементов управления.
 *
 * Раньше эти действия висели на inline-атрибутах (onclick/onchange/onsubmit),
 * но CSP портала не разрешает inline-скрипты — браузер их не выполнял, и
 * кнопка загрузки, сортировка и закрытие предпросмотра не работали.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        // Кнопка «Загрузить файл» раскрывает <details> с формой загрузки.
        var opener = event.target.closest('[data-open-details]');
        if (opener) {
            var details = document.querySelector(opener.getAttribute('data-open-details'));
            if (details) { details.setAttribute('open', 'true'); }
            return;
        }

        var closer = event.target.closest('[data-close-dialog]');
        if (closer) {
            var dialog = document.getElementById(closer.getAttribute('data-close-dialog'));
            if (dialog && typeof dialog.close === 'function') { dialog.close(); }
        }
    });

    // Сортировка списка: выбор отправляет свою GET-форму. Адрес страницы
    // собирает браузер из action и полей формы — скрипт не берёт адрес из
    // разметки и никуда по нему не переходит.
    document.addEventListener('change', function (event) {
        var select = event.target.closest('[data-autosubmit]');
        if (!select || !select.form) { return; }

        if (typeof select.form.requestSubmit === 'function') {
            select.form.requestSubmit();
        } else {
            select.form.submit();
        }
    });

    // Необратимые действия (отключение 2FA, отвязка Telegram) спрашивают подтверждение.
    document.addEventListener('submit', function (event) {
        var form = event.target.closest('form[data-confirm-submit]');
        if (form && !window.confirm(form.getAttribute('data-confirm-submit'))) {
            event.preventDefault();
        }
    });

    // --- Переключение режима вида (плитка / таблица) ---
    document.addEventListener('DOMContentLoaded', function () {
        var gridView = document.getElementById('rd-grid-view');
        var tableView = document.getElementById('rd-table-view');
        var toggleBtns = document.querySelectorAll('[data-view-mode]');
        if (!toggleBtns.length) { return; }

        function setViewMode(mode) {
            toggleBtns.forEach(function (b) {
                b.classList.toggle('is-active', b.getAttribute('data-view-mode') === mode);
            });
            if (gridView && tableView) {
                // Раскладки различаются типом контейнера, поэтому переключаем
                // display, а не hidden: у сетки собственный display: grid.
                gridView.style.display = mode === 'table' ? 'none' : 'grid';
                tableView.style.display = mode === 'table' ? 'block' : 'none';
            }
        }

        var savedMode = 'grid';
        try { savedMode = localStorage.getItem('repo_view_mode') || 'grid'; } catch (err) {}
        setViewMode(savedMode);

        toggleBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var mode = btn.getAttribute('data-view-mode');
                setViewMode(mode);
                try { localStorage.setItem('repo_view_mode', mode); } catch (err) {}
            });
        });
    });

    // --- Копирование ссылки на файл ---
    document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-copy-link]');
        if (!btn) { return; }

        event.preventDefault();
        var fullUrl = window.location.origin + btn.getAttribute('data-copy-link');
        var done = function () {
            var oldTitle = btn.getAttribute('title');
            btn.setAttribute('title', 'Скопировано!');
            btn.classList.add('is-copied');
            setTimeout(function () {
                btn.setAttribute('title', oldTitle || '');
                btn.classList.remove('is-copied');
            }, 1800);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(fullUrl).then(done).catch(function () {
                window.prompt('Ссылка на файл:', fullUrl);
            });
        } else {
            window.prompt('Ссылка на файл:', fullUrl);
        }
    });
})();
