<?php

declare(strict_types=1);

test('503 fail-safe remains standalone, responsive and actionable', function (): void {
    $html = (string) file_get_contents(APP_ROOT . '/app/Views/errors/503.php');
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/system.css');

    assert_contains('<meta name="robots" content="noindex, nofollow">', $html);
    assert_contains('class="system-error system-error--503"', $html);
    assert_contains('<main>', $html);
    assert_contains('href="">Повторить попытку</a>', $html);
    assert_contains('.system-error--503 a:focus-visible', $css);
    assert_contains('@media (max-width: 480px)', $css);
    assert_false(str_contains($html, '<?php'), 'bootstrap отдаёт 503 через file_get_contents, PHP-код здесь не выполнится');
});

test('public dynamic UI uses localized labels and text-only untrusted values', function (): void {
    $footer = (string) file_get_contents(APP_ROOT . '/app/Views/site/_footer.php');
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');
    $en = require APP_ROOT . '/app/Core/lang/en.php';
    $uz = require APP_ROOT . '/app/Core/lang/uz.php';

    assert_contains('type="application/json" id="frontend-labels"', $footer);
    assert_not_contains('window.__frontendLabels', $footer);
    assert_contains("'totalVotes' => t('Всего голосов:')", $footer);
    assert_same('Image viewer', $en['Просмотр изображения'] ?? null);
    assert_same('Rasmni ko‘rish', $uz['Просмотр изображения'] ?? null);

    assert_contains("messageEl.textContent = String(message == null ? '' : message)", $js);
    assert_contains("resultLabel.textContent = String(item.label == null ? '' : item.label)", $js);
    assert_contains("resDiv.textContent = ''", $js);
    assert_false(str_contains($js, "'<span class=\"news-poll-res-label\">' + item.label"), 'подпись опроса нельзя вставлять через innerHTML');
});

test('reader, lightbox and quick search preserve keyboard focus', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/site/news_show.php');
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');
    $css = theme_css();

    assert_contains('role="progressbar"', $view);
    assert_contains('aria-valuenow="0"', $view);
    assert_contains('aria-pressed="true"', $view);
    assert_contains('aria-live="polite"', $view);
    assert_contains('aria-hidden="true">ESC</span>', $header);

    assert_contains("setReaderIsolation(true)", $js);
    assert_contains("readerLastFocus.focus()", $js);
    assert_contains("lightboxLastFocus.focus()", $js);
    assert_contains("e.key === 'Tab' && !modal.hidden", $js);
    assert_contains("img.setAttribute('tabindex', '0')", $js);
    assert_contains("input.setAttribute('aria-controls', panel.id)", $js);
    assert_contains("e.key === 'ArrowDown' && !panel.hidden", $js);
    assert_contains('body.quick-search-active { overflow: hidden; }', $css);
    assert_contains('.is-lightboxable[tabindex]:focus-visible', $css);
});

test('search suggestions expose links without invalid listbox semantics', function (): void {
    $partial = (string) file_get_contents(APP_ROOT . '/app/Views/site/_search_suggest.php');

    assert_contains('<ul class="search-suggest__list">', $partial);
    assert_false(str_contains($partial, 'role="listbox"'));
    assert_false(str_contains($partial, 'role="option"'));
});
