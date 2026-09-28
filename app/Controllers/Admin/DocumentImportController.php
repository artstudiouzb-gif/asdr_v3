<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\LegacyDocumentImporter;
use App\Core\View;

/**
 * Импорт раздела документов со старого сайта (`/admin/documents/import`).
 *
 * Загрузки через браузер здесь нет намеренно: файлов две сотни и сотни
 * мегабайт, а файловый менеджер хостинга кладёт папку целиком. Экран читает
 * то, что уже лежит в `storage/imports/documents/`, и ведёт перенос пакетами
 * тем же бегунком, что достройка миниатюр.
 */
final class DocumentImportController
{
    private const BATCH_SECONDS = 15;

    public function index(): void
    {
        Auth::requireSuperAdmin();

        $inbox = LegacyDocumentImporter::inbox();
        $summary = null;
        $problem = '';
        try {
            $summary = LegacyDocumentImporter::summary($inbox);
        } catch (\Throwable $e) {
            $problem = $e->getMessage();
        }

        View::render('admin/documents/import', [
            'inbox' => LegacyDocumentImporter::INBOX,
            'summary' => $summary,
            'problem' => $problem,
        ]);
    }

    public function run(): never
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        $dryRun = (string) ($_POST['dry'] ?? '') === '1';

        try {
            $result = LegacyDocumentImporter::run(
                LegacyDocumentImporter::inbox(),
                $dryRun,
                $offset,
                (float) self::BATCH_SECONDS,
                Auth::id()
            );
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        $this->json(['ok' => true] + $result);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"ok":false}';
        exit;
    }
}
