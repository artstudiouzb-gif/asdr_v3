<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Backup;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\LegacyWxrImporter;
use App\Core\View;

final class NewsImportController
{
    private const MAX_BYTES = 268435456; // 256 MiB
    private const MAX_CHUNKS = 4096;
    // Пакет ограничен временем, а не числом новостей: стоимость записи
    // непредсказуема (у одной три картинки со старого сайта, у другой ни
    // одной), поэтому фиксированные «3 новости» то не выбирали и секунды, то
    // не укладывались в шлюзовой таймаут. 15 секунд — с запасом под обычные
    // 60 у nginx, включая разбор XML в начале запроса.
    private const BATCH_SECONDS = 15;
    // Верхняя граница на случай очень быстрых записей: ответ пакета не должен
    // раздуваться без предела.
    private const BATCH_MAX = 40;
    private const SESSION_KEY = 'admin_news_wxr_imports';

    public function index(): void
    {
        Auth::requireSuperAdmin();
        self::cleanupExpired();
        View::render('admin/news/import', [
            'maxUploadMb' => (int) floor(self::MAX_BYTES / 1048576),
            'batchSeconds' => self::BATCH_SECONDS,
        ]);
    }

    public function uploadChunk(): never
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();
        self::cleanupExpired();

        $index = max(0, (int) ($_POST['index'] ?? 0));
        $total = max(1, (int) ($_POST['total'] ?? 1));
        $fileSize = max(0, (int) ($_POST['file_size'] ?? 0));
        $originalName = trim((string) ($_POST['file_name'] ?? ''));
        $token = strtolower(trim((string) ($_POST['token'] ?? '')));

