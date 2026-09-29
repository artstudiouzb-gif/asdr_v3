<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Состояние задачи переезда (раздел `/admin/migration`): что заказано из
 * панели, на каком шаге воркер и чем кончилось.
 *
 * Устроено как `UpdateState`, с одним отличием, ради которого класс и
 * отдельный: **состояние лежит файлом, а не в `settings`.** Установка пакета
 * заменяет базу целиком — запись в `settings` исчезла бы посреди работы
 * вместе со всей таблицей, и панель после установки не знала бы, чем она
 * кончилась. Каталог `storage/migration` восстановление не трогает.
 *
 * **Пароль архива в журнал не попадает.** Панель передаёт его воркеру
 * отдельным файлом (`storePassword`) — зашифрованным ключом сервера, если он
 * задан, — и воркер стирает файл, как только прочёл (`takePassword`): пароль
 * живёт на диске ровно до начала задачи.
 */
final class SiteMigrationState
{
    public const TASK_EXPORT = 'export';
    public const TASK_IMPORT = 'import';

    public const STATUS_IDLE = 'idle';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    /**
     * Молчание дольше — задача сорвалась. Час, а не пятнадцать минут, как у
     * обновления: страховочная копия и распаковка большой медиатеки идут
     * одним шагом без отметок.
     */
    public const STALE_AFTER = 3600;

    private const LOG_LIMIT = 40;

    private static function path(): string
    {
        return SiteMigration::dir() . '/state.json';
    }

    private static function passwordPath(): string
    {
        return SiteMigration::dir() . '/.password';
    }

    /**
     * @return array{task:string, status:string, requested_by:string, requested_at:int,
     *     started_at:int, finished_at:int, heartbeat:int, archive:string,
     *     options:array{from_url:string, to_url:string, plain_backup:bool},
     *     result:string, error:string, log:list<array{at:int,level:string,text:string}>}
     */
    public static function read(): array
    {
        $raw = is_file(self::path()) ? (string) file_get_contents(self::path()) : '';
        $data = $raw !== '' ? json_decode($raw, true) : null;

        return self::normalize(is_array($data) ? $data : []);
    }

