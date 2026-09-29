<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;
use PDO;

/**
 * Переезд сайта на другой хостинг: пакет со старого сервера и его установка на
 * новом.
 *
 * Резервная копия уже умела унести базу и загрузки, но перенос ею держался на
 * памяти человека, и тихих отказов в нём три.
 *
 * 1. **Ключ шифрования.** Секреты в базе (коды второго фактора, токены
 *    Telegram и интеграций) зашифрованы ключом из конфигурации сервера
 *    (`SecretBox`). Архив ключа не несёт — и не должен: копия, которая
 *    расшифровывает сама себя, делает бесполезным шифрование. Новый сервер со
 *    своим ключом разворачивает базу без единой ошибки, а потом никто не
 *    может войти в панель: код приложения-аутентификатора не сходится.
 *    Поэтому пакет несёт **отпечаток** ключа, и установка сверяет его до того,
 *    как трогает базу.
 * 2. **Загрузка через форму.** Восстановление из панели упирается в лимит
 *    размера загрузки хостинга; архив сайта с фотографиями его превышает.
 *    Установка идёт из консоли по файлу, положенному файловым менеджером.
 * 3. **Хвосты.** Код нового сервера бывает новее базы (миграции), права
 *    файлов после распаковки чужие, эталон целостности описывает старый
 *    сервер, а в тексте материалов остаются абсолютные ссылки на старый адрес.
 *
 * Последовательность одна и останавливается на первой неудаче: проверка
 * окружения → сумма архива → пароль → совместимость ключа → замена базы и
 * загрузок (с откатом, как у восстановления из панели) → миграции → адрес →
 * права → эталон целостности → кэш.
 *
 * **Пакет шифруется паролем** (AES-256 внутри ZIP, открывается и 7-Zip): в
 * нём полный дамп — хеши паролей, заявки посетителей, закрытые загрузки, — и
 * файл, который едет между серверами, не должен читаться тем, кто его
 * перехватит. Открытым остаются только `manifest.txt` и манифест переезда:
 * по ним архив узнаётся и сверяется без пароля.
 *
 * **Каталоги свои** (`storage/migration/outgoing|incoming`), а не
 * `storage/backups`: там ротация ночного бэкапа удалила бы пакет, не
 * дождавшийся скачивания, а репетиция восстановления взяла бы свежий
 * зашифрованный пакет за обычную копию и подняла бы ложную тревогу.
 */
final class SiteMigration
{
    public const KIND = 'asdr.site-migration';
    public const MANIFEST = 'migration.json';
    public const CONFIRM_CODE = 'MIGRATE';

    /** Пароль короче — перебирается; пакет содержит хеши паролей и заявки. */
    public const PASSWORD_MIN = 12;

    public static function dir(): string
    {
        return APP_ROOT . '/storage/migration';
    }

    /** Сюда ложится пакет со старого сервера (консоль и панель). */
    public static function outgoingDir(): string
    {
        return self::dir() . '/outgoing';
    }

    /** Сюда владелец кладёт пакет на новом сервере — файловым менеджером. */
    public static function incomingDir(): string
    {
        return self::dir() . '/incoming';
    }

    /**
     * Архивы в каталоге, новые первыми.
     *
     * @return list<string> имена файлов
     */
    public static function archives(string $dir): array
    {
        $files = glob($dir . '/*.zip') ?: [];
        usort($files, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));

