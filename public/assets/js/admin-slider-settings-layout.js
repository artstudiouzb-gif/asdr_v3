(function () {
    'use strict';

    var TARGETS = {
        toggles: ['Автопрокрутка', 'Стрелки', 'Пагинация', 'Цикл'],
        fields: ['Интервал, мс', 'Переход, мс', 'Эффект перехода', 'Навигация']
    };
    var ALL_LABELS = TARGETS.toggles.concat(TARGETS.fields);
    var TAB_LABELS = ['Общие', 'Карточка', 'Слайдер'];

    function cleanText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function lower(value) {
        return cleanText(value).toLocaleLowerCase('ru-RU');
    }

    function labelMatches(label, expected) {
        var value = lower(label.textContent);
        var needle = lower(expected);
        return value === needle || value.indexOf(needle) === 0;
    }

    function findLabel(root, expected) {
        var labels = root.querySelectorAll('label');
        for (var i = 0; i < labels.length; i++) {
            if (labelMatches(labels[i], expected)) {
                return labels[i];
            }
        }
        return null;
    }

    function countTargetLabels(root) {
        var count = 0;
        ALL_LABELS.forEach(function (expected) {
            if (findLabel(root, expected)) { count++; }
        });
        return count;
    }

    function hasSliderTabs(root) {
        var found = {};
        var candidates = root.querySelectorAll('button, a, [role="tab"]');
        candidates.forEach(function (candidate) {
            var text = cleanText(candidate.textContent);
            if (TAB_LABELS.indexOf(text) !== -1) {
                found[text] = true;
            }
        });
        return TAB_LABELS.every(function (label) { return found[label]; });
    }

    function findSettingsHeading(scope) {
        var candidates = scope.querySelectorAll('h1, h2, h3, h4, h5, .admin-card-title, .form-card__title, strong');
        for (var i = 0; i < candidates.length; i++) {
            if (lower(candidates[i].textContent).indexOf('настройки слайдера') !== -1) {
                return candidates[i];
            }
        }
        return null;
    }

    function findSettingsRoot(heading) {
        var node = heading;
        var best = null;
        for (var depth = 0; node && node !== document.body && depth < 10; depth++, node = node.parentElement) {
            if (countTargetLabels(node) >= 6) {
                best = node;
                if (hasSliderTabs(node)) { return node; }
            }
        }
        return best;
    }

    function fieldContainer(label) {
        return label.closest('.form-field, .settings-field, .admin-field, .field, .control-group')
            || (label.parentElement && label.parentElement !== document.body ? label.parentElement : null);
    }

    function controlFor(label, container, expectedType) {
        var id = label.getAttribute('for');
        var control = id ? document.getElementById(id) : null;
        if (control && container && !container.contains(control)) {
            control = null;
        }
        if (!control) {
            control = label.querySelector('input, select, textarea');
        }
        if (!control && container) {
            control = container.querySelector(expectedType || 'input, select, textarea');
        }
        return control;
    }

    function commonParent(elements, boundary) {
        if (!elements.length) { return null; }
        var node = elements[0];
        while (node && node !== boundary.parentElement) {
            var ownsAll = elements.every(function (element) { return node.contains(element); });
            if (ownsAll) { return node; }
            node = node.parentElement;
        }
        return boundary;
    }

    function directChildWithin(element, parent) {
        var node = element;
        while (node && node.parentElement !== parent) {
            node = node.parentElement;
        }
        return node && node.parentElement === parent ? node : element;
    }

    function addSectionHeader(section, title, hint) {
        var header = document.createElement('div');
        header.className = 'slider-settings-layout__section-head';

        var text = document.createElement('div');
        var heading = document.createElement('h4');
        heading.className = 'slider-settings-layout__section-title';
        heading.textContent = title;
        var description = document.createElement('p');
        description.className = 'slider-settings-layout__section-hint';
        description.textContent = hint;
        text.appendChild(heading);
        text.appendChild(description);
        header.appendChild(text);
        section.appendChild(header);
    }

    function enhanceTabs(root) {
        var tabs = [];
        root.querySelectorAll('button, a, [role="tab"]').forEach(function (candidate) {
            if (TAB_LABELS.indexOf(cleanText(candidate.textContent)) !== -1) {
                tabs.push(candidate);
            }
        });
        if (tabs.length < 3) { return; }

        var parent = commonParent(tabs, root);
        if (!parent || parent === root) {
            parent = tabs[0].parentElement;
        }
        if (!parent) { return; }

        parent.classList.add('slider-settings-layout__tabs');
        tabs.forEach(function (tab) {
            tab.classList.add('slider-settings-layout__tab');
            function sync() {
                var selected = tab.classList.contains('active')
                    || tab.classList.contains('is-active')
                    || tab.getAttribute('aria-selected') === 'true';
                tab.classList.toggle('is-current', selected);
            }
            sync();
            new MutationObserver(sync).observe(tab, {
                attributes: true,
                attributeFilter: ['class', 'aria-selected']
            });
        });
    }

    function enhanceToggle(label, container) {
        var checkbox = controlFor(label, container, 'input[type="checkbox"]');
        if (!checkbox || checkbox.type !== 'checkbox') { return; }

        container.classList.add('slider-settings-layout__toggle');
        label.classList.add('slider-settings-layout__toggle-label');
        checkbox.classList.add('slider-settings-layout__switch');

        function sync() {
            container.classList.toggle('is-checked', checkbox.checked);
        }
        checkbox.addEventListener('change', sync);
        sync();
    }

    function enhanceField(label, container) {
        var control = controlFor(label, container, 'input, select');
        container.classList.add('slider-settings-layout__field');
        label.classList.add('slider-settings-layout__field-label');
        if (control) {
            control.classList.add('slider-settings-layout__control');
        }
    }

    function buildCompactGroups(root, entries) {
        var containers = entries.map(function (entry) { return entry.container; });
        var panel = commonParent(containers, root);
        if (!panel) { return; }

        panel.classList.add('slider-settings-layout__panel');

        var directItems = containers.map(function (container) {
            return directChildWithin(container, panel);
        });
        var uniqueItems = directItems.filter(function (item, index) {
            return directItems.indexOf(item) === index;
        });

        // Если поля живут в разных сложных поддеревьях, не двигаем их: классы
        // уже улучшают размеры контролов и переключателей без риска для логики.
        if (uniqueItems.length !== containers.length || !uniqueItems.every(function (item) { return item.parentElement === panel; })) {
            panel.classList.add('slider-settings-layout__panel--fallback');
            return;
        }

        var firstItem = uniqueItems.reduce(function (first, item) {
            if (!first) { return item; }
            return (first.compareDocumentPosition(item) & Node.DOCUMENT_POSITION_PRECEDING) ? item : first;
        }, null);

        var groups = document.createElement('div');
        groups.className = 'slider-settings-layout__groups';

        var behavior = document.createElement('section');
        behavior.className = 'slider-settings-layout__section slider-settings-layout__section--behavior';
        addSectionHeader(behavior, 'Поведение', 'Автопрокрутка и элементы управления слайдером.');
        var behaviorGrid = document.createElement('div');
        behaviorGrid.className = 'slider-settings-layout__toggle-grid';
        behavior.appendChild(behaviorGrid);

        var motion = document.createElement('section');
        motion.className = 'slider-settings-layout__section slider-settings-layout__section--motion';
        addSectionHeader(motion, 'Тайминг и переход', 'Скорость смены слайдов, эффект и тип навигации.');
        var fieldGrid = document.createElement('div');
        fieldGrid.className = 'slider-settings-layout__field-grid';
        motion.appendChild(fieldGrid);

        if (firstItem) {
            panel.insertBefore(groups, firstItem);
        } else {
            panel.appendChild(groups);
        }
        groups.appendChild(behavior);
        groups.appendChild(motion);

        entries.forEach(function (entry) {
            if (entry.kind === 'toggle') {
                behaviorGrid.appendChild(entry.container);
            } else {
                fieldGrid.appendChild(entry.container);
            }
        });
    }

    function enhanceSliderSettings(scope) {
        var heading = findSettingsHeading(scope || document);
        if (!heading) { return false; }
        var root = findSettingsRoot(heading);
        if (!root || root.dataset.sliderSettingsLayout === '1') { return false; }

        var entries = [];
        TARGETS.toggles.forEach(function (expected) {
            var label = findLabel(root, expected);
            var container = label ? fieldContainer(label) : null;
            if (!label || !container) { return; }
            enhanceToggle(label, container);
            entries.push({ kind: 'toggle', name: expected, label: label, container: container });
        });
        TARGETS.fields.forEach(function (expected) {
            var label = findLabel(root, expected);
            var container = label ? fieldContainer(label) : null;
            if (!label || !container) { return; }
            enhanceField(label, container);
            entries.push({ kind: 'field', name: expected, label: label, container: container });
        });

        if (entries.length < 6) { return false; }

        root.dataset.sliderSettingsLayout = '1';
        root.classList.add('slider-settings-layout');
        heading.classList.add('slider-settings-layout__title');
        enhanceTabs(root);
        buildCompactGroups(root, entries);
        return true;
    }

    function scan() {
        enhanceSliderSettings(document);
    }

    scan();

    // Некоторые настройки блоков появляются после выбора типа/вкладки.
    // Наблюдатель запускает повторный поиск, но уже обработанный блок не трогает.
    if (window.MutationObserver) {
        var scheduled = false;
        new MutationObserver(function () {
            if (scheduled) { return; }
            scheduled = true;
            window.requestAnimationFrame(function () {
                scheduled = false;
                scan();
            });
        }).observe(document.body, { childList: true, subtree: true });
    }
})();
