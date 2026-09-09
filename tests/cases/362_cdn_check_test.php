<?php

declare(strict_types=1);

/*
 * «Идёт ли статика через CDN» — вопрос, на который владелец не может ответить
 * сам: заголовки ответа в браузере он не смотрит, а панель хостинга говорит
 * лишь о том, что CDN включён, а не о том, что файлы через него идут.
 *
 * Два решения здесь принципиальны и потому стерегутся.
 *
 * Первое: проверка выполняется в браузере, а не на сервере. Запрос сайта к
 * собственному адресу разрешается в себя же и до края сети не доходит вовсе —
 * серверная проверка отвечала бы «CDN нет» при работающем CDN, то есть врала
 * бы ровно в том случае, ради которого её и открыли.
 *
 * Второе: ответ печатается заголовками как есть. Имена у каждого CDN свои,
 * закрытого списка поставщиков не существует, и вердикт одним словом уже
 * дважды уводил диагноз не туда на соседней интеграции.
 */

test('Проверка CDN идёт из браузера, а не с сервера', function (): void {
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin-cdn-check.js');
    assert_contains('fetch(', $js, 'запрос делает браузер');
    assert_contains("cache: 'reload'", $js, 'кэш браузера обходится, иначе второй запрос до сети не дойдёт');

    // Серверного двойника быть не должно: он отвечал бы на другой вопрос,
    // а выглядел бы как ответ на этот.
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/performance/index.php');
    assert_contains('data-cdn-check', $view, 'кнопка проверки на месте');
    assert_contains('из вашего браузера', $view, 'причина такого устройства названа редактору');

    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/PerformanceController.php');
    assert_not_contains('cdnCheck', $controller, 'проверка CDN не должна ходить с сервера');
});

test('Ответ печатается заголовками, а не одним словом', function (): void {
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin-cdn-check.js');
    foreach (['x-hcdn-cache-status', 'cf-cache-status', 'x-cache', 'age', 'via'] as $header) {
        assert_contains($header, $js, 'заголовок ' . $header . ' обязан разбираться');
    }
    assert_contains('cdn-check__row', $js, 'каждый найденный заголовок печатается строкой');

    // Кэш LiteSpeed стоит на самом хостинге и постоянно принимается за CDN.
    // Считать его признаком CDN — значит отвечать «работает» там, где не
    // работает.
    assert_contains('x-litespeed-cache', $js, 'кэш хостинга обязан узнаваться');
    assert_contains("'server'", $js, 'и относиться не к CDN, а к самому серверу');
});

test('CDN хостинга не вписывается в поле стороннего CDN', function (): void {
    // Такой CDN стоит перед тем же адресом сайта, отдельного хоста для файлов
    // не появляется — вписанный туда адрес сломал бы ссылки на стили.
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/performance/index.php');
    assert_contains('поле должно', $view, 'о пустом поле сказано прямо');
    assert_contains('pull-zone', $view, 'названо, какому CDN поле всё-таки нужно');
});
