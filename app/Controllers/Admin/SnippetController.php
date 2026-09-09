<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\PageTemplateFile;
use App\Core\View;
use App\Models\BlockSnippet;
use App\Models\Language;
use App\Models\Page;

/**
 * Шаблоны целых страниц: снимок всех блоков языкового стека (включая дочерние
 * блоки колонок и активность) и применение к любой странице — добавлением к
 * текущим блокам или полной заменой. При вставке блоки получают новые id
 * (custom_css скоупится по #block-{id} — конфликтов не возникает).
 */
final class SnippetController
{
    private function resolveLang(): string
    {
        $lang = (string) ($_POST['block_lang'] ?? Language::defaultCode());
        return Language::isActive($lang) ? $lang : Language::defaultCode();
    }

    /** @param array<string, string> $params */
    public function save(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $pageId = (int) $params['id'];
        $page = Page::findById($pageId);
        if (!$page) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $lang = $this->resolveLang();
        $name = trim((string) ($_POST['snippet_name'] ?? ''));
        if ($name === '') {
            Flash::error('Укажите название шаблона.');
            $this->back($pageId, $lang);
        }

        // Снимок целой страницы: верхний уровень + дочерние блоки колонок.
        $blocks = BlockSnippet::captureFromPage($pageId, $lang);

        if ($blocks === []) {
            Flash::error('На этом языке нет блоков для сохранения.');
            $this->back($pageId, $lang);
        }

        BlockSnippet::create($name, $blocks);
        Flash::success('Шаблон «' . $name . '» сохранён (' . count($blocks) . ' блоков).');
        $this->back($pageId, $lang);
    }

    /** @param array<string, string> $params */
    public function insert(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $pageId = (int) $params['id'];
        $page = Page::findById($pageId);
        if (!$page) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $lang = $this->resolveLang();
        $snippet = BlockSnippet::findById((int) ($_POST['snippet_id'] ?? 0));
        if ($snippet === null) {
            Flash::error('Шаблон не найден.');
            $this->back($pageId, $lang);
        }

        $blocks = json_decode((string) $snippet['blocks_json'], true);
        if (!is_array($blocks)) {
            Flash::error('Шаблон повреждён.');
            $this->back($pageId, $lang);
        }

        $replace = ($_POST['mode'] ?? 'append') === 'replace';
        // Замена удаляет блоки безвозвратно — сначала снимаем автокопию.
        $backup = $replace ? BlockSnippet::autoBackup($pageId, $lang, (string) ($page['title'] ?? '')) : null;
        $count = BlockSnippet::applyToPage($blocks, $pageId, $lang, $replace);

        Cache::forgetPrefix('page:');
        Flash::success(($replace ? 'Страница заменена шаблоном. ' : '') . 'Вставлено блоков: ' . $count . '.');
        if ($backup !== null) {
            Flash::success('Прежние блоки сохранены как шаблон «' . $backup . '» — применить его с режимом «Заменить», чтобы вернуть как было.');
        }
        $this->back($pageId, $lang);
    }

    /**
     * Готовая сборка страницы (App\Core\PagePresets): те же блоки, что и у
     * пользовательского шаблона, только описаны в коде и приходят с уже
     * расставленными фонами и отступами.
     * @param array<string, string> $params
     */
    public function applyPreset(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $pageId = (int) $params['id'];
        $page = Page::findById($pageId);
        if (!$page) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $lang = $this->resolveLang();
        $preset = \App\Core\PagePresets::find((string) ($_POST['preset'] ?? ''), $lang);
        if ($preset === null) {
            Flash::error('Сборка не найдена.');
            $this->back($pageId, $lang);
        }

        $replace = ($_POST['mode'] ?? 'append') === 'replace';
        // Замена удаляет блоки безвозвратно — сначала снимаем автокопию.
        $backup = $replace ? BlockSnippet::autoBackup($pageId, $lang, (string) ($page['title'] ?? '')) : null;
        $count = BlockSnippet::applyToPage($preset['blocks'], $pageId, $lang, $replace);

        $presetKey = (string) ($_POST['preset'] ?? '');
        if ($presetKey === 'home' || !empty($page['is_home']) || (string) ($page['slug'] ?? '') === 'home') {
            \App\Core\Database::pdo()->exec("UPDATE pages SET is_home = 0 WHERE id <> " . (int) $pageId);
            \App\Core\Database::pdo()->exec("UPDATE pages SET is_home = 1, status = 'published' WHERE id = " . (int) $pageId);
        }

        Cache::flush();
        Flash::success(sprintf(
            'Сборка «%s» применена: блоков — %d. Замените тексты-заготовки своим содержимым.',
            $preset['name'],
            $count
        ));
        if ($backup !== null) {
            Flash::success('Прежние блоки сохранены как шаблон «' . $backup . '» — применить его с режимом «Заменить», чтобы вернуть как было.');
        }
        $this->back($pageId, $lang);
    }

