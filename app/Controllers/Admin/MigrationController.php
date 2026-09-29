<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Backup;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Heartbeat;
use App\Core\Logger;
use App\Core\SiteMigration;
use App\Core\SiteMigrationState;
use App\Core\View;

/**
 * Раздел «Переезд»: пакет сайта со старого сервера и его установка на новом.
 *
 * **Панель ничего не снимает и не заменяет сама** — как и «Обновление
 * системы», она пишет заказ (`SiteMigrationState::queue`), а работу делает
 * `app/Console/migration_worker.php` по cron. Снятие пакета с медиатекой и
 * тем более замена базы длиннее таймаута веб-запроса, и обрыв посреди
 * замены оставил бы базу собранной наполовину. Отсюда главная проверка
 * контроллера: без живого воркера кнопки не нажимаются.
 *
 * **Архив для установки не загружается формой**: её лимит на хостинге
 * меньше архива сайта с фотографиями. Владелец кладёт файл файловым
 * менеджером в `storage/migration/incoming`, панель показывает найденное и
 * сверяет то, что можно сверить быстро: манифест, ключ шифрования, пароль.
 * Контрольную сумму многогигабайтного файла считает воркер перед заменой.
 *
 * Раздел — только супер-админу: пакет — это вся база сайта.
 */
final class MigrationController
{
    /** Воркер молчит дольше — считаем, что cron не работает. */
    private const WORKER_SILENT_AFTER = 300;

    public function index(): void
    {
        Auth::requireSuperAdmin();

        $state = SiteMigrationState::read();
        $incoming = [];
        foreach (SiteMigration::archives(SiteMigration::incomingDir()) as $name) {
            $path = SiteMigration::resolve(SiteMigration::incomingDir(), $name);
            if ($path !== null) {
                $incoming[] = SiteMigration::inspect($path);
            }
        }
        $outgoing = [];
        foreach (SiteMigration::archives(SiteMigration::outgoingDir()) as $name) {
            $path = SiteMigration::resolve(SiteMigration::outgoingDir(), $name);
            if ($path !== null) {
                $outgoing[] = ['name' => $name, 'size' => (int) filesize($path), 'mtime' => (int) filemtime($path)];
            }
        }

        View::render('admin/migration/index', [
            'state' => $state,
            'stale' => SiteMigrationState::isStale($state),
            'busy' => SiteMigrationState::isBusy($state),
            'worker' => self::workerStatus(),
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'incomingDir' => SiteMigration::incomingDir(),
            'outgoingDir' => SiteMigration::outgoingDir(),
            'encryption' => Backup::encryptionSupported(),
            'appUrl' => rtrim((string) Config::get('app.url', ''), '/'),
            'confirmCode' => SiteMigration::CONFIRM_CODE,
            'passwordMin' => SiteMigration::PASSWORD_MIN,
            'cronLine' => '* * * * * ' . \App\Core\Cli::binary() . ' ' . APP_ROOT . '/app/Console/migration_worker.php'
                . ' >> ' . APP_ROOT . '/storage/logs/migration_worker.log 2>&1',
        ]);
    }

    /** Заказ пакета со старого сервера. Пароль обязателен: в пакете вся база. */
    public function export(): void
    {
        $this->guardQueue();

        $password = (string) ($_POST['password'] ?? '');
        if (!hash_equals($password, (string) ($_POST['password_repeat'] ?? ''))) {
            $this->fail('Пароли не совпадают.');
        }
        try {
            SiteMigration::assertPassword($password);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
        }
        if (!Backup::encryptionSupported()) {
            $this->fail('Шифрование ZIP (AES-256) на этом сервере недоступно — снимите пакет из консоли: '
                . 'php scripts/site_migrate.php export.');
        }

        SiteMigrationState::storePassword($password);
        SiteMigrationState::queue(SiteMigrationState::TASK_EXPORT, self::username());
        Flash::success('Снятие пакета поставлено в очередь. Воркер возьмёт его в течение минуты; '
            . 'сайт при этом работает как обычно.');
        $this->back();
    }

