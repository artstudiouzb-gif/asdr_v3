    </main>
</div>

<div class="tabler-picker" data-icon-picker hidden role="dialog" aria-modal="true" aria-labelledby="tabler-picker-title">
    <div class="tabler-picker__dialog">
        <div class="tabler-picker__head">
            <div>
                <strong id="tabler-picker-title">Tabler Icons</strong>
                <span>Локальный каталог — без CDN</span>
            </div>
            <button type="button" class="btn btn--secondary" data-icon-picker-close aria-label="Закрыть">
                <?= \App\Core\Icon::render('x', 18) ?>
            </button>
        </div>
        <div class="tabler-picker__search">
            <?= \App\Core\Icon::render('search', 18) ?>
            <input type="search" data-icon-picker-search placeholder="Поиск: home, user, chart…" autocomplete="off">
        </div>
        <div class="tabler-picker__results" data-icon-picker-results aria-live="polite">
            <div class="tabler-picker__status">Каталог загружается…</div>
        </div>
        <div class="tabler-picker__foot">
            <span data-icon-picker-count></span>
            <button type="button" class="btn btn--secondary" data-icon-picker-empty>Без иконки</button>
        </div>
    </div>
</div>

<div class="media-modal" data-media-modal hidden role="dialog" aria-modal="true" aria-labelledby="media-modal-title">
    <div class="media-modal__dialog" data-media-upload data-csrf="<?= htmlspecialchars(\App\Core\Csrf::token(), ENT_QUOTES) ?>">
        <aside class="media-modal__rail">
            <h2 class="media-modal__title" id="media-modal-title">Выберите медиа</h2>
            <nav class="media-modal__nav" data-media-nav aria-label="Виды файлов"></nav>
        </aside>

        <div class="media-modal__body">
            <div class="media-modal__toolbar" data-media-toolbar>
                <div class="media-modal__search-box">
                    <?= \App\Core\Icon::render('search', 16, 'media-modal__search-icon') ?>
                    <input type="search" class="media-modal__search" data-media-search placeholder="Поиск по медиа…" aria-label="Поиск в медиабиблиотеке">
                </div>
                <label class="media-modal__sort">
                    <span>Сортировка</span>
                    <select data-media-sort aria-label="Порядок файлов">
                        <option value="date_desc">Новые</option>
                        <option value="date_asc">Старые</option>
                        <option value="name_asc">По имени</option>
                        <option value="size_desc">Крупные</option>
                    </select>
                </label>
                <div class="media-modal__view" role="group" aria-label="Вид списка">
                    <button type="button" class="media-modal__viewbtn is-active" data-media-view="grid" aria-label="Плитка"><?= \App\Core\Icon::render('layout-grid', 16) ?></button>
                    <button type="button" class="media-modal__viewbtn" data-media-view="list" aria-label="Список"><?= \App\Core\Icon::render('list', 16) ?></button>
                </div>
                <label class="btn btn--primary media-modal__upload-btn" data-media-upload-button>
                    <?= \App\Core\AdminUi::icon('upload', 15, 'btn__icon', 2) ?>Загрузить файлы
                    <input class="u-inline-c8be1ccba6" type="file" data-media-upload-input>
                </label>
                <button type="button" class="media-modal__close" data-media-close aria-label="Закрыть"><?= \App\Core\Icon::render('x', 18) ?></button>
            </div>

            <div class="media-modal__upload-status u-inline-d8a81eac84" data-media-upload-status aria-live="polite"></div>

            <div class="media-modal__grid" data-media-grid aria-busy="true">
                <div class="media-modal__empty">Загрузка…</div>
            </div>
        </div>

        <aside class="media-modal__details" data-media-details hidden>
            <div class="media-modal__details-head">
                <h3>Детали файла</h3>
                <button type="button" class="media-modal__close" data-media-details-close aria-label="Скрыть детали"><?= \App\Core\Icon::render('x', 16) ?></button>
            </div>
            <div class="media-modal__details-body" data-media-details-body></div>
        </aside>

        <div class="media-modal__footer">
            <div class="media-modal__selected" data-media-selected>
                <span class="media-modal__selected-thumb" data-media-selected-thumb></span>
                <span class="media-modal__selected-info" data-media-selected-info>Файл не выбран</span>
                <button type="button" class="media-modal__selected-drop" data-media-selected-drop hidden>Снять выбор</button>
            </div>
            <div class="media-modal__footer-actions">
                <button type="button" class="btn" data-media-close>Отмена</button>
                <button type="button" class="btn btn--primary" data-media-select-btn disabled>Выбрать файл</button>
            </div>
        </div>

        <div class="media-modal__dropveil" data-media-dropveil aria-hidden="true">Отпустите файл — он загрузится в библиотеку</div>
        <?php
        /* Значки, которые рисует уже скрипт: символы спрайта ищутся по готовому
           HTML, поэтому имена обязаны встретиться на странице. Список
           RUNTIME_ICONS для этого не годится — он сверяется с frontend.js. */
        ?>
        <span class="u-inline-c8be1ccba6" aria-hidden="true" data-media-icons><?php
            foreach (['folder', 'photo', 'vector', 'movie', 'music', 'file-text',
                      'cloud-upload', 'player-play', 'external-link', 'copy'] as $mediaIcon) {
                echo \App\Core\Icon::render($mediaIcon, 16);
            }
        ?></span>
    </div>
