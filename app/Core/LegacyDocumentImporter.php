<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\FileEntry;
use App\Models\Language;
use App\Models\Page;
use App\Models\Redirect;

/**
 * Перенос раздела документов со старого сайта (WXR-экспорт плагина
 * Download Manager, тип записи `wpdmpro`).
 *
 * В экспорте только описания документов: название, рубрики и имя файла. Сами
 * файлы плагин держит в `wp-content/uploads/download-manager-files/`, и в XML
 * их нет. Поэтому владелец кладёт XML и эту папку в закрытый каталог
 * `storage/imports/documents/` (файловым менеджером хостинга — сотни
 * мегабайт через форму браузера не пройдут), а импорт сопоставляет записи с
 * файлами по имени.
 *
 * Результат — файлы в медиатеке и черновик страницы «Документы» на каждом
 * языке, где у каждой рубрики свой блок «Документы». Перенесённый файл
 * удаляется из папки приёма, поэтому она по ходу импорта пустеет. Что уже
 * перенесено, помнит файл состояния рядом, поэтому импорт можно прерывать и
 * запускать заново — второй копии файла не появится.
 */
final class LegacyDocumentImporter
{
    public const POST_TYPE = 'wpdmpro';

    /** Каталог приёма относительно корня сайта. */
    public const INBOX = 'storage/imports/documents';

    private const STATE_FILE = '.import-state.json';

    // Отчёты доноров бывают по 50 МБ; общий предел загрузки (20 МБ) задан
    // для формы браузера, а здесь файл уже лежит на диске сервера.
    private const MAX_FILE_BYTES = 256 * 1024 * 1024;

    /** @var array<string, string> */
    private const PAGE_TITLES = ['ru' => 'Документы', 'uz' => 'Hujjatlar', 'en' => 'Documents'];

    private const PAGE_SLUG = 'documents';

    // Больше шести строк плитками занимают пол-экрана, списком — строку.
    private const GRID_MAX_ITEMS = 6;

    /**
     * @return array{
     *   site:string,
     *   documents:list<array{id:int,slug:string,title:string,date:string,link:string,file:string,file_title:string,categories:array<string,string>}>,
     *   skipped:list<array{title:string,reason:string}>
     * }
     */
    public static function parse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $rss = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $empty = ['site' => '', 'documents' => [], 'skipped' => []];
        if ($rss === false || !isset($rss->channel)) {
            return $empty;
        }

        $ns = $rss->getNamespaces(true);
        $vendorHost = 'word' . 'press.org';
        $wp = $ns['wp'] ?? 'http://' . $vendorHost . '/export/1.2/';

        $site = '';
        foreach ($rss->channel->children($wp) as $name => $val) {
            if ($name === 'base_site_url') {
                $site = rtrim((string) $val, '/');
            }
        }