        return array_map('basename', $files);
    }

    /**
     * Путь к архиву по имени из списка. Имя приходит из формы, поэтому
     * принимается только голое имя файла из своего каталога.
     */
    public static function resolve(string $dir, string $name): ?string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.zip$/', $name) !== 1) {
            return null;
        }
        $path = $dir . '/' . $name;

        return is_file($path) ? $path : null;
    }

    public static function assertPassword(string $password): void
    {
        if (mb_strlen($password) < self::PASSWORD_MIN) {
            throw new \RuntimeException('Пароль архива — не короче ' . self::PASSWORD_MIN . ' знаков.');
        }
    }

    /** Зашифрован ли архив: смотрим на дамп, манифест открыт всегда. */
    public static function isEncrypted(string $archive): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Не удалось открыть архив: ' . basename($archive));
        }
        try {
            $stat = $zip->statName('database.sql');
        } finally {
            $zip->close();
        }

        return is_array($stat) && (int) ($stat['encryption_method'] ?? 0) !== \ZipArchive::EM_NONE;
    }

    /**
     * Подходит ли пароль. Читаются первые байты дампа — проверка мгновенная и
     * годится для панели; полную целостность подтверждает распаковка.
     */
    public static function passwordMatches(string $archive, string $password): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            $zip->setPassword($password);

            return is_string($zip->getFromName('database.sql', 16));
        } finally {
            $zip->close();
        }
    }

    /**
     * Сведения об архиве для панели — без подсчёта суммы: хеш многогигабайтного
     * файла веб-запрос бы не пережил, его считает воркер перед установкой.
     *
     * @return array{name:string, size:int, mtime:int, checksum:bool, encrypted:bool,
     *     manifest:?array<string,mixed>, key_ok:bool, key_message:string, error:string}
     */
    public static function inspect(string $archive): array
    {
        $info = [
            'name' => basename($archive),
            'size' => (int) filesize($archive),
            'mtime' => (int) filemtime($archive),
            'checksum' => Backup::storedChecksum($archive) !== null,
            'encrypted' => false,
            'manifest' => null,
            'key_ok' => false,
            'key_message' => '',
            'error' => '',
        ];
        try {
            $info['encrypted'] = self::isEncrypted($archive);
            $info['manifest'] = self::readManifest($archive);
            if ($info['manifest'] === null) {
                $info['key_message'] = 'Обычная резервная копия без манифеста переезда — ключ шифрования не сверить.';
            } else {
                $info['key_message'] = self::assertKeyCompatible($info['manifest']['key_fingerprint'] ?? null, self::keyFingerprint());
                $info['key_ok'] = true;
            }
        } catch (\RuntimeException $e) {
            if ($info['manifest'] !== null) {
                $info['key_message'] = $e->getMessage();
            } else {
                $info['error'] = $e->getMessage();
            }
        }

        return $info;
    }

    /**
     * Отпечаток ключа шифрования: 16 hex-знаков SHA-256 с солью назначения.
     * По нему видно «тот же ключ или нет», а сам ключ восстановить нельзя.
     */
    public static function keyFingerprint(?string $rawKey = null): ?string
    {
        $raw = trim($rawKey ?? (string) Config::get('crypto.encryption_key', ''));
        if ($raw === '') {
            return null;
        }
        $bytes = preg_match('/^[a-f0-9]{64}$/i', $raw) === 1 ? (string) hex2bin($raw) : (string) base64_decode($raw, true);
        if (strlen($bytes) !== 32) {
            return null;
        }

        return substr(hash('sha256', 'asdr-migration-key|' . $bytes), 0, 16);
    }

    /**
     * Пакет переезда: обычная резервная копия плюс манифест переезда.
     *
     * Режим обслуживания при снятии не включается: он попал бы в дамп, и новый
     * сервер открылся бы закрытым. Согласованность держит та же блокировка
     * записи, что у ночного бэкапа.
     *
     * @return array{archive: string, manifest: array<string, mixed>}
     */
    public static function export(?string $password = null): array
    {
        if ($password !== null) {
            self::assertPassword($password);
        }
        $dir = self::outgoingDir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог ' . $dir . '.');
        }
        // Манифест кладётся при сборке, а не дописывается потом: повторное
        // открытие переписывало бы весь архив — вдвое дольше и вдвое места.
        $manifest = self::manifest() + ['encrypted' => $password !== null];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $created = Backup::create(false, $password, [self::MANIFEST => $json]);
        $path = $dir . '/' . basename($created, '.zip') . '-migration.zip';
        $hash = Backup::storedChecksum($created);
        if ($hash === null || !rename($created, $path)) {
            @unlink($created);
            @unlink(Backup::checksumPath($created));
            throw new \RuntimeException('Не удалось перенести пакет в ' . $dir . '.');
        }
        @unlink(Backup::checksumPath($created));
        @chmod($path, 0640); // в пакете вся база — читать его незачем никому, кроме владельца
        // Сумма записана с именем файла (формат sha256sum -c) — имя сменилось.
        $line = $hash . '  ' . basename($path) . "\n";
        if (file_put_contents(Backup::checksumPath($path), $line, LOCK_EX) !== strlen($line)) {
            throw new \RuntimeException('Не удалось записать контрольную сумму пакета.');
        }

        return ['archive' => $path, 'manifest' => $manifest];
    }

    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        return [
            'kind' => self::KIND,
            'created_at' => date('c'),
            'app_url' => rtrim((string) Config::get('app.url', ''), '/'),
            'release' => Release::id(),
            'php' => PHP_VERSION,
            'key_fingerprint' => self::keyFingerprint(),
        ];
    }

    /**
     * Манифест переезда из архива; обычная резервная копия его не несёт.
     *
     * @return array<string, mixed>|null
     */
    public static function readManifest(string $archive): ?array
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Не удалось открыть архив: ' . basename($archive));
        }
        try {
            $raw = $zip->getFromName(self::MANIFEST, 65536);
        } finally {
            $zip->close();
        }
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || ($data['kind'] ?? null) !== self::KIND) {
            throw new \RuntimeException('Манифест переезда в архиве повреждён или чужого формата.');
        }

        return $data;
    }

    /**
     * Проверки до того, как тронута база. Каждая пройденная уходит в отчёт
     * сразу — при неудаче видно, что уже проверено; первая неудача —
     * исключение с объяснением, что сделать.
     *
     * @param callable(string): void $report
     */
    public static function preflight(string $archive, bool $allowPlainBackup, callable $report, ?string $password = null): void
    {
        $failed = array_filter(
            array_merge(EnvironmentCheck::requirements(), EnvironmentCheck::permissions()),
            static fn (array $check): bool => !$check['ok']
        );
        if ($failed !== []) {
            $first = array_values($failed)[0];
            throw new \RuntimeException('Окружение не готово: ' . $first['label'] . '. ' . $first['hint']);
        }
        $report('Окружение: PHP ' . PHP_VERSION . ', расширения и права на запись в порядке.');

        Database::pdo()->query('SELECT 1');
        $report('База данных нового сервера отвечает: «' . (string) Config::get('db.database', '') . '».');

        if (!is_file($archive)) {
            throw new \RuntimeException('Архив не найден: ' . $archive);
        }
        if (Backup::storedChecksum($archive) === null) {
            throw new \RuntimeException('Рядом с архивом нет файла .sha256 — целостность после переноса не проверить. '
                . 'Скопируйте его вместе с архивом.');
        }
        if (!Backup::verify($archive)) {
            throw new \RuntimeException('Контрольная сумма не совпала: архив повреждён при переносе. Скопируйте его заново '
                . '(в FTP — в двоичном режиме).');
        }
        $report('Контрольная сумма архива подтверждена.');

        if (self::isEncrypted($archive)) {
            if ($password === null || $password === '') {
                throw new \RuntimeException('Архив зашифрован — нужен пароль, заданный при снятии пакета.');
            }
            if (!Backup::encryptionSupported()) {
                throw new \RuntimeException('Архив зашифрован, а libzip этого сервера не умеет AES-256. '
                    . 'Попросите хостинг обновить расширение zip или распакуйте архив 7-Zip и упакуйте без пароля.');
            }
            if (!self::passwordMatches($archive, $password)) {
                throw new \RuntimeException('Неверный пароль архива.');
            }
            $report('Пароль архива подошёл.');
        }

        $manifest = self::readManifest($archive);
        if ($manifest === null) {
            if (!$allowPlainBackup) {
                throw new \RuntimeException('Это обычная резервная копия, а не пакет переезда: совместимость ключа '
                    . 'шифрования проверить нечем. Снимите пакет на старом сервере командой export или запустите '
                    . 'установку с --plain-backup, если ключ перенесён вручную.');
            }
            $report('ВНИМАНИЕ: обычная копия без манифеста — ключ шифрования не сверялся.');

            return;
        }

        $report(self::assertKeyCompatible($manifest['key_fingerprint'] ?? null, self::keyFingerprint()));
    }

    /**
     * Сверка ключа шифрования: пакет со старым ключом на сервере с другим
     * ключом развернулся бы без ошибок и оставил бы секреты нечитаемыми.
     */
    public static function assertKeyCompatible(mixed $archiveFingerprint, ?string $currentFingerprint): string
    {
        if (!is_string($archiveFingerprint) || $archiveFingerprint === '') {
            return 'На старом сервере ключ шифрования не был задан — сверять нечего.';
        }
        if ($currentFingerprint === null) {
            throw new \RuntimeException('На новом сервере не задан ключ шифрования (APP_ENCRYPTION_KEY), а данные '
                . 'старого им зашифрованы. Перенесите ключ со старого сервера: иначе коды входа и токены интеграций '
                . 'не расшифруются.');
        }
        if (!hash_equals($archiveFingerprint, $currentFingerprint)) {
            throw new \RuntimeException('Ключ шифрования нового сервера не совпадает со старым (отпечаток '
                . $currentFingerprint . ' против ' . $archiveFingerprint . '). Перенесите APP_ENCRYPTION_KEY со '
                . 'старого сервера до установки — иначе коды входа и токены интеграций не расшифруются.');
        }

        return 'Ключ шифрования совпадает со старым сервером (отпечаток ' . $currentFingerprint . ').';
    }

    /**
     * Нормализует адрес сайта для замены: схема, хост, без хвостового «/».
     */
    public static function normalizeUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ($parts['host'] ?? '') === ''
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('Адрес сайта должен быть вида https://example.uz: ' . $url);
        }

        return $url;
    }

    /**
     * Варианты записи адреса в базе: как есть и в JSON без флага
     * JSON_UNESCAPED_SLASHES («https:\/\/…»). Данные блоков лежат JSON'ом, и
     * одна замена оставила бы половину ссылок на старом адресе.
     *
     * @return array<string, string> старое => новое
     */
    public static function urlPairs(string $from, string $to): array
    {
        $from = self::normalizeUrl($from);
        $to = self::normalizeUrl($to);
        if ($from === $to) {
            return [];
        }

        return [
            $from => $to,
            str_replace('/', '\\/', $from) => str_replace('/', '\\/', $to),
        ];
    }

    /**
     * Меняет абсолютный адрес старого сайта на новый во всех текстовых
     * колонках базы. Возвращает число затронутых строк по таблицам.
     *
     * Граница адреса — следующий знак: «https://asdr.uz» не должен задеть
     * «https://asdr.uzbek.gov», поэтому заменяется адрес со «/» или кавычкой
     * после него, а голый адрес в конце строки — отдельно.
     *
     * @return array<string, int>
     */
    public static function replaceUrl(PDO $pdo, string $from, string $to, bool $dryRun): array
    {
        $pairs = self::urlPairs($from, $to);
        if ($pairs === []) {
            return [];
        }
        $schema = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $stmt = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ? AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json')
            ORDER BY TABLE_NAME, ORDINAL_POSITION");
        $stmt->execute([$schema]);
        $columns = [];
        foreach (Database::rows($stmt) as $row) {
            $columns[(string) $row['TABLE_NAME']][] = (string) $row['COLUMN_NAME'];
        }

        $report = [];
        foreach ($columns as $table => $names) {
            if (!self::isSafeIdentifier($table) || $table === 'migrations') {
                continue;
            }
            foreach ($names as $column) {
                if (!self::isSafeIdentifier($column)) {
                    continue;
                }
                foreach ($pairs as $old => $new) {
                    $report[$table] = ($report[$table] ?? 0)
                        + self::replaceInColumn($pdo, $table, $column, $old, $new, $dryRun);
                }
            }
        }

        return array_filter($report);
    }

    /**
     * Строка с адресом старого сайта, заменённым на новый, с той же проверкой
     * границы, что в базе (для тестов и предпросмотра).
     */
    public static function replaceInString(string $text, string $old, string $new): string
    {
        // Точка — граница только в конце предложения: «https://asdr.uz.gov» —
        // другой хост, а «…на https://asdr.uz.» — тот же адрес.
        return (string) preg_replace(
            '~' . preg_quote($old, '~') . '(?=[/"\'\\\\?#\s<>),;!&\]]|\.(?:[\s"\'<)]|$)|$)~u',
            $new,
            $text
        );
    }

    private static function replaceInColumn(PDO $pdo, string $table, string $column, string $old, string $new, bool $dryRun): int
    {
        $like = '%' . addcslashes($old, '%_\\') . '%';
        $select = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE `' . $column . '` LIKE ?');
        $select->execute([$like]);
        $rows = Database::rows($select);
        if ($rows === []) {
            return 0;
        }
        $key = self::primaryKey($pdo, $table);
        $changed = 0;
        foreach ($rows as $row) {
            $value = (string) ($row[$column] ?? '');
            $next = self::replaceInString($value, $old, $new);
            if ($next === $value) {
                continue;
            }
            $changed++;
            if ($dryRun) {
                continue;
            }
            if ($key === []) {
                // Без первичного ключа строку не адресовать — только прямое
                // сравнение значения, как и выбирали.
                $update = $pdo->prepare('UPDATE `' . $table . '` SET `' . $column . '` = ? WHERE `' . $column . '` = ?');
                $update->execute([$next, $value]);
                continue;
            }
            $where = implode(' AND ', array_map(static fn (string $k): string => '`' . $k . '` = ?', $key));
            $update = $pdo->prepare('UPDATE `' . $table . '` SET `' . $column . '` = ? WHERE ' . $where);
            $update->execute(array_merge([$next], array_map(static fn (string $k): mixed => $row[$k], $key)));
        }

        return $changed;
    }

    /** @return list<string> */
    private static function primaryKey(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query('SHOW KEYS FROM `' . $table . "` WHERE Key_name = 'PRIMARY'");
        $keys = [];
        foreach (Database::rows($stmt) as $row) {
            $name = (string) ($row['Column_name'] ?? '');
            if (self::isSafeIdentifier($name)) {
                $keys[] = $name;
            }
        }

        return $keys;
    }

    private static function isSafeIdentifier(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) === 1;
    }

    /**
     * Установка пакета на новый сервер. Заменяет базу и загрузки целиком.
     *
     * @param array{confirm?: string, from_url?: ?string, to_url?: ?string, plain_backup?: bool, password?: ?string} $options
     * @param callable(string): void $report
     */
    public static function import(string $archive, array $options, callable $report): void
    {
        $password = $options['password'] ?? null;
        self::preflight($archive, (bool) ($options['plain_backup'] ?? false), $report, $password);
        if (strtoupper(trim((string) ($options['confirm'] ?? ''))) !== self::CONFIRM_CODE) {
            throw new \RuntimeException('Установка заменяет базу и загрузки этого сервера целиком. Повторите с '
                . '--confirm=' . self::CONFIRM_CODE . ', когда будете готовы.');
        }

        $manifest = self::readManifest($archive) ?? [];
        $fromUrl = trim((string) ($options['from_url'] ?? ($manifest['app_url'] ?? '')));
        $toUrl = trim((string) ($options['to_url'] ?? Config::get('app.url', '')));

        $restored = BackupRestore::restoreLocal($archive, BackupRestore::CONFIRM_CODE, $password);
        $report(sprintf('База и загрузки заменены: таблиц %d, файлов %d. Страховочная копия прежнего состояния: %s.',
            $restored['restored_tables'], $restored['restored_files'], $restored['safety_backup']));

        // Хвост (миграции, ссылки) идёт при закрытом сайте: код бывает новее
        // развёрнутой базы, и до миграций страницы отдавали бы ошибку. Режим
        // после работы — тот, что приехал с базой старого сервера.
        $maintenanceBefore = Setting::get('maintenance_mode', '0');
        Setting::set('maintenance_mode', '1');
        try {
            self::finishImport($fromUrl, $toUrl, $report);
        } finally {
            Setting::set('maintenance_mode', $maintenanceBefore === '1' ? '1' : '0');
        }
    }

    /** @param callable(string): void $report */
    private static function finishImport(string $fromUrl, string $toUrl, callable $report): void
    {
        $pdo = Database::pdo();
        $applied = MigrationRunner::applyPending($pdo, APP_ROOT . '/database/migrations');
        $report($applied === [] ? 'Миграции: база уже соответствует коду.' : 'Применены миграции: ' . implode(', ', $applied) . '.');

        if ($fromUrl !== '' && $toUrl !== '' && self::urlPairs($fromUrl, $toUrl) !== []) {
            $changed = self::replaceUrl($pdo, $fromUrl, $toUrl, false);
            $report($changed === []
                ? 'Абсолютных ссылок на ' . $fromUrl . ' в материалах нет.'
                : 'Ссылки ' . $fromUrl . ' → ' . $toUrl . ': ' . self::describeCounts($changed) . '.');
        } else {
            $report('Адрес сайта не менялся — ссылки в материалах оставлены как есть.');
        }

        $perms = MediaPermissions::run((string) Config::get('paths.public_uploads'));
        $report(sprintf('Права загрузок: проверено %d, исправлено %d%s.', $perms['scanned'], $perms['fixed'],
            $perms['empty'] > 0 ? ', пустых файлов ' . $perms['empty'] . ' (загрузка на старом сервере не удалась)' : ''));

        $report('Эталон целостности пересобран: файлов ' . Integrity::writeBaseline() . '.');

        foreach (glob(APP_ROOT . '/storage/cache/page/*') ?: [] as $file) {
            is_file($file) && @unlink($file);
        }
        $report('Кэш страниц очищен.');
    }

    /** @param array<string, int> $counts */
    public static function describeCounts(array $counts): string
    {
        arsort($counts);
        $parts = [];
        foreach ($counts as $table => $n) {
            $parts[] = $table . ' — ' . $n;
        }

        return 'строк ' . array_sum($counts) . ' (' . implode(', ', $parts) . ')';
    }
}
