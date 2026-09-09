<?php

declare(strict_types=1);

use App\Core\AdminUi;

/*
 * Поле секрета показывает своё состояние.
 *
 * Значение секрета в форму не возвращается — иначе ключ уезжал бы в HTML при
 * каждой отрисовке страницы. Но пока состояние ничем не обозначалось, «ключ
 * сохранён» и «ключа нет» рисовались одинаковой пустой строкой: владелец видел
 * пустое поле у настроенной интеграции и не мог понять, потерялся токен или
 * просто не показывается. Плашка «готово / требует внимания» была только у
 * Telegram, у остальных семи полей — ничего.
 *
 * Договорённость: состояние объявляется значком у подписи и точками в самом
 * поле, а `value` остаётся пустым — договор «пусто = оставить сохранённое»
 * читают все контроллеры, и маска в значении ушла бы в базу как настоящий
 * токен.
 */

test('Заполненное поле секрета видно, а маска не уходит в значение', function () {
    $set = AdminUi::secretField('cf_api_token', 'API-токен', true, ['placeholder' => 'cf_xxx']);

    assert_contains('•', $set, 'у сохранённого секрета в поле должны стоять точки, а не пустота');
    assert_contains('сохранён', $set, 'состояние объявляется словом, а не только точками');
    assert_contains('value=""', $set, 'значение секрета в разметку не попадает');
    assert_not_contains('value="•', $set, 'маска в значении ушла бы на сервер как настоящий токен');
    assert_not_contains('cf_xxx', $set, 'пример формата у заполненного поля не нужен — он читается как «пусто»');
    assert_contains('name="clear_cf_api_token"', $set, 'сохранённый секрет должно быть чем удалить');

    $empty = AdminUi::secretField('cf_api_token', 'API-токен', false, ['placeholder' => 'cf_xxx']);
    assert_contains('не задан', $empty, 'пустое поле обязано сказать, что ключа нет');
    assert_contains('cf_xxx', $empty, 'у пустого поля подсказкой служит пример формата');
    assert_not_contains('•', $empty, 'точки у пустого поля соврали бы о сохранённом ключе');
    assert_not_contains('clear_cf_api_token', $empty, 'удалять нечего');
});

test('Точки заполненного поля видны, а не бледны как подсказка', function () {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/admin.css');
    $start = strpos($css, '.secretfield.is-set .secretfield__input::placeholder');
    assert_true($start !== false, 'у заполненного поля placeholder красится как обычный текст');
    $rule = substr($css, (int) $start, 200);
    assert_contains('var(--admin-text)', $rule, 'бледный серый читался бы как «поле пустое»');
    assert_contains('opacity: 1', $rule, 'полупрозрачные точки — тот же бледный серый');
});

test('Все секретные настройки рисует один помощник', function () {
    $views = [
        'app/Views/admin/videos/index.php',
        'app/Views/admin/telegram/index.php',
        'app/Views/admin/settings/index.php',
        'app/Views/admin/performance/index.php',
    ];
    // Имена полей формы: у части настроек имя в POST короче ключа настройки
    // (own_token → social_telegram_token), поэтому список свой.
    $fields = [
        'youtube_api_key',
        'telegram_bot_token',
        'own_token',
        'telegram_gateway_token',
        'smtp_password',
        'ai_api_key',
        'cf_api_token',
    ];

    $markup = '';
    foreach ($views as $view) {
        $markup .= (string) file_get_contents(APP_ROOT . '/' . $view);
    }

    foreach ($fields as $field) {
        assert_contains(
            "AdminUi::secretField('" . $field . "'",
            $markup,
            'поле ' . $field . ' должно рисоваться общим помощником: своя копия разъедется с ним'
        );
        assert_not_contains(
            'name="' . $field . '"',
            $markup,
            'рукописный input для ' . $field . ' остался — состояние в нём не показано'
        );
    }
});
