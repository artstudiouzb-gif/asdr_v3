(function () {
    'use strict';

    var loading = false;
    var callbacks = [];

    function loadTinyMCE(callback) {
        if (window.tinymce) {
            callback();
            return;
        }
        callbacks.push(callback);
        if (loading) { return; }
        loading = true;

        var script = document.createElement('script');
        script.src = '/assets/js/vendor/tinymce/tinymce.min.js';
        script.onload = function () {
            while (callbacks.length > 0) {
                var cb = callbacks.shift();
                try { cb(); } catch (e) { console.error(e); }
            }
        };
        script.onerror = function () {
            console.error('Failed to load TinyMCE');
        };
        document.head.appendChild(script);
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function safeImageUrl(value) {
        var url = String(value || '').trim();
        if (!/^\/[^/]/.test(url) && !/^https?:\/\//i.test(url)) { return ''; }
        try {
            // Decode once before encoding to preserve already escaped paths while
            // ensuring DOM text is contextually encoded before reaching img.src.
            return encodeURI(decodeURI(url));
        } catch (error) {
            return '';
        }
    }

    function safeEmbedUrl(value) {
        var url = String(value || '').trim();
        return /^https?:\/\//i.test(url) ? url : '';
    }

    function embedService(url) {
        var value = String(url || '').trim();
        if (/^https?:\/\/(?:www\.)?(?:youtu\.be\/[A-Za-z0-9_-]{11}|youtube\.com\/(?:watch\?[^\s]*\bv=|embed\/|shorts\/)[A-Za-z0-9_-]{11})/i.test(value)) {
            return { key: 'youtube', label: 'YouTube', result: 'В статье появится видеоплеер 16:9.' };
        }
        if (/^https?:\/\/t\.me\/[a-zA-Z0-9_]{3,}\/[0-9]+/i.test(value)) {
            return { key: 'telegram', label: 'Telegram', result: 'В статье появится виджет публикации и резервная карточка.' };
        }
        if (/^https?:\/\/(?:www\.)?instagram\.com\/(?:p|reel|tv)\//i.test(value)) {
            return { key: 'instagram', label: 'Instagram', result: 'В статье появится безопасная карточка публикации.' };
        }
        if (/^https?:\/\/(?:www\.|m\.)?(?:facebook\.com|fb\.watch)\//i.test(value)) {
            return { key: 'facebook', label: 'Facebook', result: 'В статье появится безопасная карточка публикации.' };
        }
        return null;
    }

    var articleMediaDialog = null;
    var articleMediaEditor = null;
    var articleMediaBookmark = null;

    function ensureArticleMediaDialog() {
        if (articleMediaDialog) { return articleMediaDialog; }

        var modal = document.createElement('div');
        modal.className = 'article-media-dialog';
        modal.hidden = true;
        modal.innerHTML = ''
            + '<button type="button" class="article-media-dialog__backdrop" data-ae-close aria-label="Закрыть"></button>'
            + '<section class="article-media-dialog__panel" role="dialog" aria-modal="true" aria-labelledby="article-media-dialog-title">'
            + '  <header class="article-media-dialog__head"><div><span class="article-media-dialog__eyebrow">Материал внутри текста</span><h2 id="article-media-dialog-title">Вставить медиа</h2></div><button type="button" class="article-media-dialog__close" data-ae-close aria-label="Закрыть">×</button></header>'
            + '  <div class="article-media-dialog__tabs" role="tablist"><button type="button" class="is-active" data-ae-tab="image" role="tab" aria-selected="true">Фото</button><button type="button" data-ae-tab="embed" role="tab" aria-selected="false">Видео / соцсеть</button></div>'
            + '  <div class="article-media-dialog__body">'
            + '    <div class="article-media-dialog__pane is-active" data-ae-pane="image">'
            + '      <div class="article-media-dialog__fields">'
            + '        <label class="article-media-dialog__field article-media-dialog__field--wide"><span>Фотография</span><div class="article-media-dialog__pickrow"><input type="text" data-ae-image-url placeholder="/uploads/public/photo.jpg"><button type="button" data-ae-image-pick>Выбрать</button></div></label>'
            + '        <label class="article-media-dialog__field article-media-dialog__field--wide"><span>Alt-текст <b>обязательно</b></span><input type="text" data-ae-image-alt placeholder="Что изображено на фотографии"></label>'
            + '        <label class="article-media-dialog__field article-media-dialog__field--wide"><span>Подпись под фото</span><textarea rows="2" data-ae-image-caption placeholder="Краткое пояснение к фотографии"></textarea></label>'
            + '        <label class="article-media-dialog__field"><span>Автор / источник</span><input type="text" data-ae-image-credit placeholder="Пресс-служба Агентства"></label>'
            + '        <label class="article-media-dialog__field"><span>Размер</span><select data-ae-image-size><option value="content">По ширине текста</option><option value="wide">Широкое</option><option value="compact">Компактное</option></select></label>'
            + '        <label class="article-media-dialog__field"><span>Выравнивание</span><select data-ae-image-align><option value="center">По центру</option><option value="left">Слева</option><option value="right">Справа</option></select></label>'
            + '      </div>'
            + '      <div class="article-media-dialog__preview article-media-dialog__preview--image" data-ae-image-preview><span>Выберите фотографию — здесь появится предварительный просмотр.</span></div>'
            + '    </div>'
            + '    <div class="article-media-dialog__pane" data-ae-pane="embed">'
            + '      <label class="article-media-dialog__field article-media-dialog__field--wide"><span>Ссылка на публикацию</span><input type="url" data-ae-embed-url placeholder="YouTube, Telegram, Instagram или Facebook"></label>'
            + '      <p class="article-media-dialog__hint">Ссылка будет сохранена в безопасном виде и преобразована при показе новости.</p>'
            + '      <div class="article-media-dialog__preview article-media-dialog__preview--embed" data-ae-embed-preview><span>Вставьте ссылку для распознавания сервиса.</span></div>'
            + '    </div>'
            + '    <p class="article-media-dialog__status" data-ae-status role="alert"></p>'
            + '  </div>'
            + '  <footer class="article-media-dialog__foot"><button type="button" class="article-media-dialog__cancel" data-ae-close>Отмена</button><button type="button" class="article-media-dialog__submit" data-ae-submit>Вставить в текст</button></footer>'
            + '</section>';
        document.body.appendChild(modal);

        var activeTab = 'image';
        var imageUrl = modal.querySelector('[data-ae-image-url]');
        var imageAlt = modal.querySelector('[data-ae-image-alt]');
        var imageCaption = modal.querySelector('[data-ae-image-caption]');
        var imageCredit = modal.querySelector('[data-ae-image-credit]');
        var imageSize = modal.querySelector('[data-ae-image-size]');
        var imageAlign = modal.querySelector('[data-ae-image-align]');
        var imagePreview = modal.querySelector('[data-ae-image-preview]');
        var embedUrl = modal.querySelector('[data-ae-embed-url]');
        var embedPreview = modal.querySelector('[data-ae-embed-preview]');
        var status = modal.querySelector('[data-ae-status]');

        function setStatus(message) {
            status.textContent = message || '';
            status.classList.toggle('is-visible', Boolean(message));
        }

        function switchTab(name) {
            activeTab = name === 'embed' ? 'embed' : 'image';
            modal.querySelectorAll('[data-ae-tab]').forEach(function (button) {
                var selected = button.getAttribute('data-ae-tab') === activeTab;
                button.classList.toggle('is-active', selected);
                button.setAttribute('aria-selected', selected ? 'true' : 'false');
            });
            modal.querySelectorAll('[data-ae-pane]').forEach(function (pane) {
                pane.classList.toggle('is-active', pane.getAttribute('data-ae-pane') === activeTab);
            });
            setStatus('');
        }

        function updateImagePreview() {
            var url = safeImageUrl(imageUrl.value);
            imagePreview.innerHTML = '';
            if (!url) {
                var empty = document.createElement('span');
                empty.textContent = 'Выберите фотографию — здесь появится предварительный просмотр.';
                imagePreview.appendChild(empty);
                return;
            }
            var figure = document.createElement('figure');
            var img = document.createElement('img');
            img.src = url;
            img.alt = imageAlt.value.trim();
            figure.appendChild(img);
            var caption = imageCaption.value.trim();
            var credit = imageCredit.value.trim();
            if (caption || credit) {
                var figcaption = document.createElement('figcaption');
                figcaption.textContent = caption;
                if (credit) {
                    var creditNode = document.createElement('span');
                    creditNode.textContent = (caption ? ' · ' : '') + '© ' + credit;
                    figcaption.appendChild(creditNode);
                }
                figure.appendChild(figcaption);
            }
            imagePreview.appendChild(figure);
        }

        function updateEmbedPreview() {
            var url = safeEmbedUrl(embedUrl.value);
            var service = embedService(url);
            embedPreview.innerHTML = '';
            if (!url) {
                var empty = document.createElement('span');
                empty.textContent = 'Вставьте ссылку для распознавания сервиса.';
                embedPreview.appendChild(empty);
                return;
            }
            if (!service) {
                var unsupported = document.createElement('span');
                unsupported.textContent = 'Ссылка не распознана. Поддерживаются YouTube, Telegram, Instagram и Facebook.';
                embedPreview.appendChild(unsupported);
                return;
            }
            var card = document.createElement('div');
            card.className = 'article-media-dialog__embed-card is-' + service.key;
            var icon = document.createElement('strong');
            icon.textContent = service.label.charAt(0);
            var body = document.createElement('span');
            var title = document.createElement('b');
            title.textContent = service.label;
            var description = document.createElement('small');
            description.textContent = service.result;
            var address = document.createElement('em');
            address.textContent = url;
            body.appendChild(title);
            body.appendChild(description);
            body.appendChild(address);
            card.appendChild(icon);
            card.appendChild(body);
            embedPreview.appendChild(card);
        }

        function close() {
            modal.hidden = true;
            document.body.classList.remove('article-media-dialog-open');
            articleMediaEditor = null;
            articleMediaBookmark = null;
        }

        modal.querySelectorAll('[data-ae-close]').forEach(function (button) {
            button.addEventListener('click', close);
        });
        modal.querySelectorAll('[data-ae-tab]').forEach(function (button) {
            button.addEventListener('click', function () { switchTab(button.getAttribute('data-ae-tab')); });
        });
        modal.querySelector('[data-ae-image-pick]').addEventListener('click', function () {
            if (!window.MediaPicker) {
                setStatus('Медиабиблиотека недоступна. Укажите адрес файла вручную.');
                return;
            }
            window.MediaPicker.pick(function (url) {
                imageUrl.value = url;
                updateImagePreview();
            });
        });
        [imageUrl, imageAlt, imageCaption, imageCredit].forEach(function (field) {
            field.addEventListener('input', updateImagePreview);
        });
        embedUrl.addEventListener('input', updateEmbedPreview);
        modal.querySelector('[data-ae-submit]').addEventListener('click', function () {
            if (!articleMediaEditor) { close(); return; }
            if (articleMediaBookmark && articleMediaEditor.selection) {
                articleMediaEditor.focus();
                articleMediaEditor.selection.moveToBookmark(articleMediaBookmark);
            }
            if (activeTab === 'image') {
                var url = safeImageUrl(imageUrl.value);
                var alt = imageAlt.value.trim();
                if (!url) { setStatus('Выберите фотографию или укажите корректный адрес.'); return; }
                if (!alt) { setStatus('Добавьте alt-текст: кратко опишите, что изображено на фото.'); imageAlt.focus(); return; }
                var caption = imageCaption.value.trim();
                var credit = imageCredit.value.trim();
                var captionHtml = '';
                if (caption || credit) {
                    captionHtml = '<figcaption>' + escapeHtml(caption);
                    if (credit) {
                        captionHtml += '<span class="article-media__credit">' + (caption ? ' · ' : '') + '© ' + escapeHtml(credit) + '</span>';
                    }
                    captionHtml += '</figcaption>';
                }
                articleMediaEditor.insertContent(
                    '<figure class="article-media article-media--' + escapeHtml(imageSize.value) + ' article-media--align-' + escapeHtml(imageAlign.value) + '">'
                    + '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(alt) + '" loading="lazy">'
                    + captionHtml + '</figure><p></p>'
                );
            } else {
                var externalUrl = safeEmbedUrl(embedUrl.value);
                if (!externalUrl || !embedService(externalUrl)) {
                    setStatus('Укажите корректную ссылку YouTube, Telegram, Instagram или Facebook.');
                    embedUrl.focus();
                    return;
                }
                articleMediaEditor.insertContent('<p><a href="' + escapeHtml(externalUrl) + '" target="_blank" rel="noopener">' + escapeHtml(externalUrl) + '</a></p><p></p>');
            }
            articleMediaEditor.save();
            close();
        });
        document.addEventListener('keydown', function (event) {
            var mediaPicker = document.querySelector('[data-media-modal]');
            if (event.key === 'Escape' && !modal.hidden && (!mediaPicker || mediaPicker.hidden)) { close(); }
        }, true);

        modal.openFor = function (editor, tab) {
            articleMediaEditor = editor;
            articleMediaBookmark = editor.selection ? editor.selection.getBookmark(2, true) : null;
            imageUrl.value = '';
            imageAlt.value = '';
            imageCaption.value = '';
            imageCredit.value = '';
            imageSize.value = 'content';
            imageAlign.value = 'center';
            embedUrl.value = '';
            updateImagePreview();
            updateEmbedPreview();
            switchTab(tab || 'image');
            modal.hidden = false;
            document.body.classList.add('article-media-dialog-open');
            window.setTimeout(function () {
                (activeTab === 'image' ? imageUrl : embedUrl).focus();
            }, 30);
        };

        articleMediaDialog = modal;
        return modal;
    }

    function attach(textarea) {
        if (textarea.dataset.wysiwygReady === '1') { return; }
        textarea.dataset.wysiwygReady = '1';

        loadTinyMCE(function () {
            if (!textarea.id) {
                textarea.id = 'wysiwyg-' + Math.random().toString(36).substr(2, 9);
            }

            var isLead = textarea.hasAttribute('data-lead-editor');
            window.tinymce.init({
                selector: '#' + textarea.id,
                base_url: '/assets/js/vendor/tinymce',
                suffix: '.min',
                license_key: 'gpl',
                promotion: false,
                branding: false,
                language: 'ru',
                language_url: '/assets/js/vendor/tinymce/langs/ru.js',
                height: isLead ? 280 : 520,
                menubar: isLead ? false : 'file edit view insert format table tools help',
                paste_webkit_styles: 'none',
                paste_retain_style_properties: 'none',
                paste_remove_styles_if_webkit: true,
                paste_merge_formats: true,
                paste_as_text: false,
                paste_data_images: false,
                paste_postprocess: function (plugin, args) {
                    var all = args.node.querySelectorAll('*');
                    for (var i = 0; i < all.length; i++) {
                        var el = all[i];
                        el.removeAttribute('style');
                        if (el.className && (el.className.indexOf('Mso') === 0 || el.className.indexOf('ms-') === 0)) {
                            el.removeAttribute('class');
                        }
                        if (el.hasAttribute('lang')) {
                            el.removeAttribute('lang');
                        }
                    }
                    if (args.node.removeAttribute) {
                        args.node.removeAttribute('style');
                    }
                },
                plugins: isLead ? [
                    'advlist', 'autolink', 'lists', 'link', 'wordcount', 'nonbreaking'
                ] : [
                    'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
                    'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
                    'insertdatetime', 'media', 'table', 'wordcount', 'codesample',
                    'emoticons', 'help', 'nonbreaking', 'quickbars'
                ],
                toolbar1: isLead
                    ? 'undo redo | bold italic underline strikethrough | link blockquote bullist numlist | removeformat'
                    : 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough subscript superscript | blockquote forecolor backcolor | alignleft aligncenter alignright alignjustify',
                toolbar2: isLead ? false : 'bullist numlist outdent indent | articlemedia link image media table blockquote hr codesample emoticons | removeformat searchreplace visualblocks code fullscreen preview',
                image_caption: !isLead,
                image_title: !isLead,
                quickbars_selection_toolbar: isLead ? 'bold italic underline | quicklink blockquote' : 'bold italic | quicklink h2 h3 blockquote',
                valid_elements: isLead
                    ? 'p,br,strong/b,em/i,u,s,ul,ol,li,blockquote,a[href|target|rel]'
                    : undefined,
                content_style: 'body { max-width: none; margin: 1.2rem; font-family: system-ui, -apple-system, sans-serif; font-size: '
                    + (isLead ? '17px' : '15px') + '; line-height: 1.65; color: #0f172a; }'
                    + (isLead ? ' p { margin: 0 0 .75em; } blockquote { border-left: 3px solid #0d9488; margin-left: 0; padding-left: 1em; }' : ''),
                setup: function (editor) {
                    if (!isLead) {
                        editor.ui.registry.addButton('articlemedia', {
                            icon: 'image',
                            text: 'Медиа',
                            tooltip: 'Вставить фото, видео или публикацию соцсети',
                            onAction: function () { ensureArticleMediaDialog().openFor(editor, 'image'); }
                        });
                    }
                    editor.on('init', function () {
                        textarea.dispatchEvent(new Event('input'));
                    });
                    editor.on('change input blur', function () {
                        editor.save();
                        textarea.dispatchEvent(new Event('input'));
                    });
                },
                file_picker_callback: function (callback, value, meta) {
                    if (meta.filetype === 'image' && window.MediaPicker) {
                        window.MediaPicker.pick(function (url) {
                            callback(url, { alt: '' });
                        });
                    }
                }
            });
        });
    }

    window.ArtEditor = { attach: attach };
})();
