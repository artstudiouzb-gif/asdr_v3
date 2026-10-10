<?php

declare(strict_types=1);

use App\Core\HtmlSanitizer;
use App\Core\MaintenanceMessage;

/*
 * Сообщение режима обслуживания правится визуальным редактором: переносы,
 * жирность, цвет, размер. Стиль пропускается только этими свойствами и только
 * с проверенными значениями — остальное в атрибуте style это уже вёрстка или
 * способ протащить на страницу чужое.
 */

test('Сообщение обслуживания: оформление текста сохраняется', function () {
    $html = MaintenanceMessage::normalize(
        '<p style="text-align: center">Сайт <strong>закрыт</strong><br>до утра</p>'
        . '<p><span style="color: #e03e2d; font-size: 24px;">Важно</span> '
        . '<span style="color: rgb(22, 145, 121)">и</span> <em>скоро</em></p>'
    );
    assert_contains('<p style="text-align: center">', $html);
    assert_contains('<strong>закрыт</strong><br>до утра', $html);
    assert_contains('style="color: #e03e2d; font-size: 24px"', $html);
    assert_contains('style="color: rgb(22, 145, 121)"', $html);
    assert_contains('<em>скоро</em>', $html);
});

test('Сообщение обслуживания: опасное и лишнее вырезается', function () {
    $html = MaintenanceMessage::normalize(
        '<p onclick="x()" style="position:fixed;inset:0;background:url(//evil/x.png)">Текст</p>'
        . '<span style="color: expression(alert(1)); font-size: 900px">Крупно</span>'
        . '<script>alert(1)</script><img src="/x.png"><a href="javascript:alert(1)">ссылка</a>'
        . '<div class="x">блок</div>'
    );
    assert_not_contains('style', $html, 'ни одно из свойств не прошло проверку — атрибута нет вовсе');
    assert_not_contains('onclick', $html);
    assert_not_contains('<script', $html);
    assert_not_contains('alert', $html);
    assert_not_contains('<img', $html);
    assert_not_contains('<div', $html);
    assert_contains('блок', $html, 'неразрешённый тег разворачивается, текст остаётся');
    assert_same('', HtmlSanitizer::filterStyle('font-size: 4px; color: red'), 'размер вне пределов и цвет словом не проходят');
});

test('Сообщение обслуживания: пустой редактор и прежний обычный текст', function () {
    assert_same('', MaintenanceMessage::normalize('<p>&nbsp;</p>'), 'пустой абзац редактора — это пустое поле');
    assert_same('<p>Стандартный текст</p>', MaintenanceMessage::html('', 'Стандартный текст'));
    assert_same(
        '<p>Закрыто &lt;до&gt; утра</p>',
        MaintenanceMessage::html('Закрыто <до> утра', 'x'),
        'текст, сохранённый до редактора, экранируется, а не читается как разметка'
    );
    assert_same("<p>Строка<br />\nвторая</p>", MaintenanceMessage::html("Строка\nвторая", 'x'));
    assert_not_contains('onclick', MaintenanceMessage::html('<p onclick="x()">Привет</p>', 'x'), 'на выводе чистится ещё раз');
});