    /**
     * Выгрузка шаблона файлом. Внутри системы шаблон уже был, но жил только в
     * базе: перенести сборку на другой сайт или отдать её кому-то было нечем.
     */
    public function export(): void
    {
        Auth::requireLogin();

        $snippet = BlockSnippet::findById((int) ($_GET['id'] ?? 0));
        if ($snippet === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $blocks = json_decode((string) ($snippet['blocks_json'] ?? ''), true);
        $name = (string) $snippet['name'];
        $file = PageTemplateFile::fileName($name);
        $body = PageTemplateFile::export($name, is_array($blocks) ? $blocks : []);

        header('Content-Type: application/json; charset=utf-8');
        // Транслитерированное имя — для клиентов, которые не понимают
        // filename*; полное идёт следом и выигрывает там, где понимают.
        header(
            'Content-Disposition: attachment; filename="' . $file . '"; '
            . "filename*=UTF-8''" . rawurlencode(PageTemplateFile::fileName($name))
        );
        header('Content-Length: ' . strlen($body));
        header('X-Content-Type-Options: nosniff');
        echo $body;
    }

    /**
     * Загрузка шаблона из файла. Присланный файл — не свои данные: тип блока
     * сверяется с реестром, поля — с умолчаниями типа, оформление проходит
     * через тот же нормализатор, что и форма блока. Что не прошло — попадает
     * в предупреждения, а не пропадает молча.
     */
    public function import(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $file = $_FILES['template'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Flash::error('Файл не выбран или не загрузился.');
            $this->backToImport();
        }
        if ((int) ($file['size'] ?? 0) > PageTemplateFile::MAX_BYTES) {
            Flash::error('Файл больше 2 МБ — это не шаблон страницы.');
            $this->backToImport();
        }

        $json = (string) file_get_contents((string) $file['tmp_name']);
        try {
            $parsed = PageTemplateFile::parse($json, Auth::isSuperAdmin());
        } catch (\InvalidArgumentException $e) {
            Flash::error('Импорт не выполнен. ' . $e->getMessage());
            $this->backToImport();
        }

        // Имя из файла может совпасть с уже сохранённым — это нормально, но
        // пустое имя оставило бы в списке безымянную строку.
        $name = $parsed['name'] !== '' ? $parsed['name'] : 'Импорт от ' . date('d.m.Y H:i');
        $posted = trim((string) ($_POST['snippet_name'] ?? ''));
        if ($posted !== '') {
            $name = mb_substr($posted, 0, 190);
        }

        BlockSnippet::create($name, $parsed['blocks']);
        Cache::flush();

        $message = 'Шаблон «' . $name . '» загружен: ' . count($parsed['blocks']) . ' блоков.';
        if ($parsed['warnings'] !== []) {
            // Предупреждения показываем целиком, а не «и ещё 12»: по ним
            // редактор понимает, что именно приехало не так.
            Flash::error($message . ' Замечания: ' . implode('; ', $parsed['warnings']) . '.');
        } else {
            Flash::success($message);
        }
        $this->backToImport();
    }

    private function backToImport(): never
    {
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '/admin/pages');
        header('Location: ' . (str_starts_with($referer, '/') ? $referer : '/admin/pages'));
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        BlockSnippet::delete((int) $params['id']);
        Flash::success('Шаблон удалён.');
        $referer = $_SERVER['HTTP_REFERER'] ?? '/admin/pages';
        header('Location: ' . (str_starts_with($referer, '/') ? $referer : '/admin/pages'));
        exit;
    }

    private function back(int $pageId, string $lang): never
    {
        header('Location: /admin/pages/' . $pageId . '/edit?block_lang=' . urlencode($lang));
        exit;
    }
}
