<?php

declare(strict_types=1);

use App\Core\InputBag;
use App\Core\Redirect;
use App\Core\RedirectException;
use App\Core\Router;

/*
 * Помощники контроллеров: InputBag вместо сырого $_POST и Redirect::to()
 * вместо header() + exit. Проверяется поведение, а не текст исходников.
 */

test('InputBag: строка обрезается, чужой тип — умолчание, предел в символах', function (): void {
    $in = new InputBag(['t' => "  Ташкент  ", 'arr' => ['x'], 'n' => 12, 'long' => 'Агентство']);
    assert_same('Ташкент', $in->str('t'));
    assert_same('dflt', $in->str('arr', 'dflt'), 'массив вместо строки — не «Array» и не TypeError');
    assert_same('', $in->str('missing'));
    assert_same('12', $in->str('n'));
    assert_same('Аген', $in->str('long', '', 4), 'кириллица режется по символам, а не посреди байта');
});

test('InputBag: число — только число, границы прижимаются, мусор — умолчание', function (): void {
    $in = new InputBag(['p' => '7', 'neg' => '-3', 'big' => '999', 'junk' => '5abc', 'zero' => '0', 'arr' => ['1']]);
    assert_same(7, $in->int('p'));
    assert_same(1, $in->int('neg', 1, 1), 'ниже минимума — минимум');
    assert_same(500, $in->int('big', 0, 1, 500));
    assert_same(42, $in->int('junk', 42), '«5abc» — не число, а не пять');
    assert_same(0, $in->int('zero', 42), 'ноль — законное значение');
    assert_same(9, $in->int('arr', 9));
});

test('InputBag: закрытый набор, флажок, список строк и id', function (): void {
    $in = new InputBag([
        'sort' => 'name_asc', 'evil' => 'drop', 'on' => '1', 'off' => '0', 'no' => 'false',
        'tags' => ['a', ['nested'], 3], 'one' => 'solo', 'ids' => ['3', 'x', '3', '-1', '0', '8'],
    ]);
    assert_same('name_asc', $in->oneOf('sort', ['date_desc', 'name_asc'], 'date_desc'));
    assert_same('date_desc', $in->oneOf('evil', ['date_desc', 'name_asc'], 'date_desc'), 'чужое — умолчание, а не ближайшее');
    assert_true($in->bool('on'));
    assert_false($in->bool('off'));
    assert_false($in->bool('no'));
    assert_false($in->bool('missing'), 'неотмеченный чекбокс не присылается вовсе');
    assert_same(['a', '3'], $in->strings('tags'), 'вложенный массив отброшен');
    assert_same(['solo'], $in->strings('one'));
    assert_same([3, 8], $in->ids('ids'), 'мусор, ноль, отрицательные и повторы выпадают');
});

test('Redirect: бросает исключение с адресом, заголовок без перевода строки', function (): void {
    try {
        Redirect::to('/admin/links');
        throw new RuntimeException('Redirect::to не бросил исключение');
    } catch (RedirectException $e) {
        assert_same('/admin/links', $e->url);
        assert_same(302, $e->status);
    }
    try {
        Redirect::to("/x\r\nSet-Cookie: a=b", 999);
    } catch (RedirectException $e) {
        assert_same(302, $e->status, 'неизвестный код — 302');
        assert_same('Location: /xSet-Cookie: a=b', $e->headerLine(), 'подставить свой заголовок нельзя');
    }
});

test('Router: редирект из действия — законный конец, наружу не выходит', function (): void {
    $after = false;
    $router = new Router();
    $router->get('/admin/redirect-probe', static function () use (&$after): void {
        Redirect::to('/admin/done');
        $after = true; // @phpstan-ignore deadCode.unreachable
    });
    $router->dispatch('GET', '/admin/redirect-probe');
    assert_false($after, 'код после редиректа не выполняется');
});

test('Бюджеты: exit и сырые суперглобалы в контроллерах только убывают', function (): void {
    foreach (['controller_exit', 'controller_superglobals'] as $id) {
        $budget = quality_budget($id);
        assert_true(
            $budget['value'] <= $budget['ceiling'],
            $budget['title'] . ': стало ' . $budget['value'] . ' при потолке ' . $budget['ceiling']
                . '; больше всего: ' . $budget['detail']
        );
    }
});