        $documents = [];
        $skipped = [];
        foreach ($rss->channel->item as $item) {
            $w = $item->children($wp);
            if ((string) $w->post_type !== self::POST_TYPE) {
                continue;
            }
            $title = self::text((string) $item->title);
            $meta = [];
            foreach ($w->postmeta as $pm) {
                $meta[(string) $pm->meta_key] = (string) $pm->meta_value;
            }

            if ((string) $w->status !== 'publish') {
                $skipped[] = ['title' => $title, 'reason' => 'черновик на старом сайте'];
                continue;
            }
            // Закрытые пакеты на старом сайте требовали входа или пароля:
            // выложить их открытой ссылкой значит раскрыть то, что прятали.
            $access = self::unserializeList($meta['__wpdm_access'] ?? '');
            if (($meta['__wpdm_password'] ?? '') !== '' || ($access !== [] && !in_array('guest', $access, true))) {
                $skipped[] = ['title' => $title, 'reason' => 'на старом сайте был закрыт (пароль или вход)'];
                continue;
            }

            $files = self::unserializeList($meta['__wpdm_files'] ?? '');
            $file = '';
            $fileKey = null;
            foreach ($files as $key => $value) {
                if (is_string($value) && trim($value) !== '') {
                    $file = trim($value);
                    $fileKey = $key;
                    break;
                }
            }
            if ($file === '') {
                $skipped[] = ['title' => $title, 'reason' => 'в записи нет файла'];
                continue;
            }
            if (count(array_filter($files, 'is_string')) > 1) {
                $skipped[] = ['title' => $title, 'reason' => 'в записи несколько файлов — перенесите вручную'];
                continue;
            }
            $info = self::unserializeList($meta['__wpdm_fileinfo'] ?? '');
            $fileTitle = is_array($info[$fileKey] ?? null) ? trim((string) ($info[$fileKey]['title'] ?? '')) : '';

            $categories = [];
            foreach ($item->category as $category) {
                if ((string) $category['domain'] !== 'wpdmcategory') {
                    continue;
                }
                $slug = (string) $category['nicename'];
                if ($slug !== '') {
                    $categories[$slug] = self::text((string) $category);
                }
            }

            $documents[] = [
                'id' => (int) $w->post_id,
                'slug' => (string) $w->post_name,
                'title' => $title,
                'date' => (string) $w->post_date,
                'link' => (string) $item->link,
                'file' => $file,
                'file_title' => $fileTitle,
                'categories' => $categories,
            ];
        }

        usort($documents, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        return ['site' => $site, 'documents' => $documents, 'skipped' => $skipped];
    }

    /**
     * Язык рубрики. Отдельного поля языка в экспорте нет (WPML его не
     * выгружает), зато рубрики на старом сайте заведены парами: «Davra
     * suhbati 1» и «Roundtable 1». Кириллица — русский, узбекские слова
     * рубрик — узбекский, остальное — английский.
     */
    public static function categoryLang(string $name): string
    {
        if (preg_match('/\p{Cyrillic}/u', $name) === 1) {
            return 'ru';
        }
        if (preg_match('/\b(davra\s+suhbati|ishchi\s+guruh\w*|rejalari|yil|yilgi|hisobot\w*|hujjat\w*)\b/iu', $name) === 1) {
            return 'uz';
        }

        return 'en';
    }

