<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\TelegramNotifier;

test('Telegram: min_level гейтит уровни, SECURITY всегда', function () {
    assert_false(TelegramNotifier::isEligible('INFO', 'WARNING'));
    assert_true(TelegramNotifier::isEligible('WARNING', 'WARNING'));
    assert_true(TelegramNotifier::isEligible('ERROR', 'WARNING'));
    assert_true(TelegramNotifier::isEligible('CRITICAL', 'WARNING'));
    assert_false(TelegramNotifier::isEligible('WARNING', 'ERROR'));
    assert_true(TelegramNotifier::isEligible('SECURITY', 'CRITICAL')); // security вне шкалы
});

test('Telegram: MarkdownV2 экранирование спецсимволов', function () {
    $out = TelegramNotifier::escapeMarkdown('a_b*c.(d)!');
    assert_contains('a\\_b\\*c\\.\\(d\\)\\!', $out);
});

test('Telegram: buildText содержит метку, текст, контекст, время', function () {
    Config::merge(['app' => ['timezone' => 'UTC', 'env' => 'testing', 'debug' => true, 'url' => 'http://localhost']]);
    $text = TelegramNotifier::buildText('CRITICAL', 'БД упала', ['file' => '/x.php', 'line' => 12]);
    assert_contains('КРИТИЧНО', $text);
    assert_contains('upala', TelegramNotifier::escapeMarkdown('upala')); // sanity
    assert_contains('File', $text);
    assert_contains('12', $text);
});

test('Telegram: троттлинг глушит повтор WARNING, но не CRITICAL', function () {
    $sent = [];
    TelegramNotifier::setTransport(function ($url, $fields) use (&$sent) { $sent[] = $fields['text']; });
    Config::merge([
        'app' => ['timezone' => 'UTC', 'env' => 'testing', 'debug' => true, 'url' => 'http://localhost'],
        'telegram' => ['bot_token' => 'T', 'chat_id' => '1', 'chat_id_security' => '2', 'min_level' => 'WARNING'],
    ]);

    // Уникализируем сообщение БУКВАМИ: сигнатура троттлинга маскирует цифры,
    // поэтому цифровой hex-суффикс мог совпасть с флагом прошлого прогона.
    $u = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 8);
    TelegramNotifier::send('WARNING', "warn $u");
    TelegramNotifier::send('WARNING', "warn $u"); // повтор — заглушен троттлингом
    $warnCount = count($sent);
    assert_same(1, $warnCount, 'повторный WARNING должен быть заглушён');

    // CRITICAL не троттлится — оба уходят.
    $before = count($sent);
    TelegramNotifier::send('CRITICAL', "crit $u");
    TelegramNotifier::send('CRITICAL', "crit $u");
    assert_same($before + 2, count($sent), 'CRITICAL не должен троттлиться');

    // INFO при min_level=WARNING не отправляется.
    $before = count($sent);
    TelegramNotifier::send('INFO', "info $u");
    assert_same($before, count($sent), 'INFO ниже min_level — не отправляется');

    TelegramNotifier::setTransport(null);
});

test('Telegram: SECURITY уходит в отдельный чат при chat_id_security', function () {
    $chats = [];
    TelegramNotifier::setTransport(function ($url, $fields) use (&$chats) { $chats[] = $fields['chat_id']; });
    Config::merge([
        'app' => ['timezone' => 'UTC', 'env' => 'testing', 'debug' => true, 'url' => 'http://localhost'],
        'telegram' => ['bot_token' => 'T', 'chat_id' => 'GENERAL', 'chat_id_security' => 'SECCHAT', 'min_level' => 'WARNING'],
    ]);
    // throttle=0: случайный hex иногда состоит из одних цифр, а сигнатура
    // троттлинга маскирует цифры — без этого тест изредка флакует.
    TelegramNotifier::send('SECURITY', 'sec ' . bin2hex(random_bytes(3)), ['throttle' => 0]);
    assert_same('SECCHAT', $chats[0] ?? '');
    TelegramNotifier::setTransport(null);
});

test('Telegram: без bot_token/chat_id ничего не отправляется', function () {
    $sent = 0;
    TelegramNotifier::setTransport(function ($url, $fields) use (&$sent) { $sent++; });
    Config::merge(['telegram' => ['bot_token' => '', 'chat_id' => '']]);
    TelegramNotifier::send('CRITICAL', 'x ' . bin2hex(random_bytes(3)));
    assert_same(0, $sent);
    TelegramNotifier::setTransport(null);
});