    /** @param array<string,mixed> $state */
    public static function write(array $state): void
    {
        self::ensureDir();
        $json = json_encode(self::normalize($state), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // Через временный файл: панель читает состояние, пока воркер пишет,
        // и половина JSON читалась бы как «ничего не заказано».
        $tmp = self::path() . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json, LOCK_EX) !== strlen($json) || !rename($tmp, self::path())) {
            @unlink($tmp);
            throw new \RuntimeException('Не удалось записать состояние переезда в ' . self::path() . '.');
        }
    }

    /**
     * Заказ из панели. Больше веб-запрос не делает ничего: замену базы он бы
     * не пережил, оборвавшись по таймауту на середине.
     *
     * @param array{from_url?:string, to_url?:string, plain_backup?:bool} $options
     */
    public static function queue(string $task, string $user, string $archive = '', array $options = []): void
    {
        self::write([
            'task' => $task,
            'status' => self::STATUS_QUEUED,
            'requested_by' => $user,
            'requested_at' => time(),
            'heartbeat' => time(),
            'archive' => $archive,
            'options' => $options,
            'log' => [[
                'at' => time(),
                'level' => 'ok',
                'text' => $task === self::TASK_EXPORT
                    ? 'Снятие пакета переезда поставлено в очередь.'
                    : 'Установка пакета ' . $archive . ' поставлена в очередь.',
            ]],
        ]);
    }

    /** @return array<string,mixed> */
    public static function markRunning(): array
    {
        $state = self::read();
        $state['status'] = self::STATUS_RUNNING;
        $state['started_at'] = time();
        $state['heartbeat'] = time();
        self::write($state);

        return $state;
    }

    public static function step(string $text, string $level = 'ok'): void
    {
        $state = self::read();
        $state['heartbeat'] = time();
        $state['log'][] = ['at' => time(), 'level' => $level, 'text' => $text];
        if (count($state['log']) > self::LOG_LIMIT) {
            $state['log'] = array_slice($state['log'], -self::LOG_LIMIT);
        }
        self::write($state);
    }

    public static function finish(string $status, string $error = '', string $result = ''): void
    {
        self::clearPassword();
        $state = self::read();
        $state['status'] = $status;
        $state['finished_at'] = time();
        $state['heartbeat'] = time();
        $state['error'] = $error;
        if ($result !== '') {
            $state['result'] = $result;
        }
        $state['log'][] = [
            'at' => time(),
            'level' => $status === self::STATUS_DONE ? 'ok' : 'fail',
            'text' => $status === self::STATUS_DONE ? 'Готово.' : ('Остановлено: ' . $error),
        ];
        self::write($state);
    }

    /** @param array<string,mixed>|null $state */
    public static function isBusy(?array $state = null): bool
    {
        $state = $state === null ? self::read() : self::normalize($state);

        return in_array($state['status'], [self::STATUS_QUEUED, self::STATUS_RUNNING], true) && !self::isStale($state);
    }

    /** @param array<string,mixed>|null $state */
    public static function isStale(?array $state = null): bool
    {
        $state = $state === null ? self::read() : self::normalize($state);
        if (!in_array($state['status'], [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return false;
        }

        return time() - $state['heartbeat'] > self::STALE_AFTER;
    }

    /**
     * Пароль для воркера. Ключом сервера шифруется, если он задан; без ключа
     * файл закрыт правами 0600 и живёт до начала задачи.
     */
    public static function storePassword(string $password): void
    {
        self::ensureDir();
        $stored = SecretBox::hasValidCurrentKey() ? SecretBox::encrypt($password, 'migration.password') : $password;
        $path = self::passwordPath();
        @unlink($path);
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Не удалось сохранить пароль для воркера.');
        }
        @chmod($path, 0600);
        $ok = fwrite($handle, $stored) === strlen($stored);
        fclose($handle);
        if (!$ok) {
            @unlink($path);
            throw new \RuntimeException('Не удалось сохранить пароль для воркера.');
        }
    }

    /** Пароль читается один раз: файл стирается сразу. */
    public static function takePassword(): ?string
    {
        $path = self::passwordPath();
        if (!is_file($path)) {
            return null;
        }
        $stored = (string) file_get_contents($path);
        self::clearPassword();
        if ($stored === '') {
            return null;
        }

        return SecretBox::isEncrypted($stored) ? SecretBox::decrypt($stored, 'migration.password') : $stored;
    }

    public static function clearPassword(): void
    {
        @unlink(self::passwordPath());
    }

    private static function ensureDir(): void
    {
        $dir = SiteMigration::dir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог ' . $dir . '.');
        }
    }

    /**
     * @param array<string,mixed> $data
     * @return array{task:string, status:string, requested_by:string, requested_at:int,
     *     started_at:int, finished_at:int, heartbeat:int, archive:string,
     *     options:array{from_url:string, to_url:string, plain_backup:bool},
     *     result:string, error:string, log:list<array{at:int,level:string,text:string}>}
     */
    private static function normalize(array $data): array
    {
        $log = [];
        foreach ((array) ($data['log'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $log[] = [
                'at' => (int) ($line['at'] ?? 0),
                'level' => ($line['level'] ?? 'ok') === 'fail' ? 'fail' : 'ok',
                'text' => (string) ($line['text'] ?? ''),
            ];
        }
        $status = (string) ($data['status'] ?? self::STATUS_IDLE);
        $known = [self::STATUS_IDLE, self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_DONE, self::STATUS_FAILED];
        $task = (string) ($data['task'] ?? '');
        $options = is_array($data['options'] ?? null) ? $data['options'] : [];

        return [
            'task' => in_array($task, [self::TASK_EXPORT, self::TASK_IMPORT], true) ? $task : '',
            'status' => in_array($status, $known, true) ? $status : self::STATUS_IDLE,
            'requested_by' => (string) ($data['requested_by'] ?? ''),
            'requested_at' => (int) ($data['requested_at'] ?? 0),
            'started_at' => (int) ($data['started_at'] ?? 0),
            'finished_at' => (int) ($data['finished_at'] ?? 0),
            'heartbeat' => (int) ($data['heartbeat'] ?? 0),
            'archive' => (string) ($data['archive'] ?? ''),
            'options' => [
                'from_url' => (string) ($options['from_url'] ?? ''),
                'to_url' => (string) ($options['to_url'] ?? ''),
                'plain_backup' => (bool) ($options['plain_backup'] ?? false),
            ],
            'result' => (string) ($data['result'] ?? ''),
            'error' => (string) ($data['error'] ?? ''),
            'log' => $log,
        ];
    }
}
