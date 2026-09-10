(function () {
    'use strict';

    // Преобразует разрешённую разметку лида в текст без повторного разбора
    // строки как HTML. Это безопасный fallback до загрузки TinyMCE; после
    // загрузки текст берётся непосредственно из уже созданного DOM редактора.
    function decodeLeadEntities(value) {
        var named = {amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' '};
        return String(value || '').replace(/&(?:#([0-9]+)|#x([0-9a-f]+)|(amp|lt|gt|quot|apos|nbsp));/gi, function (match, decimal, hex, name) {
            if (name) { return named[name.toLowerCase()] || match; }
            var codePoint = parseInt(decimal || hex, decimal ? 10 : 16);
            if (!Number.isFinite(codePoint) || codePoint < 0 || codePoint > 0x10FFFF
                || (codePoint >= 0xD800 && codePoint <= 0xDFFF)) {
                return '\uFFFD';
            }
            return String.fromCodePoint(codePoint);
        });
    }

    // Один проход не позволяет фрагментам вложенных тегов сложиться в новый
    // тег после удаления (например, «<scr<x>ipt>»). Между тегами оставляем
    // пробел, чтобы соседние абзацы и пункты списка не склеивались.
    function stripLeadTags(value) {
        var text = String(value || '');
        var out = '';
        var tag = null;
        for (var i = 0; i < text.length; i++) {
            var character = text.charAt(i);
            if (tag === null) {
                if (character === '<') { tag = character; } else { out += character; }
                continue;
            }
            tag += character;
            if (character === '>') {
                out += ' ';
                tag = null;
            }
        }
        return tag === null ? out : out + tag;
    }

    function plainTextFromLeadMarkup(value) {
        var text = stripLeadTags(value);
        return decodeLeadEntities(text).replace(/\s+/g, ' ').trim();
    }

    function leadEditorFor(field) {
        return window.tinymce && field && field.id ? window.tinymce.get(field.id) : null;
    }

    function plainLeadText(field) {
        var editor = leadEditorFor(field);
        var body = editor && editor.getBody ? editor.getBody() : null;
        return body
            ? (body.textContent || '').replace(/\s+/g, ' ').trim()
            : plainTextFromLeadMarkup(field ? field.value : '');
    }

    // --- Единая система выбора цвета во всей админке. ---
    // В HTML остаётся нативный input[type=color] как рабочий fallback без JS.
    // До DOMContentLoaded он превращается в текстовое HEX-поле и подключается
    // к локально размещённому Coloris.
    (function () {
        var colorInputs = document.querySelectorAll('input[type="color"]');
        if (!colorInputs.length) { return; }

        colorInputs.forEach(function (input) {
            input.type = 'text';
            input.setAttribute('data-coloris', '');
            input.setAttribute('inputmode', 'text');
            input.setAttribute('autocomplete', 'off');
            input.setAttribute('spellcheck', 'false');
            input.setAttribute('maxlength', '7');
            input.setAttribute('pattern', '#[0-9a-fA-F]{6}');
            input.setAttribute('placeholder', '#17375E');
        });

        // Тёмный пикер на тёмной панели. Прежде здесь спрашивалось имя
        // цветовой темы (`dark_emerald`), а тем больше нет: тёмный вид —
        // это настройка внешнего вида (`data-admin-appearance`), поэтому в
        // тёмной панели пикер оставался белым прямоугольником.
        var darkMedia = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        function adminThemeMode() {
            var appearance = document.documentElement.getAttribute('data-admin-appearance');
            if (appearance === 'dark') { return 'dark'; }
            if (appearance === 'light') { return 'light'; }
            return darkMedia && darkMedia.matches ? 'dark' : 'light';
        }

        var swatches;
        try { swatches = JSON.parse(document.body.getAttribute('data-color-swatches') || '[]'); } catch (e) { swatches = []; }
        if (!Array.isArray(swatches) || swatches.length === 0) {
            swatches = ['#17375e', '#214d84', '#5e7fa6', '#a8b7c9', '#6cb9b1', '#ffffff', '#000000'];
        }

        if (window.Coloris) {
            window.Coloris({
                el: '[data-coloris]',
                theme: 'large',
                themeMode: adminThemeMode(),
                format: 'hex',
                formatToggle: false,
                alpha: false,
                focusInput: true,
                selectInput: true,
                closeButton: true,
                closeLabel: 'Готово',
                clearButton: false,
                // Палитра самого сайта, а не зашитый набор синих тонов:
                // образец даёт ровно тот цвет, что уже стоит на странице.
                swatches: swatches,
                a11y: {
                    open: 'Открыть выбор цвета',
                    close: 'Закрыть выбор цвета',
                    clear: 'Очистить цвет',
                    marker: 'Насыщенность: {s}. Яркость: {v}.',
                    hueSlider: 'Оттенок',
                    alphaSlider: 'Прозрачность',
                    input: 'Значение цвета',
                    format: 'Формат цвета',
                    swatch: 'Образец цвета',
                    instruction: 'Выбор насыщенности и яркости. Используйте клавиши со стрелками.'
                }
            });

            // Внешний вид панели переключается на лету (предпросмотр в
            // профиле, смена темы системы), а тема пикера задаётся при
            // запуске — без пересборки он остался бы от прежнего вида.
            var applyThemeMode = function () { window.Coloris({ themeMode: adminThemeMode() }); };
            if (darkMedia && darkMedia.addEventListener) { darkMedia.addEventListener('change', applyThemeMode); }
            if (window.MutationObserver) {
                new MutationObserver(applyThemeMode).observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['data-admin-appearance']
                });
            }
        }

        document.querySelectorAll('.colorfield').forEach(function (group) {
            var off = group.querySelector('.colorfield__off input[type="checkbox"]');
            var color = group.querySelector('[data-coloris]');
            if (!off || !color) { return; }
            var reset = group.querySelector('[data-colorfield-reset]');
            var offLabel = group.getAttribute('data-colorfield-default') || 'по умолчанию';

            // Поле остаётся рабочим и в состоянии «по умолчанию». Прежде оно
            // выключалось (disabled), и образец не открывался вовсе: редактор
            // видел поле цвета, которое не отзывается на нажатие, — так «Цвет
            // текста» и «Цвет фона основной кнопки» у обложки было нечем
            // поменять. Само значение при этом на сайт не уходит: сервер
            // читает галочку (<имя>_off) раньше значения.
            var syncing = false;

            // Управление одно: образец плюс кнопка возврата. Подписанная
            // галочка — это вариант без JavaScript, и пока она стояла рядом с
            // полем, длинная подпись («Использовать общую настройку обложки»)
            // вылезала за колонку и читалась как отдельная настройка.
            group.classList.add('is-enhanced');
            if (reset) { reset.hidden = false; }

            function syncDefaultState() {
                syncing = true;
                group.classList.toggle('is-default', off.checked);

                if (off.checked) {
                    if (color.value) { color.dataset.colorfieldValue = color.value; }
                    color.value = '';
                    // Подпись состояния — в самом поле: «цвет темы» на месте
                    // значения не говорил, какой именно цвет туда приедет.
                    color.placeholder = offLabel;
                } else if (!color.value) {
                    // Возврат к своему цвету без выбора: подставляем прежний,
                    // а на первый раз — умолчание поля из разметки.
                    color.value = color.dataset.colorfieldValue || color.defaultValue || '';
                }
                if (!off.checked) { color.placeholder = '#17375E'; }
                if (reset) { reset.hidden = off.checked; }
                color.dispatchEvent(new Event('input', { bubbles: true }));
                syncing = false;
            }

            // Выбор цвета сам означает «не по умолчанию»: снимать галочку
            // заранее редактор не обязан — о том, что она запирает поле,
            // ниоткуда не видно.
            color.addEventListener('input', function () {
                if (syncing || !off.checked || color.value.trim() === '') { return; }
                off.checked = false;
                syncDefaultState();
            });

            if (reset) {
                reset.addEventListener('click', function () {
                    off.checked = true;
                    syncDefaultState();
                });
            }

            off.addEventListener('change', syncDefaultState);
            syncDefaultState();
        });
    })();

    // Универсальная функция копирования в буфер обмена (работает по HTTPS и HTTP с фаллбэком)
    function copyToClipboard(text, btnEl) {
        if (!text) { return Promise.reject(new Error('Empty text')); }

        function showSuccess() {
            if (btnEl) {
                // Прежнее содержимое сохраняем узлами: чтение innerHTML с
                // обратной записью заново разбирает разметку кнопки.
                const oldNodes = Array.prototype.slice.call(btnEl.childNodes);
                btnEl.textContent = 'Скопировано!';
                btnEl.classList.add('is-copy-success');
                setTimeout(function () {
                    btnEl.textContent = '';
                    oldNodes.forEach(function (node) { btnEl.appendChild(node); });
                    btnEl.classList.remove('is-copy-success');
                }, 2000);
            }
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).then(function () {
                showSuccess();
                return true;
            }).catch(function () {
                return fallbackCopy(text, showSuccess);
            });
        }
        return Promise.resolve(fallbackCopy(text, showSuccess));
    }

    // Копирование нужно и деталям файла в медиабиблиотеке — отдаём наружу.
    window.asdrCopyToClipboard = copyToClipboard;

    function fallbackCopy(text, onSuccess) {
        try {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.className = 'clipboard-fallback';
            textarea.setAttribute('readonly', '');
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            textarea.setSelectionRange(0, 99999);
            var successful = document.execCommand('copy');
            document.body.removeChild(textarea);
            if (successful) {
                if (onSuccess) { onSuccess(); }
                return true;
            }
        } catch (e) {
            console.error('Fallback copy failed:', e);
        }
        return false;
    }

    window.copyToClipboard = copyToClipboard;

    document.querySelectorAll('[data-swatch-color]').forEach(function (element) {
        element.style.setProperty('--swatch-color', element.getAttribute('data-swatch-color'));
    });
    document.querySelectorAll('[data-font-family]').forEach(function (element) {
        element.style.setProperty('font-family', element.getAttribute('data-font-family'));
    });
    document.querySelectorAll('[data-font-size]').forEach(function (element) {
        element.style.setProperty('--preview-font-size', element.getAttribute('data-font-size'));
    });
    document.querySelectorAll('[data-progress-width]').forEach(function (element) {
        var value = Math.max(0, Math.min(100, Number(element.getAttribute('data-progress-width')) || 0));
        element.style.setProperty('--progress-width', value + '%');
    });

    // --- Раздел «Дизайн сайта»: доступные вкладки и честный live preview. ---
    (function initDesignBuilder() {
        var tabsRoot = document.querySelector('[data-design-tabs]');
        if (!tabsRoot) { return; }

        var tabs = Array.prototype.slice.call(tabsRoot.querySelectorAll('[role="tab"][data-tab-target]'));
        var panels = tabs.map(function (tab) {
            return document.getElementById(tab.getAttribute('data-tab-target'));
        }).filter(Boolean);
        var storageKey = 'artstudio:design-active-tab';

        function activateTab(tab, shouldFocus, shouldPersist) {
            if (!tab) { return; }
            var targetId = tab.getAttribute('data-tab-target');
            tabs.forEach(function (item) {
                var active = item === tab;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
                item.setAttribute('tabindex', active ? '0' : '-1');
            });
            panels.forEach(function (panel) {
                var active = panel.id === targetId;
                panel.classList.toggle('is-active', active);
                panel.hidden = !active;
            });
            var saveActions = document.querySelector('[data-design-save-actions]');
            if (saveActions) {
                saveActions.hidden = targetId === 'tab-presets';
            }
            if (shouldPersist !== false) {
                try { localStorage.setItem(storageKey, targetId); } catch (err) {}
            }
            if (shouldFocus) { tab.focus(); }
        }

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () { activateTab(tab, false, true); });
            tab.addEventListener('keydown', function (event) {
                var nextIndex = null;
                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    nextIndex = (index + 1) % tabs.length;
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    nextIndex = (index - 1 + tabs.length) % tabs.length;
                } else if (event.key === 'Home') {
                    nextIndex = 0;
                } else if (event.key === 'End') {
                    nextIndex = tabs.length - 1;
                }
                if (nextIndex !== null) {
                    event.preventDefault();
                    activateTab(tabs[nextIndex], true, true);
                }
            });
        });

        var initialTab = tabs.find(function (tab) { return tab.classList.contains('is-active'); }) || tabs[0];
        try {
            var savedTarget = localStorage.getItem(storageKey);
            initialTab = tabs.find(function (tab) {
                return tab.getAttribute('data-tab-target') === savedTarget;
            }) || initialTab;
        } catch (err) {}
        activateTab(initialTab, false, false);

        var form = document.querySelector('[data-design-form]');
        var canvas = document.getElementById('liveDeckCanvas');
        if (!form || !canvas) { return; }

        function fieldValue(name, fallback) {
            var control = form.elements.namedItem(name);
            if (!control) { return fallback; }
            var value = typeof control.value === 'string' ? control.value : '';
            return value !== '' ? value : fallback;
        }

        function numericValue(name) {
            var raw = fieldValue(name, '');
            var value = parseFloat(String(raw).replace(',', '.'));
            return Number.isFinite(value) ? value : null;
        }

        function selectedFont(select, fallback) {
            if (!select || !select.options || select.selectedIndex < 0) { return fallback; }
            return select.options[select.selectedIndex].getAttribute('data-font-family') || fallback;
        }

        function rounded(value) {
            return Math.round(value * 10) / 10;
        }

        function setLive(name, value) {
            canvas.style.setProperty(name, value);
        }

        function updateCodePreview(values) {
            var output = document.querySelector('[data-design-code-preview]');
            if (!output) { return; }
            output.textContent = ':root {\n'
                + '    --color-primary: ' + values.primary + ';\n'
                + '    --color-accent: ' + values.accent + ';\n'
                + '    --bg-primary: ' + values.bgPrimary + ';\n'
                + '    --bg-surface: ' + values.bgSurface + ';\n'
                + '    --text-main: ' + values.textMain + ';\n'
                + '    --text-muted: ' + values.textMuted + ';\n'
                + '    --border-color: ' + values.borderColor + ';\n'
                + '    --space-small: ' + fieldValue('space_small', 'clamp(14px, 2.5vw, 24px)') + ';\n'
                + '    --space-premium: ' + fieldValue('space_premium', 'clamp(28px, 4vw, 56px)') + ';\n'
                + '    --space-max: ' + fieldValue('space_max', 'clamp(40px, 5vw, 76px)') + ';\n'
                + '    --font-family: ' + values.bodyFont + ';\n'
                + '    --font-heading: ' + values.headingFont + ';\n'
                + '}';
        }

        function updateLiveDeck() {
            var radiusMap = { none: 0, small: 8, medium: 14, large: 22 };
            var gapMap = { xs: 8, sm: 16, md: 24, lg: 32 };
            var densityMap = { compact: 16, standard: 24, spacious: 32 };
            var sizeMap = { sm: 15, md: 16, lg: 17, xl: 18 };
            var lineMap = { tight: 1.45, normal: 1.6, relaxed: 1.8 };
            var headingLineMap = { tight: 1.15, normal: 1.25, relaxed: 1.35 };
            var letterMap = { tight: '-0.03em', normal: '-0.02em', wide: '0em' };
            var shadowMap = {
                flat: 'none',
                soft: '0 1px 3px rgba(16,24,40,.06), 0 6px 18px rgba(16,24,40,.05)',
                elevated: '0 10px 30px rgba(16,24,40,.12)'
            };
            var ratioMap = { theme: 0, compact: 1.2, classic: 1.25, expressive: 1.333 };

            var primary = fieldValue('color_primary', '#173a63');
            var accent = fieldValue('color_accent', '#17999b');
            var bgPrimary = fieldValue('bg_primary', '#f6f8fa');
            var bgSurface = fieldValue('bg_surface', '#ffffff');
            var textMain = fieldValue('text_main', '#1a1a1a');
            var textMuted = fieldValue('text_muted', '#666666');
            var borderColor = fieldValue('border_color', '#e1e3e8');

            var customRadius = numericValue('radius_custom');
            var radius = customRadius !== null ? customRadius : (radiusMap[fieldValue('radius', 'medium')] || 0);
            var baseSize = numericValue('font_size_custom');
            if (baseSize === null) { baseSize = sizeMap[fieldValue('font_size', 'md')] || 16; }
            var lineHeight = numericValue('line_height_custom');
            if (lineHeight === null) { lineHeight = lineMap[fieldValue('line_height', 'normal')] || 1.6; }
            var headingLine = numericValue('heading_line_height_custom');
            if (headingLine === null) { headingLine = headingLineMap[fieldValue('heading_line_height', 'normal')] || 1.25; }

            var bodySelect = form.querySelector('[data-font-body-choice]');
            var bodyFont = selectedFont(bodySelect, 'system-ui, sans-serif');
            if (bodySelect && bodySelect.value === 'style:custom') {
                bodyFont = fieldValue('font_family', bodyFont);
            }
            var headingSelect = form.elements.namedItem('font_google_heading');
            var headingFont = headingSelect && headingSelect.value !== ''
                ? selectedFont(headingSelect, bodyFont)
                : bodyFont;

            var defaults = { fs_h1: 32, fs_h2: 24, fs_h3: 18, fs_btn: 15 };
            var scale = fieldValue('typo_scale', 'theme');
            var ratio = ratioMap[scale] || 0;
            var steps = { fs_h1: 5, fs_h2: 4, fs_h3: 3 };
            Object.keys(steps).forEach(function (key) {
                var manual = numericValue(key);
                defaults[key] = manual !== null
                    ? manual
                    : (ratio > 1 ? rounded(baseSize * Math.pow(ratio, steps[key])) : defaults[key]);
            });
            var manualButton = numericValue('fs_btn');
            if (manualButton !== null) { defaults.fs_btn = manualButton; }

            var buttonStyle = fieldValue('button', 'rounded');
            var buttonRadius = buttonStyle === 'pill' ? 999 : (buttonStyle === 'square' ? 0 : radius);

            setLive('--live-color-primary', primary);
            setLive('--live-color-accent', accent);
            setLive('--live-bg-primary', bgPrimary);
            setLive('--live-bg-surface', bgSurface);
            setLive('--live-text-main', textMain);
            setLive('--live-text-muted', textMuted);
            setLive('--live-border-color', borderColor);
            setLive('--live-radius', radius + 'px');
            setLive('--live-btn-radius', buttonRadius + 'px');
            setLive('--live-card-shadow', shadowMap[fieldValue('card_style', 'soft')] || shadowMap.soft);
            setLive('--live-card-gap', (gapMap[fieldValue('card_gap', 'md')] || 24) + 'px');
            setLive('--live-section-pad', (densityMap[fieldValue('density', 'standard')] || 24) + 'px');
            setLive('--live-font-size', baseSize + 'px');
            setLive('--live-line-height', String(lineHeight));
            setLive('--live-heading-line-height', String(headingLine));
            setLive('--live-heading-font-weight', fieldValue('heading_font_weight', '700'));
            setLive('--live-heading-letter-spacing', letterMap[fieldValue('heading_letter_spacing', 'normal')] || '-0.02em');
            setLive('--live-font-body', bodyFont);
            setLive('--live-font-heading', headingFont);
            setLive('--live-fs-h1', defaults.fs_h1 + 'px');
            setLive('--live-fs-h2', defaults.fs_h2 + 'px');
            setLive('--live-fs-h3', defaults.fs_h3 + 'px');
            setLive('--live-fs-btn', defaults.fs_btn + 'px');

            updateCodePreview({
                primary: primary,
                accent: accent,
                bgPrimary: bgPrimary,
                bgSurface: bgSurface,
                textMain: textMain,
                textMuted: textMuted,
                borderColor: borderColor,
                bodyFont: bodyFont,
                headingFont: headingFont
            });
        }

        var fontChoice = form.querySelector('[data-font-body-choice]');
        var customFontFields = form.querySelector('[data-custom-font-fields]');
        function syncCustomFontFields() {
            if (fontChoice && customFontFields) {
                customFontFields.hidden = fontChoice.value !== 'style:custom';
            }
        }

        form.querySelectorAll('[data-design-preview-field]').forEach(function (element) {
            element.addEventListener('input', updateLiveDeck);
            element.addEventListener('change', function () {
                syncCustomFontFields();
                updateLiveDeck();
            });
        });
        syncCustomFontFields();
        updateLiveDeck();

        var frame = document.querySelector('[data-design-page-preview]');
        var widthButtons = document.querySelectorAll('[data-design-preview-width]');
        widthButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (!frame) { return; }
                frame.style.width = button.getAttribute('data-design-preview-width') || '100%';
                widthButtons.forEach(function (item) {
                    var active = item === button;
                    item.classList.toggle('is-active', active);
                    item.setAttribute('aria-pressed', active ? 'true' : 'false');
                });
            });
        });

        var refreshButton = document.querySelector('[data-design-preview-refresh]');
        if (refreshButton && frame) {
            refreshButton.addEventListener('click', function () {
                var params = new URLSearchParams(new FormData(form));
                params.delete('csrf_token');
                var base = frame.getAttribute('data-preview-base') || '/admin/design/preview';
                var url = base + '?' + params.toString();
                frame.src = url;
                var external = document.querySelector('.design-preview__bar a[target="_blank"]');
                if (external) { external.href = url; }
            });
        }
    })();

    // --- Telegram: toolbar, безопасное удаление токена и быстрые действия. ---
    (function initTelegramAdmin() {
        var signature = document.querySelector('[data-tg-signature]');
        var signatureCount = document.querySelector('[data-tg-signature-count]');

        // Считаем длину так, как её увидит Telegram: без тегов и с раскрытыми
        // сущностями. Разбирать ввод как разметку (innerHTML) нельзя, а
        // вырезать теги одним replace недостаточно: после удаления пары
        // «<…>» соседние куски складываются в новый тег («<scr<x>ipt>»).
        // Поэтому идём по строке ровно один раз.
        function stripTags(text) {
            var out = '';
            var tag = null;
            for (var i = 0; i < text.length; i++) {
                var ch = text.charAt(i);
                if (tag === null) {
                    if (ch === '<') { tag = ch; } else { out += ch; }
                    continue;
                }
                tag += ch;
                if (ch === '>') { tag = null; }
            }
            // Незакрытый «<» тегом не является — это обычный текст.
            return tag === null ? out : out + tag;
        }

        var NAMED_ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: '\u00a0' };

        // Один проход: раскрытая сущность повторно не разбирается, поэтому
        // «&amp;lt;» остаётся текстом «&lt;», как и в самом Telegram.
        function decodeEntities(text) {
            return text.replace(/&(#\d{1,7}|#x[0-9a-f]{1,6}|[a-z]+);/gi, function (match, body) {
                if (body.charAt(0) === '#') {
                    var hex = body.charAt(1) === 'x' || body.charAt(1) === 'X';
                    var code = hex ? parseInt(body.slice(2), 16) : parseInt(body.slice(1), 10);
                    return code > 0 && code <= 0x10FFFF ? String.fromCodePoint(code) : match;
                }
                var name = body.toLowerCase();
                return Object.prototype.hasOwnProperty.call(NAMED_ENTITIES, name) ? NAMED_ENTITIES[name] : match;
            });
        }

        function updateSignatureCount() {
            if (!signature || !signatureCount) { return; }
            var length = decodeEntities(stripTags(signature.value)).length;
            signatureCount.textContent = length + ' / 500';
            signatureCount.classList.toggle('is-over-limit', length > 500);
        }

        if (signature) {
            signature.addEventListener('input', updateSignatureCount);
            updateSignatureCount();
        }

        var clearToken = document.querySelector('[data-tg-clear-token]');
        var clearConfirm = document.querySelector('[data-tg-clear-confirm]');
        function syncClearToken() {
            if (!clearToken || !clearConfirm) { return; }
            clearConfirm.hidden = !clearToken.checked;
            var input = clearConfirm.querySelector('input');
            if (input) {
                input.required = clearToken.checked;
                if (!clearToken.checked) { input.value = ''; }
            }
        }
        if (clearToken) {
            clearToken.addEventListener('change', syncClearToken);
            syncClearToken();
        }

        document.addEventListener('click', function (event) {
            var tagButton = event.target.closest('[data-tg-tag-start]');
            if (tagButton && signature) {
                event.preventDefault();
                var startTag = tagButton.getAttribute('data-tg-tag-start') || '';
                var endTag = tagButton.getAttribute('data-tg-tag-end') || '';
                var start = signature.selectionStart;
                var end = signature.selectionEnd;
                var selected = signature.value.substring(start, end);
                signature.setRangeText(startTag + selected + endTag, start, end, 'end');
                signature.focus();
                signature.setSelectionRange(start + startTag.length, start + startTag.length + selected.length);
                updateSignatureCount();
                return;
            }

            var copyButton = event.target.closest('[data-tg-copy-code]');
            if (copyButton) {
                event.preventDefault();
                var code = document.getElementById('tg_link_code_val');
                if (code) { copyToClipboard(code.textContent.trim(), copyButton); }
                return;
            }

            var addButton = event.target.closest('[data-tg-add-chat-id]');
            if (addButton) {
                event.preventDefault();
                var input = document.getElementById('telegram_notify_chat_ids');
                var chatId = addButton.getAttribute('data-tg-add-chat-id') || '';
                if (!input || chatId === '') { return; }
                var values = input.value.split(/[\s,;]+/).filter(Boolean);
                if (values.indexOf(chatId) === -1) { values.push(chatId); }
                input.value = values.join(', ');
                input.dispatchEvent(new Event('input', { bubbles: true }));
                return;
            }

            var channelButton = event.target.closest('[data-tg-use-channel-id]');
            if (channelButton) {
                event.preventDefault();
                var channelInput = document.getElementById('tg_chat_id');
                var channelId = channelButton.getAttribute('data-tg-use-channel-id') || '';
                if (!channelInput || channelId === '') { return; }
                channelInput.value = channelId;
                channelInput.dispatchEvent(new Event('input', { bubbles: true }));
                channelInput.dispatchEvent(new Event('change', { bubbles: true }));
                channelInput.focus();
                document.querySelectorAll('[data-tg-use-channel-id]').forEach(function (button) {
                    button.classList.toggle('is-active', button === channelButton);
                    button.setAttribute('aria-pressed', button === channelButton ? 'true' : 'false');
                });
            }
        });
    })();

    /**
     * Номер для новой строки репитера: на единицу больше самого большого
     * индекса в именах полей. Считать по числу строк нельзя — после удаления
     * строки из середины номер повторится, и в POST останется только
     * последняя из двух одноимённых строк (введённое молча пропадает).
     */
    function nextRepeaterIndex(container, template) {
        // Образец имени берём из шаблона строки: у разных повторителей
        // индекс стоит по-разному («columns[0][heading]», «custom_fields[cf_0][key]»).
        var probe = template.content.querySelector('[name*="__INDEX__"]');
        if (!probe) {
            return container.children.length;
        }

        var escaped = probe.getAttribute('name').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        var pattern = new RegExp('^' + escaped.replace('__INDEX__', '(\\d+)') + '$');
        var max = -1;
        Array.prototype.forEach.call(container.querySelectorAll('[name]'), function (field) {
            var match = pattern.exec(field.getAttribute('name') || '');
            if (match) {
                max = Math.max(max, Number(match[1]));
            }
        });

        return max + 1;
    }

    /**
     * Разворачивает <template> репитера: клон содержимого с заменой
     * плейсхолдера __INDEX__ в атрибутах и текстовых узлах.
     */
    function instantiateRepeaterTemplate(template, index) {
        const fragment = template.content.cloneNode(true);
        const walker = document.createTreeWalker(fragment, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
        const marker = /__INDEX__/g;
        let node = walker.nextNode();

        while (node) {
            if (node.nodeType === Node.ELEMENT_NODE) {
                Array.prototype.forEach.call(node.attributes, function (attr) {
                    if (attr.value.indexOf('__INDEX__') !== -1) {
                        attr.value = attr.value.replace(marker, String(index));
                    }
                });
            } else if (node.nodeValue && node.nodeValue.indexOf('__INDEX__') !== -1) {
                node.nodeValue = node.nodeValue.replace(marker, String(index));
            }
            node = walker.nextNode();
        }

        return fragment;
    }

    // В редакторе блоков кнопки сортировки и удаления относятся ко всей
    // карточке, а не к отдельным полям. Собираем их в одну компактную панель:
    // иначе flex-репитер растягивал каждую кнопку в самостоятельную строку.
    function arrangeBlockRepeaterRow(row) {
        if (!row || !row.closest('.block-editor-form') || row.classList.contains('fb-card')
            || row.classList.contains('widget-slot-row') || row.querySelector(':scope > .repeater-row__actions')) {
            return;
        }
        var buttons = Array.from(row.querySelectorAll(':scope > [data-repeater-move], :scope > [data-repeater-remove]'));
        if (buttons.length === 0) { return; }
        var actions = document.createElement('div');
        actions.className = 'repeater-row__actions';
        buttons.forEach(function (button) { actions.appendChild(button); });
        row.appendChild(actions);
    }

    function arrangeBlockRepeaterRows(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('.block-editor-form .repeater-row').forEach(arrangeBlockRepeaterRow);
        if (scope.matches && scope.matches('.block-editor-form .repeater-row')) {
            arrangeBlockRepeaterRow(scope);
        }
    }

    function initBlockRepeaterLayout() {
        var editor = document.querySelector('.block-editor-form');
        if (!editor) { return; }
        arrangeBlockRepeaterRows(editor);
        if (window.MutationObserver) {
            new MutationObserver(function (records) {
                records.forEach(function (record) {
                    Array.from(record.addedNodes).forEach(function (node) {
                        if (node.nodeType === Node.ELEMENT_NODE) { arrangeBlockRepeaterRows(node); }
                    });
                });
            }).observe(editor, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBlockRepeaterLayout);
    } else {
        initBlockRepeaterLayout();
    }

    document.addEventListener('click', function (event) {
        const copyBtn = event.target.closest('[data-copy-link], [data-copy-text]');
        if (copyBtn) {
            event.preventDefault();
            const text = copyBtn.getAttribute('data-copy-link') || copyBtn.getAttribute('data-copy-text');
            if (text) {
                copyToClipboard(text, copyBtn);
                return;
            }
        }

        const addBtn = event.target.closest('[data-repeater-add]');
        if (addBtn) {
            event.preventDefault();
            const name = addBtn.getAttribute('data-repeater-add');
            const container = document.querySelector('[data-repeater="' + name + '"]');
            const template = document.querySelector('template[data-repeater-template="' + name + '"]');
            if (!container || !template) {
                return;
            }
            const maxRows = Number(container.getAttribute('data-repeater-max'));
            if (Number.isFinite(maxRows) && maxRows > 0 && container.children.length >= maxRows) {
                adminAlert('Максимум строк: ' + maxRows + '.');
                return;
            }
            const hasStoredIndex = container.hasAttribute('data-repeater-next-index');
            const storedIndex = Number(container.getAttribute('data-repeater-next-index'));
            const index = hasStoredIndex && Number.isFinite(storedIndex) && storedIndex >= 0
                ? storedIndex
                : nextRepeaterIndex(container, template);
            if (hasStoredIndex) {
                container.setAttribute('data-repeater-next-index', String(index + 1));
            }
            const wrapper = document.createElement('div');
            wrapper.className = 'repeater-row';
            // Клонируем содержимое <template> и подставляем номер строки в
            // узлах: чтение innerHTML с обратной записью — это повторный
            // разбор разметки, на котором данные становятся HTML.
            wrapper.appendChild(instantiateRepeaterTemplate(template, index));
            container.appendChild(wrapper);
            arrangeBlockRepeaterRow(wrapper);
            if (window.__enhanceIconFields) { window.__enhanceIconFields(wrapper); }
            return;
        }

        const removeBtn = event.target.closest('[data-repeater-remove]');
        if (removeBtn) {
            event.preventDefault();
            const row = removeBtn.closest('.repeater-row');
            if (row) {
                row.remove();
            }
        }
    });

    // Стилизованное модальное подтверждение — замена нативного window.confirm.
    // Возвращает Promise<boolean>. Доступно и другим скриптам как window.adminConfirm.
    function adminConfirm(message) {
        return new Promise(function (resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'admin-modal-overlay';
            overlay.innerHTML =
                '<div class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="admin-modal-msg">'
                + '<div class="admin-modal__body">'
                + '<div class="admin-modal__icon" aria-hidden="true">?</div>'
                + '<p class="admin-modal__msg" id="admin-modal-msg"></p>'
                + '</div>'
                + '<div class="admin-modal__actions">'
                + '<button type="button" class="btn admin-modal__cancel">Отмена</button>'
                + '<button type="button" class="btn btn--primary admin-modal__ok">Подтвердить</button>'
                + '</div>'
                + '</div>';
            overlay.querySelector('.admin-modal__msg').textContent = message;
            document.body.appendChild(overlay);
            document.body.classList.add('has-modal-open');
            requestAnimationFrame(function () { overlay.classList.add('is-open'); });

            var okBtn = overlay.querySelector('.admin-modal__ok');
            var cancelBtn = overlay.querySelector('.admin-modal__cancel');
            okBtn.focus();

            function close(result) {
                overlay.classList.remove('is-open');
                document.removeEventListener('keydown', onKey);
                document.body.classList.remove('has-modal-open');
                setTimeout(function () { overlay.remove(); }, 150);
                resolve(result);
            }
            function onKey(e) {
                if (e.key === 'Escape') { close(false); }
                else if (e.key === 'Enter') { close(true); }
            }
            okBtn.addEventListener('click', function () { close(true); });
            cancelBtn.addEventListener('click', function () { close(false); });
            overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(false); } });
            document.addEventListener('keydown', onKey);
        });
    }
    window.adminConfirm = adminConfirm;

    function adminAlert(message) {
        return new Promise(function (resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'admin-modal-overlay';
            overlay.innerHTML =
                '<div class="admin-modal" role="dialog" aria-modal="true" aria-labelledby="admin-modal-msg">'
                + '<div class="admin-modal__body">'
                + '<div class="admin-modal__icon" aria-hidden="true">ℹ</div>'
                + '<p class="admin-modal__msg" id="admin-modal-msg"></p>'
                + '</div>'
                + '<div class="admin-modal__actions">'
                + '<button type="button" class="btn btn--primary admin-modal__ok">ОК</button>'
                + '</div>'
                + '</div>';
            overlay.querySelector('.admin-modal__msg').textContent = message;
            document.body.appendChild(overlay);
            document.body.classList.add('has-modal-open');
            requestAnimationFrame(function () { overlay.classList.add('is-open'); });

            var okBtn = overlay.querySelector('.admin-modal__ok');
            okBtn.focus();

            function close() {
                overlay.classList.remove('is-open');
                document.removeEventListener('keydown', onKey);
                document.body.classList.remove('has-modal-open');
                setTimeout(function () { overlay.remove(); }, 150);
                resolve();
            }
            function onKey(e) {
                if (e.key === 'Escape' || e.key === 'Enter') { close(); }
            }
            okBtn.addEventListener('click', close);
            overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
            document.addEventListener('keydown', onKey);
        });
    }
    window.adminAlert = adminAlert;

    document.querySelectorAll('[data-confirm]').forEach(function (element) {
        if (element.tagName === 'FORM') {
            element.addEventListener('submit', function (event) {
                if (element.dataset.confirmed === '1') {
                    element.dataset.confirmed = '0';
                    return;
                }
                event.preventDefault();
                adminConfirm(element.getAttribute('data-confirm')).then(function (ok) {
                    if (!ok) { return; }
                    element.dataset.confirmed = '1';
                    if (typeof element.requestSubmit === 'function') { element.requestSubmit(); }
                    else { element.submit(); }
                });
            });
        } else {
            element.addEventListener('click', function (event) {
                if (element.dataset.confirmed === '1') {
                    element.dataset.confirmed = '0';
                    return;
                }
                event.preventDefault();
                adminConfirm(element.getAttribute('data-confirm')).then(function (ok) {
                    if (!ok) { return; }
                    element.dataset.confirmed = '1';
                    if (element.tagName === 'A') {
                        window.location.href = element.href;
                    } else if (element.type === 'submit' && element.form) {
                        if (typeof element.form.requestSubmit === 'function') {
                            element.form.requestSubmit(element);
                        } else {
                            element.form.submit();
                        }
                    } else {
                        element.click();
                    }
                });
            });
        }
    });

    // Применение шаблона страницы: режим «заменить» требует подтверждения.
    document.querySelectorAll('[data-snippet-insert]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var mode = form.querySelector('select[name=mode]');
            if (mode && mode.value === 'replace') {
                if (form.dataset.confirmed === '1') {
                    form.dataset.confirmed = '0';
                    return;
                }
                event.preventDefault();
                adminConfirm('Заменить все текущие блоки этого языка блоками из шаблона? Перед заменой будет создана автокопия. Другие языки не изменятся.').then(function (ok) {
                    if (ok) {
                        form.dataset.confirmed = '1';
                        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
                        else { form.submit(); }
                    }
                });
            }
        });
    });

    // Чанковая загрузка больших файлов через File API.
    var chunkBtn = document.getElementById('chunk_upload_btn');
    if (chunkBtn) {
        chunkBtn.addEventListener('click', function () {
            var input = document.getElementById('chunk_file');
            var progress = document.getElementById('chunk_progress');
            var access = document.getElementById('chunk_access');
            if (!input.files || !input.files.length) {
                progress.textContent = 'Выберите файл.';
                return;
            }
            var file = input.files[0];
            var chunkSize = 1024 * 1024; // 1 МБ
            var total = Math.ceil(file.size / chunkSize);
            var uploadId = '';
            for (var i = 0; i < 32; i++) { uploadId += Math.floor(Math.random() * 16).toString(16); }
            var csrf = chunkBtn.getAttribute('data-csrf');
            chunkBtn.disabled = true;

            function sendChunk(index) {
                var start = index * chunkSize;
                var blob = file.slice(start, Math.min(start + chunkSize, file.size));
                var fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('upload_id', uploadId);
                fd.append('index', String(index));
                fd.append('total', String(total));
                fd.append('name', file.name);
                fd.append('access_type', access.value);
                fd.append('chunk', blob);

                fetch('/admin/files/chunk', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); })
                    .then(function (res) {
                        if (!res.ok) {
                            progress.textContent = 'Ошибка: ' + (res.error || 'неизвестная');
                            chunkBtn.disabled = false;
                            return;
                        }
                        if (res.done) {
                            progress.textContent = 'Готово! Файл загружен. Обновите страницу.';
                            chunkBtn.disabled = false;
                            setTimeout(function () { window.location.reload(); }, 800);
                            return;
                        }
                        progress.textContent = 'Загрузка… ' + Math.round(((index + 1) / total) * 100) + '%';
                        sendChunk(index + 1);
                    })
                    .catch(function () {
                        progress.textContent = 'Сетевая ошибка при загрузке.';
                        chunkBtn.disabled = false;
                    });
            }

            progress.textContent = 'Загрузка… 0%';
            sendChunk(0);
        });
    }

    // --- Массовый выбор в списках (задача 91) ---
    document.querySelectorAll('[data-select-all]').forEach(function (master) {
        var table = master.closest('table');
        if (!table) { return; }
        var items = table.querySelectorAll('[data-bulk-item]');
        var formId = items.length ? items[0].getAttribute('form') : '';
        var bulkForm = formId ? document.getElementById(formId) : null;
        var counter = bulkForm ? bulkForm.querySelector('[data-bulk-count]') : null;
        function refresh() {
            var checked = table.querySelectorAll('[data-bulk-item]:checked').length;
            if (counter) { counter.textContent = checked + ' выбрано'; }
            master.checked = checked > 0 && checked === items.length;
            master.indeterminate = checked > 0 && checked < items.length;
        }
        master.addEventListener('change', function () {
            items.forEach(function (i) { i.checked = master.checked; });
            refresh();
        });
        items.forEach(function (i) { i.addEventListener('change', refresh); });
    });

    // Не отправлять bulk-форму без выбранного действия/записей.
    document.querySelectorAll('[data-bulk-form]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.confirmed === '1') {
                form.dataset.confirmed = '0';
                return;
            }
            var formId = form.id;
            var associated = Array.prototype.filter.call(document.querySelectorAll('[data-bulk-item]:checked'), function (item) {
                return item.getAttribute('form') === formId;
            });
            var anyChecked = associated.length > 0;
            var action = form.querySelector('[name="bulk_action"]');
            if (!anyChecked) {
                e.preventDefault();
                adminAlert('Выберите хотя бы одну запись.');
                return;
            }
            if (action && !action.value) {
                e.preventDefault();
                adminAlert('Выберите действие.');
                return;
            }
            // «В корзину» ещё можно откатить, «Удалить» — нет (у видео корзины
            // не бывает), поэтому спрашиваем и там, и там, но разными словами.
            if (action && (action.value === 'trash' || action.value === 'delete')) {
                e.preventDefault();
                var question = action.value === 'delete'
                    ? 'Удалить выбранные записи? Это действие необратимо.'
                    : 'Переместить выбранные записи в корзину?';
                adminConfirm(question).then(function (ok) {
                    if (ok) {
                        form.dataset.confirmed = '1';
                        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); }
                        else { form.submit(); }
                    }
                });
            }
        });
    });

    // --- Быстрый глобальный поиск (задача 92, Ctrl+K) ---
    (function () {
        var box = document.querySelector('[data-search]');
        if (!box) { return; }
        var input = box.querySelector('[data-search-input]');
        var results = box.querySelector('[data-search-results]');
        var timer = null, lastQuery = '';

        function render(items) {
            if (!items.length) { results.innerHTML = '<div class="admin-search__empty">Ничего не найдено</div>'; }
            else {
                // Строки ответа собираем узлами: подстановка в HTML-строку
                // позволяла бы выйти из атрибута href и вставить разметку.
                results.textContent = '';
                items.forEach(function (r) {
                    var link = document.createElement('a');
                    link.className = 'admin-search__item';
                    // Только собственные адреса панели: чужая схема в href — XSS.
                    link.setAttribute('href', /^\/(?!\/)/.test(String(r.url || '')) ? r.url : '#');

                    var type = document.createElement('span');
                    type.className = 'admin-search__type';
                    type.textContent = r.type || '';

                    var title = document.createElement('span');
                    title.className = 'admin-search__title';
                    title.textContent = r.title || '';

                    link.appendChild(type);
                    link.appendChild(title);
                    results.appendChild(link);
                });
            }
            results.hidden = false;
        }

        function search() {
            var q = input.value.trim();
            if (q === lastQuery) { return; }
            lastQuery = q;
            if (q.length < 2) { results.hidden = true; results.innerHTML = ''; return; }
            fetch('/admin/search?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) { render(data.results || []); })
                .catch(function () { results.hidden = true; });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(search, 200);
        });
        input.addEventListener('focus', function () { if (results.innerHTML) { results.hidden = false; } });
        document.addEventListener('click', function (e) {
            if (!box.contains(e.target)) { results.hidden = true; }
        });
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault(); input.focus(); input.select();
            }
            if (e.key === 'Escape') { results.hidden = true; input.blur(); }
        });
    })();

    // --- Медиабиблиотека: выбор или загрузка файла прямо из формы ---
    (function () {
        var modal = document.querySelector('[data-media-modal]');
        if (!modal) { return; }
        var dialog = modal.querySelector('.media-modal__dialog');
        var grid = modal.querySelector('[data-media-grid]');
        var nav = modal.querySelector('[data-media-nav]');
        var uploadBox = modal.querySelector('[data-media-upload]');
        var uploadInput = modal.querySelector('[data-media-upload-input]');
        var uploadStatus = modal.querySelector('[data-media-upload-status]');
        var searchInput = modal.querySelector('[data-media-search]');
        var sortSelect = modal.querySelector('[data-media-sort]');
        var selectedInfo = modal.querySelector('[data-media-selected-info]');
        var selectedThumb = modal.querySelector('[data-media-selected-thumb]');
        var selectedDrop = modal.querySelector('[data-media-selected-drop]');
        var selectBtn = modal.querySelector('[data-media-select-btn]');
        var details = modal.querySelector('[data-media-details]');
        var detailsBody = modal.querySelector('[data-media-details-body]');
        var viewButtons = modal.querySelectorAll('[data-media-view]');

        var currentTarget = null;
        var currentCallback = null;
        var loaded = false;
        var loadedKey = null;
        var baseType = 'image';
        var currentType = 'image';
        var currentSort = 'date_desc';
        var currentMultiple = false;
        var selectedUrl = null;
        var selectedName = null;
        var selectedUrls = [];
        var selectedNames = {};
        var libraryItems = [];
        var counts = {};
        var detailsUrl = null;
        var lastTrigger = null;
        var dropDepth = 0;
        var searchTimer = null;

        var typeOptions = {
            image: { accept: '.jpg,.jpeg,.png,.gif,.webp,.svg', label: 'изображение' },
            raster: { accept: '.jpg,.jpeg,.png,.gif,.webp', label: 'изображение' },
            svg: { accept: '.svg,image/svg+xml', label: 'SVG-файл' },
            video: { accept: '.mp4,video/mp4', label: 'видео MP4' },
            audio: { accept: '.mp3,.aac,.ogg,.wav,.m4a,audio/*', label: 'аудиофайл' },
            document: { accept: '.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip', label: 'документ' },
            all_files: { accept: '', label: 'файл' },
            all: { accept: '', label: 'файл' }
        };

        var typeLabels = {
            all_files: 'Все файлы',
            all: 'Все медиа',
            image: 'Изображения',
            raster: 'Фотографии',
            svg: 'Векторные',
            video: 'Видео',
            audio: 'Аудио',
            document: 'Документы'
        };

        var typeIcons = {
            all_files: 'folder',
            all: 'folder',
            image: 'photo',
            raster: 'photo',
            svg: 'vector',
            video: 'movie',
            audio: 'music',
            document: 'file-text'
        };

        // Колонка видов показывает только то, что поле готово принять: у поля
        // изображения выбор из документов ничего не даёт, кроме битой картинки.
        var typeBranches = {
            image: ['image', 'raster', 'svg'],
            raster: ['raster'],
            svg: ['svg'],
            video: ['video'],
            audio: ['audio'],
            document: ['document'],
            all: ['all', 'image', 'raster', 'svg', 'video', 'audio'],
            all_files: ['all_files', 'image', 'raster', 'svg', 'video', 'audio', 'document']
        };

        function icon(name, size) {
            return window.asdrTablerIcon ? window.asdrTablerIcon(name, size || 16) : '';
        }

        function formatSize(bytes) {
            var value = Number(bytes || 0);
            if (!value) { return ''; }
            if (value < 1024) { return value + ' Б'; }
            if (value < 1024 * 1024) { return Math.round(value / 1024) + ' КБ'; }
            return (value / (1024 * 1024)).toFixed(value < 10 * 1024 * 1024 ? 1 : 0) + ' МБ';
        }

        function formatDate(value) {
            var raw = String(value || '').replace(' ', 'T');
            var date = raw ? new Date(raw) : null;
            if (!date || isNaN(date.getTime())) { return ''; }
            return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
                + ' в ' + date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        }

        function extOf(name) {
            var parts = String(name || '').split('.');
            return parts.length > 1 ? parts.pop().toUpperCase() : 'ФАЙЛ';
        }

        function isImageUrl(url) {
            return /\.(jpe?g|png|gif|svg|webp|avif)$/i.test(String(url || ''));
        }

        function isVideoUrl(url) {
            return /\.(mp4|webm|ogg|mov|m4v)$/i.test(String(url || ''));
        }

        function itemByUrl(url) {
            for (var i = 0; i < libraryItems.length; i++) {
                if (libraryItems[i].url === url) { return libraryItems[i]; }
            }
            return null;
        }

        function setUploadStatus(message, state) {
            if (!uploadStatus) { return; }
            uploadStatus.textContent = message || '';
            uploadStatus.classList.toggle('is-visible', Boolean(message));
            uploadStatus.classList.toggle('is-error', state === 'error');
            uploadStatus.classList.toggle('is-success', state === 'success');
        }

        function selectUrl(url) {
            if (!url) { return; }
            if (currentCallback) {
                currentCallback(currentMultiple ? [url] : url);
            } else if (currentTarget) {
                currentTarget.value = url;
                currentTarget.dispatchEvent(new Event('change', { bubbles: true }));
                currentTarget.dispatchEvent(new Event('input', { bubbles: true }));
            }
            close();
        }

        function renderNav() {
            if (!nav) { return; }
            var branch = typeBranches[baseType] || typeBranches.image;
            nav.textContent = '';

            branch.forEach(function (key) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'media-modal__navitem' + (key === currentType ? ' is-active' : '');
                button.setAttribute('data-media-type-filter', key);
                button.setAttribute('aria-pressed', key === currentType ? 'true' : 'false');
                button.innerHTML = '<span class="media-modal__navicon">' + icon(typeIcons[key] || 'folder', 17) + '</span>'
                    + '<span class="media-modal__navlabel"></span>'
                    + '<span class="media-modal__navcount"></span>';
                button.querySelector('.media-modal__navlabel').textContent = typeLabels[key] || key;
                button.querySelector('.media-modal__navcount').textContent = counts[key] === undefined ? '' : String(counts[key]);
                button.addEventListener('click', function () {
                    if (currentType === key) { return; }
                    currentType = key;
                    renderNav();
                    loadLibrary(true);
                });
                nav.appendChild(button);
            });
        }

        function updateSelectedUI() {
            var selectedCount = currentMultiple ? selectedUrls.length : (selectedUrl ? 1 : 0);
            if (selectedThumb) { selectedThumb.textContent = ''; }
            if (selectedDrop) { selectedDrop.hidden = !selectedCount; }

            if (!selectedCount) {
                if (selectedInfo) { selectedInfo.textContent = 'Файл не выбран'; }
                if (selectBtn) {
                    selectBtn.disabled = true;
                    selectBtn.textContent = currentMultiple ? 'Выбрать файлы' : 'Выбрать файл';
                }
            } else {
                if (selectedInfo) {
                    selectedInfo.textContent = currentMultiple
                        ? 'Выбрано файлов: ' + selectedCount
                        : (selectedName || selectedUrl);

                    var meta = currentMultiple ? null : itemByUrl(selectedUrl);
                    if (meta) {
                        var parts = [extOf(meta.name)];
                        if (formatSize(meta.size)) { parts.push(formatSize(meta.size)); }
                        var metaNode = document.createElement('span');
                        metaNode.className = 'media-modal__selected-meta';
                        metaNode.textContent = parts.join(' · ');
                        selectedInfo.appendChild(metaNode);
                    }
                }
                if (selectedThumb && !currentMultiple && isImageUrl(selectedUrl)) {
                    var thumb = document.createElement('img');
                    thumb.src = encodeURI(selectedUrl);
                    thumb.alt = '';
                    selectedThumb.appendChild(thumb);
                }
                if (selectBtn) {
                    selectBtn.disabled = false;
                    selectBtn.textContent = currentMultiple
                        ? 'Выбрать файлы (' + selectedCount + ')'
                        : 'Выбрать файл';
                }
            }

            grid.querySelectorAll('.media-modal__item').forEach(function (fig) {
                var itemUrl = fig.getAttribute('data-url');
                var isThis = currentMultiple ? selectedUrls.indexOf(itemUrl) !== -1 : itemUrl === selectedUrl;
                fig.classList.toggle('is-selected', isThis);
                fig.setAttribute('aria-pressed', isThis ? 'true' : 'false');
            });
        }

        // Правая колонка живёт данными выдачи: отдельного запроса за деталями
        // нет, поэтому она не может разойтись с карточкой в сетке.
        function renderDetails(item) {
            if (!details || !detailsBody) { return; }
            if (!item) {
                details.hidden = true;
                detailsUrl = null;
                return;
            }

            detailsUrl = item.url;
            details.hidden = false;
            detailsBody.textContent = '';

            var preview = document.createElement('div');
            preview.className = 'media-modal__details-preview';
            if (isImageUrl(item.url)) {
                var img = document.createElement('img');
                img.src = encodeURI(item.url);
                img.alt = '';
                img.addEventListener('load', function () {
                    var box = detailsBody.querySelector('[data-media-details-size]');
                    if (box && img.naturalWidth) {
                        box.textContent = img.naturalWidth + ' × ' + img.naturalHeight;
                    }
                });
                preview.appendChild(img);
            } else {
                var badge = document.createElement('span');
                badge.className = 'media-modal__fileicon';
                badge.textContent = extOf(item.name);
                preview.appendChild(badge);
            }
            detailsBody.appendChild(preview);

            var name = document.createElement('p');
            name.className = 'media-modal__details-name';
            name.textContent = item.name;
            detailsBody.appendChild(name);

            var meta = document.createElement('p');
            meta.className = 'media-modal__details-meta';
            meta.textContent = [extOf(item.name), formatSize(item.size)].filter(Boolean).join(' · ');
            detailsBody.appendChild(meta);

            if (formatDate(item.created_at)) {
                var uploaded = document.createElement('p');
                uploaded.className = 'media-modal__details-meta';
                uploaded.textContent = 'Загружен ' + formatDate(item.created_at);
                detailsBody.appendChild(uploaded);
            }

            var actions = document.createElement('div');
            actions.className = 'media-modal__details-actions';

            var openLink = document.createElement('a');
            openLink.className = 'btn btn--small';
            openLink.href = item.url;
            openLink.target = '_blank';
            openLink.rel = 'noopener';
            openLink.innerHTML = icon('external-link', 15) + '<span>Открыть</span>';
            actions.appendChild(openLink);

            var copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'btn btn--small';
            copyBtn.innerHTML = icon('copy', 15) + '<span>Копировать ссылку</span>';
            copyBtn.addEventListener('click', function () {
                if (window.asdrCopyToClipboard) { window.asdrCopyToClipboard(item.url, copyBtn); }
            });
            actions.appendChild(copyBtn);
            detailsBody.appendChild(actions);

            var link = document.createElement('input');
            link.type = 'text';
            link.readOnly = true;
            link.className = 'media-modal__details-link';
            link.value = item.url;
            link.setAttribute('aria-label', 'Ссылка на файл');
            detailsBody.appendChild(fieldRow('Ссылка на файл', link));

            var alt = document.createElement('input');
            alt.type = 'text';
            alt.value = item.alt_text || '';
            alt.setAttribute('data-media-alt', '');
            alt.setAttribute('aria-label', 'Альтернативный текст');
            detailsBody.appendChild(fieldRow('Альтернативный текст', alt));

            var caption = document.createElement('input');
            caption.type = 'text';
            caption.value = item.caption || '';
            caption.setAttribute('data-media-caption', '');
            caption.setAttribute('aria-label', 'Подпись');
            detailsBody.appendChild(fieldRow('Подпись', caption));

            var save = document.createElement('button');
            save.type = 'button';
            save.className = 'btn btn--small';
            save.textContent = 'Сохранить описание';
            save.addEventListener('click', function () { saveMetadata(item, alt.value, caption.value, save); });
            detailsBody.appendChild(save);

            var info = document.createElement('dl');
            info.className = 'media-modal__details-info';
            info.innerHTML = '<dt>Тип файла</dt><dd data-media-details-mime></dd>'
                + '<dt>Размер</dt><dd data-media-details-weight></dd>'
                + '<dt>Разрешение</dt><dd data-media-details-size>—</dd>';
            info.querySelector('[data-media-details-mime]').textContent = item.mime_type || extOf(item.name);
            info.querySelector('[data-media-details-weight]').textContent = formatSize(item.size) || '—';
            detailsBody.appendChild(info);
        }

        function fieldRow(labelText, control) {
            var row = document.createElement('div');
            row.className = 'media-modal__details-field';
            var label = document.createElement('span');
            label.textContent = labelText;
            row.appendChild(label);
            row.appendChild(control);
            return row;
        }

        function saveMetadata(item, altText, captionText, button) {
            var token = uploadBox ? uploadBox.getAttribute('data-csrf') : '';
            if (!token || !item.id) { return; }
            var fd = new FormData();
            fd.append('csrf_token', token);
            fd.append('metadata_id', String(item.id));
            fd.append('alt_text', altText || '');
            fd.append('caption', captionText || '');
            button.disabled = true;

            fetch('/admin/files/upload', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    button.disabled = false;
                    if (!res.ok) { throw new Error(res.error || 'Ошибка'); }
                    item.alt_text = res.item ? res.item.alt_text : altText;
                    item.caption = res.item ? res.item.caption : captionText;
                    setUploadStatus('Описание сохранено.', 'success');
                })
                .catch(function (err) {
                    button.disabled = false;
                    setUploadStatus('Не удалось сохранить описание: ' + err.message, 'error');
                });
        }

        function renderLibraryItems(items) {
            libraryItems = items || [];
            grid.textContent = '';

            var zone = document.createElement('button');
            zone.type = 'button';
            zone.className = 'media-modal__dropcard';
            zone.innerHTML = '<span class="media-modal__dropcard-icon">' + icon('cloud-upload', 26) + '</span>'
                + '<span class="media-modal__dropcard-title">Загрузить файлы</span>'
                + '<span class="media-modal__dropcard-hint">или перетащите их сюда</span>';
            zone.addEventListener('click', function () { if (uploadInput) { uploadInput.click(); } });
            grid.appendChild(zone);

            if (!libraryItems.length) {
                var empty = document.createElement('div');
                empty.className = 'media-modal__empty';
                empty.textContent = searchInput && searchInput.value.trim()
                    ? 'Ничего не найдено. Измените запрос или загрузите файл.'
                    : 'В библиотеке нет подходящих файлов.';
                grid.appendChild(empty);
                return;
            }

            libraryItems.forEach(function (it) {
                var fig = document.createElement('button');
                fig.type = 'button';
                fig.className = 'media-modal__item';
                fig.title = it.name;
                fig.setAttribute('data-url', it.url);

                if ((currentMultiple && selectedUrls.indexOf(it.url) !== -1) || (!currentMultiple && selectedUrl === it.url)) {
                    fig.classList.add('is-selected');
                }

                var thumb = document.createElement('span');
                thumb.className = 'media-modal__thumb';
                if (isImageUrl(it.url)) {
                    var img = document.createElement('img');
                    // Карточка показывается размером в сотню пикселей: берём
                    // мелкий вариант, если сервер его прислал.
                    img.src = encodeURI(it.thumb || it.url);
                    img.alt = '';
                    img.loading = 'lazy';
                    thumb.appendChild(img);
                } else {
                    fig.classList.add('media-modal__item--file');
                    var badge = document.createElement('span');
                    badge.className = 'media-modal__fileicon';
                    badge.textContent = extOf(it.name);
                    thumb.appendChild(badge);
                }
                // Знак «видео» ставим только поверх кадра: рядом с плашкой
                // расширения он был вторым обозначением того же самого.
                if (isVideoUrl(it.url) && isImageUrl(it.url)) {
                    var play = document.createElement('span');
                    play.className = 'media-modal__play';
                    play.innerHTML = icon('player-play', 18);
                    thumb.appendChild(play);
                }
                fig.appendChild(thumb);

                var caption = document.createElement('span');
                caption.className = 'media-modal__caption';
                var title = document.createElement('span');
                title.className = 'media-modal__caption-name';
                title.textContent = it.name;
                var sub = document.createElement('span');
                sub.className = 'media-modal__caption-meta';
                sub.textContent = [extOf(it.name), formatSize(it.size)].filter(Boolean).join(' · ');
                caption.appendChild(title);
                caption.appendChild(sub);
                fig.appendChild(caption);

                fig.addEventListener('click', function () {
                    if (currentMultiple) {
                        var selectedIndex = selectedUrls.indexOf(it.url);
                        if (selectedIndex === -1) {
                            selectedUrls.push(it.url);
                            selectedNames[it.url] = it.name;
                        } else {
                            selectedUrls.splice(selectedIndex, 1);
                            delete selectedNames[it.url];
                        }
                    } else {
                        selectedUrl = it.url;
                        selectedName = it.name;
                    }
                    renderDetails(it);
                    updateSelectedUI();
                });

                fig.addEventListener('dblclick', function () {
                    if (!currentMultiple) { selectUrl(it.url); }
                });

                grid.appendChild(fig);
            });

            if (detailsUrl) {
                var stillHere = itemByUrl(detailsUrl);
                renderDetails(stillHere);
            }
        }

        function loadLibrary(force) {
            var query = searchInput ? searchInput.value.trim() : '';
            var key = currentType + '|' + currentSort + '|' + query;
            if (!force && loaded && loadedKey === key) { return Promise.resolve(); }
            loaded = false; loadedKey = key;
            grid.setAttribute('aria-busy', 'true');
            grid.innerHTML = '<div class="media-modal__empty">Загрузка…</div>';

            var url = '/admin/media/list?type=' + encodeURIComponent(currentType)
                + '&sort=' + encodeURIComponent(currentSort)
                + (query ? '&q=' + encodeURIComponent(query) : '');

            return fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    loaded = true;
                    grid.setAttribute('aria-busy', 'false');
                    counts = data.counts || {};
                    renderNav();
                    renderLibraryItems(data.items || []);
                    updateSelectedUI();
                })
                .catch(function () {
                    grid.setAttribute('aria-busy', 'false');
                    grid.innerHTML = '<div class="media-modal__empty">Ошибка загрузки.</div>';
                });
        }

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () { loadLibrary(true); }, 250);
            });
        }

        if (sortSelect) {
            sortSelect.addEventListener('change', function () {
                currentSort = sortSelect.value;
                loadLibrary(true);
            });
        }

        viewButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var view = button.getAttribute('data-media-view');
                viewButtons.forEach(function (other) {
                    other.classList.toggle('is-active', other === button);
                });
                grid.classList.toggle('media-modal__grid--list', view === 'list');
            });
        });

        if (selectedDrop) {
            selectedDrop.addEventListener('click', function () {
                selectedUrl = null;
                selectedName = null;
                selectedUrls = [];
                selectedNames = {};
                updateSelectedUI();
            });
        }

        if (details) {
            var detailsClose = modal.querySelector('[data-media-details-close]');
            if (detailsClose) {
                detailsClose.addEventListener('click', function () { renderDetails(null); });
            }
        }

        if (selectBtn) {
            selectBtn.addEventListener('click', function () {
                if (currentMultiple) {
                    if (selectedUrls.length && currentCallback) {
                        currentCallback(selectedUrls.slice());
                        close();
                    }
                } else if (selectedUrl) {
                    selectUrl(selectedUrl);
                }
            });
        }

        function open(targetSelector, callback, type, multiple) {
            currentTarget = targetSelector ? document.querySelector(targetSelector) : null;
            currentCallback = callback || null;
            baseType = typeOptions[type] ? type : 'image';
            currentType = baseType;
            currentMultiple = Boolean(multiple);
            selectedUrl = null;
            selectedName = null;
            selectedUrls = [];
            selectedNames = {};
            counts = {};
            renderDetails(null);
            renderNav();
            updateSelectedUI();

            var options = typeOptions[currentType] || typeOptions.all;
            if (uploadInput) {
                uploadInput.value = '';
                uploadInput.accept = options.accept;
            }
            setUploadStatus('');
            modal.hidden = false;
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }
            loadLibrary(true);
        }

        function close() {
            if (modal.hidden) { return; }
            modal.hidden = true;
            modal.classList.remove('is-dropping');
            dropDepth = 0;
            currentCallback = null;
            currentMultiple = false;
            renderDetails(null);
            if (lastTrigger && lastTrigger.isConnected) { lastTrigger.focus(); }
            lastTrigger = null;
        }

        function uploadFile(file) {
            if (!uploadBox || !file) { return; }
            if (!file.size || file.size > 200 * 1024 * 1024) {
                setUploadStatus('Неверный размер файла (макс 200 МБ).', 'error');
                return;
            }

            var chunkSize = 1024 * 1024;
            var total = Math.ceil(file.size / chunkSize);
            var uploadId = '';
            for (var i = 0; i < 32; i++) { uploadId += Math.floor(Math.random() * 16).toString(16); }

            if (uploadInput) { uploadInput.disabled = true; }
            setUploadStatus('Загрузка… 0%');

            function sendChunk(index) {
                var fd = new FormData();
                fd.append('csrf_token', uploadBox.getAttribute('data-csrf'));
                fd.append('upload_id', uploadId);
                fd.append('index', String(index));
                fd.append('total', String(total));
                fd.append('name', file.name);
                fd.append('access_type', 'public');
                fd.append('chunk', file.slice(index * chunkSize, Math.min((index + 1) * chunkSize, file.size)));

                fetch('/admin/files/chunk', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.ok) { throw new Error(res.error || 'Ошибка загрузки'); }
                        if (res.done) {
                            if (uploadInput) { uploadInput.disabled = false; uploadInput.value = ''; }
                            setUploadStatus('Файл загружен.', 'success');
                            loadLibrary(true).then(function () {
                                if (!res.url) { return; }
                                // Файл загружали, чтобы его поставить: в поле
                                // он уходит сразу, в галерее — просто
                                // отмечается в выборе.
                                if (!currentMultiple) {
                                    selectUrl(res.url);
                                    return;
                                }
                                if (selectedUrls.indexOf(res.url) === -1) {
                                    selectedUrls.push(res.url);
                                    selectedNames[res.url] = file.name;
                                }
                                renderDetails(itemByUrl(res.url));
                                updateSelectedUI();
                            });
                            return;
                        }
                        setUploadStatus('Загрузка… ' + Math.round(((index + 1) / total) * 100) + '%');
                        sendChunk(index + 1);
                    })
                    .catch(function (err) {
                        if (uploadInput) { uploadInput.disabled = false; }
                        setUploadStatus('Ошибка: ' + err.message, 'error');
                    });
            }

            sendChunk(0);
        }

        if (uploadInput) {
            uploadInput.addEventListener('change', function () {
                if (!uploadInput.files || !uploadInput.files.length) { return; }
                uploadFile(uploadInput.files[0]);
            });
        }

        // Файл можно бросить в любое место окна: раньше для этого нужно было
        // сначала переключиться на вкладку загрузки и найти там область.
        if (dialog) {
            dialog.addEventListener('dragenter', function (event) {
                if (modal.hidden || !event.dataTransfer) { return; }
                event.preventDefault();
                dropDepth++;
                modal.classList.add('is-dropping');
            });
            dialog.addEventListener('dragover', function (event) {
                if (modal.hidden) { return; }
                event.preventDefault();
                if (event.dataTransfer) { event.dataTransfer.dropEffect = 'copy'; }
            });
            dialog.addEventListener('dragleave', function () {
                dropDepth = Math.max(0, dropDepth - 1);
                if (!dropDepth) { modal.classList.remove('is-dropping'); }
            });
            dialog.addEventListener('drop', function (event) {
                if (modal.hidden) { return; }
                event.preventDefault();
                dropDepth = 0;
                modal.classList.remove('is-dropping');
                if (event.dataTransfer && event.dataTransfer.files.length) {
                    uploadFile(event.dataTransfer.files[0]);
                }
            });
        }

        // Стрелками ходим по карточкам, Enter выбирает файл: без этого до
        // нужного кадра в конце библиотеки приходилось долго жать Tab.
        grid.addEventListener('keydown', function (event) {
            var card = event.target.closest('.media-modal__item');
            if (!card) { return; }
            var cards = Array.prototype.filter.call(
                grid.querySelectorAll('.media-modal__item'),
                function (node) { return !node.hidden; }
            );
            var index = cards.indexOf(card);
            if (index === -1) { return; }

            if (event.key === 'Enter') {
                event.preventDefault();
                var url = card.getAttribute('data-url');
                if (currentMultiple) { card.click(); } else if (url) { selectUrl(url); }
                return;
            }

            var perRow = 1;
            if (cards.length > 1) {
                var top = cards[0].getBoundingClientRect().top;
                perRow = cards.filter(function (node) {
                    return Math.abs(node.getBoundingClientRect().top - top) < 2;
                }).length || 1;
            }

            var next = null;
            if (event.key === 'ArrowRight') { next = cards[index + 1]; }
            if (event.key === 'ArrowLeft') { next = cards[index - 1]; }
            if (event.key === 'ArrowDown') { next = cards[index + perRow]; }
            if (event.key === 'ArrowUp') { next = cards[index - perRow]; }
            if (event.key === 'Home') { next = cards[0]; }
            if (event.key === 'End') { next = cards[cards.length - 1]; }
            if (!next) { return; }

            event.preventDefault();
            next.focus();
        });
        // Публичный API для редактора: выбор изображения/SVG с колбэком.
        window.MediaPicker = {
            pick: function (cb) { open(null, cb, 'image', false); },
            pickMany: function (cb) { open(null, cb, 'image', true); },
            pickSvg: function (cb) { open(null, cb, 'svg', false); }
        };

        // Галерея новости: переиспользование нескольких изображений из
        // медиабиблиотеки без повторной загрузки файлов.
        document.addEventListener('click', function (e) {
            var galleryPick = e.target.closest('[data-media-gallery-pick]');
            if (!galleryPick) { return; }
            e.preventDefault();
            var gallery = galleryPick.closest('[data-media-gallery]');
            var selection = gallery ? gallery.querySelector('[data-media-gallery-selection]') : null;
            if (!selection) { return; }

            window.MediaPicker.pickMany(function (urls) {
                (urls || []).forEach(function (url) {
                    var duplicate = Array.prototype.some.call(
                        selection.querySelectorAll('input[name="gallery_library[]"]'),
                        function (input) { return input.value === url; }
                    );
                    if (duplicate || (!/^\/(?!\/)/.test(url) && !/^https?:\/\//i.test(url))) { return; }

                    var item = document.createElement('div');
                    item.className = 'file-preview__item';

                    var image = document.createElement('img');
                    image.src = encodeURI(url);
                    image.alt = '';
                    item.appendChild(image);

                    var name = document.createElement('span');
                    name.className = 'file-preview__name';
                    var fileName = url.split('/').pop() || url;
                    try { fileName = decodeURIComponent(fileName); } catch (error) {}
                    name.textContent = fileName;
                    item.appendChild(name);

                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'gallery_library[]';
                    input.value = url;
                    item.appendChild(input);

                    var drop = document.createElement('button');
                    drop.type = 'button';
                    drop.className = 'file-preview__drop';
                    drop.setAttribute('data-media-gallery-drop', '');
                    drop.setAttribute('aria-label', 'Убрать фотографию из выбора');
                    drop.textContent = '×';
                    item.appendChild(drop);

                    selection.appendChild(item);
                });
                selection.hidden = !selection.children.length;
            });
        });

        document.addEventListener('click', function (e) {
            var galleryDrop = e.target.closest('[data-media-gallery-drop]');
            if (!galleryDrop) { return; }
            e.preventDefault();
            var selection = galleryDrop.closest('[data-media-gallery-selection]');
            var item = galleryDrop.closest('.file-preview__item');
            if (item) { item.remove(); }
            if (selection) { selection.hidden = !selection.children.length; }
        });

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-media-pick]');
            if (btn) {
                e.preventDefault();
                lastTrigger = btn;
                open(btn.getAttribute('data-media-target'), null, btn.getAttribute('data-media-type'), false);
                return;
            }
            if (e.target.closest('[data-media-close]') || e.target === modal) { close(); }
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
    })();

    // --- Живое значение ползунков прозрачности (overlay/подложка hero и др.) ---
    document.addEventListener('input', function (e) {
        var input = e.target.closest('input[type="range"][data-range-input]');
        if (!input) { return; }
        var out = document.querySelector('[data-range-output="' + input.getAttribute('data-range-input') + '"]');
        if (out) { out.textContent = input.value; }
    });

    // --- Hero: отдельное управление затемнением изображения и подложкой текста. ---
    (function () {
        var toggles = document.querySelectorAll('[data-hero-visual-toggle]');
        if (toggles.length) {
            function sync(toggle) {
                var id = toggle.getAttribute('data-hero-visual-toggle');
                var panel = id ? document.getElementById(id) : null;
                if (!panel) { return; }
                panel.hidden = !toggle.checked;
                toggle.setAttribute('aria-expanded', toggle.checked ? 'true' : 'false');
            }

            toggles.forEach(function (toggle) {
                toggle.addEventListener('change', function () { sync(toggle); });
                sync(toggle);
            });
        }

        var overlayModes = document.querySelectorAll('[data-hero-overlay-mode]');
        var gradientSettings = document.querySelector('[data-hero-overlay-gradient]');
        if (!overlayModes.length || !gradientSettings) { return; }

        function syncOverlayMode() {
            var selected = document.querySelector('[data-hero-overlay-mode]:checked');
            gradientSettings.hidden = !selected || selected.value !== 'gradient';
        }

        overlayModes.forEach(function (mode) {
            mode.addEventListener('change', syncOverlayMode);
        });
        syncOverlayMode();
    })();

    // --- Hero: произвольная высота с единицей измерения. ---
    (function () {
        var mode = document.querySelector('[data-hero-height]');
        var custom = document.querySelector('[data-hero-custom-height]');
        var value = document.getElementById('hero_height_value');
        var unit = document.getElementById('hero_height_unit');
        if (!mode || !custom || !value || !unit) { return; }

        function sync() {
            custom.hidden = mode.value !== 'custom';
            var limits = unit.value === 'px' ? [160, 2000]
                : (unit.value === 'rem' ? [10, 120] : [20, 150]);
            value.min = String(limits[0]);
            value.max = String(limits[1]);
        }
        mode.addEventListener('change', sync);
        unit.addEventListener('change', sync);
        sync();
    })();

    // --- Поле изображения с превью (медиабиблиотека / URL / загрузка файла) ---
    (function () {
        // Имя выбранного файла рядом с кнопками: раньше о содержимом поля
        // говорил только длинный путь в текстовом поле под ним.
        function setName(field, src) {
            var name = field.querySelector('[data-image-name]');
            if (!name) { return; }
            var value = String(src || '').split('?')[0];
            var base = value ? (value.split('/').pop() || value) : '';
            try { base = decodeURIComponent(base); } catch (error) {}
            name.textContent = base || 'Файл не выбран';
            name.classList.toggle('is-empty', !base);
        }

        function setPreview(field, src) {
            setName(field, /^data:/.test(String(src || '')) ? 'загруженный файл' : src);
            var box = field.querySelector('[data-image-preview]');
            if (!box) { return; }
            if (src) {
                box.innerHTML = '';
                var img = document.createElement('img');
                img.src = src; img.alt = ''; img.loading = 'lazy';
                box.appendChild(img);
            } else {
                box.innerHTML = '<span class="image-field__placeholder" aria-hidden="true">'
                    + (window.asdrTablerIcon ? window.asdrTablerIcon('photo', 22) : '') + '</span>';
            }
        }
        // URL-инпут (в т.ч. установленный медиабиблиотекой — она шлёт change).
        document.addEventListener('input', function (e) {
            var input = e.target.closest('[data-image-input]');
            if (!input) { return; }
            var field = input.closest('[data-image-field]');
            if (field) { setPreview(field, input.value.trim()); }
        });
        document.addEventListener('change', function (e) {
            var input = e.target.closest('[data-image-input]');
            if (input) {
                var f = input.closest('[data-image-field]');
                if (f) { setPreview(f, input.value.trim()); }
                return;
            }
            // Локальное превью выбранного файла (до загрузки на сервер).
            var file = e.target.closest('[data-image-file]');
            if (file && file.files && file.files[0]) {
                var field = file.closest('[data-image-field]');
                if (field && window.FileReader) {
                    var reader = new FileReader();
                    reader.onload = function (ev) { setPreview(field, ev.target.result); };
                    reader.readAsDataURL(file.files[0]);
                }
            }
        });
        // Очистка.
        document.addEventListener('click', function (e) {
            var clear = e.target.closest('[data-image-clear]');
            if (!clear) { return; }
            e.preventDefault();
            var field = clear.closest('[data-image-field]');
            if (!field) { return; }
            var input = field.querySelector('[data-image-input]');
            var file = field.querySelector('[data-image-file]');
            if (input) { input.value = ''; }
            if (file) { file.value = ''; }
            setPreview(field, '');
        });

        // Обложка (hero): выбор фото при типе фона «Без фона» раньше молча
        // терялся — снимок сохранялся, но не показывался. Переключаем список
        // сами, чтобы редактор видел, что фон стал фотографией.
        var syncHeroBg = function (target) {
            var bgSelect = document.querySelector('[data-hero-bg]');
            if (!bgSelect) { return; }
            if (target.matches('[name="youtube_url"]')
                && /(?:youtu\.be\/|youtube\.com\/(?:watch\?[^\s]*v=|embed\/|shorts\/))[A-Za-z0-9_-]{11}/i.test(target.value.trim())) {
                bgSelect.value = 'youtube';
                return;
            }
            if (target.matches('[name="video_url"]') && target.value.trim() !== '') {
                bgSelect.value = 'video';
                return;
            }
            if (bgSelect.value !== 'none') { return; }
            var field = target.closest('[data-image-field]');
            var input = field ? field.querySelector('[data-image-input]') : null;
            // Только поле фонового изображения обложки, не прочие картинки блока.
            if (!input || input.getAttribute('name') !== 'image') { return; }
            var hasImage = input.value.trim() !== ''
                || (field.querySelector('[data-image-file]') || {}).value;
            if (hasImage) { bgSelect.value = 'image'; }
        };
        document.addEventListener('input', function (e) {
            if (e.target.closest('[data-image-input]') || e.target.matches('[name="youtube_url"], [name="video_url"]')) {
                syncHeroBg(e.target);
            }
        });
        document.addEventListener('change', function (e) {
            if (e.target.closest('[data-image-input]') || e.target.closest('[data-image-file]')
                || e.target.matches('[name="youtube_url"], [name="video_url"]')) {
                syncHeroBg(e.target);
            }
        });
    })();

    // --- Умный лид: чистый текст, длина и предпросмотр каналов ---
    (function initLeadCounter() {
        var field = document.querySelector('[data-lead-field]');
        var out = document.querySelector('[data-lead-count]');
        if (!field || !out) { return; }

        var title = document.querySelector('[name="title"]');
        var tabs = document.querySelectorAll('[data-lead-preview-tab]');
        var panels = document.querySelectorAll('[data-lead-preview-panel]');
        var card = document.querySelector('[data-lead-preview-card]');
        var telegram = document.querySelector('[data-lead-preview-telegram]');
        var seo = document.querySelector('[data-lead-preview-seo]');
        var previewTitles = document.querySelectorAll('[data-lead-preview-title], [data-lead-preview-seo-title]');

        function safeRich(target) {
            if (!target) { return; }
            target.textContent = '';
            var editor = leadEditorFor(field);
            var source = editor && editor.getBody ? editor.getBody() : null;
            if (!source) {
                target.textContent = plainLeadText(field) || 'Здесь появится форматированный лид Telegram.';
                return;
            }
            var allowed = {P:1, BR:1, STRONG:1, B:1, EM:1, I:1, U:1, S:1, UL:1, OL:1, LI:1, BLOCKQUOTE:1, A:1};
            function copy(node, parent) {
                if (node.nodeType === 3) { parent.appendChild(document.createTextNode(node.nodeValue || '')); return; }
                if (node.nodeType !== 1) { return; }
                var tag = allowed[node.tagName] ? node.tagName.toLowerCase() : 'span';
                var el = document.createElement(tag);
                if (tag === 'a') {
                    var href = node.getAttribute('href') || '';
                    if (/^(https?:|mailto:|tel:|\/|#)/i.test(href)) { el.setAttribute('href', href); }
                }
                Array.prototype.forEach.call(node.childNodes, function (child) { copy(child, el); });
                parent.appendChild(el);
            }
            Array.prototype.forEach.call(source.childNodes, function (node) { copy(node, target); });
        }

        function render() {
            var text = plainLeadText(field);
            var len = text.length;
            if (len === 0) {
                out.textContent = '';
            } else {
                var note = len < 180
                    ? ' — можно добавить больше сути'
                    : (len > 360 ? ' — полный текст сохранится, карточки покажут сокращение' : ' — оптимальная длина');
                out.textContent = 'Знаков: ' + len + note + '.';
            }

            if (card) { card.textContent = text.length > 180 ? text.slice(0, 179).trimEnd() + '…' : (text || 'Здесь появится текст карточки.'); }
            if (seo) { seo.textContent = text.length > 160 ? text.slice(0, 159).trimEnd() + '…' : (text || 'Здесь появится описание для поисковика.'); }
            safeRich(telegram);
            var titleText = title && title.value.trim() ? title.value.trim() : 'Заголовок новости';
            previewTitles.forEach(function (el) { el.textContent = titleText; });
        }

        field.addEventListener('input', render);
        if (title) { title.addEventListener('input', render); }
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var name = tab.getAttribute('data-lead-preview-tab');
                tabs.forEach(function (item) {
                    var active = item === tab;
                    item.classList.toggle('is-active', active);
                    item.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                panels.forEach(function (panel) { panel.hidden = panel.getAttribute('data-lead-preview-panel') !== name; });
            });
        });
        render();
    })();

    // --- Превью выбранных файлов до сохранения ---
    // Снимки галереи появлялись в форме только после сохранения: редактор не
    // видел ни что выбрал, ни сколько кадров, ни того, что выбор вообще
    // сработал. Показываем их сразу и даём убрать лишний кадр, не начиная
    // выбор заново. Подписи и порядок остаются послесохранёнными — они живут
    // в БД и привязаны к id снимка.
    (function initFilePreviews() {
        if (!window.DataTransfer || !window.FileReader) { return; }

        // Только растровые форматы. SVG умеет нести скрипт, и превью такого
        // файла — лишний риск: ссылка вида blob: живёт в нашем origin, и
        // открытая как страница она этот скрипт исполнит. Читаем data:-адресом,
        // как это уже сделано у превью обложки выше.
        var RASTER = /^image\/(jpeg|png|webp|gif|avif|bmp)$/i;

        function render(box) {
            if (!box) { return; }
            var input = box.querySelector('[data-file-preview-input]');
            var list = box.querySelector('[data-file-preview-list]');
            if (!input || !list) { return; }

            list.innerHTML = '';
            var files = Array.prototype.slice.call(input.files || []);
            list.hidden = files.length === 0;
            if (files.length === 0) { return; }

            var note = document.createElement('p');
            note.className = 'file-preview__note form-hint';
            note.textContent = 'Выбрано файлов: ' + files.length + '. Загрузятся при сохранении новости.';
            list.appendChild(note);

            files.forEach(function (file, i) {
                var item = document.createElement('div');
                item.className = 'file-preview__item';

                if (RASTER.test(file.type)) {
                    var img = document.createElement('img');
                    img.alt = '';
                    var reader = new FileReader();
                    reader.onload = function (ev) { img.src = String(ev.target.result); };
                    reader.readAsDataURL(file);
                    item.appendChild(img);
                }

                var name = document.createElement('span');
                name.className = 'file-preview__name';
                name.textContent = file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' КБ';
                item.appendChild(name);

                var drop = document.createElement('button');
                drop.type = 'button';
                drop.className = 'file-preview__drop';
                drop.setAttribute('data-file-preview-drop', String(i));
                drop.setAttribute('aria-label', 'Убрать «' + file.name + '» из выбора');
                drop.textContent = '×';
                item.appendChild(drop);

                list.appendChild(item);
            });
        }

        document.addEventListener('change', function (e) {
            var input = e.target.closest('[data-file-preview-input]');
            if (input) { render(input.closest('[data-file-preview]')); }
        });

        document.addEventListener('click', function (e) {
            var drop = e.target.closest('[data-file-preview-drop]');
            if (!drop) { return; }
            e.preventDefault();
            var box = drop.closest('[data-file-preview]');
            var input = box ? box.querySelector('[data-file-preview-input]') : null;
            if (!input) { return; }

            // FileList доступен только на чтение — пересобираем через DataTransfer.
            var skip = parseInt(drop.getAttribute('data-file-preview-drop'), 10);
            var keep = new DataTransfer();
            Array.prototype.slice.call(input.files || []).forEach(function (file, i) {
                if (i !== skip) { keep.items.add(file); }
            });
            input.files = keep.files;
            render(box);
        });
    })();

    // --- Умный интерактивный виджет фокальной точки (UI/UX Pro Max) ---
    (function initFocalPickers() {
        function updateFocal(picker) {
            var targetName = picker.getAttribute('data-image-input-name') || 'image_url';
            var imgInput = document.querySelector('[name="' + targetName + '"]') || document.querySelector('[data-image-input]');
            var imgEl = picker.querySelector('[data-focal-img]');
            var placeholder = picker.querySelector('[data-focal-placeholder]');
            var pin = picker.querySelector('[data-focal-pin]');
            var inputX = picker.querySelector('[data-focal-input-x]');
            var inputY = picker.querySelector('[data-focal-input-y]');

            var xVal = parseInt(inputX ? inputX.value : '', 10);
            var yVal = parseInt(inputY ? inputY.value : '', 10);
            var x = isNaN(xVal) ? 50 : Math.max(0, Math.min(100, xVal));
            var y = isNaN(yVal) ? 50 : Math.max(0, Math.min(100, yVal));

            if (pin) {
                pin.style.setProperty('--focal-x', x + '%');
                pin.style.setProperty('--focal-y', y + '%');
            }

            if (imgInput && imgEl) {
                var src = imgInput.value.trim();
                if (src) {
                    imgEl.src = src;
                    imgEl.classList.remove('is-hidden');
                    if (placeholder) { placeholder.classList.add('is-hidden'); }
                } else {
                    imgEl.classList.add('is-hidden');
                    if (placeholder) { placeholder.classList.remove('is-hidden'); }
                }
            }

            picker.querySelectorAll('[data-focal-set-x]').forEach(function (btn) {
                var px = parseInt(btn.getAttribute('data-focal-set-x'), 10);
                var py = parseInt(btn.getAttribute('data-focal-set-y'), 10);
                btn.classList.toggle('is-active', px === x && py === y);
            });
        }

        document.querySelectorAll('[data-focal-picker]').forEach(updateFocal);

        document.addEventListener('click', function (e) {
            var canvas = e.target.closest('[data-focal-canvas]');
            if (canvas) {
                var picker = canvas.closest('[data-focal-picker]');
                if (!picker) return;
                var rect = canvas.getBoundingClientRect();
                var clickX = e.clientX - rect.left;
                var clickY = e.clientY - rect.top;
                var pctX = Math.round((clickX / rect.width) * 100);
                var pctY = Math.round((clickY / rect.height) * 100);
                pctX = Math.max(0, Math.min(100, pctX));
                pctY = Math.max(0, Math.min(100, pctY));

                var inputX = picker.querySelector('[data-focal-input-x]');
                var inputY = picker.querySelector('[data-focal-input-y]');
                if (inputX) inputX.value = pctX;
                if (inputY) inputY.value = pctY;
                updateFocal(picker);
                return;
            }

            var presetBtn = e.target.closest('[data-focal-set-x]');
            if (presetBtn) {
                var picker = presetBtn.closest('[data-focal-picker]');
                if (!picker) return;
                var px = presetBtn.getAttribute('data-focal-set-x');
                var py = presetBtn.getAttribute('data-focal-set-y');
                var inputX = picker.querySelector('[data-focal-input-x]');
                var inputY = picker.querySelector('[data-focal-input-y]');
                if (inputX) inputX.value = px;
                if (inputY) inputY.value = py;
                updateFocal(picker);
                return;
            }

            var resetBtn = e.target.closest('[data-focal-reset]');
            if (resetBtn) {
                var picker = resetBtn.closest('[data-focal-picker]');
                if (!picker) return;
                var inputX = picker.querySelector('[data-focal-input-x]');
                var inputY = picker.querySelector('[data-focal-input-y]');
                if (inputX) inputX.value = '';
                if (inputY) inputY.value = '';
                updateFocal(picker);
                return;
            }
        });

        document.addEventListener('input', function (e) {
            if (e.target.matches('[data-focal-input-x], [data-focal-input-y]') || e.target.matches('[data-image-input]')) {
                document.querySelectorAll('[data-focal-picker]').forEach(updateFocal);
            }
        });
    })();

    // --- Автономный WYSIWYG (задача 75): инициализация на textarea[data-wysiwyg] ---
    if (window.ArtEditor) {
        document.querySelectorAll('textarea[data-wysiwyg], textarea[data-lead-editor]').forEach(function (ta) {
            window.ArtEditor.attach(ta);
        });
    }

    // --- Панель явного сохранения порядка ---
    // Раньше перетаскивание сохранялось мгновенно (AJAX на каждый drop). Теперь
    // изменения порядка копятся, а применяются только по кнопке «Сохранить» —
    // при уходе со страницы с несохранёнными правками браузер предупреждает.
    var ReorderBar = (function () {
        var bar = null, saveBtn = null, statusEl = null;
        var pendingSave = null, dirty = false, saving = false, hideTimer = null;

        function build() {
            bar = document.createElement('div');
            bar.className = 'reorder-bar';
            bar.setAttribute('hidden', '');
            bar.setAttribute('role', 'status');
            bar.setAttribute('aria-live', 'polite');
            bar.innerHTML = '<span class="reorder-bar__text"></span>'
                + '<button type="button" class="btn btn--small" data-reorder-cancel>Отменить</button>'
                + '<button type="button" class="btn btn--small btn--primary" data-reorder-save>Сохранить</button>';
            document.body.appendChild(bar);
            statusEl = bar.querySelector('.reorder-bar__text');
            saveBtn = bar.querySelector('[data-reorder-save]');
            saveBtn.addEventListener('click', function () {
                if (!pendingSave || saving) { return; }
                saving = true; saveBtn.disabled = true;
                statusEl.textContent = 'Сохранение…';
                pendingSave(function (ok, msg) {
                    saving = false; saveBtn.disabled = false;
                    if (ok) {
                        dirty = false;
                        statusEl.textContent = 'Порядок сохранён';
                        hideTimer = window.setTimeout(function () { bar.setAttribute('hidden', ''); }, 1400);
                    } else {
                        statusEl.textContent = msg || 'Не удалось сохранить. Попробуйте ещё раз.';
                    }
                });
            });
            bar.querySelector('[data-reorder-cancel]').addEventListener('click', function () {
                // Отмена = вернуться к последнему сохранённому порядку (перезагрузка).
                dirty = false;
                window.location.reload();
            });
        }

        window.addEventListener('beforeunload', function (e) {
            if (dirty) { e.preventDefault(); e.returnValue = ''; return ''; }
        });

        return {
            markDirty: function (saveFn) {
                if (!bar) { build(); }
                if (hideTimer) { window.clearTimeout(hideTimer); hideTimer = null; }
                pendingSave = saveFn;
                dirty = true;
                statusEl.textContent = 'Есть несохранённые изменения порядка';
                bar.removeAttribute('hidden');
            }
        };
    })();

    // --- Drag-and-drop сортировка блоков (задача 134, нативный HTML5 DnD) ---
    document.querySelectorAll('[data-block-sortable]').forEach(function (list) {
        var dragged = null;

        list.querySelectorAll('.block-list-item').forEach(function (item) {
            item.addEventListener('dragstart', function (e) {
                dragged = item;
                item.classList.add('is-dragging');
                try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); } catch (err) {}
            });
            item.addEventListener('dragend', function () {
                item.classList.remove('is-dragging');
                ReorderBar.markDirty(saveOrder);
            });
        });

        list.addEventListener('dragover', function (e) {
            e.preventDefault();
            if (!dragged) { return; }
            var after = null;
            var items = Array.prototype.slice.call(list.querySelectorAll('.block-list-item:not(.is-dragging)'));
            for (var i = 0; i < items.length; i++) {
                var box = items[i].getBoundingClientRect();
                if (e.clientY < box.top + box.height / 2) { after = items[i]; break; }
            }
            if (after == null) { list.appendChild(dragged); }
            else { list.insertBefore(dragged, after); }
        });

        function saveOrder(done) {
            var order = Array.prototype.map.call(
                list.querySelectorAll('.block-list-item'),
                function (el) { return el.getAttribute('data-block-id'); }
            );
            var body = new URLSearchParams();
            body.append('csrf_token', list.getAttribute('data-csrf'));
            body.append('page_id', list.getAttribute('data-page-id'));
            body.append('block_lang', list.getAttribute('data-block-lang'));
            order.forEach(function (id) { body.append('order[]', id); });

            fetch('/admin/blocks/reorder', {
                method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); })
              .then(function (res) { done(!!res.ok, res.ok ? '' : 'Не удалось сохранить порядок.'); })
              .catch(function () { done(false, 'Сетевая ошибка при сохранении порядка.'); });
        }
    });

    // --- Блок «Обложка»: выбранная обложка прячет собственные поля блока ---
    // Поля остаются в форме и уходят на сервер: редактор может вернуться к
    // «старому способу», и набранный текст при этом не теряется.
    document.querySelectorAll('[data-hero-picker]').forEach(function (picker) {
        var own = document.querySelector('[data-hero-own-fields]');
        if (!own) { return; }
        picker.addEventListener('change', function () {
            own.hidden = picker.value !== '0' && picker.value !== '';
        });
    });

    // --- Обложки: перетаскивание слайдов ---
    // Порядок слайдов — это порядок показа на сайте, поэтому сохраняется он
    // тем же явным действием, что и порядок блоков: копим перестановки,
    // применяем по кнопке в панели порядка.
    document.querySelectorAll('[data-hero-sortable]').forEach(function (list) {
        var dragged = null;

        list.querySelectorAll('.hero-slide-item').forEach(function (item) {
            item.addEventListener('dragstart', function (e) {
                dragged = item;
                item.classList.add('is-dragging');
                try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); } catch (err) {}
            });
            item.addEventListener('dragend', function () {
                item.classList.remove('is-dragging');
                renumber();
                ReorderBar.markDirty(saveOrder);
            });
        });

        list.addEventListener('dragover', function (e) {
            e.preventDefault();
            if (!dragged) { return; }
            var after = null;
            var items = Array.prototype.slice.call(list.querySelectorAll('.hero-slide-item:not(.is-dragging)'));
            for (var i = 0; i < items.length; i++) {
                var box = items[i].getBoundingClientRect();
                if (e.clientY < box.top + box.height / 2) { after = items[i]; break; }
            }
            if (after == null) { list.appendChild(dragged); }
            else { list.insertBefore(dragged, after); }
        });

        // Номера в списке — часть смысла («01», «02»), а не украшение:
        // после перестановки они обязаны совпадать с новым порядком.
        function renumber() {
            Array.prototype.forEach.call(list.querySelectorAll('.hero-slide-item__num'), function (el, i) {
                el.textContent = i < 9 ? '0' + (i + 1) : String(i + 1);
            });
        }

        function saveOrder(done) {
            var body = new URLSearchParams();
            body.append('csrf_token', list.getAttribute('data-csrf'));
            Array.prototype.forEach.call(list.querySelectorAll('.hero-slide-item'), function (el) {
                body.append('order[]', el.getAttribute('data-hero-slide-id'));
            });

            fetch('/admin/heroes/' + list.getAttribute('data-hero-id') + '/slides/reorder', {
                method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); })
              .then(function (res) { done(!!res.ok, res.ok ? '' : 'Не удалось сохранить порядок слайдов.'); })
              .catch(function () { done(false, 'Сетевая ошибка при сохранении порядка.'); });
        }
    });

    // --- Меню: визуальная сортировка и автосохранение вложенности ---
    document.querySelectorAll('[data-menu-sortable]').forEach(function (root) {
        var dragged = null, startParent = null, startNext = null, moved = false, saving = false;
        var status = root.closest('[data-menu-builder]').querySelector('[data-menu-save-status]');

        function isChildList(list) { return list.hasAttribute('data-menu-children'); }
        function draggedHasChildren() {
            var kids = dragged.querySelector('[data-menu-children]');
            return kids && kids.querySelector('.menu-node');
        }
        function setStatus(text, state) {
            if (!status) { return; }
            status.textContent = text;
            status.classList.toggle('is-saving', state === 'saving');
            status.classList.toggle('is-error', state === 'error');
        }
        function refreshPreview() {
            var panel = root.closest('[data-menu-lang-panel]');
            var viewport = panel ? panel.querySelector('[data-menu-preview]') : null;
            if (!viewport) { return; }
            viewport.replaceChildren();
            root.querySelectorAll(':scope > .menu-node').forEach(function (top) {
                if (top.getAttribute('data-menu-active') !== '1' || top.getAttribute('data-menu-divider') === '1') { return; }
                var title = top.querySelector(':scope > .menu-node__row .menu-node__title');
                var item = document.createElement('div');
                item.className = 'menu-preview__item';
                var label = document.createElement('span');
                label.textContent = title ? title.textContent : '';
                item.appendChild(label);
                var submenu = document.createElement('div');
                submenu.className = 'menu-preview__submenu';
                var childList = top.querySelector(':scope > [data-menu-children]');
                if (childList) {
                    childList.querySelectorAll(':scope > .menu-node').forEach(function (child) {
                        if (child.getAttribute('data-menu-active') !== '1' || child.getAttribute('data-menu-divider') === '1') { return; }
                        var childTitle = child.querySelector(':scope > .menu-node__row .menu-node__title');
                        var childLabel = document.createElement('span');
                        childLabel.textContent = childTitle ? childTitle.textContent : '';
                        submenu.appendChild(childLabel);
                    });
                }
                if (submenu.childElementCount > 0) { item.appendChild(submenu); }
                viewport.appendChild(item);
            });
        }
        function restorePosition(parent, next, node) {
            if (!parent || !node) { return; }
            if (next && next.parentNode === parent) { parent.insertBefore(node, next); }
            else { parent.appendChild(node); }
            var restoredAsChild = isChildList(parent);
            node.classList.toggle('menu-node--child', restoredAsChild);
            var ownChildren = node.querySelector(':scope > [data-menu-children]');
            if (restoredAsChild && ownChildren && !ownChildren.querySelector('.menu-node')) {
                ownChildren.remove();
            }
        }

        root.addEventListener('dragstart', function (e) {
            var handle = e.target.closest('.menu-node__handle');
            var node = handle ? handle.closest('.menu-node') : null;
            if (saving || !node || node.getAttribute('data-menu-lang') !== root.getAttribute('data-menu-lang')) {
                e.preventDefault();
                return;
            }
            dragged = node;
            startParent = node.parentNode;
            startNext = node.nextElementSibling;
            moved = false;
            node.classList.add('is-dragging');
            try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', ''); } catch (err) {}
        });

        root.addEventListener('dragend', function () {
            var movedNode = dragged;
            var previousParent = startParent;
            var previousNext = startNext;
            var changed = !!(movedNode && (moved || movedNode.parentNode !== previousParent || movedNode.nextElementSibling !== previousNext));
            if (movedNode) { movedNode.classList.remove('is-dragging'); }
            dragged = null;
            startParent = null;
            startNext = null;
            moved = false;
            if (changed) {
                saving = true;
                root.classList.add('is-saving');
                setStatus('Сохранение порядка…', 'saving');
                saveOrder(function (ok, message) {
                    saving = false;
                    root.classList.remove('is-saving');
                    if (ok) {
                        refreshPreview();
                        setStatus('Порядок сохранён', 'success');
                        window.setTimeout(function () { setStatus('Все изменения сохранены', 'success'); }, 1600);
                    } else {
                        restorePosition(previousParent, previousNext, movedNode);
                        setStatus(message || 'Не удалось сохранить порядок', 'error');
                    }
                });
            }
        });

        function bindDropList(list) {
            if (list.dataset.menuDropBound === '1') { return; }
            list.dataset.menuDropBound = '1';
            list.addEventListener('dragover', function (e) {
                if (!dragged) { return; }
                // Ограничение глубины 1: пункт со своими детьми нельзя вкладывать.
                if (isChildList(list) && draggedHasChildren()) { return; }
                if (isChildList(list) && dragged.classList.contains('menu-node--divider')) { return; }
                // Нельзя поместить пункт внутрь его собственной области детей.
                if (dragged.contains(list)) { return; }
                e.preventDefault();
                e.stopPropagation();
                var siblings = Array.prototype.slice.call(list.querySelectorAll(':scope > .menu-node:not(.is-dragging)'));
                var after = null;
                for (var i = 0; i < siblings.length; i++) {
                    var box = siblings[i].getBoundingClientRect();
                    if (e.clientY < box.top + box.height / 2) { after = siblings[i]; break; }
                }
                if (after == null) { list.appendChild(dragged); }
                else { list.insertBefore(dragged, after); }
                var movingToChild = isChildList(list);
                dragged.classList.toggle('menu-node--child', movingToChild);
                var ownChildren = dragged.querySelector(':scope > [data-menu-children]');
                if (movingToChild && ownChildren && !ownChildren.querySelector('.menu-node')) {
                    ownChildren.remove();
                } else if (!movingToChild && !ownChildren && !dragged.classList.contains('menu-node--divider')) {
                    ownChildren = document.createElement('ul');
                    ownChildren.className = 'menu-node__children';
                    ownChildren.setAttribute('data-menu-children', '');
                    ownChildren.setAttribute('aria-label', 'Вложенные пункты');
                    dragged.appendChild(ownChildren);
                    bindDropList(ownChildren);
                }
                moved = true;
            });
        }

        // Разрешаем вставку в корень и в каждую область подменю.
        bindDropList(root);
        root.querySelectorAll('[data-menu-children]').forEach(bindDropList);

        function saveOrder(done) {
            var ids = [];
            var parents = [];
            Array.prototype.forEach.call(root.querySelectorAll(':scope > .menu-node'), function (top) {
                ids.push(top.getAttribute('data-menu-id'));
                parents.push('');
                var childList = top.querySelector(':scope > [data-menu-children]');
                if (childList) {
                    Array.prototype.forEach.call(childList.querySelectorAll(':scope > .menu-node'), function (child) {
                        ids.push(child.getAttribute('data-menu-id'));
                        parents.push(top.getAttribute('data-menu-id'));
                    });
                }
            });

            var body = new URLSearchParams();
            body.append('csrf_token', root.getAttribute('data-csrf'));
            ids.forEach(function (id) { body.append('id[]', id); });
            parents.forEach(function (p) { body.append('parent_id[]', p); });

            fetch('/admin/menu/reorder', {
                method: 'POST', body: body, credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); })
              .then(function (res) { done(!!res.ok, res.ok ? '' : (res.error || 'Не удалось сохранить меню. Обновите страницу.')); })
              .catch(function () { done(false, 'Сетевая ошибка при сохранении меню.'); });
        }
    });

    // --- Меню: языки, инспектор, быстрый тип элемента и зависимые поля ---
    (function () {
        var builder = document.querySelector('[data-menu-builder]');
        if (!builder) { return; }

        function fieldValue(field) {
            if (!field) { return ''; }
            if (field.type === 'checkbox') { return field.checked ? field.value : ''; }
            return field.value;
        }

        function syncMenuForm(form) {
            var type = form.querySelector('[data-menu-url-type]');
            var divider = form.querySelector('[data-menu-divider]');
            var lang = form.querySelector('[data-menu-lang-select]');
            var typeValue = fieldValue(type);
            var isDivider = fieldValue(divider) === '1';
            form.querySelectorAll('[data-menu-link-only]').forEach(function (field) {
                field.hidden = isDivider;
            });
            form.querySelectorAll('[data-menu-url-field]').forEach(function (field) {
                field.hidden = isDivider || field.getAttribute('data-menu-url-field') !== typeValue;
            });
            form.querySelectorAll('[data-menu-parent-field]').forEach(function (field) {
                field.hidden = isDivider;
            });
            form.querySelectorAll('[data-menu-divider-title-hint]').forEach(function (hint) {
                hint.hidden = !isDivider;
            });

            var parent = form.querySelector('[data-menu-parent-select]');
            if (parent && lang) {
                Array.prototype.forEach.call(parent.options, function (option, index) {
                    if (index === 0) { option.hidden = false; option.disabled = false; return; }
                    var matches = option.getAttribute('data-lang') === lang.value;
                    option.hidden = !matches;
                    option.disabled = !matches;
                    if (!matches && option.selected) { parent.value = ''; }
                });
            }

            var pageSelect = form.querySelector('[data-menu-page-select]');
            if (pageSelect && lang) {
                Array.prototype.forEach.call(pageSelect.options, function (option, index) {
                    if (index === 0) { option.hidden = false; option.disabled = false; return; }
                    var matches = option.getAttribute('data-lang') === lang.value;
                    option.hidden = !matches;
                    option.disabled = !matches;
                    if (!matches && option.selected) { pageSelect.value = ''; }
                });
            }
            form.querySelectorAll('[data-menu-top-only]').forEach(function (field) {
                field.hidden = !!(parent && parent.value !== '');
            });
        }

        function applyCreateKind(select) {
            var form = select.closest('[data-menu-link-form]');
            if (!form) { return; }
            var type = form.querySelector('[data-menu-url-type]');
            var divider = form.querySelector('[data-menu-divider]');
            var kind = select.value;
            if (type) { type.value = kind === 'divider' ? 'custom' : kind; }
            if (divider) { divider.value = kind === 'divider' ? '1' : '0'; }
            var submit = form.closest('form').querySelector('[data-menu-create-submit]');
            if (submit) {
                var label = submit.querySelector('[data-menu-create-label]');
                if (label) { label.textContent = kind === 'divider' ? 'Добавить разделитель' : 'Добавить пункт'; }
            }
            syncMenuForm(form);
        }

        function closeInspector() {
            builder.querySelectorAll('[data-menu-inspector]').forEach(function (panel) {
                panel.setAttribute('hidden', '');
            });
            builder.querySelectorAll('.menu-node.is-selected').forEach(function (node) {
                node.classList.remove('is-selected');
            });
            var empty = builder.querySelector('[data-menu-inspector-empty]');
            if (empty) { empty.removeAttribute('hidden'); }
            var inspector = builder.querySelector('.menu-inspector');
            if (inspector) { inspector.classList.remove('has-selection'); }
        }

        function openInspector(id, focusPanel) {
            var panel = builder.querySelector('[data-menu-inspector="' + id + '"]');
            var node = builder.querySelector('.menu-node[data-menu-id="' + id + '"]');
            if (!panel || !node) { return; }
            builder.querySelectorAll('[data-menu-inspector]').forEach(function (candidate) {
                candidate.toggleAttribute('hidden', candidate !== panel);
            });
            builder.querySelectorAll('.menu-node.is-selected').forEach(function (candidate) {
                candidate.classList.remove('is-selected');
            });
            node.classList.add('is-selected');
            var empty = builder.querySelector('[data-menu-inspector-empty]');
            if (empty) { empty.setAttribute('hidden', ''); }
            var inspector = builder.querySelector('.menu-inspector');
            if (inspector) {
                inspector.classList.add('has-selection');
                if (focusPanel && window.innerWidth <= 1280) { inspector.focus({preventScroll: true}); }
            }
        }

        document.querySelectorAll('[data-menu-link-form]').forEach(syncMenuForm);
        document.querySelectorAll('[data-menu-create-kind]').forEach(applyCreateKind);

        document.addEventListener('change', function (e) {
            if (e.target.matches('[data-menu-create-kind]')) {
                applyCreateKind(e.target);
                return;
            }
            if (!e.target.matches('[data-menu-url-type], [data-menu-divider], [data-menu-lang-select], [data-menu-parent-select]')) { return; }
            var form = e.target.closest('[data-menu-link-form]');
            if (form) syncMenuForm(form);
        });

        // Название пункта меню подставляется из заголовка выбранной страницы,
        // пока его не отредактировали вручную (флаг data-autofilled).
        document.addEventListener('change', function (e) {
            if (!e.target.matches('[data-menu-page-select]')) { return; }
            var form = e.target.closest('[data-menu-link-form]');
            if (!form) { return; }
            var titleInput = form.querySelector('input[name="title"]');
            if (!titleInput) { return; }
            var opt = e.target.options[e.target.selectedIndex];
            var pageTitle = opt ? (opt.getAttribute('data-title') || '') : '';
            if (pageTitle === '') { return; }
            if (titleInput.value.trim() === '' || titleInput.dataset.autofilled === '1') {
                titleInput.value = pageTitle;
                titleInput.dataset.autofilled = '1';
            }
        });
        document.addEventListener('input', function (e) {
            if (e.target.matches('[data-menu-link-form] input[name="title"]')) {
                delete e.target.dataset.autofilled;
            }
        });

        document.addEventListener('click', function (e) {
            var inspect = e.target.closest('[data-menu-inspect]');
            if (inspect) {
                openInspector(inspect.getAttribute('data-menu-inspect'), true);
                return;
            }

            if (e.target.closest('[data-menu-inspector-close]')) {
                closeInspector();
                return;
            }

            var previewMode = e.target.closest('[data-menu-preview-mode]');
            if (previewMode) {
                var preview = previewMode.closest('.menu-preview');
                if (!preview) { return; }
                preview.querySelectorAll('[data-menu-preview-mode]').forEach(function (button) {
                    button.classList.toggle('is-active', button === previewMode);
                });
                var viewport = preview.querySelector('[data-menu-preview]');
                if (viewport) {
                    viewport.classList.toggle('is-mobile', previewMode.getAttribute('data-menu-preview-mode') === 'mobile');
                }
                return;
            }

            var tab = e.target.closest('[data-menu-lang-tab]');
            if (tab) {
                var code = tab.getAttribute('data-menu-lang-tab');
                try { localStorage.setItem('artstudio:admin-menu-lang', code); } catch (err) {}
                document.querySelectorAll('[data-menu-lang-tab]').forEach(function (button) {
                    var active = button === tab;
                    button.classList.toggle('is-active', active);
                    button.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                document.querySelectorAll('[data-menu-lang-panel]').forEach(function (panel) {
                    panel.toggleAttribute('hidden', panel.getAttribute('data-menu-lang-panel') !== code);
                });
                var createLang = document.querySelector('#menu-add [data-menu-lang-select]');
                if (createLang) { createLang.value = code; syncMenuForm(createLang.closest('[data-menu-link-form]')); }
                closeInspector();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeInspector(); }
        });

        try {
            var query = new URLSearchParams(window.location.search);
            var queryLang = query.get('lang');
            var savedMenuLang = localStorage.getItem('artstudio:admin-menu-lang');
            var preferredLang = queryLang || savedMenuLang;
            if (preferredLang !== null) {
                var savedTab = Array.prototype.find.call(document.querySelectorAll('[data-menu-lang-tab]'), function (button) {
                    return button.getAttribute('data-menu-lang-tab') === preferredLang;
                });
                if (savedTab) savedTab.click();
            }
            var selectedId = query.get('selected');
            if (selectedId) { openInspector(selectedId, false); }
        } catch (e) {}
    })();

    // Языковые вкладки: переключение панелей внутри одной группы [data-lang-tabs]
    document.querySelectorAll('[data-lang-tabs]').forEach(function (group) {
        const buttons = group.querySelectorAll('.lang-tab-btn');
        const panels = group.querySelectorAll('.lang-tab-panel');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                const target = btn.getAttribute('data-lang-target');
                buttons.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                panels.forEach(function (p) {
                    p.classList.toggle('is-active', p.getAttribute('data-lang-panel') === target);
                });

                if (group.hasAttribute('data-sync-block-language')) {
                    var search = window.location.search;
                    var hasBlockLang = search.indexOf('block_lang=') !== -1;
                    var param = 'block_lang=' + encodeURIComponent(target);
                    var newSearch = '';
                    if (hasBlockLang) {
                        newSearch = search.replace(/block_lang=[^&]*/g, param);
                    } else {
                        newSearch = search ? (search + '&' + param) : ('?' + param);
                    }
                    newSearch = newSearch.replace(/[&?]draft_saved=[^&]*/g, '');
                    newSearch = newSearch.replace(/&&+/g, '&').replace(/\?&/, '?').replace(/&$/, '');
                    if (search !== newSearch) {
                        window.location.assign(window.location.pathname + newSearch + window.location.hash);
                    }
                }
            });
        });
    });
})();

