<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;

final class NotificationController
{
    /** Early-dispatch admin notification routes. */
    public static function dispatch(string $path, string $method): bool
    {
        if ($path !== '/admin/notifications' && !str_starts_with($path, '/admin/notifications/')) {
            return false;
        }

        $controller = new self();
        if ($method === 'GET' && $path === '/admin/notifications') {
            $controller->index();
            return true;
        }
        if ($method === 'GET' && $path === '/admin/notifications/summary') {
            $controller->summary();
            return true;
        }
        if ($method === 'POST' && $path === '/admin/notifications/read-all') {
            // Обработчик объявлен `never`: он делает редирект и выходит,
            // поэтому возврата после него нет — он был бы недостижим.
            $controller->readAll();
        }
        if ($method === 'POST' && $path === '/admin/notifications/preferences') {
            $controller->savePreferences();
        }
        if ($method === 'POST' && preg_match('#^/admin/notifications/(\d+)/(read|acknowledge|dismiss)$#', $path, $matches) === 1) {
            $id = (int) $matches[1];
            match ($matches[2]) {
                'read' => $controller->read($id),
                'acknowledge' => $controller->acknowledge($id),
                'dismiss' => $controller->dismiss($id),
            };
        }

        http_response_code(404);
        View::render('errors/404');
        return true;
    }

    public function index(): void
    {
        Auth::requireLogin();
        $userId = (int) Auth::id();
        $deliveries = ['pending' => 0, 'sent' => 0, 'failed' => 0];
        if (Auth::isSuperAdmin()) {
            try {
                $deliveries = NotificationDelivery::summary();
            } catch (\Throwable) {
                // Миграция могла ещё не примениться; сама страница останется доступной.
            }
        }

        View::render('admin/notifications/index', [
            'items' => Notification::forUser($userId, 100),
            'unreadCount' => Notification::unreadCount($userId),
            'pendingAckCount' => Notification::pendingAcknowledgementCount($userId),
            'preferences' => NotificationPreference::forUser($userId),
            'deliveries' => $deliveries,
            'isSuperAdmin' => Auth::isSuperAdmin(),
        ]);
    }

    public function summary(): void
    {
        Auth::requireLogin();
        $userId = (int) Auth::id();
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, private');

        try {
            echo json_encode([
                'ok' => true,
                'unread' => Notification::unreadCount($userId),
                'pending_ack' => Notification::pendingAcknowledgementCount($userId),
                'csrf' => Csrf::token(),
                'items' => array_map(static function (array $item): array {
                    return [
                        'id' => (int) $item['id'],
                        'category' => (string) $item['category'],
                        'severity' => (string) $item['severity'],
                        'title' => (string) $item['title'],
                        'message' => (string) $item['message'],
                        'action_url' => $item['action_url'] !== null ? (string) $item['action_url'] : null,
                        'requires_ack' => (bool) $item['requires_ack'],
                        'read' => $item['read_at'] !== null,
                        'acknowledged' => $item['acknowledged_at'] !== null,
                        'created_at' => (string) $item['created_at'],
                    ];
                }, Notification::recentForUser($userId, 6)),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            http_response_code(503);
            echo json_encode(['ok' => false, 'unread' => 0, 'pending_ack' => 0, 'items' => []]);
        }
    }

    private function read(int $id): never
    {
        $this->authorizePost();
        Notification::markRead($id, (int) Auth::id());
        $this->respondAfterMutation();
    }

    private function readAll(): never
    {
        $this->authorizePost();
        Notification::markAllRead((int) Auth::id());
        Flash::success('Все уведомления отмечены как прочитанные.');
        $this->respondAfterMutation();
    }

    private function acknowledge(int $id): never
    {
        $this->authorizePost();
        if (Notification::acknowledge($id, (int) Auth::id())) {
            Flash::success('Ознакомление подтверждено.');
        }
        $this->respondAfterMutation();
    }

    private function dismiss(int $id): never
    {
        $this->authorizePost();
        if (!Notification::dismiss($id, (int) Auth::id())) {
            Flash::error('Сначала подтвердите обязательное уведомление.');
        }
        $this->respondAfterMutation();
    }

    private function savePreferences(): never
    {
        $this->authorizePost();
        try {
            NotificationPreference::save((int) Auth::id(), $_POST);
            Flash::success('Настройки уведомлений сохранены.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }
        $this->respondAfterMutation();
    }

    private function authorizePost(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();
    }

    private function respondAfterMutation(): never
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json')) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store, private');
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: /admin/notifications');
        exit;
    }
}
