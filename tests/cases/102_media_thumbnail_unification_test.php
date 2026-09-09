<?php

declare(strict_types=1);

test('медиабиблиотека использует единый thumbnail-контракт в разделе и модальном picker', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin-media-unified.css');
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');

    // Пропорция принадлежит кадру, а не карточке: подпись страницы лежит
    // внутри .media-card, и рамка 16:10 на самой карточке оставляла подпись
    // абсолютной поверх снимка.
    assert_contains('.media-grid .media-card__thumb,', $css, 'страница медиабиблиотеки входит в общий контракт кадра');
    assert_contains('.media-modal__thumb {', $css, 'окно выбора входит в тот же контракт кадра');
    assert_contains('aspect-ratio: 16 / 10 !important;', $css, 'кадр в обоих контекстах имеет одну пропорцию');
    assert_contains('.media-grid .media-card__caption,', $css, 'подпись standalone-карточки участвует в общей системе');
    assert_contains('.media-modal__caption {', $css, 'подпись карточки окна выбора — тот же компонент');
    assert_contains('background: none !important;', $css, 'подпись лежит под кадром, а не плашкой поверх снимка');
    assert_contains('position: static !important;', $css, 'подпись выведена из абсолютного слоя над снимком');

    assert_contains('.media-grid .media-card.is-selected .media-card__thumb,', $css, 'standalone-карточка использует общий selected-state');
    assert_contains('.media-modal__item.is-selected .media-modal__thumb {', $css, 'карточка окна использует тот же selected-state');
    assert_contains('.media-grid .media-card__check', $css, 'отметка выбранного описана один раз для обоих контекстов');

    assert_contains("var isThis = currentMultiple ? selectedUrls.indexOf(itemUrl) !== -1 : itemUrl === selectedUrl;", $js, 'одинарный и множественный выбор вычисляют selected-state одинаково');
    assert_contains("fig.classList.toggle('is-selected', isThis);", $js, 'визуальный selected-state синхронизируется через общий класс');
});
