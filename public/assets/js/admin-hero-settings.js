/** Contextual fields for the cover and slide editors. Hidden values stay in POST. */
(function () {
    'use strict';
    document.querySelectorAll('[data-hero-editor]').forEach(function (form) {
        function field(name) { return form.elements.namedItem(name); }
        function value(name) {
            var input = field(name);
            return input ? (input.type === 'checkbox' ? input.checked : input.value) : '';
        }
        function show(names, visible) {
            names.split(',').forEach(function (name) {
                var input = field(name);
                if (!input) { return; }
                var box = input.closest('.form-field');
                if (!box) { return; }
                if (box.parentElement.matches('[class^="col-"], [class*=" col-"]')) { box = box.parentElement; }
                box.setAttribute('data-hero-dependent', '');
                box.hidden = !visible;
                // Do not disable or clear: changing a mode and returning to it
                // must preserve the editor's values, including unchecked flags.
            });
        }
        function summary() {
            form.querySelectorAll('[data-hero-summary]').forEach(function (group) {
                var out = group.querySelector('.form-section__state');
                if (!out) { return; }
                var parts = group.getAttribute('data-hero-summary').split(',').map(function (name) {
                    var input = field(name);
                    if (!input || input.closest('[hidden]')) { return ''; }
                    if (input.type === 'checkbox') {
                        return name === 'autoplay' ? (input.checked ? 'Включена' : 'Выключена')
                            : (input.checked ? 'со стрелками' : 'без стрелок');
                    }
                    if (input.tagName === 'SELECT') { return input.selectedOptions[0].textContent; }
                    return input.value + ({ autoplay_interval: ' с', transition_duration: ' мс', overlay_opacity: '%' }[name] || '');
                }).filter(Boolean);
                out.textContent = parts.join(' · ');
            });
        }
        function schedule(fromName, toName, required) {
            var from = field(fromName), to = field(toName);
            if (!from || !to) { return; }
            from.setCustomValidity(required && !from.value && !to.value ? 'Укажите начало или конец показа по расписанию.' : '');
            to.setCustomValidity(from.value && to.value && from.value >= to.value ? 'Конец показа должен быть позже начала.' : '');
            if (form.dataset.heroEditor === 'cover' && value('status') !== 'scheduled') {
                from.setCustomValidity('');
                to.setCustomValidity('');
            }
        }
        function dependentGroups() {
            form.querySelectorAll('[data-hero-dependent-group]').forEach(function (group) {
                var fields = Array.from(group.querySelectorAll('[data-hero-dependent]'));
                if (fields.length === 0) { return; }
                group.hidden = fields.every(function (item) { return item.hidden; });
            });
            emptySections();
        }

        // Секция, у которой скрылись все поля, прячется целиком вместе со
        // ссылкой на неё в навигации. Рамка с одним заголовком читается как
        // поломка: при фоне «Только цвет обложки» карточка «На телефоне»
        // оставалась пустой — заголовок, подпись и ничего больше.
        function emptySections() {
            form.querySelectorAll('.settings-card[id]').forEach(function (card) {
                var visible = Array.from(card.querySelectorAll('input, select, textarea')).some(function (control) {
                    return !control.closest('[hidden]');
                });
                card.hidden = !visible;
                var link = document.querySelector('.settings-jump-nav a[href="#' + card.id + '"]');
                if (link) { link.hidden = !visible; }
            });
        }
        function refresh() {
            if (form.dataset.heroEditor === 'cover') {
                show('published_from,published_to', value('status') === 'scheduled');
                show('height_value', value('height') === 'custom');
                show('height_mobile_value', value('height_mobile') === 'custom');
                show('scheme_bg', value('scheme') === 'custom');
                show('scheme_text', value('scheme') === 'custom' && value('content_scheme') === 'auto');
                show('overlay_color,overlay_opacity', value('overlay') !== 'none');
                show('overlay_direction', value('overlay') === 'gradient');
                show('panel_color,panel_opacity', value('panel'));
                show('nav_arrows_mobile', value('nav_arrows'));
                show('autoplay_interval', value('autoplay'));
                schedule('published_from', 'published_to', value('status') === 'scheduled');
                summary();
            } else {
                var media = value('media_type');
                var video = media === 'video' || media === 'youtube';
                show('image', media === 'image');
                show('image_fit,image_position,image_mobile,image_position_mobile', media !== 'none');
                show('video_url', media === 'video');
                show('youtube_url', media === 'youtube');
                show('poster,mobile_media', video);
                // A mobile MP4 is meaningful only for a native video, not YouTube.
                var mobile = field('mobile_media');
                if (mobile) {
                    if (media === 'youtube' && mobile.value === 'mobile_video') { mobile.value = 'image'; }
                    Array.from(mobile.options).forEach(function (option) {
                        if (option.value === 'mobile_video') { option.disabled = media !== 'video'; }
                    });
                }
                show('video_mobile_url', media === 'video' && value('mobile_media') === 'mobile_video');
                show('art_alt,art_position,art_size', !!value('art_image'));
                show('art_width', !!value('art_image') && value('art_size') === 'custom');
                ['cta', 'cta2'].forEach(function (prefix) {
                    show(['text', 'url', 'style', 'icon', 'new_tab'].map(function (key) { return prefix + '_' + key; }).join(','), value(prefix + '_enabled'));
                });
                // Цвет спрашиваем только у того вида, который его слушает:
                // заливку красит вид «Основная», цвет ссылки — вид «Ссылка».
                var ctaStyles = ['cta', 'cta2'].map(function (prefix) {
                    return value(prefix + '_enabled') ? value(prefix + '_style') : '';
                });
                show('cta_color,cta_text_color', ctaStyles.indexOf('primary') !== -1);
                show('link_color', ctaStyles.indexOf('link') !== -1);
                var overlay = value('overlay') || form.dataset.heroOverlayDefault;
                show('overlay_color,overlay_opacity', overlay !== 'none');
                show('overlay_direction', overlay === 'gradient');
                schedule('_visible_from', '_visible_to', false);
            }
            dependentGroups();
        }
        form.addEventListener('input', refresh);
        form.addEventListener('change', refresh);
        form.addEventListener('reset', function () { window.setTimeout(refresh, 0); });
        form.addEventListener('invalid', function (event) {
            var group = event.target.closest('details');
            if (group) { group.open = true; }
        }, true);
        refresh();
    });
})();