    /**
     * Сопоставление записи с файлом на диске. Плагин кладёт файл под своим
     * именем, а при совпадении имён приписывает спереди отметку времени
     * (`1736063738wpdm_…`), поэтому второй ключ поиска — имя без неё. Он
     * используется, только если однозначен: два разных файла с одинаковым
     * «чистым» именем — не повод выбрать любой.
     *
     * @param array<string, string> $index ключ → путь, из indexFiles()
     */
    public static function locate(string $file, array $index): ?string
    {
        $name = self::basename($file);
        foreach ([self::key($name), 'bare:' . self::key(self::stripStamp($name))] as $key) {
            $path = $index[$key] ?? '';
            if ($path !== '') {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> ключ имени → путь
     */
    public static function indexFiles(string $dir): array
    {
        $index = [];
        $bare = [];
        if (!is_dir($dir)) {
            return $index;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        $paths = [];
        foreach ($it as $entry) {
            /** @var \SplFileInfo $entry */
            if (!$entry->isFile()) {
                continue;
            }
            $name = $entry->getFilename();
            if ($name[0] === '.' || strtolower($entry->getExtension()) === 'xml') {
                continue;
            }
            $paths[] = $entry->getPathname();
        }
        // Порядок обхода задаёт файловая система: сортировка делает выбор при
        // совпадении имён одинаковым от запуска к запуску.
        sort($paths);
        foreach ($paths as $path) {
            $name = basename($path);
            $index[self::key($name)] ??= $path;
            $bare[self::key(self::stripStamp($name))][$path] = true;
        }
        foreach ($bare as $key => $found) {
            if (count($found) === 1) {
                $index['bare:' . $key] = (string) array_key_first($found);
            }
        }

        return $index;
    }

    /**
     * Один проход импорта. Работа ограничена временем, а не числом записей:
     * перенос файла дешёвый, но проверка его содержимого на большом PDF — нет.
     *
     * @return array{
     *   cursor:int,total:int,done:bool,dry:bool,
     *   planned:int,imported:int,reused:int,skipped:int,missing:int,failed:int,
     *   pages:int,blocks:int,redirects:int,
     *   missing_files:list<string>,errors:list<string>,notes:list<string>
     * }
     */
    public static function run(string $inbox, bool $dryRun, int $offset, float $budgetSeconds, ?int $userId): array
    {
        $started = microtime(true);
        $xmlPath = self::findXml($inbox);
        $data = self::parse((string) file_get_contents($xmlPath));
        $documents = $data['documents'];
        if ($documents === []) {
            throw new \RuntimeException('В XML нет документов Download Manager (записей типа ' . self::POST_TYPE . ').');
        }

        $state = self::loadState($inbox);
        $index = self::indexFiles($inbox);
        $total = count($documents);
        $result = [
            'cursor' => $offset, 'total' => $total, 'done' => false, 'dry' => $dryRun,
            'planned' => 0, 'imported' => 0, 'reused' => 0, 'skipped' => 0, 'missing' => 0, 'failed' => 0,
            'pages' => 0, 'blocks' => 0, 'redirects' => 0,
            'missing_files' => [], 'errors' => [], 'notes' => [],
        ];

        for ($i = $offset; $i < $total; $i++) {
            if ($i > $offset && microtime(true) - $started >= $budgetSeconds) {
                break;
            }
            $doc = $documents[$i];
            $result['cursor'] = $i + 1;
            $docKey = (string) $doc['id'];
            if (isset($state['docs'][$docKey])) {
                $result['skipped']++;
                continue;
            }

            $fileKey = self::key(self::basename($doc['file']));
            $url = $state['files'][$fileKey] ?? null;
            if ($url === null) {
                $path = self::locate($doc['file'], $index);
                if ($path === null) {
                    $result['missing']++;
                    $name = self::basename($doc['file']);
                    if (!in_array($name, $result['missing_files'], true)) {
                        $result['missing_files'][] = $name;
                    }
                    continue;
                }
                if ($dryRun) {
                    // Один файл у нескольких записей переносится один раз —
                    // и проверка обязана считать его так же.
                    $state['files'][$fileKey] = '';
                    $result['planned']++;
                    continue;
                }
                try {
                    $stored = self::store($path, self::originalName($doc, $path), $userId);
                } catch (\Throwable $e) {
                    $result['failed']++;
                    $result['errors'][] = self::basename($doc['file']) . ': ' . $e->getMessage();
                    continue;
                }
                $url = FileEntry::publicUrl($stored);
                $state['files'][$fileKey] = $url;
                $result['imported']++;
            } else {
                $result['reused']++;
                if ($dryRun) {
                    continue;
                }
            }

            $state['docs'][$docKey] = $url;
            if (self::redirect($doc['link'], $url)) {
                $result['redirects']++;
            }
            self::saveState($inbox, $state);
        }

        $result['done'] = $result['cursor'] >= $total;
        if ($result['done'] && !$dryRun) {
            [$result['pages'], $result['blocks'], $notes] = self::buildPages($documents, $state);
            $result['notes'] = $notes;
            self::saveState($inbox, $state);
            Cache::forgetPrefix('page:');
        }

        return $result;
    }

    /**
     * Сводка для экрана до запуска: что нашлось в каталоге и как ляжет по
     * страницам. Считается целиком за один запрос — это разбор XML и обход
     * каталога, без записи.
     *
     * @return array{xml:string,documents:int,files_on_disk:int,already:int,skipped:list<array{title:string,reason:string}>,categories:list<array{name:string,lang:string,count:int}>,langs:array<string,int>}
     */
    public static function summary(string $inbox): array
    {
        $xmlPath = self::findXml($inbox);
        $data = self::parse((string) file_get_contents($xmlPath));
        $state = self::loadState($inbox);
        $categories = [];
        $langs = [];
        foreach ($data['documents'] as $doc) {
            foreach (self::docGroups($doc) as $key => [$lang, $name]) {
                $categories[$key] ??= ['name' => $name, 'lang' => $lang, 'count' => 0];
                $categories[$key]['count']++;
            }
        }
        foreach ($categories as $category) {
            $langs[$category['lang']] = ($langs[$category['lang']] ?? 0) + $category['count'];
        }
        $categories = array_values($categories);
        usort($categories, static fn (array $a, array $b): int => [$a['lang'], $a['name']] <=> [$b['lang'], $b['name']]);
        $files = count(array_filter(array_keys(self::indexFiles($inbox)), static fn (string $k): bool => !str_starts_with($k, 'bare:')));

        return [
            'xml' => basename($xmlPath),
            'documents' => count($data['documents']),
            'files_on_disk' => $files,
            'already' => count($state['docs']),
            'skipped' => $data['skipped'],
            'categories' => $categories,
            'langs' => $langs,
        ];
    }

    public static function inbox(): string
    {
        return rtrim((string) APP_ROOT, '/') . '/' . self::INBOX;
    }

    public static function findXml(string $inbox): string
    {
        $found = glob(rtrim($inbox, '/') . '/*.xml') ?: [];
        if ($found === []) {
            throw new \RuntimeException('В каталоге ' . self::INBOX . ' нет XML-файла экспорта.');
        }
        if (count($found) > 1) {
            throw new \RuntimeException('В каталоге ' . self::INBOX . ' несколько XML-файлов — оставьте один.');
        }

        return $found[0];
    }

    /**
     * Язык страницы, на которую уходит рубрика: язык рубрики, если он
     * включён на сайте, иначе основной — документ не должен пропасть из-за
     * того, что языковой версии сайта нет.
     */
    private static function targetLang(string $lang): string
    {
        try {
            return Language::isActive($lang) ? $lang : Language::defaultCode();
        } catch (\Throwable) {
            return $lang;
        }
    }

    /**
     * @param list<array{id:int,slug:string,title:string,date:string,link:string,file:string,file_title:string,categories:array<string,string>}> $documents
     * @param array{docs:array<array-key,string>,files:array<array-key,string>,pages:array<array-key,int>} $state
     * @return array{0:int,1:int,2:list<string>}
     */
    private static function buildPages(array $documents, array &$state): array
    {
        // Рубрика → её документы, в порядке загрузки на старом сайте.
        $groups = [];
        foreach ($documents as $doc) {
            $url = $state['docs'][(string) $doc['id']] ?? null;
            if ($url === null) {
                continue;
            }
            foreach (self::docGroups($doc) as $key => [$lang, $name]) {
                $groups[$lang][$key]['name'] = $name;
                $groups[$lang][$key]['latest'] = max($groups[$lang][$key]['latest'] ?? '', $doc['date']);
                $groups[$lang][$key]['items'][] = [
                    'title' => $doc['title'],
                    'meta' => '',
                    'url' => $url,
                    'number' => '',
                    'date' => '',
                ];
            }
        }

        $pages = 0;
        $blocks = 0;
        $notes = [];
        $groupId = null;
        $slug = null;
        foreach ($state['pages'] as $pageId) {
            $existing = Page::findById((int) $pageId);
            if ($existing !== null) {
                $groupId = (int) ($existing['translation_group_id'] ?: $existing['id']);
                $slug = (string) $existing['slug'];
                break;
            }
        }
        ksort($groups);
        // Адрес у языковых версий общий, поэтому свободным он должен быть
        // сразу на всех языках, а не на каждом по отдельности.
        $slug ??= self::freeSlug(array_keys($groups));

        foreach ($groups as $lang => $categories) {
            // Свежие рубрики сверху: на старом сайте последним загружали
            // последний круглый стол, и открывать страницу 2023 годом незачем.
            uasort($categories, static fn (array $a, array $b): int => $b['latest'] <=> $a['latest']);

            $pageId = (int) ($state['pages'][$lang] ?? 0);
            $page = $pageId > 0 ? Page::findById($pageId) : null;
            if ($page !== null && (string) $page['status'] !== 'draft') {
                $notes[] = 'Страница /' . $page['slug'] . ' [' . $lang . '] уже опубликована — её блоки не пересобраны.';
                continue;
            }
            if ($page === null) {
                $pageId = Page::create([
                    'title' => self::PAGE_TITLES[$lang] ?? self::PAGE_TITLES['en'],
                    'slug' => $slug,
                    'status' => 'draft',
                    'lang' => $lang,
                ]);
                if ($groupId !== null) {
                    Database::pdo()->prepare('UPDATE pages SET translation_group_id = :g WHERE id = :id')
                        ->execute([':g' => $groupId, ':id' => $pageId]);
                } else {
                    $groupId = $pageId;
                }
                $state['pages'][$lang] = $pageId;
                $pages++;
            }

            // Черновик, созданный импортом, пересобирается целиком: повторный
            // запуск после докладки недостающих файлов должен дать полный
            // список, а не второй набор блоков рядом с первым.
            Database::pdo()->prepare('DELETE FROM blocks WHERE page_id = :id AND lang = :lang')
                ->execute([':id' => $pageId, ':lang' => $lang]);
            foreach ($categories as $category) {
                $items = $category['items'];
                \App\Models\Block::create($pageId, $lang, 'docs_list', $category['name'], [
                    'variant' => count($items) > self::GRID_MAX_ITEMS ? 'links' : 'grid',
                    'title' => $category['name'],
                    'all_text' => '',
                    'all_url' => '',
                    'columns' => 3,
                    'search_enabled' => true,
                    'items' => $items,
                ], '');
                $blocks++;
            }
        }
        return [$pages, $blocks, $notes];
    }

    /**
     * Блоки, в которые попадает документ. Рубрика — по названию, а не по
     * slug'у: на старом сайте встречаются двойники («Roundtable 4 – 13.12.2023»
     * заведена дважды), и два блока с одним заголовком на странице читались
     * бы как ошибка. Документ из двух таких рубрик попадает в блок один раз.
     *
     * @param array{categories:array<string,string>} $doc
     * @return array<string, array{0:string,1:string}>
     */
    private static function docGroups(array $doc): array
    {
        $groups = [];
        foreach ($doc['categories'] as $name) {
            $lang = self::targetLang(self::categoryLang($name));
            $groups[$lang . '|' . mb_strtolower($name, 'UTF-8')] = [$lang, $name];
        }

        return $groups;
    }

    /** @param list<string> $langs */
    private static function freeSlug(array $langs): string
    {
        $taken = static function (string $slug) use ($langs): bool {
            foreach ($langs as $lang) {
                if (Page::slugExists($slug, null, $lang)) {
                    return true;
                }
            }
            return false;
        };
        $slug = self::PAGE_SLUG;
        for ($n = 2; $taken($slug); $n++) {
            $slug = self::PAGE_SLUG . '-' . $n;
        }

        return $slug;
    }

    /**
     * Uploader переносит файл (`rename`) до записи в медиатеку, поэтому ему
     * отдаётся копия, а исходник удаляется только после успеха: иначе сбой
     * записи (база, проверка содержимого) уносил бы файл из папки приёма, и
     * повторный запуск его уже не нашёл бы. Копия лежит рядом — на том же
     * диске, и `rename` внутри Uploader остаётся мгновенным.
     *
     * @return array<string, mixed>
     */
    private static function store(string $path, string $originalName, ?int $userId): array
    {
        $tmp = dirname($path) . '/.import-' . bin2hex(random_bytes(6)) . '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!@copy($path, $tmp)) {
            throw new \RuntimeException('Не удалось прочитать файл в папке приёма.');
        }
        try {
            $stored = Uploader::storeFromPath($tmp, $originalName, (int) filesize($tmp), 'public', $userId, false, self::MAX_FILE_BYTES, null);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
        @unlink($path);

        return $stored;
    }

    /**
     * Старый адрес карточки (`/download/<slug>/`) ведёт на файл: по нему
     * документ знает поиск и ссылаются чужие сайты. Пока домен другой, запись
     * лежит без дела — и начинает работать, когда сайт встанет на прежний адрес.
     */
    private static function redirect(string $link, string $url): bool
    {
        $from = (string) parse_url($link, PHP_URL_PATH);
        if ($from === '' || trim($from, '/') === '') {
            return false;
        }

        return Redirect::create($from, $url, 301);
    }

    /**
     * Имя для медиатеки: подпись файла из плагина («WG 1 Energy Work
     * Plan_2023.pdf»), если у неё то же расширение, иначе имя на диске без
     * отметки времени. Из него Uploader строит адрес файла.
     *
     * @param array{file_title:string} $doc
     */
    private static function originalName(array $doc, string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $title = $doc['file_title'];
        if ($title !== '' && strtolower(pathinfo($title, PATHINFO_EXTENSION)) === $ext) {
            return $title;
        }

        return self::stripStamp(basename($path));
    }

    /**
     * @return array{docs:array<array-key,string>,files:array<array-key,string>,pages:array<array-key,int>}
     */
    private static function loadState(string $inbox): array
    {
        $empty = ['docs' => [], 'files' => [], 'pages' => []];
        $path = rtrim($inbox, '/') . '/' . self::STATE_FILE;
        if (!is_file($path)) {
            return $empty;
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            throw new \RuntimeException('Файл состояния импорта повреждён: ' . self::INBOX . '/' . self::STATE_FILE);
        }

        return [
            'docs' => array_map('strval', (array) ($raw['docs'] ?? [])),
            'files' => array_map('strval', (array) ($raw['files'] ?? [])),
            'pages' => array_map('intval', (array) ($raw['pages'] ?? [])),
        ];
    }

    /** @param array{docs:array<array-key,string>,files:array<array-key,string>,pages:array<array-key,int>} $state */
    private static function saveState(string $inbox, array $state): void
    {
        $path = rtrim($inbox, '/') . '/' . self::STATE_FILE;
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($path . '.tmp', $json) === false || !rename($path . '.tmp', $path)) {
            throw new \RuntimeException('Не удалось записать состояние импорта в ' . self::INBOX . '.');
        }
    }

    /** @return array<array-key, mixed> */
    private static function unserializeList(string $raw): array
    {
        if ($raw === '' || !str_starts_with($raw, 'a:')) {
            return [];
        }
        // Данные пришли из чужого файла: объекты не восстанавливаем никогда.
        $value = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($value) ? $value : [];
    }

    private static function text(string $raw): string
    {
        // Рубрики в экспорте экранированы дважды: «Energy &amp;amp; Mining».
        $text = html_entity_decode(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function basename(string $file): string
    {
        $path = (string) (parse_url($file, PHP_URL_PATH) ?? $file);

        return basename(rawurldecode(str_replace('\\', '/', $path)));
    }

    private static function stripStamp(string $name): string
    {
        return (string) preg_replace('/^\d{9,11}wpdm_/', '', $name);
    }

    /**
     * Ключ сравнения имён: без регистра и в одной форме Unicode. Архив,
     * собранный на Mac, хранит «ў» разложенной (NFD), а XML — составной
     * (NFC); побайтно это разные имена одного файла.
     */
    private static function key(string $name): string
    {
        if (class_exists(\Normalizer::class)) {
            $name = (string) \Normalizer::normalize($name, \Normalizer::FORM_C);
        }

        return mb_strtolower($name, 'UTF-8');
    }
}