/* Единый локальный выбор Tabler Icons для меню и конструкторов блоков. */
(function () {
    'use strict';

    var spriteMeta = document.querySelector('meta[name="asdr-icon-sprite"]');
    var catalogMeta = document.querySelector('meta[name="asdr-icon-catalog"]');
    var sprite = spriteMeta ? spriteMeta.getAttribute('content') : '/assets/vendor/tabler/tabler-sprite.svg';
    var catalogUrl = catalogMeta ? catalogMeta.getAttribute('content') : '/assets/vendor/tabler/tabler-icons.json';
    var picker = document.querySelector('[data-icon-picker]');
    var search = picker ? picker.querySelector('[data-icon-picker-search]') : null;
    var results = picker ? picker.querySelector('[data-icon-picker-results]') : null;
    var count = picker ? picker.querySelector('[data-icon-picker-count]') : null;
    var activeField = null;
    var iconNames = null;
    var restoreFocus = null;

    function cleanName(value) {
        var name = String(value || '').trim().toLowerCase().replace(/^tabler-/, '');
        return /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(name) && name.length <= 80 ? name : '';
    }

    function iconMarkup(name, size, className) {
        name = cleanName(name);
        if (!name) { return ''; }
        size = Math.max(8, Math.min(160, Number(size) || 20));
        className = className || 'ui-icon';
        return '<svg class="icon icon-tabler ' + className + '" width="' + size + '" height="' + size
            + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
            + 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            + '<use href="' + sprite + '#tabler-' + name + '"></use></svg>';
    }

    window.asdrTablerIcon = iconMarkup;
    window.__enhanceIconFields = function (root) {
        (root || document).querySelectorAll('[data-icon-field]').forEach(updatePreview);
    };

    function updatePreview(field) {
        if (!field) { return; }
        var input = field.querySelector('[data-icon-input]');
        var preview = field.querySelector('[data-icon-preview]');
        if (!input || !preview) { return; }
        var name = cleanName(input.value);
        if (input.value !== name) { input.value = name; }
        preview.innerHTML = iconMarkup(name || 'photo-off', 22);
        field.classList.toggle('has-icon', name !== '');
    }

    function closePicker() {
        if (!picker) { return; }
        picker.hidden = true;
        document.body.classList.remove('has-icon-picker');
        activeField = null;
        if (restoreFocus) { restoreFocus.focus(); }
        restoreFocus = null;
    }

    function choose(name) {
        if (!activeField) { return; }
        var input = activeField.querySelector('[data-icon-input]');
        if (input) {
            input.value = cleanName(name);
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        updatePreview(activeField);
        closePicker();
    }

    function render(query) {
        if (!results || !Array.isArray(iconNames)) { return; }
        var normalized = cleanName(query) || String(query || '').trim().toLowerCase();
        var filtered = iconNames.filter(function (name) {
            return normalized === '' || name.indexOf(normalized) !== -1;
        });
        var visible = filtered.slice(0, 180);
        results.innerHTML = '';
        visible.forEach(function (name) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'tabler-picker__item';
            button.setAttribute('data-icon-picker-value', name);
            button.setAttribute('title', name);
            button.innerHTML = iconMarkup(name, 24) + '<span></span>';
            button.querySelector('span').textContent = name;
            results.appendChild(button);
        });
        if (visible.length === 0) {
            results.innerHTML = '<div class="tabler-picker__status">Иконки не найдены</div>';
        }
        if (count) {
            count.textContent = filtered.length > visible.length
                ? 'Показано ' + visible.length + ' из ' + filtered.length
                : 'Найдено: ' + filtered.length;
        }
    }

    function loadCatalog() {
        if (Array.isArray(iconNames)) {
            render(search ? search.value : '');
            return Promise.resolve(iconNames);
        }
        return fetch(catalogUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.json();
            })
            .then(function (payload) {
                iconNames = Array.isArray(payload.icons) ? payload.icons.filter(cleanName) : [];
                render(search ? search.value : '');
                return iconNames;
            })
            .catch(function () {
                if (results) {
                    results.innerHTML = '<div class="tabler-picker__status tabler-picker__status--error">'
                        + 'Не удалось загрузить каталог. Можно ввести имя Tabler вручную.</div>';
                }
                return [];
            });
    }

    function openPicker(button) {
        if (!picker) { return; }
        activeField = button.closest('[data-icon-field]');
        if (!activeField) { return; }
        restoreFocus = button;
        picker.hidden = false;
        document.body.classList.add('has-icon-picker');
        var input = activeField.querySelector('[data-icon-input]');
        if (search) {
            search.value = input ? input.value : '';
            search.focus();
        }
        loadCatalog();
    }

    document.addEventListener('input', function (event) {
        if (event.target.matches('[data-icon-input]')) {
            updatePreview(event.target.closest('[data-icon-field]'));
        }
        if (event.target.matches('[data-icon-picker-search]')) {
            render(event.target.value);
        }
    });

    document.addEventListener('click', function (event) {
        var open = event.target.closest('[data-icon-picker-open]');
        if (open) {
            openPicker(open);
            return;
        }
        var clear = event.target.closest('[data-icon-clear]');
        if (clear) {
            var field = clear.closest('[data-icon-field]');
            var input = field ? field.querySelector('[data-icon-input]') : null;
            if (input) {
                input.value = '';
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            updatePreview(field);
            return;
        }
        var choice = event.target.closest('[data-icon-picker-value]');
        if (choice) {
            choose(choice.getAttribute('data-icon-picker-value'));
            return;
        }
        if (event.target.closest('[data-icon-picker-empty]')) {
            choose('');
            return;
        }
        if (event.target.closest('[data-icon-picker-close]') || event.target === picker) {
            closePicker();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && picker && !picker.hidden) {
            closePicker();
        }
    });

    document.querySelectorAll('[data-icon-field]').forEach(updatePreview);
})();

