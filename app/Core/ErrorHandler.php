<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Централизованный перехватчик ошибок и исключений. В production логирует
 * стек в storage/logs/error.log и отдаёт заглушку errors/500.php с HTTP 500,
 * не раскрывая деталей. В режиме отладки показывает подробности.
 */
final class ErrorHandler
{
    private static bool $debug = false;

    public static function register(bool $debug): void
    {
        self::$debug = $debug;

        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        // Уведомление об устаревшем API — не повод отдавать 500. Каждая новая
        // версия PHP помечает deprecated то, что вчера работало молча (8.5 —
        // curl_close(), imagedestroy(), $http_response_header, driver-константы
        // PDO), а обработчик превращал любую ошибку в исключение: сайт целиком
        // переставал открываться на новом сервере, хотя код продолжал работать.
        // Поэтому deprecated пишем в журнал, а не бросаем.
        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
            self::logDeprecation($message, $file, $line);
            return true;
        }
        // Остальные ошибки превращаем в исключение, чтобы обработать единообразно.
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Устаревший вызов повторяется на каждой странице, поэтому одинаковые
     * сообщения за запрос пишутся один раз — иначе журнал вырастает быстрее,
     * чем его успевают прочитать.
     */
    private static function logDeprecation(string $message, string $file, int $line): void
    {
        static $seen = [];

        $key = $message . '|' . $file . '|' . $line;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;

        Logger::log('error', Logger::redact($message) . ' in ' . $file . ':' . $line, 'DEPRECATED');
    }

    public static function handleException(Throwable $e): void
    {
        $concise = Logger::redact(get_class($e) . ': ' . $e->getMessage());
        $trace = Logger::redact($e->getTraceAsString());
        // Полный стек — в файл; в Telegram уходит компактное сообщение + контекст.
        Logger::log('error', $concise . ' in ' . $e->getFile() . ':' . $e->getLine()
            . PHP_EOL . 'Stack trace:' . PHP_EOL . $trace, 'ERROR');
        // Журнал ошибок в панели (понятное объяснение + 7 дней хранения).
        if (defined('APP_INSTALLED') && APP_INSTALLED) {
            \App\Models\ErrorLog::record('ERROR', $concise, $e->getFile(), $e->getLine());
        }
        \App\Core\TelegramNotifier::send('ERROR', $concise, Logger::redactContext([
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'url' => $_SERVER['REQUEST_URI'] ?? 'cli',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]));

        self::renderErrorPage($e);
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null) {
            return;
        }
        if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        Logger::critical('Fatal: ' . Logger::redact($error['message']), [
            'file' => $error['file'],
            'line' => $error['line'],
            'url' => $_SERVER['REQUEST_URI'] ?? 'cli',
        ]);
        if (defined('APP_INSTALLED') && APP_INSTALLED) {
            \App\Models\ErrorLog::record(
                'CRITICAL',
                Logger::redact($error['message']),
                (string) $error['file'],
                (int) $error['line']
            );
        }

        self::renderErrorPage(new \ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        ));
    }

    private static function renderErrorPage(Throwable $e): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, (string) $e . PHP_EOL);
            // Без явного кода выхода упавший скрипт возвращает 0: шаг CI с
            // фикстурой отрабатывал «успешно» на неприменённой фикстуре, а
            // ошибка вылезала позже — на входе в панель.
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(500);
        }

        // Очищаем незавершённый вывод, чтобы отдать чистую страницу ошибки.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // AJAX-эндпоинты админки ждут JSON. Отдать им HTML-страницу 500 значит
        // показать редактору «Сервер вернул некорректный ответ» вместо причины:
        // именно так выглядел обрыв импорта новостей на «2006 gone away».
        if (self::wantsJson()) {
            self::renderJsonError($e);
            return;
        }

        if (self::$debug) {
            echo '<pre class="system-debug-error">';
            echo htmlspecialchars((string) $e, ENT_QUOTES);
            echo '</pre>';
            return;
        }

        $view = dirname(__DIR__) . '/Views/errors/500.php';
        if (is_file($view)) {
            require $view;
        } else {
            echo 'Внутренняя ошибка сервера.';
        }
    }

    /** Запрос пришёл из fetch/XHR и ждёт JSON, а не страницу. */
    private static function wantsJson(): bool
    {
        if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return true;
        }

        return str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    private static function renderJsonError(Throwable $e): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
        }

        $technical = get_class($e) . ': ' . $e->getMessage();
        try {
            // Тот же «перевод» ошибок, что и в журнале: внутренностей не
            // раскрывает, но говорит, что случилось и что с этим делать.
            $message = \App\Models\ErrorLog::explain($technical);
        } catch (Throwable) {
            $message = 'Внутренняя ошибка сервера.';
        }
        if (self::$debug) {
            $message .= ' — ' . Logger::redact($technical);
        } else {
            $message .= ' Подробности — в разделе «Журнал ошибок».';
        }

        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