</div>

<script nonce="<?= \App\Core\SecurityHeaders::nonce() ?>">
var stickyActions = Array.prototype.slice.call(document.querySelectorAll('.form-actions--sticky'));
if (stickyActions.length) {
    document.body.classList.add('has-sticky-actions');
    stickyActions.forEach(function (actions) {
        actions.setAttribute('role', 'toolbar');
        actions.setAttribute('aria-label', 'Действия формы');
        actions.classList.remove('is-context-hidden');
    });
}

/* Обратная связь при сохранении контента: сразу показываем процесс у нажатой
   кнопки, а после серверного redirect дублируем success-flash заметным toast. */
document.querySelectorAll('form[data-content-draft]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        var submitter = event.submitter;
        window.setTimeout(function () {
            if (event.defaultPrevented || !submitter) { return; }
            submitter.dataset.originalHtml = submitter.innerHTML;
            submitter.classList.add('is-loading');
            submitter.setAttribute('aria-busy', 'true');
            submitter.textContent = 'Сохранение…';

            window.setTimeout(function () {
                if (!document.body.contains(submitter)) { return; }
                submitter.classList.remove('is-loading');
                submitter.removeAttribute('aria-busy');
                if (submitter.dataset.originalHtml) {
                    submitter.innerHTML = submitter.dataset.originalHtml;
                    delete submitter.dataset.originalHtml;
                }
            }, 15000);
        }, 0);
    });
});

