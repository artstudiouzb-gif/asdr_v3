<?php

declare(strict_types=1);

use App\Core\RedisAdapter;

test('RedisAdapter: безопасный fallback при отсутствии конфигурации или расширения', function (): void {
    RedisAdapter::reset();

    // При пустом хосте адаптер считает себя недоступным
    putenv('REDIS_HOST=');
    assert_false(RedisAdapter::isAvailable());
    assert_same(null, RedisAdapter::client());

    // Все методы безопасны и не бросают исключений
    assert_same(null, RedisAdapter::get('non_existent_key'));
    assert_false(RedisAdapter::set('test_key', 'value', 60));
    assert_false(RedisAdapter::delete('test_key'));
    assert_same(0, RedisAdapter::deletePrefix('test'));
    assert_same(null, RedisAdapter::acquireLock('test_lock', 5));
    assert_false(RedisAdapter::releaseLock('test_lock', 'invalid_token'));
    assert_false(RedisAdapter::pushQueue('jobs', ['task' => 'mail']));
    assert_same(null, RedisAdapter::popQueue('jobs'));
    assert_same(0, RedisAdapter::queueLength('jobs'));
});

test('RedisAdapter: нормализация префикса и окружения', function (): void {
    putenv('REDIS_PREFIX=custom_prefix');
    assert_same('custom_prefix:', RedisAdapter::prefix());

    putenv('REDIS_PREFIX=custom_prefix:');
    assert_same('custom_prefix:', RedisAdapter::prefix());

    putenv('REDIS_PREFIX');
    assert_same('asdr:', RedisAdapter::prefix());
});

test('RedisAdapter: сброс состояния reset() очищает кэш подключения', function (): void {
    RedisAdapter::reset();
    assert_false(RedisAdapter::isAvailable());

    // Повторный вызов после reset работает корректно
    RedisAdapter::reset();
    assert_same(null, RedisAdapter::client());
});
