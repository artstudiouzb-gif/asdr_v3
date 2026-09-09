<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Вывод адаптивных изображений через <picture>. Если файл — локальная загрузка,
 * для которой Uploader::optimizeImage сгенерировал WebP-разрешения
 * (name-800.webp / name-1600.webp / name.webp), они подставляются в srcset.
 * Внешние URL и файлы без вариантов отдаются обычным <img> (graceful fallback).
 *
 * Фокальная точка (в %) кладётся в object-position — при object-fit: cover
 * ключевой объект остаётся в кадре на любых пропорциях.
 */
final class Media
{
    /** @var array<string, array{width: int, height: int}|null> */
    private static array $dimensionCache = [];

    /** @var array<string, array{full: ?string, sized: array<int, string>}|null> */
    private static array $variantCache = [];

    /**
     * Автор снимка без служебного слова «Фото:» в начале: подпись выводится с
     * этим словом сама, а редактор естественно пишет его руками — и получалось
     * «Фото: Фото: пресс-служба».
     */
    public static function photoCredit(?string $credit): string
    {
        $credit = trim((string) $credit);
        if ($credit === '') {
            return '';
        }
        // Русское, узбекское и английское написание, с двоеточием или тире.
        $stripped = preg_replace('/^\s*(фото|foto|surat|photo)\s*[:—–-]\s*/iu', '', $credit);

        return trim((string) ($stripped ?? $credit));
    }

    public static function picture(
        ?string $url,
        string $alt = '',
        ?int $focalX = null,
        ?int $focalY = null,
        string $imgClass = '',
        bool $lazy = true,
        string $sizes = '(max-width: 800px) 100vw, 800px',
        bool $highPriority = false,
        string $pictureClass = '',
        ?string $mobileUrl = null
    ): string {
        $url = trim((string) $url);
        if ($url === '' || !UrlGuard::isSafeMedia($url)) {
            return '';
        }

        // Если локальный jpg/png отсутствует на диске, но одноимённый webp есть —
        // используем существующий webp, чтобы браузер не делал запрос к отсутствующему jpg.
        $url = self::resolveExistingMediaUrl($url);

        // Глобальный тумблер ленивой загрузки (Производительность). Отключение
        // делает все картинки «eager» (например, для специфичных лендингов).
        try {
            if (\App\Models\Setting::get('perf_lazy_load', '1') !== '1') {
                $lazy = false;
            }
        } catch (\Throwable) {
            // БД недоступна — оставляем как передано.
        }

        $altAttr = htmlspecialchars($alt, ENT_QUOTES);
        $classAttr = $imgClass !== '' ? ' class="' . htmlspecialchars($imgClass, ENT_QUOTES) . '"' : '';
        $loadingAttr = $lazy
            ? ' loading="lazy" decoding="async"'
            : ' loading="eager" decoding="async"';
        $priorityAttr = $highPriority ? ' fetchpriority="high"' : '';
        $pictureClassAttr = $pictureClass !== ''
            ? ' class="' . htmlspecialchars($pictureClass, ENT_QUOTES) . '"'
            : '';
        // Кадрирование задаётся двумя механизмами: классы `media-position--*`
        // (пресеты из админки) и inline-переменная `--media-object-position`
        // (автоподбор SmartCrop). В CSS правило классов идёт после правила
        // `[style*="--media-object-position"]`, поэтому при одинаковой
        // специфичности класс всегда выигрывает — inline-стиль в таком случае
        // не применяется и остаётся мёртвым атрибутом на каждой картинке.
        // Не выводим его, когда вызывающий код уже передал классы позиции.
        $hasPositionClasses = str_contains($imgClass, 'media-position--')
            || str_contains($pictureClass, 'media-position--');

        $styleAttr = '';
        if ($focalX !== null && $focalY !== null) {
            $fx = max(0, min(100, $focalX));
            $fy = max(0, min(100, $focalY));
            $styleAttr = ' style="--media-object-position:' . $fx . '% ' . $fy . '%"';
        } elseif (!$hasPositionClasses) {
            $focalPos = SmartCrop::focalPosition($url);
            $styleAttr = ' style="--media-object-position:' . htmlspecialchars($focalPos, ENT_QUOTES) . '"';
        }

        // Собственные размеры файла резервируют место под картинку: без них
        // содержимое прыгает, пока изображения грузятся (CLS).
        $dimensions = self::dimensions($url);
        $sizeAttr = $dimensions !== null
            ? ' width="' . $dimensions[0] . '" height="' . $dimensions[1] . '"'
            : '';

        $img = '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . $altAttr . '"'
            . $classAttr . $sizeAttr . $loadingAttr . $priorityAttr . $styleAttr . '>';

        // Отдельный кадр для телефона: источник с медиазапросом идёт первым,
        // и браузер скачивает ровно одну картинку — ту, что подойдёт экрану.
        $mobileSources = self::mobileSources($mobileUrl, $sizes);

        $variants = self::webpVariants($url);
        $srcset = $variants !== null ? self::webpSrcset($variants) : [];
        if ($srcset === []) {
            if ($mobileSources === '' && $pictureClass === '') {
                return $img;
            }
            if ($pictureClass === '') {
                $pictureClassAttr = ' class="media-picture"';
            }

            return '<picture' . $pictureClassAttr . '>' . $mobileSources . $img . '</picture>';
        }

        if ($pictureClass === '') {
            $pictureClassAttr = ' class="media-picture"';
        }

        return '<picture' . $pictureClassAttr . '>'
            . $mobileSources
            . '<source type="image/webp" srcset="' . implode(', ', $srcset) . '" '
            . 'sizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '">'
            . $img
            . '</picture>';
    }

