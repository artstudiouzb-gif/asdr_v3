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

test('Проверка связи спрашивает все три звена, а не одно', function (): void {
    // Пока проверка спрашивала только сведения о зоне, она отвечала
    // «Подключено к зоне», а очистка тут же отказывала: чтение зоны требует
    // Zone:Read, которого интеграции не нужно, а саму очистку никто не
    // проверял. Понять по такому ответу, что чинить, было нечем.
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/Cloudflare.php');
    assert_contains('/user/tokens/verify', $source, 'сам токен не проверяется');
    assert_contains('probePurge', $source, 'очистка — то, ради чего токен заведён, — не проверяется');
    assert_contains('purge_cache', $source, 'пробная очистка идёт на тот же адрес API');
    assert_not_contains('purge_everything', explode('probePurge', $source)[1] ?? '', 'проба не чистит кэш по-настоящему');

    // Подсказка в форме обязана называть то же право, иначе редактор создаст
    // токен по инструкции и не пройдёт нашу же проверку.
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/performance/index.php');
    assert_contains('Create Custom Token', $form, 'шаблоны Cloudflare для очистки кэша не подходят');
    assert_contains('Cache Purge', $form, 'право названо');
    assert_contains('Global API Key', $form, 'частая подмена названа прямо в форме');
});

test('Причина отказа очистки доходит до сообщения, а не только до журнала', function (): void {
    // «Cloudflare: ошибка очистки» не подсказывает, что чинить, а журнал на
    // shared-хостинге владелец не читает: до storage/logs он не доходит.
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/Cloudflare.php');
    assert_contains('lastError', $source, 'причина отказа обязана сохраняться');
    assert_contains('cache.purge', $source, 'отказ прав объясняется, а не только пересказывается');

    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/PerformanceController.php');
    assert_contains('Cloudflare::lastError()', $controller, 'сообщение панели обязано называть причину');
    assert_same(
        2,
        preg_match_all('/Cloudflare::lastError\(\)/', $controller),
        'причину называют обе кнопки: и «Сброс кэша», и «Очистить кэш Cloudflare»'
    );
});

test('Подсказка написана на формулировки, которые Cloudflare действительно шлёт', function (): void {
    // Первая версия проверяла текст «requires permission» — его в ответах нет,
    // и подсказка не срабатывала ни разу. Эти две строки сняты с боевого
    // журнала: 'Authentication error' (токен не принят) и 'Unable to purge.
    // Unauthorized.' (токен узнан, права Cache Purge нет).
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/Cloudflare.php');
    assert_contains("'Unable to purge'", $source, 'формулировка отказа очистки');
    assert_contains("'Authentication error'", $source, 'формулировка непринятого токена');
    assert_contains('Cache Purge', $source, 'подсказка называет право');
});

test('Код ошибки Cloudflare попадает в сообщение', function (): void {
    // Одна и та же фраза приходит с разными кодами, и без кода причину
    // приходится угадывать по формулировке — что уже подводило.
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/Cloudflare.php');
    assert_contains("' (код '", $source, 'код обязан печататься рядом с текстом');
    assert_contains('ведёт партнёр', $source, 'причина «зону ведёт партнёр» названа');
});

/*
 * Второй заход: токен активен, зона читается, очистка отказывает.
 *
 * На этом месте догадка о причине была сделана дважды и дважды мимо. Разница
 * между «у токена нет права», «право срезано ролью в чужом аккаунте» и «зону
 * ведёт партнёр» из текста отказа не видна вовсе — зато она видна в самой
 * записи зоны, которую мы уже запрашиваем и раньше выбрасывали, прочитав из
 * неё одно название.
 */
test('Запись зоны отвечает на то, чего нет в тексте отказа', function (): void {
    $own = Cloudflare::zoneFacts([
        'result' => ['name' => 'asdr.uz', 'type' => 'full', 'status' => 'active', 'paused' => false,
                     'account' => ['name' => 'Artstudio']],
    ]);
    assert_same('asdr.uz', $own['name'], 'имя зоны читается');
    assert_same('Artstudio', $own['account'], 'аккаунт зоны читается: роль в нём режет права токена');
    assert_same('asdr.uz (аккаунт «Artstudio»)', Cloudflare::zoneSummary($own), 'сводка называет аккаунт');

    $partner = Cloudflare::zoneFacts([
        'result' => ['name' => 'asdr.uz', 'type' => 'partial', 'status' => 'active', 'paused' => true,
                     'host' => ['name' => 'Hostinger'], 'account' => ['name' => 'Hostinger Managed']],
    ]);
    $summary = Cloudflare::zoneSummary($partner);
    assert_contains('партнёр Hostinger', $summary, 'партнёрскую зону видно по полю host');
    assert_contains('CNAME', $summary, 'частичное подключение названо');
    assert_contains('проксирование выключено', $summary, 'при paused кэша нет вовсе — очистке нечего чистить');

    // Пустой ответ не должен превращаться в набор скобок с пустотой.
    assert_same('доступна', Cloudflare::zoneSummary(Cloudflare::zoneFacts(null)), 'без данных — без домыслов');
});

test('Зона не того домена — это отдельная поломка, а не отказ прав', function (): void {
    // Очистка чужой зоны отработает успешно и не тронет ни одной страницы
    // сайта. Молчать об этом нельзя: искать потом причину «кэш не
    // сбрасывается» будет негде — ошибки-то нет.
    $warn = Cloudflare::siteHostMismatch('https://artstudio.uz', 'asdr.uz');
    assert_contains('не затронет сайт', $warn, 'расхождение обязано называться прямо');
    assert_contains('artstudio.uz', $warn, 'назван адрес сайта');

    assert_same('', Cloudflare::siteHostMismatch('https://asdr.uz', 'asdr.uz'), 'домен зоны — не расхождение');
    assert_same('', Cloudflare::siteHostMismatch('https://www.asdr.uz/', 'asdr.uz'), 'поддомен принадлежит зоне');
    assert_same('', Cloudflare::siteHostMismatch('', 'asdr.uz'), 'сравнивать не с чем — молчим');
    assert_same('', Cloudflare::siteHostMismatch('https://asdr.uz', ''), 'зона не прочиталась — молчим');
});

test('Пробная очистка идёт по домену зоны, а не по адресу сайта', function (): void {
    // Адрес чужого домена Cloudflare отвергает независимо от прав, и такой
    // отказ читался бы как нехватка права, которого на деле хватает.
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/Cloudflare.php');
    $probe = explode('private static function probePurge', $source)[1] ?? '';
    assert_contains("'https://' . \$zoneName", $probe, 'проба обязана брать домен у самой зоны');
    assert_contains('app.url', $probe, 'без имени зоны остаётся прежний адрес сайта');
});

test('Ответ Cloudflare печатается как есть, а не только в пересказе', function (): void {
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/Cloudflare.php');
    assert_contains('ответ Cloudflare:', $source, 'сырой ответ обязан доходить до владельца');
    assert_contains('rawTail', $source, 'хвост с ответом собирается отдельно');
    assert_contains("'raw' =>", $source, 'проба обязана вернуть тело ответа');

    // Третью причину назвали только после того, как первые две не подтвердились.
    assert_contains('Причин три', $source, 'причин у отказа очистки три, а не две');
    assert_contains('не может больше своего хозяина', $source, 'роль в чужом аккаунте режет права токена');
});
