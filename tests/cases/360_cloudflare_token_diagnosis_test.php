<?php

declare(strict_types=1);

use App\Core\Cloudflare;

/*
 * Отказ Cloudflare обязан называть причину.
 *
 * Проверка связи показывала верхнюю строку ответа — «Invalid request headers»,
 * — и на этом заканчивалась. Строка не говорит ни что не так с заголовком, ни
 * какое поле чинить, а подробность у Cloudflare лежит ниже, в `error_chain`, и
 * мы её отбрасывали. Причин у этого кода на практике две, и обе в ответе не
 * названы: вместо API-токена вставлен Global API Key (это другой способ
 * авторизации, парой заголовков) или в токен затесался невидимый пробел из
 * буфера обмена.
 */

test('Невидимые пробелы в токене вырезаются, обычное значение не меняется', function (): void {
    $token = 'abcdefghij_klmnopqrst-uvwxyz0123456789AB';
    assert_same($token, Cloudflare::normalizeToken($token), 'нормальный токен не трогаем');
    assert_same($token, Cloudflare::normalizeToken(' ' . $token . "\n"), 'обычные пробелы снимаются');

    // Неразрывный пробел и нулевой ширины: в заголовке они и дают отказ,
    // а глазом в поле формы не видны вовсе.
    assert_same($token, Cloudflare::normalizeToken("\u{00A0}" . $token), 'неразрывный пробел');
    assert_same($token, Cloudflare::normalizeToken("abcdefghij_klmnopqrst-\u{200B}uvwxyz0123456789AB"), 'нулевой ширины внутри');
    assert_same($token, Cloudflare::normalizeToken($token . "\u{FEFF}"), 'BOM в хвосте');
});

test('Global API Key и Zone ID не принимаются за API-токен', function (): void {
    assert_true(
        Cloudflare::looksLikeToken('abcdefghij_klmnopqrst-uvwxyz0123456789AB'),
        'настоящий токен обязан проходить'
    );
    // Global API Key — 37 шестнадцатеричных знаков, отправляется другими
    // заголовками; в Bearer он и даёт «Invalid request headers».
    assert_false(Cloudflare::looksLikeToken('0123456789abcdef0123456789abcdef01234'), 'ключ длиной 37 — не токен');
    assert_false(Cloudflare::looksLikeToken('коротко'), 'короткая строка');
    assert_false(Cloudflare::looksLikeToken('token with space 0123456789012345678'), 'пробел внутри');
    assert_false(Cloudflare::looksLikeToken('user@example.com'), 'почта вместо токена');
});

test('Причина отказа доходит до сообщения, а не теряется в error_chain', function (): void {
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/Cloudflare.php');
    assert_contains("error_chain", $source, 'подробность ответа обязана читаться');
    assert_contains('6003', $source, 'код «Invalid request headers» объясняется отдельно');

    // Сообщение о неверном формате выдаётся до похода в сеть: ждать ответа
    // API, чтобы узнать про вставленный Zone ID, незачем.
    assert_contains('looksLikeToken', $source, 'форма токена проверяется в verify()');

    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/PerformanceController.php');
    assert_contains('Cloudflare::normalizeToken', $controller, 'токен чистится при сохранении');
    assert_contains('Cloudflare::looksLikeToken', $controller, 'форма проверяется при сохранении');
});
