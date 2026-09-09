<?php

declare(strict_types=1);

use App\Core\HeartbeatPing;

test('Сигнал наружу: без адреса ничего не делает', function (): void {
    // Функция выключена по умолчанию: сайт без внешнего приёмника не должен
    // ходить в сеть на каждом проходе сторожа.
    putenv('MONITORING_HEARTBEAT_URL');
    assert_false(HeartbeatPing::isConfigured());
    assert_same('', HeartbeatPing::url());
    assert_false(HeartbeatPing::send());
});

test('Сигнал наружу: небезопасный адрес не отправляется', function (): void {
    // Адрес приходит из окружения, но опечатка не должна превращать сторож в
    // инструмент обхода сети: loopback и внутренние адреса отсекаются.
    foreach (['http://127.0.0.1/ping', 'https://localhost/ping', 'file:///etc/passwd', 'ftp://example.com/x'] as $bad) {
        putenv('MONITORING_HEARTBEAT_URL=' . $bad);
        assert_true(HeartbeatPing::isConfigured(), 'адрес не прочитан: ' . $bad);
        assert_false(HeartbeatPing::send(), 'отправлено на небезопасный адрес: ' . $bad);
    }
    putenv('MONITORING_HEARTBEAT_URL');
});

test('Сигнал наружу: запрос идёт через защищённый клиент', function (): void {
    // Http::get() не проверяет хост и не закрепляет IP. Для адреса из
    // окружения этого мало — между проверкой и запросом возможна подмена DNS.
    $source = (string) file_get_contents(APP_ROOT . '/app/Core/HeartbeatPing.php');
    assert_contains('Http::getSafeRemote(', $source);
    assert_false(str_contains($source, 'Http::get('), 'использован незащищённый клиент');
});

test('Сторож пингует только чистый проход', function (): void {
    // Приёмник поднимает тревогу по тишине. Сигнал при известных проблемах
    // прятал бы их от него — сторож молчал бы ровно тогда, когда нужен.
    $watchdog = (string) file_get_contents(APP_ROOT . '/app/Console/watchdog.php');
    assert_contains('HeartbeatPing::isConfigured()', $watchdog);
    assert_contains("\$result['problems'] === []", $watchdog);

    $pingPos = strpos($watchdog, 'HeartbeatPing::send()');
    $guardPos = strpos($watchdog, "\$result['problems'] === []");
    assert_true($pingPos !== false && $guardPos !== false && $guardPos < $pingPos, 'пинг не защищён проверкой проблем');
});
