<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\AdminEntryConfig;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Logger;
use App\Core\NotificationCenter;

/** Handles the protected admin-entry settings form before the main router. */
final class AdminEntrySettingsController
{
    public function update(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $action = (string) ($_POST['action'] ?? 'save');
        if (empty($_POST['confirm_access_change'])) {
            Flash::error('Подтвердите, что новый адрес сохранён в безопасном месте.');
            $this->redirect();
        }

        $actor = Auth::sessionUser();
        $actorName = (string) ($actor['username'] ?? 'Администратор');
        $actorId = isset($actor['id']) ? (int) $actor['id'] : null;

        try {
            if ($action === 'disable') {
                AdminEntryConfig::disable();
                Logger::security('Скрытый адрес административной панели отключён', [
                    'user' => $actorName,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ]);
                NotificationCenter::administrators(
                    'Скрытый вход отключён',
                    $actorName . ' отключил защищённый адрес входа. Стандартный маршрут авторизации снова доступен.',
                    'security',
                    'critical',
                    '/admin/security#admin-entry',
                    true,
                    null,
                    $actorId
                );
                Flash::success('Скрытый адрес отключён. Стандартный /admin/login снова доступен.');
                $this->redirect();
            }

            $state = AdminEntryConfig::state();
            $ttl = (int) ($_POST['admin_entry_ttl'] ?? $state['ttl']);
            $cidrs = (string) ($_POST['admin_entry_allowed_cidrs'] ?? '');
            $path = $action === 'regenerate'
                ? AdminEntryConfig::generatePath()
                : (string) ($_POST['admin_entry_path'] ?? '');

            AdminEntryConfig::save($path, $ttl, preg_split('/[\s,]+/', $cidrs, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $event = $action === 'regenerate'
                ? 'Скрытый адрес административной панели сгенерирован заново'
                : 'Настройки скрытого адреса административной панели изменены';
            Logger::security($event, [
                'user' => $actorName,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'cidr_restricted' => trim($cidrs) !== '',
            ]);
            NotificationCenter::administrators(
                $action === 'regenerate' ? 'Адрес входа обновлён' : 'Защита входа изменена',
                $actorName . ' изменил настройки защищённого входа. Сам секретный адрес во внешние уведомления не передаётся.',
                'security',
                'security',
                '/admin/security#admin-entry',
                true,
                null,
                $actorId
            );
            Flash::success(
                $action === 'regenerate'
                    ? 'Новый адрес создан. Скопируйте его до выхода из панели.'
                    : 'Защищённый адрес входа сохранён.'
            );
        } catch (\Throwable $e) {
            Logger::security('Не удалось изменить скрытый адрес административной панели', [
                'user' => $actorName,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'error' => $e->getMessage(),
            ]);
            NotificationCenter::administrators(
                'Ошибка изменения защищённого входа',
                'Не удалось сохранить настройки защищённого входа. Проверьте Центр безопасности и журналы.',
                'security',
                'error',
                '/admin/security#admin-entry',
                false,
                null,
                $actorId
            );
            Flash::error($e->getMessage());
        }

        $this->redirect();
    }

    private function redirect(): never
    {
        header('Location: /admin/security#admin-entry');
        exit;
    }
}
