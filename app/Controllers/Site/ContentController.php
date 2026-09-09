<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\ContentLanguageNotice;
use App\Core\Fragment;
use App\Core\Locale;
use App\Core\View;
use App\Models\ContentEntry;
use App\Models\ContentType;

/**
 * Публичный фронтенд пользовательских типов контента (Документы, Вакансии,
 * Тендеры и любые типы, созданные в админке с флагом «публичный»). Общий
 * список и карточка записи; значения кастомных полей рендерятся по типу.
 */
final class ContentController
{
    /**
     * Список раздела по адресу `/catalog/<type>`.
     *
     * @param array<string, string> $params
     */
    public function index(array $params): void
    {
        $type = ContentType::findBySlug((string) ($params['type'] ?? ''));
        if ($type === null || (int) ($type['is_public'] ?? 0) !== 1) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        // У раздела без префикса адрес с `catalog/` остаётся рабочим, но
        // каноническим быть перестаёт: две страницы с одним содержимым — это
        // дубль, а старые ссылки и закладки терять нельзя.
        if ($this->redirectToCanonical($type)) {
            return;
        }

        $this->renderIndex($type);
    }

    /**
     * Список раздела, вынесенного в корень сайта (`/<type>`).
     *
     * Зовётся из маршрута страниц: `/{slug}` обслуживает страницы, и каталог
     * отвечает там только тогда, когда страницы с таким адресом нет.
     * Возвращает false, ничего не напечатав, если это не наш адрес, — тогда
     * маршрут страниц отдаёт свой 404.
     */
    public function tryRootIndex(string $slug): bool
    {
        $type = ContentType::findRootBySlug($slug);
        if ($type === null) {
            return false;
        }

        $this->renderIndex($type);

        return true;
    }

    /**
     * Запись раздела в корне сайта (`/<type>/<entry>`).
     *
     * @param array<string, string> $params
     */
    public function rootShow(array $params): void
    {
        $type = ContentType::findRootBySlug((string) ($params['type'] ?? ''));
        if ($type === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $this->renderEntry($type, (string) ($params['slug'] ?? ''));
    }

    /**
     * Постоянный редирект на канонический адрес раздела, если запрос пришёл на
     * прежний. Возвращает true, когда ответ уже отправлен.
     *
     * @param array<string, mixed> $type
     */
    private function redirectToCanonical(array $type, string $entrySlug = ''): bool
    {
        if (empty($type['root_url'])) {
            return false;
        }

        $path = $entrySlug === ''
            ? ContentType::path($type)
            : ContentType::entryPath($type, $entrySlug);
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        $target = Locale::url($path) . ($query !== '' ? '?' . $query : '');
        header('Location: ' . $target, true, 301);

        return true;
    }

    /** @param array<string, mixed> $type */
    private function renderIndex(array $type): void
    {
        // Стили каталога вынесены из общей темы: подключаем их только здесь.
        \App\Core\AssetCollector::requireThemePart('catalog');

        $lang = Locale::current();
        $fields = ContentType::fields((int) $type['id']);

        $perPage = 12;
        $q = trim((string) ($_GET['q'] ?? ''));
        $sort = in_array($_GET['sort'] ?? '', ['new', 'old', 'title'], true) ? (string) $_GET['sort'] : 'new';
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $total = ContentEntry::countTypePublic((int) $type['id'], $q, $lang);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        // Тип с дедлайном (вакансии/тендеры) — помечаем просроченные как «архив».
        $deadlineField = null;
        foreach ($fields as $f) {
            if ($f['field_type'] === 'date' && in_array($f['name'], ['deadline', 'end_date'], true)) {
                $deadlineField = $f['name'];
                break;
            }
        }

        $entries = [];
        foreach (ContentEntry::forTypePublic((int) $type['id'], $q, $sort, $perPage, $offset, $lang) as $entry) {
            $entry['data'] = json_decode((string) $entry['data'], true) ?: [];
            $entry['is_archived'] = false;
            if ($deadlineField !== null && !empty($entry['data'][$deadlineField])) {
                $ts = strtotime((string) $entry['data'][$deadlineField]);
                $entry['is_archived'] = $ts !== false && $ts < strtotime('today');
            }
            $entries[] = $entry;
        }

        $vars = [
            'type' => $type,
            'fields' => $fields,
            'entries' => $entries,
            'q' => $q,
            'sort' => $sort,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'hasDeadline' => $deadlineField !== null,
        ];

        // AJAX-фильтрация: тот же список, но без шапки и подвала.
        if (Fragment::wanted()) {
            Fragment::render('site/_catalog_list', $vars);
            return;
        }

        View::render('site/content_index', $vars);
    }

    /**
     * Запись раздела по адресу `/catalog/<type>/<entry>`.
     *
     * @param array<string, string> $params
     */
    public function show(array $params): void
    {
        $type = ContentType::findBySlug((string) ($params['type'] ?? ''));
        if ($type === null || (int) ($type['is_public'] ?? 0) !== 1) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        if ($this->redirectToCanonical($type, (string) ($params['slug'] ?? ''))) {
            return;
        }

        $this->renderEntry($type, (string) ($params['slug'] ?? ''));
    }

    /** @param array<string, mixed> $type */
    private function renderEntry(array $type, string $entrySlug): void
    {
        $entry = ContentEntry::findPublishedBySlug((int) $type['id'], $entrySlug);
        if ($entry === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        \App\Core\AssetCollector::requireThemePart('catalog');

        $lang = Locale::current();
        if ((int) ($type['has_translations'] ?? 0) === 1) {
            $available = ContentEntry::availableLangs((int) $entry['id']);
            $path = '/' . ContentType::entryPath($type, (string) $entry['slug']);
            if (ContentLanguageNotice::renderIfMissing($available, $path)) {
                return;
            }
            Locale::setContentLangs($available);
        }
        $entry = ContentEntry::localize(
            $entry,
            $lang,
            (int) ($type['has_translations'] ?? 0) === 1
        );

        View::render('site/content_show', [
            'type' => $type,
            'fields' => ContentType::fields((int) $type['id']),
            'entry' => $entry,
        ]);
    }

}
