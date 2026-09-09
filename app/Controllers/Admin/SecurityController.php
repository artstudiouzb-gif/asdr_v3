<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\AdminEntryConfig;
use App\Core\Auth;
use App\Core\Database;
use App\Core\TelegramBot;
use App\Core\TelegramGateway;
use App\Core\View;
use App\Models\AuditLog;
use App\Models\SessionRegistry;

final class SecurityController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();

        $currentUser = Auth::user();
        $authEvents = [];
        $authSummary = [
            'total' => 0,
            'successful' => 0,
            'failed' => 0,
            'locked' => 0,
            'delivery_failed' => 0,
        ];
        $activeSessions = 0;
        if (Database::isConnected()) {
            try {
                $authEvents = AuditLog::recentAuthEvents(50);
                $authSummary = AuditLog::authSummary(24);
                $activeSessions = $currentUser
                    ? count(SessionRegistry::forUser((int) $currentUser['id']))
                    : 0;
            } catch (\Throwable) {
                $authEvents = [];
            }
        }

        $logFile = APP_ROOT . "/storage/logs/security.log";
        $technicalEvents = [];
        if (is_file($logFile)) {
            $lines = array_reverse(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
            $technicalEvents = array_slice($lines, 0, 30);
        }

        $botLinked = TelegramBot::isConfigured()
            && (int) ($currentUser['telegram_chat_id'] ?? 0) > 0;
        $gatewayLinked = TelegramGateway::isConfigured()
            && trim((string) ($currentUser['phone'] ?? '')) !== '';

        View::render("admin/security/index", [
            "authEvents" => $authEvents,
            "authSummary" => $authSummary,
            "technicalEvents" => $technicalEvents,
            "currentUser" => $currentUser,
            "activeSessions" => $activeSessions,
            "botLinked" => $botLinked,
            "gatewayLinked" => $gatewayLinked,
            "adminEntry" => AdminEntryConfig::state(),
        ]);
    }
}