        if ($total > self::MAX_CHUNKS || $index >= $total) {
            $this->json(['ok' => false, 'error' => 'Некорректная последовательность частей файла.'], 422);
        }
        if ($fileSize < 1 || $fileSize > self::MAX_BYTES) {
            $this->json(['ok' => false, 'error' => 'XML-файл слишком большой. Максимум ' . (int) floor(self::MAX_BYTES / 1048576) . ' МБ.'], 413);
        }
        if ($originalName === '' || strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xml') {
            $this->json(['ok' => false, 'error' => 'Разрешены только WXR/XML-файлы с расширением .xml.'], 422);
        }
        if (empty($_FILES['chunk']) || (int) ($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->json(['ok' => false, 'error' => 'Не удалось получить часть загружаемого файла.'], 400);
        }

        if ($index === 0) {
            $token = bin2hex(random_bytes(16));
            self::forgetToken($token);
            $_SESSION[self::SESSION_KEY][$token] = [
                'name' => mb_substr($originalName, 0, 240),
                'size' => $fileSize,
                'total' => $total,
                'created_at' => time(),
                'inspected' => false,
                'report' => null,
                'backup' => null,
            ];
        } elseif (!self::validToken($token) || !isset($_SESSION[self::SESSION_KEY][$token])) {
            $this->json(['ok' => false, 'error' => 'Сессия загрузки истекла. Выберите XML ещё раз.'], 409);
        }

        $state = $_SESSION[self::SESSION_KEY][$token];
        if ((int) ($state['total'] ?? 0) !== $total || (int) ($state['size'] ?? 0) !== $fileSize) {
            $this->json(['ok' => false, 'error' => 'Параметры файла изменились во время загрузки.'], 409);
        }

        $dir = self::stagingDir();
        $part = $dir . '/' . $token . '.part.' . str_pad((string) $index, 5, '0', STR_PAD_LEFT);
        $tmp = (string) ($_FILES['chunk']['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp) || !move_uploaded_file($tmp, $part)) {
            $this->json(['ok' => false, 'error' => 'Не удалось сохранить часть XML на сервере.'], 500);
        }

        if ($index + 1 < $total) {
            $this->json(['ok' => true, 'token' => $token, 'complete' => false, 'received' => $index + 1, 'total' => $total]);
        }

        $final = $dir . '/' . $token . '.xml';
        $out = @fopen($final . '.tmp', 'wb');
        if ($out === false) {
            $this->json(['ok' => false, 'error' => 'Не удалось собрать XML-файл.'], 500);
        }
        $written = 0;
        try {
            for ($i = 0; $i < $total; $i++) {
                $piece = $dir . '/' . $token . '.part.' . str_pad((string) $i, 5, '0', STR_PAD_LEFT);
                if (!is_file($piece)) {
                    throw new \RuntimeException('Не хватает части #' . ($i + 1) . '. Повторите загрузку.');
                }
                $in = fopen($piece, 'rb');
                if ($in === false) {
                    throw new \RuntimeException('Не удалось прочитать часть XML.');
                }
                $copied = stream_copy_to_stream($in, $out);
                fclose($in);
                if ($copied === false) {
                    throw new \RuntimeException('Не удалось собрать XML-файл.');
                }
                $written += $copied;
                @unlink($piece);
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($final . '.tmp');
            self::forgetToken($token);
            $this->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
        fclose($out);

        // Верхнюю границу держит проверка $fileSize на входе метода, а
        // сравнение с ним ниже переносит её на $written: третья проверка не
        // срабатывала никогда.
        if ($written !== $fileSize || $written < 16) {
            @unlink($final . '.tmp');
            self::forgetToken($token);
            $this->json(['ok' => false, 'error' => 'Размер собранного XML не совпадает с исходным файлом.'], 422);
        }
        if (!@rename($final . '.tmp', $final)) {
            @unlink($final . '.tmp');
            self::forgetToken($token);
            $this->json(['ok' => false, 'error' => 'Не удалось завершить загрузку XML.'], 500);
        }

        $_SESSION[self::SESSION_KEY][$token]['sha256'] = hash_file('sha256', $final) ?: '';
        $this->json(['ok' => true, 'token' => $token, 'complete' => true, 'name' => $state['name'], 'size' => $written]);
    }

    public function inspect(): never
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $token = strtolower(trim((string) ($_POST['token'] ?? '')));
        $state = self::state($token);
        $path = self::xmlPath($token);
        if ($state === null || !is_file($path)) {
            $this->json(['ok' => false, 'error' => 'Загруженный XML не найден. Загрузите файл повторно.'], 404);
        }
        if (!hash_equals((string) ($state['sha256'] ?? ''), hash_file('sha256', $path) ?: '')) {
            self::forgetToken($token);
            $this->json(['ok' => false, 'error' => 'Контрольная сумма XML изменилась. Файл удалён из очереди импорта.'], 409);
        }

        $xml = file_get_contents($path);
        if ($xml === false) {
            $this->json(['ok' => false, 'error' => 'Не удалось прочитать XML.'], 500);
        }
        $data = LegacyWxrImporter::parse($xml);
        if ($data['posts'] === []) {
            $this->json(['ok' => false, 'error' => 'В XML не найдено записей типа post. Проверьте WXR-экспорт.'], 422);
        }

        $langs = ['uz' => 'uz', 'ru' => 'ru', 'en' => 'en'];
        $plan = LegacyWxrImporter::plan($data, $langs);
        $dry = LegacyWxrImporter::importFile($path, [
            'status' => 'draft',
            'langs' => $langs,
            'dryRun' => true,
            'authorId' => Auth::id(),
        ]);

        $languageCounts = ['uz' => 0, 'ru' => 0, 'en' => 0, 'other' => 0];
        $galleryPosts = 0;
        $galleryIds = [];
        $published = 0;
        foreach ($data['posts'] as $post) {
            if ((string) ($post['status'] ?? '') !== 'publish') {
                continue;
            }
            $published++;
            $lang = (string) ($post['lang'] ?? '');
            if (isset($languageCounts[$lang])) {
                $languageCounts[$lang]++;
            } else {
                $languageCounts['other']++;
            }
            $ids = (array) ($post['gallery_ids'] ?? []);
            if ($ids !== []) {
                $galleryPosts++;
                foreach ($ids as $id) {
                    $galleryIds[(int) $id] = true;
                }
            }
        }

        $translations = 0;
        foreach ($plan['groups'] as $group) {
            $translations += max(0, count($group) - 1);
        }

        $report = [
            'site' => (string) ($data['site'] ?? ''),
            'published' => $published,
            'drafts' => (int) $plan['drafts'],
            'comments' => (int) $plan['comments'],
            'planned_news' => count($plan['groups']),
            'translations' => $translations,
            'unresolved' => count($plan['unresolved']),
            'to_import' => (int) ($dry['imported'] ?? 0),
            'already_exists' => (int) ($dry['skipped'] ?? 0),
            'attachments' => count($data['attachments']),
            'gallery_posts' => $galleryPosts,
            'gallery_images' => count($galleryIds),
            'languages' => $languageCounts,
            'errors' => array_values((array) ($dry['errors'] ?? [])),
        ];

        $_SESSION[self::SESSION_KEY][$token]['inspected'] = true;
        $_SESSION[self::SESSION_KEY][$token]['report'] = $report;
        $_SESSION[self::SESSION_KEY][$token]['inspected_at'] = time();

        $this->json(['ok' => true, 'token' => $token, 'report' => $report]);
    }

    /**
     * Резервная копия снимается своим запросом, а не первым пакетом импорта.
     * Дамп базы на боевом сайте занимает минуты, и внутри пакета он съедал
     * весь шлюзовой таймаут ещё до первой перенесённой новости: импорт падал
     * на самом первом «Начать импорт» и выглядел неработающим целиком.
     * Отдельным шагом отказ копии остаётся отказом копии — импорт после него
     * можно продолжить сознательно.
     */
    public function backup(): never
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $token = strtolower(trim((string) ($_POST['token'] ?? '')));
        $state = self::state($token);
        if ($state === null || empty($state['inspected'])) {
            $this->json(['ok' => false, 'error' => 'Сначала загрузите и проверьте XML.'], 409);
        }
        if (!empty($state['started_at'])) {
            // Импорт уже идёт: копия «в середине» не описывает ни состояние до,
            // ни состояние после, и снимать её незачем.
            $this->json(['ok' => true, 'backup' => (string) ($state['backup'] ?? ''), 'skipped' => true]);
        }

        try {
            $path = Backup::create();
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'error' => 'Не удалось создать резервную копию: ' . $e->getMessage()], 500);
        }