/* ==========================================================================
   Конструктор шапки: drag-and-drop микро-виджетов по зонам (палитра ↔ зоны).
   Палитра — источник доступных элементов. Неповторяемые (поиск, языки, тема,
   слабовидящие, соцсети, кнопка) размещаются по одному; «Разделитель» —
   повторяемый (клонируется из палитры). Порядок в зоне задаётся перетаскиванием.
   ========================================================================== */
(function () {
    'use strict';
    var REPEATABLE = ['divider', 'spacer', 'space'];
    var builders = document.querySelectorAll('[data-hdr-builder]');
    if (!builders.length) { return; }
    // Pro Max: палитра — общий источник (чипы клонируются), секции — приёмники.
    // Перетаскивание работает МЕЖДУ билдерами (глобальное состояние).
    var palette = document.querySelector('[data-hdr-zone="palette"]');
    var dragged = null;       // перетаскиваемый чип (клон или размещённый)
    var fromPalette = false;  // тянем из палитры (клонировать)
    var selectedZone = null;  // выбранная зона для добавления по клику/касанию

    function serializeAll() {
        builders.forEach(function (builder) {
            builder.querySelectorAll('[data-hdr-input]').forEach(function (input) {
                var dz = builder.querySelector('[data-hdr-zone="' + input.getAttribute('data-hdr-input') + '"]');
                if (!dz) { return; }
                var types = Array.prototype.map.call(dz.querySelectorAll('.hdr-chip'), function (c) {
                    return c.getAttribute('data-el');
                });
                input.value = types.join(',');
            });
        });
    }

    // Уникальность в пределах одной секции (билдера): повторяем только divider.
    function sectionHasType(builder, type) {
        return !!builder.querySelector('[data-hdr-zone]:not([data-hdr-zone="palette"]) .hdr-chip[data-el="' + type + '"]:not(.is-dragging)');
    }

    function makeChip(type) {
        var src = palette ? palette.querySelector('.hdr-chip[data-el="' + type + '"]') : null;
        if (!src) { return null; }
        var chip = src.cloneNode(true);
        chip.classList.add('hdr-chip--placed');
        bindChip(chip);
        return chip;
    }

    function selectZone(zone) {
        if (!zone || zone.getAttribute('data-hdr-zone') === 'palette') { return; }
        document.querySelectorAll('[data-hdr-zone].is-selected').forEach(function (item) {
            item.classList.remove('is-selected');
        });
        selectedZone = zone;
        selectedZone.classList.add('is-selected');
    }

    function visibleSelectedZone() {
        if (selectedZone && selectedZone.offsetParent) { return selectedZone; }
        var zones = Array.prototype.slice.call(document.querySelectorAll(
            '[data-hdr-zone]:not([data-hdr-zone="palette"])'
        ));
        var visible = zones.find(function (zone) { return !!zone.offsetParent; }) || null;
        if (visible) { selectZone(visible); }
        return visible;
    }

    function bindChip(chip) {
        chip.addEventListener('dragstart', function (e) {
            fromPalette = !!chip.closest('[data-hdr-zone="palette"]');
            dragged = fromPalette ? makeChip(chip.getAttribute('data-el')) : chip;
            if (!fromPalette) {
                setTimeout(function () { chip.classList.add('is-dragging'); }, 0);
            }
            e.dataTransfer.effectAllowed = fromPalette ? 'copy' : 'move';
            try { e.dataTransfer.setData('text/plain', chip.getAttribute('data-el')); } catch (err) {}
        });
        chip.addEventListener('dragend', function () {
            chip.classList.remove('is-dragging');
            // Отменённое перетаскивание из палитры: убираем невставленный клон.
            if (fromPalette && dragged && !dragged.parentNode) { /* не вставлен */ }
            dragged = null;
            fromPalette = false;
            serializeAll();
        });
        var rm = chip.querySelector('.hdr-chip__remove, .hb-el__remove');
        if (rm) {
            rm.addEventListener('click', function () {
                if (chip.closest('[data-hdr-zone="palette"]')) { return; }
                chip.remove();
                serializeAll();
            });
        }
        if (chip.closest('[data-hdr-zone="palette"]')) {
            chip.addEventListener('click', function () {
                var zone = visibleSelectedZone();
                if (!zone) { return; }
                var builder = zone.closest('[data-hdr-builder]');
                var type = chip.getAttribute('data-el');
                if (!builder || !type) { return; }
                if (REPEATABLE.indexOf(type) === -1 && sectionHasType(builder, type)) { return; }
                var placed = makeChip(type);
                if (!placed) { return; }
                zone.appendChild(placed);
                serializeAll();
            });
        }
    }

    function afterElement(zone, x, y) {
        var chips = Array.prototype.slice.call(zone.querySelectorAll('.hdr-chip:not(.is-dragging)'));
        var closest = { offset: -Infinity, el: null };
        chips.forEach(function (c) {
            var box = c.getBoundingClientRect();
            // Горизонтальные зоны: сравниваем по X в пределах строки, иначе по Y.
            var offset = (Math.abs(y - (box.top + box.height / 2)) < box.height)
                ? x - box.left - box.width / 2
                : y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) { closest = { offset: offset, el: c }; }
        });
        return closest.el;
    }

    document.querySelectorAll('[data-hdr-zone]').forEach(function (zone) {
        var isPalette = zone.getAttribute('data-hdr-zone') === 'palette';
        if (!isPalette) {
            zone.addEventListener('click', function () { selectZone(zone); });
            zone.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') { return; }
                event.preventDefault();
                selectZone(zone);
            });
        }
        zone.addEventListener('dragover', function (e) {
            if (!dragged) { return; }
            e.preventDefault();
            zone.classList.add('is-over');
            var type = dragged.getAttribute('data-el');

            if (isPalette) {
                // Бросок размещённого чипа в палитру = удаление из секции.
                if (!fromPalette && dragged.parentNode) { dragged.remove(); }
                return;
            }

            var builder = zone.closest('[data-hdr-builder]');
            // Не даём дублировать неповторяемый элемент в той же секции
            // (перенос внутри секции — можно; из палитры/другой секции — нет).
            var draggedBuilder = dragged.parentNode ? dragged.closest('[data-hdr-builder]') : null;
            if (REPEATABLE.indexOf(type) === -1 && draggedBuilder !== builder && sectionHasType(builder, type)) {
                return;
            }
            var after = afterElement(zone, e.clientX, e.clientY);
            if (after == null) { zone.appendChild(dragged); }
            else { zone.insertBefore(dragged, after); }
        });
        zone.addEventListener('dragleave', function () { zone.classList.remove('is-over'); });
        zone.addEventListener('drop', function (e) {
            e.preventDefault();
            zone.classList.remove('is-over');
            serializeAll();
        });
    });

    document.querySelectorAll('.hdr-chip').forEach(bindChip);
    visibleSelectedZone();
    serializeAll();
})();

