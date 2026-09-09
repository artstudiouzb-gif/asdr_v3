(function () {
    'use strict';

    // Direct Telegram/Facebook/X/LinkedIn share URLs can send only a page URL.
    // This page-only helper uses Web Share API File[] to hand the complete
    // news gallery to the operating-system share sheet when supported.

    function labelForCurrentLanguage() {
        var lang = (document.documentElement.lang || 'ru').toLowerCase();
        if (lang.indexOf('uz') === 0) { return 'Barcha rasmlarni ulashish'; }
        if (lang.indexOf('en') === 0) { return 'Share all photos'; }
        if (lang.indexOf('kk') === 0) { return 'Барлық фотолармен бөлісу'; }
        if (lang.indexOf('tr') === 0) { return 'Tüm fotoğrafları paylaş'; }
        if (lang.indexOf('de') === 0) { return 'Alle Fotos teilen'; }
        return 'Поделиться всеми фото';
    }

    function collectOpenGraphImages() {
        var seen = Object.create(null);
        var urls = [];

        document.querySelectorAll('meta[property="og:image"]').forEach(function (meta) {
            var raw = (meta.getAttribute('content') || '').trim();
            if (!raw) { return; }

            try {
                var absolute = new URL(raw, window.location.href).href;
                if (!seen[absolute]) {
                    seen[absolute] = true;
                    urls.push(absolute);
                }
            } catch (error) {
                // Ignore malformed metadata instead of breaking the share row.
            }
        });

        return urls;
    }

    function extensionForMime(type) {
        return {
            'image/jpeg': 'jpg',
            'image/png': 'png',
            'image/gif': 'gif',
            'image/webp': 'webp',
            'image/avif': 'avif'
        }[type] || 'jpg';
    }

    function fileNameFor(url, index, type) {
        var base = '';

        try {
            base = decodeURIComponent(new URL(url).pathname.split('/').pop() || '');
        } catch (error) {
            base = '';
        }

        base = base.replace(/[^a-zA-Z0-9._-]+/g, '-').replace(/^-+|-+$/g, '');
        if (!base || base.indexOf('.') === -1) {
            base = 'photo-' + String(index + 1).padStart(2, '0') + '.' + extensionForMime(type);
        }

        return base;
    }

    function loadFiles(urls) {
        return Promise.all(urls.map(function (url, index) {
            var requestUrl;

            try {
                requestUrl = new URL(url, window.location.href);
            } catch (error) {
                return null;
            }

            return fetch(requestUrl.href, {
                credentials: requestUrl.origin === window.location.origin ? 'same-origin' : 'omit',
                mode: 'cors'
            }).then(function (response) {
                if (!response.ok) { return null; }
                return response.blob();
            }).then(function (blob) {
                if (!blob || !/^image\//i.test(blob.type || '')) { return null; }

                return new File([blob], fileNameFor(requestUrl.href, index, blob.type), {
                    type: blob.type,
                    lastModified: Date.now()
                });
            }).catch(function () {
                // Cross-origin images without CORS cannot become local File objects.
                return null;
            });
        })).then(function (files) {
            return files.filter(Boolean);
        });
    }

    function createButton(label) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'newsdetail-share__btn newsdetail-share__btn--native';
        button.setAttribute('aria-label', label);
        button.setAttribute('title', label);
        button.setAttribute('data-share-gallery-native', '');
        button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/><path d="M12 16V4"/><path d="m7 9 5-5 5 5"/></svg>';
        return button;
    }

    function pageTitle() {
        var meta = document.querySelector('meta[property="og:title"]');
        return meta ? (meta.getAttribute('content') || document.title) : document.title;
    }

    function shareFiles(files) {
        var title = pageTitle();
        var pageUrl = window.location.href;

        if (files.length > 0
            && typeof navigator.canShare === 'function'
            && navigator.canShare({ files: files })) {
            return navigator.share({
                title: title,
                text: title + '\n' + pageUrl,
                files: files
            });
        }

        return navigator.share({ title: title, url: pageUrl });
    }

    function init() {
        if (typeof navigator.share !== 'function' || typeof window.File !== 'function') { return; }

        var imageUrls = collectOpenGraphImages();
        if (imageUrls.length === 0) { return; }

        var label = labelForCurrentLanguage();
        var preparedFiles = null;
        var preparePromise = null;

        function prepare() {
            if (preparedFiles !== null) { return Promise.resolve(preparedFiles); }
            if (!preparePromise) {
                preparePromise = loadFiles(imageUrls).then(function (files) {
                    preparedFiles = files;
                    return files;
                });
            }
            return preparePromise;
        }

        document.querySelectorAll('.newsdetail-share__row').forEach(function (row) {
            if (row.querySelector('[data-share-gallery-native]')) { return; }

            var button = createButton(label);
            row.insertBefore(button, row.firstChild);

            // Warm the files before click where possible; on touch devices the
            // first touch starts loading. If loading outlives transient user
            // activation, the prepared files make the second click immediate.
            button.addEventListener('pointerenter', prepare, { once: true });
            button.addEventListener('focus', prepare, { once: true });
            button.addEventListener('touchstart', prepare, { once: true, passive: true });

            button.addEventListener('click', function () {
                button.setAttribute('aria-busy', 'true');

                if (preparedFiles !== null) {
                    shareFiles(preparedFiles).catch(function (error) {
                        if (!error || error.name !== 'AbortError') {
                            console.warn('Gallery share failed:', error);
                        }
                    }).finally(function () {
                        button.removeAttribute('aria-busy');
                    });
                    return;
                }

                prepare().then(function (files) {
                    return shareFiles(files);
                }).catch(function (error) {
                    if (!error || (error.name !== 'AbortError' && error.name !== 'NotAllowedError')) {
                        console.warn('Gallery share failed:', error);
                    }
                }).finally(function () {
                    button.removeAttribute('aria-busy');
                });
            });
        });
    }

    init();
})();