        $_SESSION[self::SESSION_KEY][$token]['backup'] = basename($path);
        $this->json(['ok' => true, 'backup' => basename($path)]);
    }

    public function importBatch(): never
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $token = strtolower(trim((string) ($_POST['token'] ?? '')));
        $state = self::state($token);
        $path = self::xmlPath($token);
        if ($state === null || empty($state['inspected']) || !is_file($path)) {
            $this->json(['ok' => false, 'error' => 'Сначала загрузите и проверьте XML.'], 409);
        }
        $report = is_array($state['report'] ?? null) ? $state['report'] : [];
        if ((int) ($report['unresolved'] ?? 1) !== 0 || !empty($report['errors'])) {
            $this->json(['ok' => false, 'error' => 'Импорт заблокирован: в отчёте проверки есть ошибки или неопределённые переводы.'], 409);
        }
        if (!hash_equals((string) ($state['sha256'] ?? ''), hash_file('sha256', $path) ?: '')) {
            self::forgetToken($token);
            $this->json(['ok' => false, 'error' => 'XML изменился после проверки. Загрузите его снова.'], 409);
        }

        $status = (string) ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft';
        $first = empty($state['started_at']);
        if ($first) {
            $_SESSION[self::SESSION_KEY][$token]['started_at'] = time();
            $_SESSION[self::SESSION_KEY][$token]['status'] = $status;
            $_SESSION[self::SESSION_KEY][$token]['new_imported'] = 0;
            $_SESSION[self::SESSION_KEY][$token]['translations'] = 0;
            $_SESSION[self::SESSION_KEY][$token]['images'] = 0;
            $_SESSION[self::SESSION_KEY][$token]['redirects'] = 0;
            $_SESSION[self::SESSION_KEY][$token]['cursor'] = 0;
        } elseif (($state['status'] ?? 'draft') !== $status) {
            $this->json(['ok' => false, 'error' => 'Нельзя менять статус публикации после начала импорта.'], 409);
        }

        // Смещение берётся из $first, а не из прочитанного выше состояния:
        // на первом пакете курсор только что обнулён в сессии, и локальная
        // копия о сбросе не знает.
        $cursor = $first ? 0 : (int) ($state['cursor'] ?? 0);

        $result = LegacyWxrImporter::importFile($path, [
            'status' => $status,
            'langs' => ['uz' => 'uz', 'ru' => 'ru', 'en' => 'en'],
            'limit' => self::BATCH_MAX,
            'timeBudget' => (float) self::BATCH_SECONDS,
            'offset' => $cursor,
            'dryRun' => false,
            'authorId' => Auth::id(),
        ]);
        $_SESSION[self::SESSION_KEY][$token]['cursor'] = (int) ($result['cursor'] ?? 0);

        $_SESSION[self::SESSION_KEY][$token]['new_imported'] += (int) ($result['imported'] ?? 0);
        $_SESSION[self::SESSION_KEY][$token]['translations'] += (int) ($result['translations'] ?? 0);
        $_SESSION[self::SESSION_KEY][$token]['images'] += (int) ($result['images'] ?? 0);
        $_SESSION[self::SESSION_KEY][$token]['redirects'] += (int) ($result['redirects'] ?? 0);
        $state = self::state($token) ?? [];

        // Завершение считается по курсору, а не по «пакет ничего не перенёс»:
        // пакет, целиком ушедший на уже существующие записи, переносит ноль и
        // при этом до конца плана не дошёл — прежнее условие обрывало бы
        // импорт на первой же такой пачке.
        $done = (int) ($result['cursor'] ?? 0) >= (int) ($result['total'] ?? 0);
        if ($done) {
            Cache::forgetPrefix('page:');
            $_SESSION[self::SESSION_KEY][$token]['finished_at'] = time();
            @unlink($path);
        }

        $this->json([
            'ok' => true,
            'done' => $done,
            'batch' => $result,
            'summary' => [
                'imported' => (int) ($state['new_imported'] ?? 0),
                'translations' => (int) ($state['translations'] ?? 0),
                'images' => (int) ($state['images'] ?? 0),
                'redirects' => (int) ($state['redirects'] ?? 0),
                'target' => (int) ($report['to_import'] ?? 0),
                'backup' => (string) ($state['backup'] ?? ''),
            ],
        ]);
    }

    public function discard(): never
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();
        $token = strtolower(trim((string) ($_POST['token'] ?? '')));
        if (self::validToken($token)) {
            self::forgetToken($token);
        }
        $this->json(['ok' => true]);
    }

    private static function stagingDir(): string
    {
        $dir = dirname(__DIR__, 3) . '/storage/imports/wxr';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать закрытую папку storage/imports/wxr.');
        }
        return $dir;
    }

    private static function xmlPath(string $token): string
    {
        return self::stagingDir() . '/' . $token . '.xml';
    }

    /** @return array<string,mixed>|null */
    private static function state(string $token): ?array
    {
        if (!self::validToken($token)) {
            return null;
        }
        $state = $_SESSION[self::SESSION_KEY][$token] ?? null;
        return is_array($state) ? $state : null;
    }

    private static function validToken(string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $token);
    }

    private static function forgetToken(string $token): void
    {
        if (!self::validToken($token)) {
            return;
        }
        $dir = dirname(__DIR__, 3) . '/storage/imports/wxr';
        foreach (glob($dir . '/' . $token . '.*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        unset($_SESSION[self::SESSION_KEY][$token]);
    }

    private static function cleanupExpired(): void
    {
        $items = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($items)) {
            $_SESSION[self::SESSION_KEY] = [];
            return;
        }
        $now = time();
        foreach ($items as $token => $state) {
            $created = is_array($state) ? (int) ($state['created_at'] ?? 0) : 0;
            if ($created < $now - 21600) {
                self::forgetToken((string) $token);
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
