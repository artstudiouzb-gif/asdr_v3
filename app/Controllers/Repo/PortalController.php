<?php

declare(strict_types=1);

namespace App\Controllers\Repo;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\RepoAuth;
use App\Core\TelegramBot;
use App\Core\TOTP;
use App\Core\View;
use App\Models\RepoCategory;
use App\Models\RepoFile;
use App\Models\RepoUser;

/**
 * Портал файлового хранилища: общий список файлов (все авторизованные видят
 * все файлы), поиск, фильтр по категории, защищённое скачивание и
 * самостоятельное управление 2FA.
 */
final class PortalController
{
    public function index(): void
    {
        RepoAuth::requireLogin();

        $query = trim((string) ($_GET['q'] ?? ''));
        $category = (int) ($_GET['category'] ?? 0);
        $ext = trim(mb_strtolower((string) ($_GET['ext'] ?? '')));
        $sort = trim((string) ($_GET['sort'] ?? 'newest'));

        $all = RepoFile::all();
        // Популярные и последние — для боковых колонок витрины.
        $popular = $all;
        usort($popular, static fn (array $a, array $b) => (int) $b['download_count'] <=> (int) $a['download_count']);

        View::render('repo/index', [
            'files' => RepoFile::all($query, $category, 'approved', $ext, $sort),
            'categories' => RepoCategory::flatOptions(),
            'query' => $query,
            'category' => $category,
            'ext' => $ext,
            'sort' => $sort,
            'repoUser' => RepoAuth::user(),
            'totalCount' => count($all),
            'popular' => array_slice($popular, 0, 5),
            'latest' => array_slice($all, 0, 5),
        ]);
    }

    /** Загрузка файла пользователем портала: публикуется после одобрения админом. */
    public function upload(): void
    {
        RepoAuth::requireLogin();
        Csrf::verifyRequest();

        // Анти-флуд: не чаще 10 загрузок за 10 минут с одной учётки.
        if (!RateLimiter::throttle('repo_upload', (string) RepoAuth::id(), 10, 10, false)) {
            Flash::error('Слишком много загрузок. Повторите позже.');
            header('Location: /repo');
            exit;
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $categoryId = $categoryId > 0 && RepoCategory::findById($categoryId) !== null ? $categoryId : null;
        $file = $_FILES['file'] ?? null;

        if ($title === '') {
            Flash::error('Укажите название файла.');
        } elseif (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Flash::error('Выберите файл для загрузки.');
        } else {
            try {
                RepoFile::store($file, $title, $description, $categoryId, null, RepoAuth::id(), 'pending');
                Logger::security('Файл отправлен на модерацию в репозиторий', [
                    'repo_user' => (string) ($_SESSION['repo_username'] ?? ''),
                ]);
                Flash::success('Файл отправлен. Он появится на портале после одобрения администратором.');
            } catch (\Throwable $e) {
                Flash::error('Не удалось загрузить файл: ' . $e->getMessage());
            }
        }
        header('Location: /repo');
        exit;
    }

    /** @param array<string, string> $params */
    public function download(array $params): void
    {
        RepoAuth::requireLogin();

        $id = (int) ($params['id'] ?? 0);
        $file = RepoFile::findById($id);
        if ($file === null || (($file['status'] ?? 'approved') !== 'approved')) {
            http_response_code(404);
            exit('Файл не найден.');
        }

        // Мягкий лимит на частоту скачиваний с одной сессии/IP (анти-выкачивание).
        if (!RateLimiter::throttle('repo_download', (string) RepoAuth::id(), 120, 5)) {
            http_response_code(429);
            header('Retry-After: 300');
            exit('Слишком много скачиваний. Повторите позже.');
        }

        $expectedBase = realpath(RepoFile::basePath());
        $fullPath = $expectedBase !== false ? realpath($expectedBase . '/' . $file['stored_name']) : false;

        if ($fullPath === false || $expectedBase === false || !str_starts_with($fullPath, $expectedBase) || !is_file($fullPath)) {
            http_response_code(404);
            exit('Файл не найден.');
        }

        RepoFile::incrementDownload($id);
        Logger::security('Скачивание файла из репозитория', [
            'file_id' => $id,
            'repo_user' => (string) ($_SESSION['repo_username'] ?? ''),
        ]);

        $mime = $file['mime_type'] !== '' ? $file['mime_type'] : 'application/octet-stream';
        $downloadName = $file['original_name'] !== '' ? basename((string) $file['original_name']) : ('file-' . $id);

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
        header('Content-Length: ' . (string) filesize($fullPath));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header("Content-Security-Policy: default-src 'none'; sandbox");

        readfile($fullPath);
        exit;
    }

    /** @param array<string, string> $params */
    public function preview(array $params): void
    {
        RepoAuth::requireLogin();

        $id = (int) ($params['id'] ?? 0);
        $file = RepoFile::findById($id);
        if ($file === null || (($file['status'] ?? 'approved') !== 'approved')) {
            http_response_code(404);
            exit('Файл не найден.');
        }

        $expectedBase = realpath(RepoFile::basePath());
        $fullPath = $expectedBase !== false ? realpath($expectedBase . '/' . $file['stored_name']) : false;

        if ($fullPath === false || $expectedBase === false || !str_starts_with($fullPath, $expectedBase) || !is_file($fullPath)) {
            http_response_code(404);
            exit('Файл не найден.');
        }

        $mime = $file['mime_type'] !== '' ? $file['mime_type'] : 'application/octet-stream';
        $downloadName = $file['original_name'] !== '' ? basename((string) $file['original_name']) : ('file-' . $id);

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . '"');
        header('Content-Length: ' . (string) filesize($fullPath));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");

        readfile($fullPath);
        exit;
    }