    /**
     * Источники для узкого экрана: сначала WebP-набор мобильного файла, затем
     * он же как есть. Оба с медиазапросом, поэтому на десктопе не скачиваются.
     */
    private static function mobileSources(?string $mobileUrl, string $sizes): string
    {
        $mobileUrl = trim((string) $mobileUrl);
        if ($mobileUrl === '' || !UrlGuard::isSafeMedia($mobileUrl)) {
            return '';
        }

        // Граница та же, что у мобильной раскладки блоков: 720px.
        $media = '(max-width: 720px)';
        $sizesAttr = ' sizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '"';
        $html = '';

        $variants = self::webpVariants($mobileUrl);
        $srcset = $variants !== null ? self::webpSrcset($variants) : [];
        if ($srcset !== []) {
            $html .= '<source media="' . $media . '" type="image/webp" srcset="'
                . implode(', ', $srcset) . '"' . $sizesAttr . '>';
        }

        return $html . '<source media="' . $media . '" srcset="'
            . htmlspecialchars($mobileUrl, ENT_QUOTES) . '">';
    }

    /**
     * Ранний preload использует тот же responsive WebP-набор, что и <picture>,
     * поэтому браузер не скачивает полноразмерный JPEG параллельно с WebP.
     */
    /**
     * Значение для `background-image`: WebP-вариант с откатом на исходный файл.
     *
     * Фон секции не грузится лениво и не умеет `srcset`, поэтому единственный
     * способ не отдавать тяжёлый JPEG — `image-set()`: браузер выберет WebP,
     * а старый возьмёт исходник из второго источника.
     */
    public static function cssImageSet(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !UrlGuard::isSafeMedia($url)) {
            return '';
        }

        $escape = static fn (string $path): string => str_replace(['\\', '"'], ['\\\\', '\\"'], $path);
        // CDN-префикс дописывает Asset::rewriteMedia() уже по готовой странице,
        // как и для <img>: здесь остаётся обычный путь.
        $original = 'url("' . $escape($url) . '")';

        $variants = self::webpVariants($url);
        $webp = $variants['w1600'] ?? $variants['full'] ?? null;
        if ($webp === null) {
            return $original;
        }

