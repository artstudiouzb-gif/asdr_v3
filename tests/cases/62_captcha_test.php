<?php

declare(strict_types=1);

use App\Core\Captcha;

// Капча публичных форм: выпуск кода, одноразовость, TTL, PNG, разметка.

test('Captcha::issue/verify: верный код проходит, регистр не важен', function () {
    $_SESSION = [];
    $code = Captcha::issue();
    assert_same(5, strlen($code));
    assert_true(Captcha::verify(strtolower($code)), 'нижний регистр принят');
});

test('Captcha: код одноразовый — вторая попытка не проходит', function () {
    $_SESSION = [];
    $code = Captcha::issue();
    assert_true(Captcha::verify($code));
    assert_false(Captcha::verify($code), 'код сожжён после проверки');
});

test('Captcha: неверный код сжигает выпуск (защита от перебора)', function () {
    $_SESSION = [];
    $code = Captcha::issue();
    assert_false(Captcha::verify('XXXXX'));
    assert_false(Captcha::verify($code), 'после провала даже верный код не проходит');
});

test('Captcha: просроченный код отклоняется', function () {
    $_SESSION = [];
    $code = Captcha::issue();
    $_SESSION['_captcha']['expires'] = time() - 1;
    assert_false(Captcha::verify($code));
});

test('Captcha: пустой/чужой ввод и отсутствие выпуска', function () {
    $_SESSION = [];
    assert_false(Captcha::verify('ABCDE'), 'без выпуска — отказ');
    Captcha::issue();
    assert_false(Captcha::verify(null));
    Captcha::issue();
    assert_false(Captcha::verify(''));
});

test('Captcha::png отдаёт валидный PNG', function () {
    $png = Captcha::png('AB3CD');
    assert_same("\x89PNG", substr($png, 0, 4));
    assert_true(strlen($png) > 500, 'картинка не пустая');
});

test('Captcha::field: разметка с картинкой, инпутом и обновлением', function () {
    $html = Captcha::field('captcha-7');
    assert_contains('/captcha.png', $html);
    assert_contains('name="_captcha"', $html);
    assert_contains('maxlength="5"', $html);
    assert_contains('id="captcha-7"', $html);
});