/* Вкладки конструктора (Десктоп / Мобильный). */
(function () {
    'use strict';
    document.querySelectorAll('[data-hdr-tabs]').forEach(function (tabs) {
        var group = tabs.parentElement;
        tabs.querySelectorAll('[data-hdr-tab]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var name = tab.getAttribute('data-hdr-tab');
                tabs.querySelectorAll('[data-hdr-tab]').forEach(function (t) {
                    var active = t === tab;
                    t.classList.toggle('is-active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                group.querySelectorAll('[data-hdr-panel]').forEach(function (p) {
                    var active = p.getAttribute('data-hdr-panel') === name;
                    p.classList.toggle('is-active', active);
                    p.hidden = !active;
                });
            });
        });
    });
})();

/* Перестановка строк повторителей стрелками (футер и слоты виджетов). */
(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-fb-move], [data-repeater-move]');
        if (!btn) { return; }
        e.preventDefault();
        var row = btn.closest('.repeater-row');
        if (!row) { return; }
        var direction = btn.getAttribute('data-repeater-move') || btn.getAttribute('data-fb-move');
        if (direction === 'up') {
            var prev = row.previousElementSibling;
            if (prev) { row.parentNode.insertBefore(row, prev); }
        } else {
            var next = row.nextElementSibling;
            if (next) { row.parentNode.insertBefore(next, row); }
        }
    });
})();

