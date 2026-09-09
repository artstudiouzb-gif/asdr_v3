<?php

declare(strict_types=1);

use App\Core\SocialPublisher;

/** Подставной Bot API: отдаёт заранее заданный ответ на каждый метод. */
$fakeApi = static function (array $responses): callable {
    return static function (string $method, string $url, string $body, array $headers) use ($responses): array {
        foreach ($responses as $apiMethod => $payload) {
            if (str_ends_with($url, '/' . $apiMethod)) {
                return ['status' => 200, 'body' => json_encode($payload), 'error' => null];
            }
        }

        return ['status' => 404, 'body' => json_encode(['ok' => false, 'error_code' => 404, 'description' => 'Not Found']), 'error' => null];
    };
};

test('Telegram: неверный токен объясняется человеческим языком', function () use ($fakeApi) {
    // Именно так Bot API отвечает на несуществующий токен: /bot<токен>/ — часть
    // адреса, поэтому 404 «Not Found», а не 401.
    $publisher = new SocialPublisher($fakeApi([]));
    $res = $publisher->checkTelegram(['token' => '123:BAD', 'chat_id' => '@channel']);

    assert_false($res['ok']);
    assert_same(1, count($res['steps']), 'дальше первого шага идти незачем');
    assert_same('Токен бота', $res['steps'][0]['name']);
    assert_contains('Токен бота неверен', $res['steps'][0]['text']);
    assert_contains('BotFather', $res['steps'][0]['text']);
});

test('Telegram: канал не найден — подсказка про chat_id', function () use ($fakeApi) {
    $publisher = new SocialPublisher($fakeApi([
        'getMe' => ['ok' => true, 'result' => ['id' => 42, 'username' => 'gov_bot']],
        'getChat' => ['ok' => false, 'description' => 'Bad Request: chat not found'],
    ]));
    $res = $publisher->checkTelegram(['token' => '123:OK', 'chat_id' => '@wrong']);

    assert_false($res['ok']);
    assert_true($res['steps'][0]['ok'], 'токен принят');
    assert_contains('@gov_bot', $res['steps'][0]['text']);
    assert_contains('chat_id', $res['steps'][1]['text']);
});

test('Telegram: бот не администратор канала', function () use ($fakeApi) {
    $publisher = new SocialPublisher($fakeApi([
        'getMe' => ['ok' => true, 'result' => ['id' => 42, 'username' => 'gov_bot']],
        'getChat' => ['ok' => true, 'result' => ['title' => 'Канал агентства']],
        'getChatMember' => ['ok' => true, 'result' => ['status' => 'left']],
    ]));
    $res = $publisher->checkTelegram(['token' => '123:OK', 'chat_id' => '@channel']);

    assert_false($res['ok']);
    assert_same(3, count($res['steps']));
    assert_contains('не администратор', $res['steps'][2]['text']);
    assert_contains('left', $res['steps'][2]['text'], 'показываем фактический статус');
});

test('Telegram: полностью рабочая конфигурация проходит все шаги', function () use ($fakeApi) {
    $publisher = new SocialPublisher($fakeApi([
        'getMe' => ['ok' => true, 'result' => ['id' => 42, 'username' => 'gov_bot']],
        'getChat' => ['ok' => true, 'result' => ['title' => 'Канал агентства']],
        'getChatMember' => ['ok' => true, 'result' => ['status' => 'administrator']],
    ]));
    $res = $publisher->checkTelegram(['token' => '123:OK', 'chat_id' => '@channel']);

    assert_true($res['ok']);
    assert_same(3, count($res['steps']));
    foreach ($res['steps'] as $step) {
        assert_true($step['ok']);
    }
    assert_contains('Канал агентства', $res['steps'][1]['text']);
});

test('Telegram: пустые настройки не ходят в сеть', function () {
    $called = false;
    $publisher = new SocialPublisher(function () use (&$called): array {
        $called = true;
        return ['status' => 200, 'body' => '{}', 'error' => null];
    });
    $res = $publisher->checkTelegram(['token' => '', 'chat_id' => '']);

    assert_false($res['ok']);
    assert_false($called, 'без настроек запрос слать некуда');
    assert_contains('Не заполнены', $res['steps'][0]['text']);
});

test('Telegram: ошибка публикации в журнале очереди тоже поясняется', function () {
    // Раньше в журнал попадало сухое «Not Found» — по нему нельзя понять,
    // что чинить именно токен.
    assert_contains('Токен бота неверен', SocialPublisher::telegramHint('Not Found'));
    assert_contains('chat_id', SocialPublisher::telegramHint('Bad Request: chat not found'));
    assert_contains('администратором', SocialPublisher::telegramHint('Bad Request: not enough rights'));
    assert_contains('токен', SocialPublisher::telegramHint('Unauthorized'));
    // Незнакомое сообщение оставляем как есть, не теряя оригинал.
    assert_same('Flood control exceeded', SocialPublisher::telegramHint('Flood control exceeded'));
});
