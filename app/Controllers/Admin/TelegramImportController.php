<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Input;
use App\Core\Redirect;
use App\Core\TelegramChannelImporter;
use App\Core\View;

/**
 * Импорт новостей из выгрузки Telegram-канала (`/admin/news/telegram-import`).
 *
 * Устроен как импорт документов: выгрузку кладут файловым менеджером в
 * `storage/imports/telegram/`, экран показывает, что в ней нашлось, и ведёт
 * перенос пакетами общим бегунком панели.
 */
final class TelegramImportController
{
    private const BATCH_SECONDS = 15;

    public function index(): void
    {
        Auth::requireSuperAdmin();

        $summary = null;
        $problem = '';
        try {
            $summary = TelegramChannelImporter::summary(TelegramChannelImporter::inbox());
        } catch (\Throwable $e) {
            $problem = $e->getMessage();
        }

        View::render('admin/news/telegram_import', [
            'inbox' => TelegramChannelImporter::INBOX,
            'summary' => $summary,
            'problem' => $problem,
        ]);
    }

    /**
     * «Только эти посты»: ссылки превращаются в номера сообщений и
     * сохраняются рядом с выгрузкой. Пустое поле снимает выбор.
     */
    public function select(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $raw = Input::post()->str('links', '', 20000);
        $ids = TelegramChannelImporter::parseLinks($raw);
        try {
            TelegramChannelImporter::saveSelection(TelegramChannelImporter::inbox(), $ids);
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
            Redirect::to('/admin/news/telegram-import');
        }
        if ($ids === []) {
            trim($raw) === ''
                ? Flash::success('Выбор снят: перенесутся все посты выгрузки.')
                : Flash::error('В тексте не нашлось ссылок на посты вида https://t.me/канал/1234.');
        } else {
            Flash::success('Выбрано постов: ' . count($ids) . '. Перенесутся только они.');
        }
        Redirect::to('/admin/news/telegram-import');
    }

    public function run(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $post = Input::post();
        $offset = $post->int('offset', 0, 0);
        $dryRun = $post->bool('dry');

        try {
            $result = TelegramChannelImporter::run(
                TelegramChannelImporter::inbox(),
                $dryRun,
                $offset,
                (float) self::BATCH_SECONDS,
                Auth::id()
            );
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);

            return;
        }

        $this->json(['ok' => true] + $result);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"ok":false}';
    }
}
