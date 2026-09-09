<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\FileEntry;
use RuntimeException;

/**
 * Общая точка загрузки файлов для файлового менеджера и полей изображений
 * (обложка проекта, фото сотрудника, логотип и т.п.). Работает только с
 * $_FILES-подобным массивом, генерирует случайное имя на диске и проверяет
 * реальный MIME-тип содержимого (а не расширение из имени файла).
 */
final class Uploader
{
    private const MAX_SIZE_BYTES = 20 * 1024 * 1024; // 20 МБ

    // Disk Space Guard: минимум свободного места, ниже которого загрузки
    // блокируются (максимум из 500 МБ и 5% от общего объёма диска).
    private const MIN_FREE_BYTES = 500 * 1024 * 1024;
    private const MIN_FREE_RATIO = 0.05;

    private const ALLOWED = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip',
        'txt' => 'text/plain',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'mp4' => 'video/mp4',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'aac' => 'audio/aac',
        'm4a' => 'audio/mp4',
    ];

    /**
     * Код страницы (стили и скрипты) загружается отдельным разрешением: это
     * та же поверхность доверия, что и поля «произвольный CSS/JS» — только
     * супер-администратор. В общий ALLOWED эти расширения класть нельзя:
     * через Uploader идут и файлы из публичных форм сайта.
     */
    private const CODE_ALLOWED = [
        'css' => 'text/css',
        'js' => 'text/javascript',
    ];

    // Типы, для которых finfo часто возвращает application/octet-stream —
    // строгую проверку MIME по расширению для них не применяем.
    private const LENIENT_MIME = ['svg', 'woff2', 'woff', 'mp3', 'ogg', 'wav', 'aac', 'm4a', 'css', 'js'];

    /**
     * @param array $fileInput один элемент $_FILES, например $_FILES['file']
     * @return array файл-запись из таблицы files (с id)
     */
    public static function store(
        array $fileInput,
        string $accessType,
        ?int $uploadedBy,
        ?array $allowedExtensions = null,
        bool $allowCode = false
    ): array
    {
        if (($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Ошибка загрузки файла.');
        }

        if (!is_uploaded_file($fileInput['tmp_name'])) {
            throw new RuntimeException('Некорректный файл.');
        }

        if ((int) $fileInput['size'] > self::MAX_SIZE_BYTES) {
            throw new RuntimeException('Файл превышает максимальный размер 20 МБ.');
        }

        return self::storeFromPath(
            (string) $fileInput['tmp_name'],
            (string) $fileInput['name'],
            (int) $fileInput['size'],
            $accessType,
            $uploadedBy,
            true,
            self::MAX_SIZE_BYTES,
            $allowedExtensions,
            $allowCode
        );
    }

    /**
     * Сохраняет файл из произвольного пути (используется загрузкой из $_FILES
     * и сборкой чанковой загрузки). Общая валидация, санитизация, оптимизация
     * и запись в БД.
     *
     * @param bool $isUploadedFile true если источник — временный файл PHP-загрузки
     * @return array файл-запись из таблицы files
     */
    public static function storeFromPath(
        string $sourcePath,
        string $originalName,
        int $size,
        string $accessType,
        ?int $uploadedBy,
        bool $isUploadedFile,
        int $maxSize = self::MAX_SIZE_BYTES,
        ?array $allowedExtensions = null,
        bool $allowCode = false
    ): array {
        if (!is_file($sourcePath)) {
            throw new RuntimeException('Файл не найден.');
        }
        if ($size > $maxSize) {
            throw new RuntimeException('Файл превышает максимальный размер ' . (int) round($maxSize / (1024 * 1024)) . ' МБ.');
        }

        $allowed = $allowCode ? self::ALLOWED + self::CODE_ALLOWED : self::ALLOWED;
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset($allowed[$extension])) {
            throw new RuntimeException('Недопустимый тип файла: .' . $extension);
        }
        if ($allowedExtensions !== null
            && !in_array($extension, array_map('strtolower', $allowedExtensions), true)) {
            throw new RuntimeException('Этот тип файла не разрешён для данного поля.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) $finfo->file($sourcePath);
        $expectedMime = $allowed[$extension];
        if (!self::mimeMatches($extension, $detectedMime, $sourcePath)) {
            throw new RuntimeException('Содержимое файла не соответствует расширению.');
        }

        self::assertDiskSpace($accessType);

        $accessType = $accessType === 'protected' ? 'protected' : 'public';
        $storedName = self::generateSeoFileName($originalName, $extension);

        $basePath = $accessType === 'protected'
            ? Config::get('paths.protected_uploads')
            : Config::get('paths.public_uploads');

        if (!is_dir($basePath) && !mkdir($basePath, 0755, true) && !is_dir($basePath)) {
            throw new RuntimeException('Не удалось создать директорию для загрузки.');
        }

        $destination = rtrim((string) $basePath, '/') . '/' . $storedName;

        $moved = $isUploadedFile
            ? move_uploaded_file($sourcePath, $destination)
            : rename($sourcePath, $destination);
        if (!$moved) {
            throw new RuntimeException('Не удалось сохранить файл на диске.');
        }

        // Права выставляем явно. Файл из формы приходит через
        // move_uploaded_file() и получает права по umask, а вот файл, собранный
        // самим PHP (чанковая загрузка, импорт с YouTube или из WP), наследует
        // права временного файла — tempnam() создаёт его с 0600. На хостинге
        // статику отдаёт веб-сервер, и такой файл он прочитать не может:
        // картинка молча выходит битой и в админке, и на сайте.
        @chmod($destination, 0644);

        if ($extension === 'svg') {
            self::sanitizeSvgFile($destination);
        }
        if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            self::optimizeImage($destination);
        }

        $accessToken = $accessType === 'protected' ? bin2hex(random_bytes(32)) : null;

        $id = FileEntry::create([
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'mime_type' => $expectedMime,
            'size' => $size,
            'access_type' => $accessType,
            'access_token' => $accessToken,
            'uploaded_by' => $uploadedBy,
        ]);

        // Запись читаем обратно, а не собираем из тех же значений: у неё есть
        // поля, которые проставляет база (id, created_at).
        //
        // Отсутствие записи здесь — не «файла нет», а сбой вставки, и молча
        // отдавать `null` нельзя: метод объявлен возвращающим массив, поэтому
        // в файле со strict_types вызывающий получил бы TypeError вместо
        // объяснения. Случай не выдуманный: `FileEntry::create()` отдаёт
        // `lastInsertId()`, а он обнуляется, если между вставкой и чтением
        // успел пройти чужой запрос (см. в заметках грабли с
        // `bustPageCache()`), — тогда сюда приходит `findById(0)`.
        //
        // Файл с диска не убираем: если вставка на самом деле прошла, а
        // потерялся только идентификатор, удаление оставило бы строку в базе
        // без файла — это хуже, чем неучтённый файл, который подберёт чистка
        // медиатеки.
        $file = FileEntry::findById($id);
        if ($file === null) {
            throw new RuntimeException(
                'Файл сохранён на диске, но запись о нём не создана (id=' . $id . '). Повторите загрузку.'
            );
        }

        return $file;
    }

    /**
     * Генерирует читаемое ЧПУ-имя файла с транслитерацией и уникальным коротких суффиксом.
     * Например: «Заседание Кабинета 2026.jpg» -> «zasedanie-kabineta-2026-4a8f9b.jpg»
     */
    public static function generateSeoFileName(string $originalName, string $extension): string
    {
        $filename = pathinfo($originalName, PATHINFO_FILENAME);
        $slug = Slug::make($filename);

        if (mb_strlen($slug) > 45) {
            $slug = mb_substr($slug, 0, 45);
            $slug = rtrim($slug, '-');
        }

        if ($slug === '' || $slug === 'item') {
            $slug = 'file';
        }

        $hash = bin2hex(random_bytes(3));

        return $slug . '-' . $hash . '.' . strtolower($extension);
    }

    /**
     * Проверяет фактический MIME. Для MP4 разные версии libmagic возвращают
     * video/mp4, application/mp4 или octet-stream, поэтому дополнительно
     * проверяем сигнатуру ISO Base Media (box `ftyp`).
     */
    public static function mimeMatches(string $extension, string $detectedMime, string $path): bool
    {
        $extension = strtolower($extension);
        if (!isset(self::ALLOWED[$extension]) && !isset(self::CODE_ALLOWED[$extension])) {
            return false;
        }
        if (in_array($extension, self::LENIENT_MIME, true)) {
            return true;
        }
        if ($extension !== 'mp4') {
            return $detectedMime === self::ALLOWED[$extension];
        }
        if (!in_array($detectedMime, ['video/mp4', 'application/mp4', 'application/octet-stream'], true)) {
            return false;
        }

        $header = @file_get_contents($path, false, null, 0, 12);

        return is_string($header) && strlen($header) >= 12 && substr($header, 4, 4) === 'ftyp';
    }

    /**
     * Санитизация SVG: удаляет опасные элементы и атрибуты (скрипты,
     * обработчики событий, javascript:-URI, внешние ссылки). Работает через
     * DOMDocument (нативное расширение, без сторонних зависимостей).
     */
    /**
     * Disk Space Guard: блокирует загрузку при нехватке свободного места на
     * диске, защищая сервер и БД от падения. Порог — максимум из 500 МБ и 5%
     * от общего объёма диска.
     */
    public static function assertDiskSpace(string $accessType): void
    {
        $dir = $accessType === 'protected'
            ? (string) Config::get('paths.protected_uploads')
            : (string) Config::get('paths.public_uploads');

        // Берём существующий родительский каталог для замера.
        $probe = $dir;
        while ($probe !== '' && !is_dir($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }

        $free = @disk_free_space($probe ?: '/');
        $total = @disk_total_space($probe ?: '/');
        if ($free === false || $total === false) {
            return; // не удалось определить — не блокируем
        }

        $threshold = max(self::MIN_FREE_BYTES, (int) ($total * self::MIN_FREE_RATIO));
        if ($free < $threshold) {
            Logger::warning(
                sprintf('Disk Space Guard: свободно %d Б при пороге %d Б — загрузка заблокирована.', $free, $threshold),
                ['free' => $free, 'threshold' => $threshold]
            );
            throw new RuntimeException('Недостаточно свободного места на сервере. Загрузка временно недоступна — освободите место.');
        }
    }

    /**
     * Создаёт WebP-версию и адаптивные разрешения (desktop/mobile) для
     * растрового изображения через нативный GD. Best-effort: любые ошибки
     * не мешают основной загрузке. Побочные файлы кладутся рядом:
     *   name.jpg -> name.webp (полный размер),
     *              name-1600.webp (desktop, если исходник шире),
     *              name-800.webp  (mobile).
     */
    /** Жёсткий предел разрешения для декодирования GD (~40 Мп). */
    private const MAX_IMAGE_PIXELS = 40_000_000;

    /** Запас памяти, который оставляем процессу после декодирования (байт). */
    private const MEMORY_HEADROOM = 16 * 1024 * 1024;

    /**
     * Переводит ini-значение (например «256M», «1G», «-1») в байты.
     * Чистая функция для Memory Limit Guard.
     */
    public static function parseIniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX; // без лимита
        }
        $unit = strtolower(substr($value, -1));
        $num = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $value,
        };
    }

    /**
     * Оценка памяти на декодирование картинки в GD: ~5 байт на пиксель
     * (truecolor RGBA + накладные расходы структуры).
     */
    public static function estimateImageMemory(int $width, int $height): int
    {
        return $width * $height * 5;
    }

    /**
     * Memory Limit Guard (getimagesize до декодирования): можно ли безопасно
     * создать GD-ресурс такого разрешения при заданных лимите и текущем
     * потреблении. Отдельные аргументы — для тестируемости без ini_set.
     */
    public static function imageDecodable(int $width, int $height, ?int $memoryLimit = null, ?int $memoryUsed = null): bool
    {
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_IMAGE_PIXELS) {
            return false;
        }
        $limit = $memoryLimit ?? self::parseIniBytes((string) ini_get('memory_limit'));
        $used = $memoryUsed ?? memory_get_usage(true);

        return self::estimateImageMemory($width, $height) <= $limit - $used - self::MEMORY_HEADROOM;
    }

    /** Качество WebP из настроек производительности (40–95, по умолчанию 82). */
    private static function webpQuality(): int
    {
        try {
            $q = (int) \App\Models\Setting::get('perf_webp_quality', '82');
        } catch (\Throwable) {
            $q = 82;
        }

        return max(40, min(95, $q));
    }

    /** Макс. ширина хранимого оригинала (px) из настроек, по умолчанию 2560. */
    /**
     * Ширина зажата снизу и сверху, но объявление `int` этого не сообщало, и
     * вызывающий (`imagecreatetruecolor`) обязан был предполагать ноль. Тип в
     * описании — это контракт, а не украшение: он же снимает находку анализатора.
     *
     * @return int<1200, 4000>
     */
    public static function originalMaxWidth(): int
    {
        try {
            $w = (int) \App\Models\Setting::get('perf_image_max_width', '2560');
        } catch (\Throwable) {
            $w = 2560;
        }

        return max(1200, min(4000, $w));
    }

    /** $downscaleOriginal=false используется для безопасной миграции старых файлов. */
    public static function optimizeImage(string $path, bool $downscaleOriginal = true): void
    {
        if (!extension_loaded('gd')) {
            return;
        }

        try {
            $info = @getimagesize($path);
            if ($info === false) {
                return;
            }
            [$width, $height] = $info;
            $type = $info[2];

            // Memory Limit Guard: фото экстремального разрешения (например,
            // 8000×6000 прямо с камеры) не декодируем — иначе Fatal Error
            // уронит запрос. Оригинал остаётся как есть, без WebP-версий.
            if (!self::imageDecodable((int) $width, (int) $height)) {
                Logger::warning('Image optimize пропущен: разрешение слишком велико для памяти', [
                    'file' => basename($path),
                    'width' => $width,
                    'height' => $height,
                    'memory_limit' => (string) ini_get('memory_limit'),
                ]);
                return;
            }

            $src = match ($type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
                IMAGETYPE_PNG => @imagecreatefrompng($path),
                default => null,
            };
            if (!$src) {
                return;
            }

            if ($type === IMAGETYPE_PNG) {
                imagepalettetotruecolor($src);
                imagealphablending($src, true);
                imagesavealpha($src, true);
            }

            $base = preg_replace('/\.[^.]+$/', '', $path) ?? $path;
            $quality = self::webpQuality();

            // WebP полного размера.
            self::writeWebp($src, $base . '.webp', $quality);

            // Адаптивные размеры — из общего списка (Media::VARIANT_WIDTHS),
            // от крупного к мелкому. Свой список здесь разъехался бы с тем, что
            // ждут подбор srcset, пакетная достройка и удаление вариантов.
            foreach (array_reverse(\App\Core\Media::VARIANT_WIDTHS) as $targetWidth) {
                if ($width <= $targetWidth) {
                    continue;
                }
                // Не меньше пикселя: у очень узкой картинки (например,
                // 2000×1) пересчёт давал ноль, и создание холста падало.
                $targetHeight = max(1, (int) round($height * ($targetWidth / $width)));
                $resized = imagecreatetruecolor($targetWidth, $targetHeight);
                if ($type === IMAGETYPE_PNG) {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                }
                imagecopyresampled($resized, $src, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
                self::writeWebp($resized, $base . '-' . $targetWidth . '.webp', $quality);
            }

            // Даунскейл самого оригинала, если он неоправданно большой (фото
            // прямо с телефона — 4000–6000px). WebP-варианты уже созданы выше;
            // уменьшённый оригинал экономит вес страниц и место на диске, при
            // этом работает и там, где картинка задаётся через CSS background.
            $maxW = self::originalMaxWidth();
            if ($downscaleOriginal && $width > $maxW) {
                $newH = max(1, (int) round($height * ($maxW / $width)));
                $down = imagecreatetruecolor($maxW, $newH);
                if ($type === IMAGETYPE_PNG) {
                    imagealphablending($down, false);
                    imagesavealpha($down, true);
                }
                imagecopyresampled($down, $src, 0, 0, 0, 0, $maxW, $newH, $width, $height);
                if ($type === IMAGETYPE_JPEG) {
                    @imagejpeg($down, $path, 85);
                } else {
                    @imagepng($down, $path);
                }
            }
        } catch (\Throwable $e) {
            Logger::error('Image optimize failed: ' . $e->getMessage());
        }
    }

    /**
     * Записывает webp-вариант и **сразу открывает его на чтение**.
     *
     * Файл, созданный самим PHP, получает права по umask — на части хостингов
     * это 0600, и веб-сервер такой файл не отдаёт. Оригинал снимка при этом
     * читается (ему `storeFromPath()` ставит 0644), а вариант — нет, и картинка
     * пропадает целиком: `<picture>` выбирает `<source type="image/webp"` по
     * типу, а НЕ по тому, загрузился ли он, и на `<img>` уже не откатывается —
     * посетитель видит alt-текст вместо фотографии. Проверено в Chromium:
     * недоступный источник даёт naturalWidth = 0.
     *
     * @param \GdImage $image
     */
    private static function writeWebp(\GdImage $image, string $file, int $quality): void
    {
        if (!@imagewebp($image, $file, $quality)) {
            return;
        }
        @chmod($file, 0644);
    }

    public static function sanitizeSvgFile(string $path): void
    {
        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return;
        }

        $sanitized = self::sanitizeSvgString($content);
        file_put_contents($path, $sanitized);
    }

    /** Число без хвостовых нулей: 24.0 → «24», 24.5 → «24.5». */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    public static function sanitizeSvgString(string $svg): string
    {
        // Быстрая грубая очистка на случай, если DOM не сможет разобрать документ.
        $svg = preg_replace('#<\?php.*?\?>#is', '', $svg) ?? $svg;
        // BOM перед <?xml валит loadXML, и файл целиком заменялся заглушкой —
        // редактор видел «сохранено», а на сайте пусто. Редакторы дописывают
        // его молча, поэтому снимаем сами.
        $svg = preg_replace('/^\xEF\xBB\xBF/', '', $svg) ?? $svg;

        // DOCTYPE вырезаем целиком: объявления <!ENTITY ... SYSTEM "file://...">
        // — это XXE (чтение локальных файлов сервера), а рекурсивные внутренние
        // сущности — DoS («billion laughs»). Легитимному SVG DOCTYPE не нужен.
        // Сущности не разворачиваем (LIBXML_NOENT недопустим — он включает
        // подстановку внешних сущностей); осиротевшие &ref; после удаления
        // DOCTYPE валят loadXML, и документ заменяется безопасной заглушкой.
        $svg = preg_replace('/<!DOCTYPE\s[^>[]*(\[[^\]]*\])?[^>]*>/is', '', $svg) ?? $svg;

        $dangerousTags = ['script', 'foreignObject', 'iframe', 'embed', 'object', 'audio', 'video', 'animate', 'set', 'handler', 'listener'];

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // LIBXML_NONET запрещает сетевые обращения при разборе.
        $loaded = $dom->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $dom->documentElement === null) {
            // Не удалось разобрать как XML — возвращаем безопасную заглушку.
            return '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';
        }

        $xpath = new \DOMXPath($dom);

        // 1. Удаляем опасные элементы.
        foreach ($dangerousTags as $tag) {
            $nodes = iterator_to_array($dom->getElementsByTagName($tag));
            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // 2. Чистим атрибуты у всех элементов.
        $allNodes = $xpath->query('//*');
        if ($allNodes !== false) {
            foreach ($allNodes as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $attrs = iterator_to_array($node->attributes ?? []);
                foreach ($attrs as $attr) {
                    $name = strtolower($attr->nodeName);
                    $value = $attr->nodeValue ?? '';

                    // Обработчики событий on* — удаляем целиком.
                    if (str_starts_with($name, 'on')) {
                        $node->removeAttribute($attr->nodeName);
                        continue;
                    }

                    // href / xlink:href / src с javascript:/data:text/html — удаляем.
                    if (in_array($name, ['href', 'xlink:href', 'src'], true)) {
                        $normalized = strtolower(preg_replace('/\s+/', '', $value) ?? '');
                        if (str_starts_with($normalized, 'javascript:')
                            || str_starts_with($normalized, 'data:text/html')
                            || str_starts_with($normalized, 'vbscript:')) {
                            $node->removeAttribute($attr->nodeName);
                            continue;
                        }
                    }

                    // Любое значение атрибута с javascript: внутри (например, style).
                    if (stripos($value, 'javascript:') !== false || stripos($value, 'vbscript:') !== false) {
                        $node->removeAttribute($attr->nodeName);
                    }
                }
            }
        }

        // 3. Гарантируем viewBox. Без него SVG нечем масштабировать: как
        // CSS-маска (фирменная эмблема, --gov-emblem) он не отрисуется вовсе,
        // а в <img> с заданной высотой схлопывается по ширине.
        $root = $dom->documentElement;
        if (strtolower($root->localName) === 'svg' && trim($root->getAttribute('viewBox')) === '') {
            $width = Emblem::length($root->getAttribute('width'));
            $height = Emblem::length($root->getAttribute('height'));
            if ($width !== null && $height !== null && $width > 0.0 && $height > 0.0) {
                $root->setAttribute('viewBox', '0 0 ' . self::number($width) . ' ' . self::number($height));
            }
        }

        $result = $dom->saveXML($dom->documentElement);

        return $result !== false ? $result : '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';
    }
}
