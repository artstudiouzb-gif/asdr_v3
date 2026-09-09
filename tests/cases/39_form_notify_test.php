<?php

declare(strict_types=1);

use App\Core\FormNotifier;

test('FormNotifier::parseChatIds разбирает списки и отбрасывает мусор', function () {
    assert_same([123456789], FormNotifier::parseChatIds('123456789'));
    assert_same([123, 456, -1001234567890], FormNotifier::parseChatIds("123, 456;\n-1001234567890"));
    assert_same([123, 456], FormNotifier::parseChatIds(' 123   456  '));
    assert_same([], FormNotifier::parseChatIds(''));
    assert_same([], FormNotifier::parseChatIds('abc, 12e3, --5, +7'));
    assert_same([], FormNotifier::parseChatIds('0, 99999999999999999999'));
    assert_same([42], FormNotifier::parseChatIds('42, 42, 42'));
});

test('FormNotifier::formatSubmission собирает текст и режет длинные значения', function () {
    $text = FormNotifier::formatSubmission('Обратная связь', [
        'name' => 'Иван',
        'email' => 'ivan@example.com',
    ]);
    assert_true(str_contains($text, 'Новая заявка: Обратная связь'), 'название формы');
    assert_true(str_contains($text, 'Иван'), 'поле name');
    assert_true(str_contains($text, 'ivan@example.com'), 'поле email');

    // Значение длиннее 500 символов укорачивается.
    $long = FormNotifier::formatSubmission('Ф', ['msg' => str_repeat('а', 900)]);
    assert_true(mb_strlen($long) < 1000, 'длинное поле обрезано');
    assert_true(str_contains($long, '…'), 'есть многоточие');

    // Итоговый текст не превышает лимит Telegram.
    $fields = [];
    for ($i = 0; $i < 30; $i++) {
        $fields['f' . $i] = str_repeat('б', 400);
    }
    $huge = FormNotifier::formatSubmission('Много полей', $fields);
    assert_true(mb_strlen($huge) <= 3801, 'сообщение в пределах лимита');
});

test('FormNotifier: без настроенного бота/получателей отправка не выполняется (БД)', function () {
    ensure_test_db();
    \App\Models\Setting::set('telegram_bot_token', '');
    \App\Models\Setting::set('telegram_notify_chat_ids', '123');
    assert_false(FormNotifier::isEnabled(), 'без токена выключено');
    assert_same(0, FormNotifier::notifySubmission('Ф', ['a' => 'b']));

    \App\Models\Setting::set('telegram_bot_token', 'token123');
    \App\Models\Setting::set('telegram_notify_chat_ids', '');
    assert_false(FormNotifier::isEnabled(), 'без получателей выключено');
    assert_same(0, FormNotifier::notifySubmission('Ф', ['a' => 'b']));

    \App\Models\Setting::set('telegram_notify_chat_ids', '123456789');
    assert_true(FormNotifier::isEnabled(), 'токен + получатели = включено');
    assert_same([123456789], FormNotifier::chatIds());
});