    public function downloadZip(): void
    {
        RepoAuth::requireLogin();
        Csrf::verifyRequest();

        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = array_filter(explode(',', (string) $ids));
        }
        $files = RepoFile::findManyByIds($ids);

        if ($files === []) {
            Flash::error('Выберите файлы для скачивания.');
            header('Location: /repo');
            exit;
        }

        $tempZipPath = tempnam(sys_get_temp_dir(), 'repo_zip_');
        $zip = new \ZipArchive();
        if ($zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            Flash::error('Не удалось создать ZIP-архив.');
            header('Location: /repo');
            exit;
        }

        $expectedBase = realpath(RepoFile::basePath());
        foreach ($files as $f) {
            $fullPath = $expectedBase !== false ? realpath($expectedBase . '/' . $f['stored_name']) : false;
            if ($fullPath !== false && $expectedBase !== false && str_starts_with($fullPath, $expectedBase) && is_file($fullPath)) {
                $zip->addFile($fullPath, (string) ($f['original_name'] ?: 'file-' . $f['id']));
                RepoFile::incrementDownload((int) $f['id']);
            }
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="repository-documents-' . date('Y-m-d') . '.zip"');
        header('Content-Length: ' . (string) filesize($tempZipPath));
        header('Cache-Control: private, max-age=0, must-revalidate');

        readfile($tempZipPath);
        @unlink($tempZipPath);
        exit;
    }

    public function security(): void
    {
        RepoAuth::requireLogin();

        $user = RepoAuth::user();
        $setupSecret = null;
        $otpauthUri = null;

        if ((int) ($user['totp_enabled'] ?? 0) !== 1) {
            if (empty($_SESSION['repo_totp_setup_secret'])) {
                $_SESSION['repo_totp_setup_secret'] = TOTP::generateSecret();
            }
            $setupSecret = $_SESSION['repo_totp_setup_secret'];
            $otpauthUri = TOTP::provisioningUri($setupSecret, (string) $user['username'], self::totpIssuer());
        }

        View::render('repo/security', [
            'repoUser' => $user,
            'setupSecret' => $setupSecret,
            'otpauthUri' => $otpauthUri,
            'error' => null,
        ] + self::telegramViewData($user));
    }

    /** Данные для блока «Вход через Telegram» на странице безопасности. */
    private static function telegramViewData(array $user): array
    {
        $configured = TelegramBot::isConfigured();
        $linked = (int) ($user['telegram_chat_id'] ?? 0) !== 0;
        $botUsername = null;
        $linkCode = null;

        if ($configured && !$linked) {
            if (empty($_SESSION['repo_tg_link_code'])) {
                $_SESSION['repo_tg_link_code'] = 'repo-' . bin2hex(random_bytes(4));
            }
            $linkCode = (string) $_SESSION['repo_tg_link_code'];
            // Username бота — для ссылки t.me (кэш в сессии, чтобы не дёргать API).
            if (empty($_SESSION['repo_tg_bot_username'])) {
                $me = TelegramBot::getMe();
                $_SESSION['repo_tg_bot_username'] = (string) ($me['username'] ?? '');
            }
            $botUsername = (string) $_SESSION['repo_tg_bot_username'] ?: null;
        }

        return [
            'telegramConfigured' => $configured,
            'telegramLinked' => $linked,
            'telegramBotUsername' => $botUsername,
            'telegramLinkCode' => $linkCode,
        ];
    }