    /**
     * Заказ установки. Всё, что проверяется быстро, проверяется здесь — до
     * заказа, чтобы ошибка в пароле не ждала минуту воркера.
     */
    public function import(): void
    {
        $this->guardQueue();

        $name = (string) ($_POST['archive'] ?? '');
        $archive = SiteMigration::resolve(SiteMigration::incomingDir(), $name);
        if ($archive === null) {
            $this->fail('Архив не найден в ' . SiteMigration::incomingDir() . '.');
        }
        if (strtoupper(trim((string) ($_POST['confirm'] ?? ''))) !== SiteMigration::CONFIRM_CODE) {
            $this->fail('Установка не запущена: введите ' . SiteMigration::CONFIRM_CODE . ' для подтверждения.');
        }

        $info = SiteMigration::inspect($archive);
        if ($info['error'] !== '') {
            $this->fail($info['error']);
        }
        if (!$info['checksum']) {
            $this->fail('Рядом с архивом нет файла ' . $name . '.sha256 — положите его в ту же папку.');
        }
        $plain = ($_POST['plain_backup'] ?? '') === '1';
        if ($info['manifest'] === null && !$plain) {
            $this->fail('Это обычная резервная копия, а не пакет переезда: ключ шифрования не сверить. '
                . 'Отметьте «ключ перенесён вручную», если это так.');
        }
        if ($info['manifest'] !== null && !$info['key_ok']) {
            $this->fail($info['key_message']);
        }

        $password = (string) ($_POST['password'] ?? '');
        if ($info['encrypted']) {
            if (!Backup::encryptionSupported()) {
                $this->fail('Архив зашифрован, а libzip этого сервера не умеет AES-256.');
            }
            if ($password === '' || !SiteMigration::passwordMatches($archive, $password)) {
                $this->fail('Неверный пароль архива.');
            }
        }

        $from = trim((string) ($_POST['from_url'] ?? ''));
        $to = trim((string) ($_POST['to_url'] ?? ''));
        try {
            foreach ([$from, $to] as $url) {
                if ($url !== '') {
                    SiteMigration::normalizeUrl($url);
                }
            }
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
        }

        if ($info['encrypted']) {
            SiteMigrationState::storePassword($password);
        }
        SiteMigrationState::queue(SiteMigrationState::TASK_IMPORT, self::username(), $name, [
            'from_url' => $from,
            'to_url' => $to,
            'plain_backup' => $plain,
        ]);
        Logger::security('Заказана установка пакета переезда', ['file' => $name]);
        Flash::success('Установка поставлена в очередь. На время замены сайт закроется на обслуживание. '
            . 'Когда база заменится, панель попросит войти заново — учётной записью старого сайта.');
        $this->back();
    }

    /** Скачивание готового пакета или его суммы. */
    public function download(): void
    {
        Auth::requireSuperAdmin();

        $name = (string) ($_GET['file'] ?? '');
        $sum = str_ends_with($name, '.sha256');
        $path = SiteMigration::resolve(SiteMigration::outgoingDir(), $sum ? substr($name, 0, -7) : $name);
        if ($path !== null && $sum) {
            $path = is_file(Backup::checksumPath($path)) ? Backup::checksumPath($path) : null;
        }
        if ($path === null) {
            http_response_code(404);
            echo 'Файл не найден.';
            exit;
        }

        Logger::security('Скачан пакет переезда', ['file' => basename($path)]);
        header('Content-Type: ' . ($sum ? 'text/plain; charset=utf-8' : 'application/zip'));
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        readfile($path);
        exit;
    }

    /** Удаление пакета: после переезда ему незачем лежать на сервере. */
    public function delete(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $dir = ($_POST['where'] ?? '') === 'incoming' ? SiteMigration::incomingDir() : SiteMigration::outgoingDir();
        $path = SiteMigration::resolve($dir, (string) ($_POST['archive'] ?? ''));
        if ($path === null) {
            $this->fail('Архив не найден.');
        }
        if (SiteMigrationState::isBusy() && basename($path) === SiteMigrationState::read()['archive']) {
            $this->fail('Этот архив сейчас устанавливается.');
        }
        @unlink($path);
        @unlink(Backup::checksumPath($path));
        Flash::success('Архив ' . basename($path) . ' удалён.');
        $this->back();
    }

    /** Сброс зависшей задачи, если воркер умер вместе с cron. */
    public function reset(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        SiteMigrationState::finish(SiteMigrationState::STATUS_FAILED, 'сброшено вручную из панели.');
        Flash::warning('Задача переезда сброшена. Если шла установка — проверьте сайт.');
        $this->back();
    }

    private function guardQueue(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        if (SiteMigrationState::isBusy()) {
            $this->fail('Задача переезда уже идёт — дождитесь окончания.');
        }
        if (!self::workerStatus()['alive']) {
            $this->fail('Воркер переезда не отвечает — заказ никто не выполнит. Заведите задание cron '
                . 'для app/Console/migration_worker.php (строка — на этой странице).');
        }
    }

    /** @return array{last:?int, age:?int, alive:bool} */
    private static function workerStatus(): array
    {
        $last = Heartbeat::lastRun('migration');
        $age = $last !== null ? time() - $last : null;

        return ['last' => $last, 'age' => $age, 'alive' => $age !== null && $age <= self::WORKER_SILENT_AFTER];
    }

    private static function username(): string
    {
        $user = Auth::user();

        return (string) ($user['username'] ?? '');
    }

    private function fail(string $message): never
    {
        Flash::error($message);
        $this->back();
    }

    private function back(): never
    {
        header('Location: /admin/migration');
        exit;
    }
}
