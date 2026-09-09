(function () {
    'use strict';

    function previewSource(slide) {
        if (!slide) { return ''; }

        var picture = slide.closest('picture');
        var source = picture ? picture.querySelector('source[type="image/webp"]') : null;
        var srcset = source ? (source.getAttribute('srcset') || '') : '';
        if (srcset) {
            // Media::picture() отдаёт варианты от меньшего к большему; для
            // маленькой ленты достаточно первого (обычно 800px).
            var first = srcset.split(',')[0].trim();
            var candidate = first.split(/\s+/)[0];
            if (candidate) { return candidate; }
        }

        return slide.currentSrc || slide.getAttribute('src') || '';
    }

    document.querySelectorAll('[data-ndgallery]').forEach(function (gallery) {
        var slides = Array.prototype.slice.call(
            gallery.querySelectorAll('.newsdetail-gallery__slide')
        );
        var thumbs = Array.prototype.slice.call(
            gallery.querySelectorAll('.newsdetail-gallery__thumb img')
        );

        thumbs.forEach(function (thumb, index) {
            var slide = slides[index];
            if (!slide) { return; }

            function sync() {
                var preview = previewSource(slide);
                if (preview && thumb.getAttribute('src') !== preview) {
                    thumb.setAttribute('src', preview);
                }
            }

            sync();
            // Lazy-слайд может определить currentSrc только после загрузки.
            slide.addEventListener('load', sync, { once: true });
            // Если исходный thumbnail успел запроситься и упал — сразу
            // переключаемся на вариант большого responsive-слайда.
            thumb.addEventListener('error', sync, { once: true });
        });
    });
})();
