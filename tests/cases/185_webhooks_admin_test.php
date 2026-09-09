<?php

declare(strict_types=1);

use App\Core\WebhookDispatcher;

test('Вебхуки: интерфейс объясняет назначение, события и точный HMAC-заголовок', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/webhooks/index.php');

    assert_contains('Если такой интеграции', $view);
    assert_contains('Новая заявка из формы', $view);
    assert_contains('Опубликована новая новость', $view);
    assert_contains('webhook.test', $view);
    assert_contains('WebhookDispatcher::SIGNATURE_HEADER', $view);
    assert_not_contains('X-ArtStudio-Signature', $view);
    assert_same('X-ASDR-Signature', WebhookDispatcher::SIGNATURE_HEADER);
});

test('Вебхуки: доступны редактирование, тест, ручной запуск и повтор ошибки', function (): void {
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/WebhookController.php');
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/webhooks/index.php');
    $model = (string) file_get_contents(APP_ROOT . '/app/Models/WebhookDelivery.php');

    assert_contains('/admin/webhooks/{id}/test', $routes);
    assert_contains('/admin/webhooks/deliveries/{id}/retry', $routes);
    assert_contains('/admin/webhooks/run', $routes);
    assert_contains('WebhookDispatcher::processQueue(5)', $controller);
    assert_contains('retryFailed', $model);
    assert_contains('name="clear_secret"', $view);
    assert_contains('type="password"', $view);
});

test('Вебхуки: разметка раздела сбалансирована и имеет навигацию', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/webhooks/index.php');

    foreach (['div', 'section', 'form', 'details', 'table', 'nav'] as $tag) {
        assert_same(
            substr_count($view, '<' . $tag),
            substr_count($view, '</' . $tag . '>'),
            'несбалансированный тег ' . $tag
        );
    }
    assert_contains('settings-jump-nav', $view);
    assert_contains('id="webhook-log"', $view);
});

test('Вебхуки: Cron и ручной запуск используют одну блокировку', function (): void {
    $dispatcher = (string) file_get_contents(APP_ROOT . '/app/Core/WebhookDispatcher.php');
    $worker = (string) file_get_contents(APP_ROOT . '/app/Console/webhook_worker.php');

    assert_contains("ProcessLock::acquire('webhook_worker')", $dispatcher);
    assert_contains('WebhookDispatcher::processQueue(30)', $worker);
    assert_contains("Heartbeat::touch('webhook')", $worker);
});
