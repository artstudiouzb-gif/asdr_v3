<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Heartbeat;
use App\Core\UrlGuard;
use App\Core\View;
use App\Core\WebhookDispatcher;
use App\Models\Webhook;
use App\Models\WebhookDelivery;

/**
 * Управление исходящими вебхуками (задача 136) — только супер-администратор.
 */
final class WebhookController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();
        // Фильтр журнала доставок по статусу (группа 2.2): all|failed|pending|sent.
        $status = (string) ($_GET['status'] ?? '');
        $status = in_array($status, ['failed', 'pending', 'sent'], true) ? $status : '';
        View::render('admin/webhooks/index', [
            'items' => Webhook::all(),
            'deliveries' => WebhookDelivery::recent(30, $status !== '' ? $status : null),
            'events' => Webhook::EVENTS,
            'statusFilter' => $status,
            'queueCounts' => WebhookDelivery::counts(),
            'workerStatus' => Heartbeat::status()['webhook'] ?? null,
        ]);
    }

    public function store(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        [$event, $url, $secret, $active, $error] = $this->collect();
        if ($error !== null) {
            Flash::error($error);
            $anchor = '#webhook-add';
        } else {
            Webhook::create($event, $url, $secret, $active);
            Flash::success('Получатель вебхука добавлен.');
            $anchor = '#webhook-endpoints';
        }
        header('Location: /admin/webhooks' . $anchor);
        exit;
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $id = (int) ($params['id'] ?? 0);
        $current = $id > 0 ? Webhook::findById($id) : null;
        if ($current === null) {
            Flash::error('Получатель вебхука не найден.');
            header('Location: /admin/webhooks#webhook-endpoints');
            exit;
        }

        [$event, $url, $secret, $active, $error] = $this->collect($current);
        if ($error !== null) {
            Flash::error($error);
        } else {
            Webhook::update($id, $event, $url, $secret, $active);
            Flash::success('Получатель вебхука обновлён.');
        }
        header('Location: /admin/webhooks#webhook-endpoints');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        Webhook::delete((int) $params['id']);
        Flash::success('Получатель и его журнал доставок удалены.');
        header('Location: /admin/webhooks#webhook-endpoints');
        exit;
    }

    /**
     * Проверить URL и HMAC-подпись безопасным событием webhook.test.
     * @param array<string, string> $params
     */
    public function test(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $webhook = Webhook::findById((int) ($params['id'] ?? 0));
        if ($webhook === null) {
            Flash::error('Получатель вебхука не найден.');
        } else {
            $result = WebhookDispatcher::test($webhook);
            if ($result['ok']) {
                Flash::success('Тест доставлен. Получатель ответил HTTP ' . $result['code'] . '.');
            } else {
                $code = $result['code'] > 0 ? 'HTTP ' . $result['code'] . ': ' : '';
                Flash::error('Тест не доставлен. ' . $code . $result['error']);
            }
        }
        header('Location: /admin/webhooks#webhook-endpoints');
        exit;
    }

    /**
     * Повторить одну доставку, завершившуюся ошибкой.
     * @param array<string, string> $params
     */
    public function retry(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $id = (int) ($params['id'] ?? 0);
        if ($id > 0 && WebhookDelivery::retryFailed($id)) {
            Flash::success('Доставка возвращена в очередь.');
        } else {
            Flash::error('Доставка не найдена или уже находится в обработке.');
        }
        header('Location: /admin/webhooks?status=pending#webhook-log');
        exit;
    }

    /** Обработать небольшую пачку очереди без ожидания Cron. */
    public function runNow(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $result = WebhookDispatcher::processQueue(5);
        if ($result['busy']) {
            Flash::error('Воркер уже выполняется. Второй запуск не создан.');
        } elseif ($result['taken'] === 0) {
            Flash::success('Очередь пуста — доставлять нечего.');
        } elseif ($result['failed'] === 0) {
            Flash::success('Доставлено: ' . $result['sent'] . '.');
        } else {
            Flash::error(
                'Доставлено: ' . $result['sent'] . ', с ошибкой: ' . $result['failed']
                . '. Подробности — в журнале.'
            );
        }
        header('Location: /admin/webhooks#webhook-log');
        exit;
    }

    /**
     * @return array{0:string,1:string,2:?string,3:bool,4:?string}
     */
    private function collect(?array $current = null): array
    {
        $event = (string) ($_POST['event_type'] ?? '');
        $url = trim((string) ($_POST['url'] ?? ''));
        $secretInput = trim((string) ($_POST['secret'] ?? ''));
        $clearSecret = !empty($_POST['clear_secret']);
        $secret = $clearSecret
            ? null
            : ($secretInput !== '' ? $secretInput : ($current['secret'] ?? null));
        $active = !empty($_POST['is_active']);

        if (!in_array($event, Webhook::EVENTS, true)) {
            return ['', '', null, false, 'Неизвестный тип события.'];
        }
        if (strlen($url) > 500
            || preg_match('#^https://#i', $url) !== 1
            || !UrlGuard::isSafeRemote($url)) {
            return ['', '', null, false, 'Укажите доступный публичный HTTPS-адрес. Внутренние и локальные URL запрещены.'];
        }
        if (!$clearSecret && $secretInput !== '' && (
            strlen($secretInput) < 16
            || strlen($secretInput) > 500
            || preg_match('/[\x00-\x1F\x7F]/', $secretInput) === 1
        )) {
            return ['', '', null, false, 'Секрет должен содержать от 16 до 500 символов без переносов строк.'];
        }

        return [$event, $url, is_string($secret) && $secret !== '' ? $secret : null, $active, null];
    }
}