        return 'image-set(url("' . $escape($webp) . '") type("image/webp"), '
            . $original . ' type("image/jpeg"))';
    }

    public static function preloadLink(string $url, string $sizes = '100vw'): string
    {
        $url = trim($url);
        if ($url === '' || !UrlGuard::isSafeMedia($url)) {
            return '';
        }

        $href = $url;
        $responsive = '';
        $variants = self::webpVariants($url);
        if ($variants !== null) {
            $srcset = self::webpSrcset($variants);
            if ($srcset !== []) {
                $href = $variants['full'] ?? $variants['w1600'] ?? $variants['w800'] ?? $url;
                $responsive = ' type="image/webp" imagesrcset="' . implode(', ', $srcset)
                    . '" imagesizes="' . htmlspecialchars($sizes, ENT_QUOTES) . '"';
            }
        }

        return '<link rel="preload" as="image" href="' . htmlspecialchars($href, ENT_QUOTES) . '"'
            . $responsive . ' fetchpriority="high">';
    }

    /**
     * @param array{full: ?string, sized: array<int, string>} $variants
     * @return list<string>
     */
    private static function webpSrcset(array $variants): array
    {
        $srcset = [];
        foreach ($variants['sized'] as $width => $variantUrl) {
            $srcset[] = htmlspecialchars($variantUrl, ENT_QUOTES) . ' '
                . self::imageWidth($variantUrl, $width) . 'w';
        }
        if ($variants['full'] !== null) {
            $srcset[] = htmlspecialchars($variants['full'], ENT_QUOTES) . ' '
                . self::imageWidth($variants['full'], 2000) . 'w';
        }

        return $srcset;
    }

    /**
     * Файл есть на диске И его отдаст веб-сервер.
     *
     * Одного `is_file()` мало: вариант, созданный самим PHP, получает права по
     * umask, и на части хостингов это 0600 — PHP файл видит, а веб-сервер
     * отдать не может. Для `<picture>` это не мелочь: источник выбирается по
     * типу, а не по тому, загрузился ли он, и на `<img>` браузер уже не
     * откатывается — вместо фотографии остаётся alt-текст. Проверяем бит
     * чтения «для всех»: не отданный вариант просто не попадает в srcset, и
     * посетитель видит исходный снимок вместо пустого места. Права при
     * создании ставит Uploader::writeWebp(), уже лежащие чинит
     * scripts/fix_upload_permissions.php.
     */
    /**
     * Гарантирует, что базовые каталоги загрузок имеют права 0755,
     * предотвращая 403 Forbidden от веб-сервера после Git pull / деплоя.
     */
    public static function ensureUploadDirPermissions(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        $dir = (string) Config::get('paths.public_uploads', '');
        if ($dir !== '' && is_dir($dir)) {
            $mode = fileperms($dir);
            if ($mode !== false && ($mode & 0005) !== 0005) {
                @chmod($dir, 0755);
            }
            $parent = dirname($dir);
            if (is_dir($parent)) {
                $pMode = fileperms($parent);
                if ($pMode !== false && ($pMode & 0005) !== 0005) {
                    @chmod($parent, 0755);
                }
            }
        }
    }

    private static function servable(string $path): bool
    {
        // Если родительский каталог закрыт umask (0700/0750), открываем его (0755):
        // веб-серверу нужен бит исполнения (+x) для входа в каталог и отдачи статики.
        $dir = dirname($path);
        if (is_dir($dir)) {
            $dirMode = fileperms($dir);
            if ($dirMode !== false && ($dirMode & 0005) !== 0005) {
                @chmod($dir, 0755);
            }
        }

        if (!is_file($path)) {
            return false;
        }
        $mode = fileperms($path);
        // Если у файла нет бита чтения «для всех» (0004), открываем на чтение:
        // созданные PHP файлы принадлежат владельцу веб-процесса, поэтому chmod() разрешён.
        if ($mode !== false && ($mode & 0004) === 0) {
            @chmod($path, 0644);
            $mode = fileperms($path);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return is_readable($path);
        }

        return $mode !== false && ($mode & 0004) !== 0;
    }

    /**
     * Если переданный URL локальной загрузки (jpg/png) физически отсутствует на диске,
     * но одноимённый webp-файл существует — перенаправляем на существующий webp,
     * чтобы браузер не делал запрос к отсутствующему jpg.
     */
    public static function resolveExistingMediaUrl(string $url): string
    {
        self::ensureUploadDirPermissions();

        $urlPrefix = rtrim((string) Config::get('paths.public_uploads_url', '/uploads/public'), '/');
        $diskBase = rtrim((string) Config::get('paths.public_uploads', ''), '/');
        if ($diskBase === '' || !str_starts_with($url, $urlPrefix . '/')) {
            return $url;
        }

        $clean = preg_replace('/[?#].*$/', '', $url) ?? $url;
        $relative = substr($clean, strlen($urlPrefix));
        $diskPath = $diskBase . $relative;

        // Файла на диске мало: 0600 веб-сервер не отдаст, а <img> на 403 не
        // откатывается ни на что — вместо фотографии остаётся alt-текст.
        // servable() чинит права, когда может; не смог — уходим на webp,
        // который отдать получится. Прежде проверялся только is_file(), то есть
        // самозалечивание досталось вариантам, а оригиналу — нет, хотя ломается
        // страница именно на нём: у варианта есть запасной путь, у оригинала
        // никакого.
        if (self::servable($diskPath)) {
            return $url;
        }

        $relNoExt = preg_replace('/\.[^.\/]+$/', '', $relative) ?? $relative;
        $webpPath = $diskBase . $relNoExt . '.webp';
        if (self::servable($webpPath)) {
            return $urlPrefix . $relNoExt . '.webp';
        }

        foreach (['.jpg', '.jpeg', '.png'] as $altExt) {
            $altPath = $diskBase . $relNoExt . $altExt;
            if (self::servable($altPath)) {
                return $urlPrefix . $relNoExt . $altExt;
            }
        }

        return $url;
    }

    /**
     * Ширины webp-вариантов, от мелкого к крупному. **Один список на проект.**
     *
     * Его читают все, кому нужен набор вариантов: генерация при загрузке,
     * подбор для srcset, пакетная достройка старых файлов и удаление вместе с
     * оригиналом. Прежде тот же набор был выписан литералами в четырёх местах,
     * а такой список расходится с первой же правкой: файл перестают удалять
     * или перестают ждать от пакетной обработки — и то и другое молча.
     *
     * 400px добавлен ради миниатюр: карточка медиатеки около 135px, кадр
     * галереи новости 110px, и даже с учётом удвоенной плотности экрана
     * 800px там втрое больше нужного.
     */
    public const VARIANT_WIDTHS = [400, 800, 1600];

    /**
     * Суффиксы всех файлов-вариантов, включая полноразмерный.
     *
     * @return list<string>
     */
    public static function variantSuffixes(): array
    {
        $suffixes = ['.webp'];
        foreach (self::VARIANT_WIDTHS as $width) {
            $suffixes[] = '-' . $width . '.webp';
        }

        return $suffixes;
    }

    /**
     * Адрес мелкого варианта для миниатюры — или исходный, если вариантов нет.
     *
     * Нужен там, где картинка показывается размером в сотню пикселей, а
     * разметку строит не `picture()`: сетка медиатеки в админке и её же список
     * в окне выбора, который рисует JS. Раньше обе тянули оригинал целиком —
     * при 2560px по умолчанию это сотни килобайт на каждую карточку, а карточек
     * на странице до трёхсот.
     *
     * Отдаётся именно самый мелкий вариант, а не srcset: у потребителей нет
     * `<picture>`, а `display: contents` объявлен во фронтовом CSS, которого в
     * админке нет.
     */
    public static function thumbUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $variants = self::webpVariants($url);
        if ($variants === null) {
            // Вариантов нет — отдаём оригинал, но тем же путём, что и picture():
            // непригодный к отдаче файл подменяется webp, если он есть.
            return self::resolveExistingMediaUrl($url);
        }
        // Самый мелкий из существующих: ключи отсортированы по ширине.
        foreach ($variants['sized'] as $variantUrl) {
            return $variantUrl;
        }

        return $variants['full'] ?? $url;
    }

    /**
     * Возвращает пути к существующим WebP-вариантам для локального URL загрузки,
     * либо null, если это не локальная загрузка / вариантов нет.
     *
     * @return array{full: ?string, sized: array<int, string>}|null
     */
    private static function webpVariants(string $url): ?array
    {
        if (array_key_exists($url, self::$variantCache)) {
            return self::$variantCache[$url];
        }

        $urlPrefix = rtrim((string) Config::get('paths.public_uploads_url', '/uploads/public'), '/');
        $diskBase = rtrim((string) Config::get('paths.public_uploads', ''), '/');
        if ($diskBase === '' || !str_starts_with($url, $urlPrefix . '/')) {
            return self::$variantCache[$url] = null;
        }

        // Отбрасываем querystring/anchor.
        $clean = preg_replace('/[?#].*$/', '', $url) ?? $url;
        $relative = substr($clean, strlen($urlPrefix));           // /abc.jpg
        $relNoExt = preg_replace('/\.[^.\/]+$/', '', $relative) ?? $relative;

        $result = ['full' => null, 'sized' => []];
        $found = false;
        foreach (self::VARIANT_WIDTHS as $width) {
            $rel = $relNoExt . '-' . $width . '.webp';
            if (self::servable($diskBase . $rel)) {
                $result['sized'][$width] = $urlPrefix . $rel;
                $found = true;
            }
        }
        $fullRel = $relNoExt . '.webp';
        if (self::servable($diskBase . $fullRel)) {
            $result['full'] = $urlPrefix . $fullRel;
            $found = true;
        }
        ksort($result['sized']);

        return self::$variantCache[$url] = ($found ? $result : null);
    }

    /** @return array{width: int, height: int}|null */
    private static function imageDimensions(string $url): ?array
    {
        if (array_key_exists($url, self::$dimensionCache)) {
            return self::$dimensionCache[$url];
        }

        $path = self::localUploadPath($url);
        if ($path === null) {
            return self::$dimensionCache[$url] = null;
        }

        $size = @getimagesize($path);
        if ($size === false || (int) $size[0] < 1 || (int) $size[1] < 1) {
            return self::$dimensionCache[$url] = null;
        }

        return self::$dimensionCache[$url] = ['width' => (int) $size[0], 'height' => (int) $size[1]];
    }

    private static function imageWidth(string $url, int $fallback): int
    {
        return self::imageDimensions($url)['width'] ?? $fallback;
    }

    private static function localUploadPath(string $url): ?string
    {
        $urlPrefix = rtrim((string) Config::get('paths.public_uploads_url', '/uploads/public'), '/');
        $diskBase = rtrim((string) Config::get('paths.public_uploads', ''), '/');
        $clean = preg_replace('/[?#].*$/', '', $url) ?? $url;

        if ($diskBase === '' || !str_starts_with($clean, $urlPrefix . '/')) {
            return null;
        }

        $relative = substr($clean, strlen($urlPrefix));
        if ($relative === '' || str_contains(str_replace('\\', '/', $relative), '/../')) {
            return null;
        }

        $path = $diskBase . $relative;

        return is_file($path) ? $path : null;
    }

    /** Ниже этого размера картинка считается заглушкой, а не изображением. */
    private const MIN_DIMENSION = 8;

    /**
     * Собственные размеры локального изображения с кэшем по пути и mtime.
     * Внешние адреса (CDN, чужие домены) пропускаем: файла рядом нет.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function dimensions(string $url): ?array
    {
        if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }
        $path = \dirname(__DIR__, 2) . '/public' . explode('?', $url)[0];
        if (!is_file($path)) {
            return null;
        }
        // SVG размеров в пикселях может не иметь — атрибуты только навредят.
        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
            return null;
        }

        $key = 'media:dim:' . md5($path . ':' . (string) filemtime($path));
        $cached = Cache::remember($key, static function () use ($path): array {
            $size = @getimagesize($path);
            if (!is_array($size)) {
                return [];
            }
            [$width, $height] = [(int) $size[0], (int) $size[1]];

            // Пиксельные заглушки (1×1 и прочая мелочь) размерами не
            // описываются: их ставят как плейсхолдер, и атрибуты только
            // навязали бы CSS-компоненту бессмысленные пропорции.
            return $width >= self::MIN_DIMENSION && $height >= self::MIN_DIMENSION
                ? [$width, $height]
                : [];
        }, 86400);

        return is_array($cached) && count($cached) === 2 ? [(int) $cached[0], (int) $cached[1]] : null;
    }
}