window.addEventListener('DOMContentLoaded', function () {
    /* Сообщение показываем тостом при любом исходе, а не только при успехе.
       Раньше ошибку выводил только .alert наверху .admin-main, и она терялась:
       обработчики возвращают на якорь (например /admin/telegram#telegram-channel),
       браузер сразу уезжает к нужной секции — и сообщение остаётся выше экрана.
       Со стороны это выглядело как «кнопка не работает». */
    var alertBox = document.querySelector('.admin-main > .alert');
    if (!alertBox) { return; }

    var message = (alertBox.textContent || '').trim();
    if (!message) { return; }

    var isError = alertBox.classList.contains('alert--error');
    var kind = isError ? 'error' : (alertBox.classList.contains('alert--warning') ? 'warning' : 'success');

    var toast = document.createElement('div');
    toast.className = 'admin-toast-notification admin-toast--' + kind;
    toast.setAttribute('role', isError ? 'alert' : 'status');
    toast.setAttribute('aria-live', isError ? 'assertive' : 'polite');
    var toastOkIcon = <?= json_encode(\App\Core\Icon::render('check', 16), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    /* У ошибки свой значок: галочка рядом с красным текстом читается как
       «получилось» и сбивает с толку сильнее, чем отсутствие значка вообще.
       Берём circle-x, а не alert-triangle: символы для спрайта собираются
       регуляркой по готовому HTML, а внутри JS-строки кавычки экранированы и
       она их не находит. Гарантированно попадают в спрайт только ключи из
       Icon::RUNTIME_ICONS — circle-x там есть. */
    var toastErrIcon = <?= json_encode(\App\Core\Icon::render('circle-x', 16), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var toastCloseIcon = <?= json_encode(\App\Core\Icon::render('x', 16), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    toast.innerHTML = '<div class="u-inline-7e30d285d2">'
        + '<span class="u-inline-4f1925a8a6" aria-hidden="true">' + (isError ? toastErrIcon : toastOkIcon) + '</span>'
        + '<span class="u-inline-94c3db5540"></span>'
        + '</div>'
        + '<button class="u-inline-d8c73d8aa0" type="button" aria-label="Закрыть уведомление">' + toastCloseIcon + '</button>';
    toast.querySelector('.u-inline-94c3db5540').textContent = message;
    toast.querySelector('button').addEventListener('click', function () { toast.remove(); });
    document.body.appendChild(toast);
    alertBox.remove();

    window.requestAnimationFrame(function () { toast.classList.add('is-visible'); });

    /* Ошибка висит, пока её не закроют: в ней написано, что именно сделать
       («добавьте бота администратором и напишите в канал»), и пятью секундами
       такое не прочитать. Успех гасим сам — там читать нечего. */
    if (isError) { return; }
    window.setTimeout(function () {
        toast.classList.remove('is-visible');
        window.setTimeout(function () { toast.remove(); }, 300);
    }, 6000);
});
/* Навигация админки: мобильная панель и запоминаемое сворачивание на десктопе. */
(function () {
    var t = document.querySelector('[data-sidebar-toggle]');
    var s = document.querySelector('[data-sidebar]');
    var backdrop = document.querySelector('[data-sidebar-backdrop]');
    var collapse = document.querySelector('[data-sidebar-collapse]');

    function setMobileOpen(open) {
        document.body.classList.toggle('sidebar-open', open);
        if (s) {
            var mobile = window.matchMedia('(max-width: 960px)').matches;
            s.inert = mobile && !open;
            if (mobile) s.setAttribute('aria-hidden', open ? 'false' : 'true');
            else s.removeAttribute('aria-hidden');
        }
        if (t) {
            t.setAttribute('aria-expanded', open ? 'true' : 'false');
            t.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
        }
    }

    function syncCollapsedState() {
        if (!collapse) return;
        var collapsed = document.documentElement.classList.contains('admin-nav-collapsed');
        collapse.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        collapse.setAttribute('title', collapsed ? 'Развернуть меню' : 'Свернуть меню');
        var label = collapse.querySelector('span');
        if (label) label.textContent = collapsed ? 'Развернуть меню' : 'Свернуть меню';
    }

    if (t && s) {
        setMobileOpen(false);
        t.addEventListener('click', function () {
            var opening = !document.body.classList.contains('sidebar-open');
            setMobileOpen(opening);
            if (opening) {
                var current = s.querySelector('[aria-current="page"]') || s.querySelector('.admin-nav-item');
                if (current) current.focus();
            }
        });
        s.addEventListener('click', function (e) {
            if (e.target.closest('.admin-nav-item') && window.matchMedia('(max-width: 960px)').matches) {
                setMobileOpen(false);
            }
        });
        if (backdrop) backdrop.addEventListener('click', function () { setMobileOpen(false); t.focus(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
                setMobileOpen(false);
                t.focus();
            }
        });
        window.addEventListener('resize', function () {
            if (!window.matchMedia('(max-width: 960px)').matches) setMobileOpen(false);
        });
    }

    if (collapse) {
        syncCollapsedState();
        collapse.addEventListener('click', function () {
            var collapsed = document.documentElement.classList.toggle('admin-nav-collapsed');
            try { localStorage.setItem('artstudio:admin-sidebar-collapsed', collapsed ? '1' : '0'); } catch (e) {}
            syncCollapsedState();
        });
    }
})();
</script>
<script src="<?= htmlspecialchars(\App\Core\Asset::url('/assets/js/vendor/editor.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(\App\Core\Asset::url('/assets/vendor/coloris/coloris.min.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(\App\Core\Asset::url('/assets/js/admin.js'), ENT_QUOTES) ?>"></script>
</body>
</html>