/* Делегированные обработчики вместо инлайн-атрибутов (CSP без 'unsafe-inline'). */
(function () {
    'use strict';
    // Селект с автоотправкой формы (фильтры списков новостей/страниц/проектов).
    document.addEventListener('change', function (e) {
        var el = e.target;
        if (el.matches && el.matches('select[data-auto-submit]') && el.form) {
            el.form.submit();
            return;
        }
        // Селект типа виджета показывает поля выбранного типа.
        if (el.matches && el.matches('select[data-widget-type-select]')) {
            document.querySelectorAll('[data-wtype]').forEach(function (block) {
                block.classList.toggle('is-hidden', block.getAttribute('data-wtype') !== el.value);
            });
        }
    });
})();

/* Локальное автосохранение контентных форм. Не сохраняем CSRF, файлы и
   пароли; черновик остаётся только в браузере редактора. */
(function () {
    'use strict';

    var currentUrl = new URL(window.location.href);
    var savedDraft = currentUrl.searchParams.get('draft_saved');
    if (savedDraft) {
        try { localStorage.removeItem('artstudio:draft:' + savedDraft); } catch (e) {}
        currentUrl.searchParams.delete('draft_saved');
        window.history.replaceState({}, document.title, currentUrl.pathname + currentUrl.search + currentUrl.hash);
    }

    document.querySelectorAll('form[data-content-draft]').forEach(function (form) {
        var key = 'artstudio:draft:' + form.getAttribute('data-content-draft');
        var dirty = false;

        function fields() {
            var values = {};
            Array.prototype.forEach.call(form.elements, function (el) {
                if (!el.name || el.disabled || el.type === 'file' || el.type === 'password'
                    || el.name === 'csrf_token' || el.name === 'expected_updated_at'
                    || el.name === 'expected_lock_version') { return; }
                if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) { return; }
                if (values[el.name] !== undefined) {
                    if (!Array.isArray(values[el.name])) { values[el.name] = [values[el.name]]; }
                    values[el.name].push(el.value);
                } else {
                    values[el.name] = el.value;
                }
            });
            return values;
        }

        function save() {
            if (!dirty) { return; }
            try {
                localStorage.setItem(key, JSON.stringify({ savedAt: Date.now(), values: fields() }));
            } catch (e) {}
        }

        function apply(values) {
            form.querySelectorAll('input[type="checkbox"], input[type="radio"]').forEach(function (el) {
                el.checked = false;
            });
            Object.keys(values || {}).forEach(function (name) {
                var controls = form.querySelectorAll('[name="' + CSS.escape(name) + '"]');
                var inputValues = Array.isArray(values[name]) ? values[name].map(String) : [String(values[name])];
                controls.forEach(function (el) {
                    if (el.type === 'checkbox' || el.type === 'radio') {
                        el.checked = inputValues.indexOf(el.value) !== -1;
                    } else {
                        el.value = inputValues[0] || '';
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                        el.dispatchEvent(new Event('arteditor:restore'));
                    }
                });
            });
            dirty = true;
        }

        form.addEventListener('input', function () { dirty = true; });
        form.addEventListener('change', function () { dirty = true; });
        form.addEventListener('submit', function () {
            try { localStorage.removeItem(key); } catch (e) {}
            dirty = false;
        });
        window.setInterval(save, 20000);
        window.addEventListener('beforeunload', function (event) {
            if (dirty) {
                save();
                event.preventDefault();
                event.returnValue = '';
            }
        });

        // После успешного сохранения на сервере (параметр draft_saved в URL)
        // сбрасываем устаревший локальный черновик и не выводим предупреждение.
        if (window.location.search.indexOf('draft_saved=') !== -1) {
            try { localStorage.removeItem(key); } catch (e) {}
            return;
        }

        try {
            var draft = JSON.parse(localStorage.getItem(key) || 'null');
            if (!draft || !draft.savedAt || !draft.values) { return; }
            if (Date.now() - Number(draft.savedAt) > 7 * 24 * 60 * 60 * 1000) {
                localStorage.removeItem(key);
                return;
            }
            var banner = document.createElement('div');
            banner.className = 'alert alert--warning content-draft-banner';
            banner.innerHTML = '<span>Найден локальный черновик от ' + new Date(draft.savedAt).toLocaleString() + '.</span> '
                + '<button type="button" class="btn btn--small" data-draft-restore>Восстановить</button> '
                + '<button type="button" class="btn btn--small" data-draft-discard>Удалить</button>';
            form.parentNode.insertBefore(banner, form);
            banner.querySelector('[data-draft-restore]').addEventListener('click', function () {
                apply(draft.values);
                banner.remove();
            });
            banner.querySelector('[data-draft-discard]').addEventListener('click', function () {
                localStorage.removeItem(key);
                banner.remove();
            });
        } catch (e) {}
    });
})();

