<?php

declare(strict_types=1);

use App\Core\ContentFields;
use App\Core\DateFormatter;
use App\Core\HtmlSanitizer;
use App\Core\Logger;

test('sanitizeText: script/iframe удаляются целиком, on*/javascript: вырезаются', function () {
    $dirty = '<p onclick="alert(1)">Привет <b>мир</b></p>'
        . '<script>alert("xss")</script>'
        . '<iframe src="https://evil"></iframe>'
        . '<a href="javascript:alert(1)">клик</a>'
        . '<img src="/x.png" onload="hack()">'
        . '<a href="https://ok.uz" target="_blank">ок</a>';

    $clean = HtmlSanitizer::sanitizeText($dirty);

    assert_false(str_contains($clean, '<script'), 'script удалён');
    assert_false(str_contains($clean, 'alert("xss")'), 'код скрипта не остался текстом');
    assert_false(str_contains($clean, '<iframe'), 'iframe удалён');
    assert_false(str_contains($clean, 'onclick'), 'on*-атрибуты вырезаны');
    assert_false(str_contains($clean, 'onload'), 'onload вырезан');
    assert_false(str_contains($clean, 'javascript:'), 'javascript: вырезан');
    assert_false(str_contains($clean, '<img'), 'img вне текстового профиля');
    assert_true(str_contains($clean, '<b>мир</b>'), 'безопасная разметка сохранена');
    assert_true(str_contains($clean, 'href="https://ok.uz"'), 'обычная ссылка сохранена');
    assert_true(str_contains($clean, 'noopener'), 'target=_blank получил rel=noopener');
});

test('ContentFields: textarea с HTML санируется, простой текст экранируется', function () {
    $field = ['field_type' => 'textarea', 'label' => 'Описание', 'name' => 'summary'];

    $html = ContentFields::displayValue($field, 'Текст <script>alert(1)</script><b>жирный</b>');
    assert_false(str_contains($html, '<script'), 'script вырезан');
    assert_true(str_contains($html, '<b>жирный</b>'), 'разметка текста осталась');

    $plain = ContentFields::displayValue($field, "строка 1\nстрока <2>");
    assert_true(str_contains($plain, '<br'), 'переводы строк — в br');
    assert_true(str_contains($plain, '&lt;2&gt;'), 'угловые скобки экранированы');
});

test('Log Flood Guard: после 50 повторов за минуту пишется маркер, остальное подавляется', function () {
    $token = 'flood_' . bin2hex(random_bytes(4));
    $file = APP_ROOT . '/storage/logs/floodtest.log';
    @unlink($file);

    for ($i = 0; $i < 60; $i++) {
        Logger::log('floodtest', 'Повторяющаяся ошибка ' . $token, 'ERROR');
    }

    $content = (string) file_get_contents($file);
    $lines = array_filter(explode("\n", $content), static fn ($l) => str_contains($l, $token));
    assert_same(51, count($lines), '50 обычных строк + 1 маркер');
    assert_true(str_contains($content, 'Log Flood Guard'), 'маркер подавления присутствует');

    @unlink($file);
});

test('DateFormatter: три языка, Ташкент, устойчивость к мусору', function () {
    assert_same('9 июля 2026 г.', DateFormatter::long('2026-07-09', 'ru'));
    assert_same('9-iyul, 2026-yil', DateFormatter::long('2026-07-09', 'uz'));
    assert_same('July 9, 2026', DateFormatter::long('2026-07-09', 'en'));
    assert_same('1 марта 2026 г.', DateFormatter::long('2026-03-01', 'ru'));
    assert_same('9 июля 2026 г.', DateFormatter::long('2026-07-09', 'de'), 'неизвестный язык → ru');

    assert_same('09.07.2026', DateFormatter::short('2026-07-09 10:00:00'));
    assert_same('', DateFormatter::long('мусор'));
    assert_same('', DateFormatter::long(''));
    assert_same('', DateFormatter::short('0000-00-00'));

    // Timestamp конвертируется в ташкентское время (UTC+5).
    // 2026-07-08 23:30 UTC = 2026-07-09 04:30 в Ташкенте.
    $ts = (int) (new DateTime('2026-07-08 23:30:00', new DateTimeZone('UTC')))->format('U');
    assert_same('9 июля 2026 г.', DateFormatter::long($ts, 'ru'));
    assert_same('09.07.2026 04:30', DateFormatter::dateTime($ts));
});