    /** «Проверить привязку»: ищем код привязки в сообщениях бота (getUpdates). */
    public function telegramVerify(): void
    {
        RepoAuth::requireLogin();
        Csrf::verifyRequest();

        $user = RepoAuth::user();
        $code = (string) ($_SESSION['repo_tg_link_code'] ?? '');

        if (!TelegramBot::isConfigured() || $code === '') {
            Flash::error('Привязка Telegram недоступна.');
        } elseif (($chatId = TelegramBot::findChatIdByCode($code)) === null) {
            Flash::error('Сообщение с кодом не найдено. Отправьте боту код привязки и повторите проверку.');
        } else {
            RepoUser::setTelegramChatId((int) $user['id'], $chatId);
            unset($_SESSION['repo_tg_link_code']);
            TelegramBot::sendMessage($chatId, 'Telegram привязан к файловому порталу. Теперь при входе сюда будет приходить одноразовый код.');
            Logger::security('Привязан Telegram для 2FA портала', ['user' => (string) $user['username']]);
            Flash::success('Telegram привязан. При входе будет приходить одноразовый код.');
        }
        header('Location: /repo/security');
        exit;
    }

    public function telegramDisable(): void
    {
        RepoAuth::requireLogin();
        Csrf::verifyRequest();

        $user = RepoAuth::user();
        $reauthKey = (string) ($user['id'] ?? 0) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!$user
            || !RateLimiter::throttle('repo_security_reauth', $reauthKey, 5, 15, false)
            || !password_verify((string) ($_POST['password'] ?? ''), (string) $user['password_hash'])) {
            Flash::error('Неверный пароль. Telegram не отвязан.');
            header('Location: /repo/security');
            exit;
        }
        RepoUser::setTelegramChatId((int) $user['id'], null);
        Logger::security('Отвязан Telegram 2FA портала', ['user' => (string) $user['username']]);
        Flash::success('Telegram отвязан. Вход по коду из Telegram отключён.');
        header('Location: /repo/security');
        exit;
    }

    /**
     * Подключение приложения-аутентификатора. Пароль спрашиваем так же, как
     * при отключении: без него угнанная сессия привязала бы к аккаунту свой
     * аутентификатор и получила постоянный второй фактор.
     */
    public function enableTotp(): void
    {
        RepoAuth::requireLogin();
        Csrf::verifyRequest();

        $user = RepoAuth::user();
        $secret = $_SESSION['repo_totp_setup_secret'] ?? null;
        $code = preg_replace('/\s+/', '', (string) ($_POST['code'] ?? ''));

        $reauthKey = (string) ($user['id'] ?? 0) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $passwordOk = $user
            && RateLimiter::throttle('repo_security_reauth', $reauthKey, 5, 15, false)
            && password_verify((string) ($_POST['password'] ?? ''), (string) $user['password_hash']);

        if (!$passwordOk || !$secret || !TOTP::verify((string) $secret, (string) $code)) {
            View::render('repo/security', [
                'repoUser' => $user,
                'setupSecret' => $secret,
                'otpauthUri' => $secret ? TOTP::provisioningUri((string) $secret, (string) $user['username'], self::totpIssuer()) : null,
                'error' => $passwordOk
                    ? 'Неверный код. Убедитесь, что время на устройстве синхронизировано.'
                    : 'Неверный пароль. Приложение-аутентификатор не подключено.',
            ] + self::telegramViewData($user));
            return;
        }

        RepoUser::enableTotp((int) $user['id'], (string) $secret);
        unset($_SESSION['repo_totp_setup_secret']);
        Flash::success('Двухфакторная аутентификация включена.');
        header('Location: /repo/security');
        exit;
    }

    /**
     * Issuer для otpauth-URI. Только короткий ASCII (домен сайта): кириллица
     * раздувает URI percent-кодированием втрое и не помещается в компактный
     * QR-генератор (QrCode, максимум ~108 байт).
     */
    private static function totpIssuer(): string
    {
        $host = (string) (parse_url((string) Config::get('app.url'), PHP_URL_HOST) ?: '');

        return $host !== '' && preg_match('/^[\x21-\x7E]{1,40}$/', $host) ? $host : 'Portal';
    }

    public function disableTotp(): void
    {
        RepoAuth::requireLogin();
        Csrf::verifyRequest();

        $user = RepoAuth::user();
        $reauthKey = (string) ($user['id'] ?? 0) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!$user
            || !RateLimiter::throttle('repo_security_reauth', $reauthKey, 5, 15, false)
            || !password_verify((string) ($_POST['password'] ?? ''), (string) $user['password_hash'])) {
            Flash::error('Неверный пароль. 2FA не отключена.');
            header('Location: /repo/security');
            exit;
        }
        RepoUser::disableTotp((int) $user['id']);
        Flash::success('Двухфакторная аутентификация отключена.');
        header('Location: /repo/security');
        exit;
    }
}