// --- Выбор картинки из медиабиблиотеки в строках повторителей ---
// Поля логотипов, фото и изображений внутри повторяющихся строк были обычными
// текстовыми input: путь приходилось вписывать руками. Кнопку добавляем
// автоматически всем таким полям — включая строки, добавленные уже после
// загрузки страницы (шаблон __INDEX__ клонируется скриптом повторителя).
(function () {
    var NAME_RE = /\[(image|logo|photo|cover|media)\]$/i;
    var seq = 0;

    function enhance(input) {
        if (!input || input.dataset.mediaEnhanced === '1') { return; }
        var name = input.getAttribute('name') || '';
        if (input.type !== 'text' || !NAME_RE.test(name)) { return; }
        // Поля, уже обёрнутые общим компонентом, трогать не нужно.
        if (input.closest('[data-image-field]') || input.hasAttribute('data-image-input')) { return; }
        input.dataset.mediaEnhanced = '1';

        if (!input.id) { input.id = 'mediafld_' + (++seq); }
        var pick = document.createElement('button');
        pick.type = 'button';
        pick.className = 'btn btn--small';
        pick.textContent = 'Медиабиблиотека';
        pick.setAttribute('data-media-pick', '');
        pick.setAttribute('data-media-target', '#' + input.id);

        var row = document.createElement('div');
        row.className = 'repeater-media';
        input.parentNode.insertBefore(row, input);
        row.appendChild(input);
        row.appendChild(pick);
    }

    function scan(root) {
        (root || document).querySelectorAll('input[type="text"]').forEach(enhance);
    }

    scan(document);
    // Новые строки повторителя появляются после клика «Добавить».
    if (window.MutationObserver) {
        new MutationObserver(function (records) {
            records.forEach(function (r) {
                Array.prototype.forEach.call(r.addedNodes, function (node) {
                    if (node.nodeType !== 1) { return; }
                    if (node.matches && node.matches('input[type="text"]')) { enhance(node); }
                    scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    // --- Автоматические уведомления и подсветка при пропуске обязательных полей ---
    (function () {
        function showNotificationToast(msg, type) {
            var existing = document.querySelector('.admin-toast-notification');
            if (existing) { existing.remove(); }

            var toast = document.createElement('div');
            toast.className = 'admin-toast-notification admin-toast--' + (type || 'warning');
            var statusIcon = window.asdrTablerIcon
                ? window.asdrTablerIcon(type === 'error' ? 'circle-x' : 'alert-triangle', 16)
                : '';
            var closeIcon = window.asdrTablerIcon ? window.asdrTablerIcon('x', 16) : '';
            toast.innerHTML = '<div class="u-inline-7e30d285d2">'
                + '<span class="u-inline-4f1925a8a6">' + statusIcon + '</span>'
                + '<span class="u-inline-94c3db5540">' + msg + '</span>'
                + '</div>'
                + '<button class="u-inline-d8c73d8aa0" type="button" onclick="this.parentNode.remove()">' + closeIcon + '</button>';
            document.body.appendChild(toast);
            requestAnimationFrame(function () { toast.classList.add('is-visible'); });
            setTimeout(function () {
                toast.classList.remove('is-visible');
                setTimeout(function () { toast.remove(); }, 300);
            }, 5000);
        }
        window.showAdminNotification = showNotificationToast;

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || form.tagName !== 'FORM') { return; }
            if (form.getAttribute('novalidate') !== null || form.dataset.skipValidation === '1') { return; }

            var emptyFields = [];
            var firstEmpty = null;

            var inputs = form.querySelectorAll('input[required], textarea[required], select[required], [data-required]');
            inputs.forEach(function (input) {
                var val = (input.value || '').trim();
                if (window.tinymce && input.id && window.tinymce.get(input.id)) {
                    val = window.tinymce.get(input.id).getContent({ format: 'text' }).trim();
                }

                if (val === '') {
                    emptyFields.push(input);
                    input.classList.add('is-invalid');
                    if (!firstEmpty) { firstEmpty = input; }

                    input.addEventListener('input', function onInp() {
                        if ((input.value || '').trim() !== '') {
                            input.classList.remove('is-invalid');
                            input.removeEventListener('input', onInp);
                        }
                    });
                } else {
                    input.classList.remove('is-invalid');
                }
            });

            if (emptyFields.length > 0) {
                event.preventDefault();
                event.stopPropagation();

                var labelText = '';
                if (firstEmpty) {
                    var label = form.querySelector('label[for="' + firstEmpty.id + '"]') || (firstEmpty.closest('.form-field') ? firstEmpty.closest('.form-field').querySelector('label') : null);
                    if (label) { labelText = label.textContent.replace(/\*/g, '').trim(); }
                }

                var msg = 'Пожалуйста, заполните обязательное поле' + (labelText ? ': «' + labelText + '»' : '');
                showNotificationToast(msg, 'warning');

                if (firstEmpty) {
                    firstEmpty.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    try { firstEmpty.focus({ preventScroll: true }); } catch (e) {}
                }
            }
        });
    })();

    // --- Интерактивный живой предпросмотр SEO & Соцсетей ---
    (function () {
        document.addEventListener('click', function (e) {
            var tabBtn = e.target.closest('[data-seo-tab]');
            if (!tabBtn) return;
            var tabName = tabBtn.getAttribute('data-seo-tab');
            var container = tabBtn.closest('.seo-live-preview');
            if (!container) return;

            container.querySelectorAll('[data-seo-tab]').forEach(function (btn) {
                btn.classList.toggle('is-active', btn === tabBtn);
            });
            container.querySelectorAll('[data-seo-panel]').forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-seo-panel') !== tabName;
            });
        });

        function updateSeoLivePreview() {
            var liveBoxes = document.querySelectorAll('.seo-live-preview');
            if (!liveBoxes.length) return;

            var titleInput = document.querySelector('input[name="title"], input[name="meta_title"]');
            var descInput = document.querySelector('input[name="meta_description"], textarea[name="meta_description"], textarea[name="lead_html"], textarea[name="key_points"]');
            var imageInput = document.querySelector('input[name="image_url"], input[name="image"]');

            var titleVal = titleInput ? (titleInput.value || '').trim() : '';
            var descVal = descInput ? (descInput.value || '').trim() : '';
            if (descInput && descInput.getAttribute('name') === 'lead_html') {
                descVal = plainLeadText(descInput);
            }
            var imgVal = imageInput ? (imageInput.value || '').trim() : '';

            var titleText = titleVal || 'Заголовок вашей новости или страницы';
            var descText = descVal || 'Краткое описание или лид новости отображается здесь в поисковой выдаче и мессенджерах...';

            liveBoxes.forEach(function (box) {
                var gTitle = box.querySelector('[data-seo-google-title]');
                var gDesc = box.querySelector('[data-seo-google-desc]');
                var sTitle = box.querySelector('[data-seo-social-title]');
                var sDesc = box.querySelector('[data-seo-social-desc]');
                var tCount = box.querySelector('[data-seo-title-count]');
                var dCount = box.querySelector('[data-seo-desc-count]');
                var sImg = box.querySelector('[data-seo-social-img]');
                var sNoImg = box.querySelector('[data-seo-social-noimg]');

                if (gTitle) gTitle.textContent = titleText;
                if (gDesc) gDesc.textContent = descText;
                if (sTitle) sTitle.textContent = titleText;
                if (sDesc) sDesc.textContent = descText;

                if (tCount) {
                    tCount.textContent = titleVal.length;
                    tCount.classList.toggle('is-invalid', titleVal.length > 65);
                    tCount.classList.toggle('is-valid', titleVal.length >= 30 && titleVal.length <= 65);
                }
                if (dCount) {
                    dCount.textContent = descVal.length;
                    dCount.classList.toggle('is-invalid', descVal.length > 160);
                    dCount.classList.toggle('is-valid', descVal.length >= 70 && descVal.length <= 160);
                }

                if (sImg && sNoImg) {
                    if (imgVal !== '') {
                        sImg.src = imgVal;
                        sImg.classList.remove('is-hidden');
                        sNoImg.classList.add('is-hidden');
                    } else {
                        sImg.classList.add('is-hidden');
                        sNoImg.classList.remove('is-hidden');
                    }
                }
            });
        }

        document.addEventListener('input', updateSeoLivePreview);
        document.addEventListener('change', updateSeoLivePreview);
        setTimeout(updateSeoLivePreview, 300);
    })();

    // --- Универсальная командная палитра (Ctrl + K / Cmd + K) ---
    // --- Универсальная командная палитра (Ctrl + K / Cmd + K) ---
    (function () {
        var paletteHtml = '<div class="admin-cmd-palette-overlay u-inline-58d7c6be2b" data-cmd-overlay>'
            + '<div class="admin-cmd-palette-modal u-inline-27aa68da7d" role="dialog" aria-modal="true" aria-label="Командная палитра">'
            + '<div class="u-inline-907d56949b">'
            + (window.asdrTablerIcon ? window.asdrTablerIcon('search', 18) : '')
            + '<input class="u-inline-7180243890" type="text" data-cmd-input placeholder="Введите название раздела, страницы или действие..." aria-label="Поиск по разделу или действию">'
            + '<kbd class="u-inline-8d83117354">ESC</kbd>'
            + '</div>'
            + '<div class="admin-cmd-results u-inline-afdf6b2045" data-cmd-results>'
            + '</div>'
            + '<div class="u-inline-a650a7522f">'
            + '<span>↑↓ Выбор</span><span>↵ Переход</span><span>ESC Закрыть</span>'
            + '</div>'
            + '</div>'
            + '</div>';

        document.body.insertAdjacentHTML('beforeend', paletteHtml);

        var overlay = document.querySelector('[data-cmd-overlay]');
        var input = document.querySelector('[data-cmd-input]');
        var resultsContainer = document.querySelector('[data-cmd-results]');
        var selectedIdx = 0;
        var matchedItems = [];
        var lastActiveElement = null;
        var searchTimer = null;

        var allCommands = [
            { icon: 'news', title: 'Новости', desc: 'Управление публикациями и новостями', url: '/admin/news' },
            { icon: 'plus', title: 'Добавить новую новость', desc: 'Создать статью или анонс', url: '/admin/news/create' },
            { icon: 'files', title: 'Страницы', desc: 'Структура и разделы сайта', url: '/admin/pages' },
            { icon: 'plus', title: 'Добавить страницу', desc: 'Создать новую страницу', url: '/admin/pages/create' },
            { icon: 'briefcase', title: 'Проекты', desc: 'Реестр проектов и направлений', url: '/admin/projects' },
            { icon: 'palette', title: 'Дизайн и Оформление', desc: 'Шрифты, цвета и пресеты', url: '/admin/design' },
            { icon: 'menu-2', title: 'Конструктор меню', desc: 'Навигация в шапке и подвале', url: '/admin/menu' },
            { icon: 'photo', title: 'Медиабиблиотека', desc: 'Загрузка изображений и документов', url: '/admin/files' },
            { icon: 'brand-telegram', title: 'Telegram Автопостинг', desc: 'Настройка социальных сетей', url: '/admin/telegram' },
            { icon: 'clipboard-list', title: 'Журналы', desc: 'Действия, ошибки и системные события', url: '/admin/audit' },
            { icon: 'shield-lock', title: 'Безопасность & 2FA', desc: 'Управление доступом и сессиями', url: '/admin/security' },
            { icon: 'world', title: 'Перейти на сайт', desc: 'Открыть публичный сайт', url: '/' }
        ];

        function isUrlAccessible(url) {
            if (url === '/' || url === '/admin' || url === '/admin/profile') { return true; }
            var sidebarLinks = document.querySelectorAll('.admin-nav-item[href], .admin-sidebar a[href]');
            if (!sidebarLinks.length) { return true; }
            for (var i = 0; i < sidebarLinks.length; i++) {
                var href = sidebarLinks[i].getAttribute('href');
                if (href && (href === url || url.indexOf(href) === 0)) {
                    return true;
                }
            }
            return false;
        }

        function getAccessibleCommands() {
            return allCommands.filter(function (c) {
                return isUrlAccessible(c.url);
            });
        }

        function updateSelectionVisual() {
            var links = resultsContainer.querySelectorAll('.admin-cmd-item');
            for (var i = 0; i < links.length; i++) {
                if (i === selectedIdx) {
                    links[i].classList.add('is-selected');
                    links[i].setAttribute('aria-selected', 'true');
                    links[i].scrollIntoView({ block: 'nearest' });
                } else {
                    links[i].classList.remove('is-selected');
                    links[i].removeAttribute('aria-selected');
                }
            }
        }

        function renderResults(filter, serverResults) {
            filter = (filter || '').toLowerCase().trim();
            var availableCmds = getAccessibleCommands();
            var matchedCmds = availableCmds.filter(function (c) {
                return !filter || c.title.toLowerCase().indexOf(filter) !== -1 || c.desc.toLowerCase().indexOf(filter) !== -1;
            });

            matchedItems = matchedCmds.map(function (c) {
                return { icon: c.icon, title: c.title, desc: c.desc, url: c.url, type: 'Раздел' };
            });

            if (serverResults && serverResults.length) {
                serverResults.forEach(function (r) {
                    matchedItems.push({
                        icon: 'search',
                        title: r.title,
                        desc: r.type || 'Объект',
                        url: r.url,
                        type: r.type || 'Объект'
                    });
                });
            }

            if (!matchedItems.length) {
                resultsContainer.innerHTML = '<div class="u-inline-f16ce3d7a8">Ничего не найдено</div>';
                return;
            }

            if (selectedIdx >= matchedItems.length) {
                selectedIdx = 0;
            }

            var html = '';
            matchedItems.forEach(function (c, idx) {
                var isSel = idx === selectedIdx;
                html += '<a href="' + c.url + '" class="admin-cmd-item u-inline-9e9c073f14' + (isSel ? ' is-selected' : '') + '" data-cmd-index="' + idx + '">'
                    + '<span class="u-inline-da71aab0cc">' + (window.asdrTablerIcon ? window.asdrTablerIcon(c.icon, 20) : '') + '</span>'
                    + '<div>'
                    + '<div class="u-inline-3f7fce4b31"></div>'
                    + '<div class="u-inline-afa3d0ea3b"></div>'
                    + '</div>'
                    + '</a>';
            });
            resultsContainer.innerHTML = html;

            var itemElems = resultsContainer.querySelectorAll('.admin-cmd-item');
            matchedItems.forEach(function (c, idx) {
                if (itemElems[idx]) {
                    var titleEl = itemElems[idx].querySelector('.u-inline-3f7fce4b31');
                    var descEl = itemElems[idx].querySelector('.u-inline-afa3d0ea3b');
                    if (titleEl) { titleEl.textContent = c.title; }
                    if (descEl) { descEl.textContent = c.desc; }
                }
            });

            updateSelectionVisual();
        }

        function openPalette() {
            lastActiveElement = document.activeElement;
            overlay.classList.add('is-open');
            input.value = '';
            selectedIdx = 0;
            renderResults('');
            setTimeout(function () { input.focus(); }, 50);
        }

        function closePalette() {
            overlay.classList.remove('is-open');
            if (lastActiveElement && typeof lastActiveElement.focus === 'function') {
                try { lastActiveElement.focus(); } catch (err) {}
            }
        }

        document.addEventListener('keydown', function (e) {
            var isK = (e.key === 'k' || e.key === 'K' || e.keyCode === 75);
            if ((e.ctrlKey || e.metaKey) && isK) {
                e.preventDefault();
                if (overlay.classList.contains('is-open')) {
                    closePalette();
                } else {
                    openPalette();
                }
                return;
            }

            if (!overlay.classList.contains('is-open')) { return; }

            if (e.key === 'Escape') {
                e.preventDefault();
                closePalette();
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (matchedItems.length > 0) {
                    selectedIdx = (selectedIdx + 1) % matchedItems.length;
                    updateSelectionVisual();
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (matchedItems.length > 0) {
                    selectedIdx = (selectedIdx - 1 + matchedItems.length) % matchedItems.length;
                    updateSelectionVisual();
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (matchedItems[selectedIdx]) {
                    window.location.href = matchedItems[selectedIdx].url;
                }
            }
        });

        document.addEventListener('click', function (e) {
            if (e.target.closest('[data-open-command-palette], [data-search], [data-search-input]')) {
                e.preventDefault();
                openPalette();
            }
            if (e.target === overlay) {
                closePalette();
            }
        });

        resultsContainer.addEventListener('mouseover', function (e) {
            var item = e.target.closest('.admin-cmd-item');
            if (!item) { return; }
            var idx = parseInt(item.getAttribute('data-cmd-index'), 10);
            if (!isNaN(idx) && idx !== selectedIdx) {
                selectedIdx = idx;
                updateSelectionVisual();
            }
        });

        if (input) {
            input.addEventListener('input', function () {
                selectedIdx = 0;
                var q = input.value.trim();
                renderResults(q);

                clearTimeout(searchTimer);
                if (q.length >= 2) {
                    searchTimer = setTimeout(function () {
                        fetch('/admin/search?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                if (overlay.classList.contains('is-open') && input.value.trim() === q) {
                                    renderResults(q, data.results || []);
                                }
                            })
                            .catch(function () {});
                    }, 200);
                }
            });
        }
    })();


    // --- ИИ-редактор новости: аннотация и SEO ---
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-ai-generate]');
        if (!btn) return;

        e.preventDefault();
        var form = btn.closest('form');
        if (!form) return;

        var titleInput = form.querySelector('[name="title"]');
        var title = titleInput ? titleInput.value : '';
        var target = btn.getAttribute('data-ai-generate') || 'summary';

        var content = '';
        var contentField = form.querySelector('[name="content"]');
        if (window.tinymce && contentField && contentField.id && tinymce.get(contentField.id)) {
            content = tinymce.get(contentField.id).getContent();
        }
        if (!content && contentField) { content = contentField.value; }

        if (!title.trim() && !content.trim()) {
            adminAlert('Пожалуйста, введите заголовок или текст новости перед генерацией.');
            return;
        }

        // Содержимое кнопки возвращаем узлами: перезапись innerHTML заново
        // разбирала бы разметку кнопки как HTML.
        var oldNodes = Array.prototype.slice.call(btn.childNodes);
        var restoreButton = function () {
            btn.textContent = '';
            oldNodes.forEach(function (node) { btn.appendChild(node); });
        };
        btn.disabled = true;
        btn.textContent = '⌛ ИИ думает...';

        var body = new URLSearchParams();
        body.append('title', title);
        body.append('content', content);
        body.append('target', target);

        var csrfInput = form.querySelector('[name="csrf_token"]');
        if (csrfInput) {
            body.append('csrf_token', csrfInput.value);
        }

        fetch('/admin/news/ai-summary', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (res) {
            return res.json().catch(function () {
                throw new Error('Сервер вернул некорректный ответ.');
            }).then(function (data) {
                if (!res.ok) {
                    throw new Error(data.error || ('HTTP ' + res.status));
                }
                return data;
            });
        }).then(function (data) {
            btn.disabled = false;
            restoreButton();
            if (data && data.ok) {
                if (target === 'summary' && data.excerpt) {
                    var excerptField = form.querySelector('[name="lead_html"]');
                    if (excerptField) {
                        var escaped = data.excerpt.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        var leadHtml = '<p>' + escaped + '</p>';
                        excerptField.value = leadHtml;
                        if (window.tinymce && excerptField.id && window.tinymce.get(excerptField.id)) {
                            window.tinymce.get(excerptField.id).setContent(leadHtml);
                            window.tinymce.get(excerptField.id).save();
                        }
                        excerptField.dispatchEvent(new Event('input'));
                    }
                }
                if (target === 'summary' && data.hashtags) {
                    var hashtagsField = form.querySelector('[name="hashtags"]');
                    if (hashtagsField) { hashtagsField.value = data.hashtags; }
                }
                if (target === 'meta_title' && data.meta_title) {
                    var metaTitleField = form.querySelector('[name="meta_title"]');
                    if (metaTitleField) { metaTitleField.value = data.meta_title; }
                }
                if (target === 'meta_description' && data.meta_description) {
                    var metaDescriptionField = form.querySelector('[name="meta_description"]');
                    if (metaDescriptionField) { metaDescriptionField.value = data.meta_description; }
                }
                if (data.notice) {
                    adminAlert(data.notice);
                }
            } else if (data && data.error) {
                adminAlert(data.error);
            }
        }).catch(function (err) {
            btn.disabled = false;
            restoreButton();
            adminAlert('Ошибка при вызове ИИ: ' + (err.message || err));
        });
    });
})();

