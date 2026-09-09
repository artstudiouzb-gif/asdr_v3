<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\DigestDispatcher;
use App\Core\Flash;
use App\Core\Logger;
use App\Core\View;
use App\Models\Language;
use App\Models\MailQueue;
use App\Models\Subscriber;

/** Рабочее пространство email-дайджеста. Только супер-админ. */
final class SubscriberController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'lang' => trim((string) ($_GET['lang'] ?? '')),
        ];
        $result = Subscriber::search($filters, max(1, (int) ($_GET['page'] ?? 1)));
        View::render('admin/subscribers/index', [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'pages' => $result['pages'],
            'filters' => $filters,
            'summary' => Subscriber::summary(),
            'queue' => MailQueue::digestStats(),
            'languages' => Language::active(),
        ]);
    }

    public function sendDigest(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        try {
            $result = DigestDispatcher::queueWeekly();
            if ($result['recipients'] === 0) {
                Flash::error('Нет активных подписчиков.');
            } elseif ($result['news'] === 0) {
                Flash::error('За последние 7 дней нет опубликованных новостей.');
            } elseif ($result['queued'] > 0) {
                Flash::success(sprintf(
                    'Дайджест поставлен в очередь: %d писем. Повторно пропущено: %d.',
                    $result['queued'],
                    $result['duplicates']
                ));
            } else {
                Flash::success('Дайджест за эту неделю уже был поставлен в очередь.');
            }
        } catch (\Throwable $exception) {
            Logger::warning('Ручное формирование дайджеста: ошибка', [
                'error' => $exception->getMessage(),
            ]);
            Flash::error('Не удалось сформировать дайджест. Проверьте журнал ошибок.');
        }

        header('Location: /admin/subscribers');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        Subscriber::delete((int) $params['id']);
        Flash::success('Подписчик удалён.');
        header('Location: /admin/subscribers');
        exit;
    }
}
