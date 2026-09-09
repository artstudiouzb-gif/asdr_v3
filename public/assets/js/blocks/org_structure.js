(function () {
    'use strict';

    /**
     * Поиск по схеме оргструктуры.
     *
     * У крупного органа узлов набирается несколько десятков, и найти нужный
     * отдел глазами дольше, чем прочитать весь список. Поле включается в
     * настройках блока и надстраивает готовую схему: совпавшие пункты
     * подсвечиваются, остальные прячутся вместе с ветками, где ничего не
     * нашлось. Без скрипта поля нет вовсе — оно приходит скрытым, и схема
     * остаётся обычным списком.
     */
    var normalize = function (text) {
        return (text || '').toLowerCase().replace(/[«»"'`ʻ‘’]/g, '').replace(/\s+/g, ' ').trim();
    };

    /**
     * Собственный текст узла без вложенных списков: иначе департамент
     * «совпадал» с любым своим сектором и подсвечивался вместе с ним, а
     * счётчик показывал втрое больше найденного.
     */
    var ownText = function (node) {
        var text = '';
        Array.prototype.forEach.call(node.childNodes, function (child) {
            if (child.nodeType === 3) { text += child.textContent; return; }
            if (child.nodeType === 1 && !child.matches('.orgstruct__subunits')) { text += child.textContent; }
        });

        return text;
    };

    var init = function (root) {
        var field = root.querySelector('[data-org-search]');
        var input = field ? field.querySelector('input') : null;
        var status = field ? field.querySelector('[data-org-search-status]') : null;
        var schema = root.querySelector('.orgstruct');
        if (!field || !input || !schema) { return; }

        field.hidden = false;

        // Узлы, которые участвуют в поиске: подразделения любого уровня,
        // карточки заместителей, органы сбоку и совет.
        var nodes = Array.prototype.slice.call(schema.querySelectorAll(
            '.orgstruct__unit, .orgstruct__subunit, .orgstruct__deputy, .orgstruct__aside-item, .orgstruct__council-card'
        )).map(function (node) {
            return { el: node, text: normalize(ownText(node)) };
        });
        var branches = Array.prototype.slice.call(schema.querySelectorAll('.orgstruct__branch'));

        var reset = function () {
            schema.classList.remove('is-filtered');
            nodes.forEach(function (node) {
                node.el.classList.remove('is-match', 'is-match-path');
                node.el.hidden = false;
            });
            branches.forEach(function (branch) { branch.hidden = false; });
            if (status) { status.textContent = ''; }
        };

        var apply = function (query) {
            if (query === '') { reset(); return; }

            schema.classList.add('is-filtered');
            var found = 0;
            nodes.forEach(function (node) {
                var hit = node.text.indexOf(query) !== -1;
                node.el.classList.toggle('is-match', hit);
                node.el.classList.remove('is-match-path');
                node.el.hidden = false;
                if (hit) { found++; }
            });

            // Родителей найденного не подсвечиваем, но и не гасим: сектор без
            // своего отдела теряет смысл.
            nodes.forEach(function (node) {
                if (!node.el.classList.contains('is-match')) { return; }
                var parent = node.el.parentElement;
                while (parent && parent !== schema) {
                    if (parent.matches('.orgstruct__unit, .orgstruct__subunit, .orgstruct__deputy')) {
                        parent.classList.add('is-match-path');
                    }
                    parent = parent.parentElement;
                }
            });

            branches.forEach(function (branch) {
                branch.hidden = branch.querySelector('.is-match') === null;
            });

            if (status) {
                status.textContent = found === 0
                    ? status.getAttribute('data-empty')
                    : status.getAttribute('data-found').replace('%d', String(found));
            }
        };

        var timer = null;
        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { apply(normalize(input.value)); }, 120);
        });
        input.addEventListener('search', function () { apply(normalize(input.value)); });
    };

    var boot = function () {
        document.querySelectorAll('[data-org-structure]').forEach(init);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