/**
 * Обработчики, заменившие inline-атрибуты (onclick/onchange) в шаблонах:
 * CSP админки не разрешает inline-скрипты, поэтому такие атрибуты браузер
 * молча не выполнял — не открывался выбор файла, не работали фильтры
 * медиабиблиотеки и предпросмотр темы.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var picker = event.target.closest('[data-file-pick]');
        if (picker) {
            var input = document.getElementById(picker.getAttribute('data-file-pick'));
            if (input) { input.click(); }
            return;
        }

        var closer = event.target.closest('[data-close-target]');
        if (closer) {
            var box = document.getElementById(closer.getAttribute('data-close-target'));
            if (box) { box.classList.remove('is-open'); }
            return;
        }

        var remover = event.target.closest('[data-remove-closest]');
        if (remover) {
            var row = remover.closest(remover.getAttribute('data-remove-closest'));
            if (row) { row.remove(); }
            return;
        }

        // Примерка внешнего вида до сохранения: пять цветовых тем сведены к
        // одной, а выбор «как в системе / светлая / тёмная» остался — и его
        // так же полезно увидеть, не сохраняя.
        var appearancePreview = event.target.closest('[data-admin-appearance-preview]');
        if (appearancePreview) {
            document.documentElement.setAttribute(
                'data-admin-appearance',
                appearancePreview.getAttribute('data-admin-appearance-preview')
            );
        }
    });

    // Фильтры медиабиблиотеки отправляют форму сразу после выбора.
    document.addEventListener('change', function (event) {
        var select = event.target.closest('select[data-autosubmit]');
        if (select && select.form) { select.form.submit(); }
    });
})();

// Напоминания редактору: список свёрнут, чтобы не занимать экран у тех, кто
// и так всё заполняет.
document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-checklist-toggle]');
    if (!toggle) { return; }
    var box = toggle.closest('[data-content-checklist]');
    var list = box && box.querySelector('.content-checklist__list');
    if (!list) { return; }
    var open = list.hidden;
    list.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.textContent = open ? 'скрыть' : 'показать';
});

// --- Автоматическое скрытие Toast-уведомлений и управление выпадающими меню ---
document.addEventListener('DOMContentLoaded', function () {
    // 1. Авто-скрытие Toast через 4 секунды
    var toasts = document.querySelectorAll('.admin-toast, [data-toast]');
    toasts.forEach(function (toast) {
        toast.classList.add('is-show');
        var timer = setTimeout(function () {
            toast.classList.remove('is-show');
            setTimeout(function () { if (toast.parentNode) toast.remove(); }, 300);
        }, 4000);
        
        var closeBtn = toast.querySelector('.admin-toast__close, [data-toast-close]');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                clearTimeout(timer);
                toast.classList.remove('is-show');
                setTimeout(function () { if (toast.parentNode) toast.remove(); }, 300);
            });
        }
    });

    // 2. Делегирование кликов для Dropdown Kebab Menu (.admin-dropdown)
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-dropdown-toggle], .admin-dropdown__toggle');
        if (toggle) {
            event.preventDefault();
            var dropdown = toggle.closest('.admin-dropdown');
            if (dropdown) {
                var isOpen = dropdown.classList.contains('is-open');
                document.querySelectorAll('.admin-dropdown.is-open').forEach(function (d) {
                    if (d !== dropdown) d.classList.remove('is-open');
                });
                dropdown.classList.toggle('is-open', !isOpen);
            }
            return;
        }

        // Закрывать выпадающие меню при клике вне
        if (!event.target.closest('.admin-dropdown')) {
            document.querySelectorAll('.admin-dropdown.is-open').forEach(function (d) {
                d.classList.remove('is-open');
            });
        }
    });
});

/* Оргструктура: выбор сектора из списка дописывает готовую строку
   «Название | /страница#team-slug» в поле подразделений этой же ветки.
   Раньше адрес собирали руками, и переименование сектора молча ломало ссылку. */
document.addEventListener('change', function (event) {
    var picker = event.target.closest ? event.target.closest('[data-org-sector-insert]') : null;
    if (!picker || !picker.value) return;

    var row = picker.closest('.repeater-row') || picker.parentElement.parentElement;
    var field = row ? row.querySelector('textarea[name$="[units]"]') : null;
    if (!field) {
        picker.value = '';
        return;
    }

    var value = field.value.replace(/\s+$/, '');
    field.value = (value ? value + '\n' : '') + picker.value + '\n';
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.focus();
    field.selectionStart = field.selectionEnd = field.value.length;
    picker.value = '';
});

/* Фон секции и подвала: показываем поля только выбранного режима. Раньше все
   двенадцать полей висели разом, и было не понять, какие из них сейчас
   работают. Без скрипта видно всё — форма остаётся рабочей. */
(function () {
    var apply = function (select) {
        var mode = select.value || 'preset';
        var scope = select.closest('form') || document;
        scope.querySelectorAll('[data-bg-group]').forEach(function (group) {
            var modes = group.getAttribute('data-bg-group').split(' ');
            group.hidden = modes.indexOf(mode) === -1;
        });
    };

    var init = function () {
        document.querySelectorAll('select[name="bg_mode"]').forEach(function (select) {
            apply(select);
            select.addEventListener('change', function () { apply(select); });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/* --- Фоновая надпись секции: показывать подгруппу настроек только при введённом тексте --- */
(function () {
    var apply = function (input) {
        var form = input.closest('form') || document;
        var group = form.querySelector('[data-watermark-group]');
        if (group) {
            group.hidden = !input.value.trim();
        }
    };

    var init = function () {
        document.querySelectorAll('input[name="watermark"]').forEach(function (input) {
            apply(input);
            input.addEventListener('input', function () { apply(input); });
            input.addEventListener('change', function () { apply(input); });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/* ========================================================================
   Enterprise Local Draft Auto-Recovery
   (Защита от потери данных редактора при сетевых сбоях и конфликтах версий)
   ======================================================================== */
(function initLocalDraftRecovery() {
    if (typeof window === 'undefined' || !window.localStorage) {
        return;
    }

    var form = document.querySelector('.admin-form, form[data-draft-track]');
    if (!form || form.getAttribute('method')?.toLowerCase() !== 'post') {
        return;
    }

    var key = 'cms_draft_' + window.location.pathname.replace(/\/$/, '');
    var timer = null;

    function getFormData() {
        var data = {};
        var elements = form.querySelectorAll('input:not([type="hidden"]):not([type="password"]), textarea, select');
        elements.forEach(function (el) {
            if (el.name && !el.disabled) {
                data[el.name] = el.value;
            }
        });
        return data;
    }

    function saveDraft() {
        try {
            var payload = {
                time: Date.now(),
                data: getFormData()
            };
            localStorage.setItem(key, JSON.stringify(payload));
        } catch (e) {}
    }

    function checkDraft() {
        try {
            var raw = localStorage.getItem(key);
            if (!raw) return;
            var draft = JSON.parse(raw);
            if (!draft || !draft.data || !draft.time) return;

            // Если черновику меньше 7 дней
            if (Date.now() - draft.time > 7 * 86400 * 1000) {
                localStorage.removeItem(key);
                return;
            }

            var banner = document.createElement('div');
            banner.className = 'admin-draft-banner';
            banner.innerHTML = '<span class="admin-draft-banner__text"><b>Черновик:</b> найдены несохраненные изменения от ' + new Date(draft.time).toLocaleTimeString() + '</span>' +
                '<div class="admin-draft-banner__actions"><button type="button" class="btn btn--sm btn--primary" data-action="restore">Восстановить</button>' +
                '<button type="button" class="btn btn--sm btn--secondary" data-action="discard">Отклонить</button></div>';

            banner.querySelector('[data-action="restore"]').addEventListener('click', function () {
                Object.keys(draft.data).forEach(function (name) {
                    var field = form.querySelector('[name="' + name + '"]');
                    if (field && field.value !== draft.data[name]) {
                        field.value = draft.data[name];
                        field.dispatchEvent(new Event('input', { bubbles: true }));
                        field.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
                banner.remove();
            });

            banner.querySelector('[data-action="discard"]').addEventListener('click', function () {
                localStorage.removeItem(key);
                banner.remove();
            });

            form.parentNode.insertBefore(banner, form);
        } catch (e) {}
    }

    form.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(saveDraft, 3000);
    });

    form.addEventListener('submit', function () {
        localStorage.removeItem(key);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', checkDraft);
    } else {
        checkDraft();
    }
})();


/*
 * Поля формы блока, которые действуют не при каждом варианте оформления.
 * Разметка: обёртка поля получает data-field-when="<имя поля>" и
 * data-field-value="<значения через запятую>". Без JS поле просто остаётся
 * видимым — настройка от этого не ломается, лишь теряется подсказка.
 */
(function () {
    var fields = document.querySelectorAll('[data-field-when]');
    if (!fields.length) return;

    function apply() {
        fields.forEach(function (field) {
            var source = document.querySelector('[name="' + field.getAttribute('data-field-when') + '"]');
            if (!source) return;
            var allowed = (field.getAttribute('data-field-value') || '').split(',');
            field.hidden = allowed.indexOf(source.value) === -1;
        });
    }

    var watched = {};
    fields.forEach(function (field) {
        var name = field.getAttribute('data-field-when');
        if (watched[name]) return;
        watched[name] = true;
        var source = document.querySelector('[name="' + name + '"]');
        if (source) source.addEventListener('change', apply);
    });
    apply();
})();

/*
 * Форма блока «Коллаж»: набор полей элемента зависит от его типа.
 *
 * Показывать все поля разом нельзя — редактор видел бы «Надпись по кругу» у
 * фотографии и «Кадрирование» у узора и не понимал бы, какие из них
 * действуют. Скрытые поля остаются в форме и уходят на сервер, но
 * нормализатор берёт только те, что относятся к выбранному типу.
 */
(function () {
    'use strict';

    var FIELDS = {
        photo: ['shape', 'photo', 'link'],
        stat: ['shape', 'stat', 'colors', 'link'],
        // Печать всегда круглая: выбор формы у неё ничего не менял бы.
        badge: ['badge', 'colors', 'link'],
        pattern: ['shape', 'pattern', 'colors']
    };

    function applyRow(row) {
        var select = row.querySelector('[data-collage-type]');
        if (!select) { return; }
        var allowed = FIELDS[select.value] || FIELDS.photo;
        row.querySelectorAll('[data-collage-fields]').forEach(function (group) {
            group.hidden = allowed.indexOf(group.getAttribute('data-collage-fields')) === -1;
        });
    }

    function applyAll(root) {
        (root || document).querySelectorAll('[data-collage-repeater] .repeater-row').forEach(applyRow);
    }

    var repeater = document.querySelector('[data-collage-repeater]');
    if (!repeater) { return; }

    applyAll();
    document.addEventListener('change', function (event) {
        var select = event.target.closest ? event.target.closest('[data-collage-type]') : null;
        if (!select) { return; }
        var row = select.closest('.repeater-row');
        if (row) { applyRow(row); }
    });
    // Новая строка приходит из шаблона репитера со всеми полями сразу.
    new MutationObserver(function () { applyAll(repeater); }).observe(repeater, { childList: true });
})();


/*
 * Ход перехода и место правки.
 *
 * Админка отвечает полной перезагрузкой на каждое сохранение и каждый переход:
 * формы уходят обычным POST, ответ приходит редиректом. Само по себе это
 * нормально (CSRF, загрузка файлов, flash-сообщения устроены под это), но
 * между нажатием и новой страницей не происходило ничего — ожидание читалось
 * как «кнопка не сработала», а на длинной форме сохранение ещё и выбрасывало
 * редактора в начало страницы.
 *
 * Здесь два приёма, оба — прогрессивное улучшение: без JS админка работает
 * ровно как раньше.
 */
(function () {
    'use strict';

    /* --- Полоса хода ------------------------------------------------------ */

    var bar = null;
    var creep = null;
    var giveUp = null;

    function width() {
        return parseFloat(bar && bar.style.width) || 0;
    }

    function startProgress() {
        if (bar) { return; }
        bar = document.createElement('div');
        bar.className = 'admin-route-progress';
        // Полоса ничего не сообщает диктору: он и так узнает о новой странице.
        bar.setAttribute('aria-hidden', 'true');
        document.body.appendChild(bar);

        // Ширина ставится следующим кадром, иначе переход от нуля не сыграет.
        requestAnimationFrame(function () {
            if (!bar) { return; }
            bar.style.width = '35%';
            // Точного прогресса у серверного перехода нет: подбираемся к 90 %
            // всё меньшими шагами и обрываемся вместе с документом.
            creep = window.setInterval(function () {
                if (!bar) { return; }
                var current = width();
                if (current >= 90) { return; }
                bar.style.width = (current + (90 - current) * 0.22) + '%';
            }, 450);
        });

        // Страница может и не уйти: скачивание файла, отменённая отправка,
        // ошибка сети. Бесконечная полоса врёт хуже, чем её отсутствие.
        giveUp = window.setTimeout(finishProgress, 20000);
    }

    function finishProgress() {
        if (!bar) { return; }
        var node = bar;
        bar = null;
        window.clearInterval(creep);
        window.clearTimeout(giveUp);
        node.style.width = '100%';
        node.classList.add('is-done');
        window.setTimeout(function () { node.remove(); }, 400);
    }

    /* --- Возврат к месту правки ------------------------------------------- */

    var SCROLL_KEY = 'artstudio:admin:scroll:';

    function rememberScroll() {
        try {
            window.sessionStorage.setItem(
                SCROLL_KEY + window.location.pathname,
                String(window.scrollY || window.pageYOffset || 0)
            );
        } catch (e) {}
    }

    function restoreScroll() {
        // Якорь в адресе — это осознанная цель редиректа (#smtp-section),
        // спорить с ней нельзя.
        if (window.location.hash) { return; }
        var key = SCROLL_KEY + window.location.pathname;
        var saved = null;
        try {
            saved = window.sessionStorage.getItem(key);
            window.sessionStorage.removeItem(key);
        } catch (e) { return; }
        var top = parseInt(saved || '', 10);
        if (!top || top < 80) { return; }
        window.scrollTo(0, top);
    }

    /* --- Точки включения --------------------------------------------------- */

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM' || form.hasAttribute('data-no-progress')) { return; }
        if ((form.getAttribute('target') || '') !== '') { return; }

        // Подтверждение и проверка полей отменяют отправку уже после этого
        // обработчика, поэтому решение откладываем на следующий тик.
        window.setTimeout(function () {
            if (event.defaultPrevented) { return; }
            rememberScroll();
            startProgress();
        }, 0);
    });

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0) { return; }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }

        var link = event.target.closest ? event.target.closest('a[href]') : null;
        if (!link || link.hasAttribute('download') || (link.getAttribute('target') || '') !== '') { return; }

        var href = link.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#') { return; }
        // Схему спрашиваем у разобранной ссылки, а не сверяем строку начала
        // href: строковая проверка не видит ни регистра, ни пробелов перед
        // схемой, ни данных, закодированных сущностями. Всё, что не http(s),
        // переходом страницы не является.
        if (link.protocol !== 'http:' && link.protocol !== 'https:') { return; }
        if (link.origin !== window.location.origin) { return; }
        // Ссылка на текущую страницу с якорем переходом не является.
        if (link.pathname === window.location.pathname && link.hash !== '') { return; }

        startProgress();
    });

    // Возврат «назад» отдаёт страницу из кэша браузера — полоса на ней осталась
    // бы навсегда: до выгрузки она не успевает исчезнуть.
    window.addEventListener('pageshow', finishProgress);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreScroll);
    } else {
        restoreScroll();
    }
})();
