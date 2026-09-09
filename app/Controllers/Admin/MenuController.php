<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Models\Language;
use App\Models\MenuItem;
use App\Models\Page;

final class MenuController
{
    public function index(): void
    {
        Auth::requireSuperAdmin();
        View::render('admin/menu/index', [
            'tree' => MenuItem::allTree(),
            'items' => MenuItem::all(),
            // Проекты — такие же записи pages, и в меню их ставят так же часто,
            // как обычные страницы: показываем оба списка одним выбором.
            'pages' => Page::filter('published'),
            'projects' => Page::filter('published', null, 'project'),
            'languages' => Language::active(),
        ]);
    }

    public function store(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        [$data, $error] = $this->collectInput();
        if ($error !== null) {
            Flash::error($error);
            $this->redirectToMenu((string) ($_POST['lang'] ?? ''));
        }

        $parentError = MenuItem::validateParent($data['parent_id'], null, $data['lang']);
        if ($parentError !== null) {
            Flash::error($parentError);
            $this->redirectToMenu((string) $data['lang']);
        }

        $id = MenuItem::create($data);
        Flash::success('Пункт меню добавлен.');
        $this->redirectToMenu((string) $data['lang'], $id);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $item = MenuItem::findById((int) $params['id']);
        if (!$item) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        [$data, $error] = $this->collectInput();
        if ($error !== null) {
            Flash::error($error);
            $this->redirectToMenu((string) $item['lang'], (int) $item['id']);
        }

        $parentError = MenuItem::validateParent($data['parent_id'], (int) $item['id'], $data['lang']);
        if ($parentError !== null) {
            Flash::error($parentError);
            $this->redirectToMenu((string) $data['lang'], (int) $item['id']);
        }

        MenuItem::update((int) $item['id'], $data);
        Flash::success('Пункт меню обновлён.');
        $this->redirectToMenu((string) $data['lang'], (int) $item['id']);
    }

    /**
     * AJAX: пакетное сохранение порядка и вложенности (drag-and-drop, задача 3).
     */
    public function reorder(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();
        header('Content-Type: application/json');

        $ids = $_POST['id'] ?? [];
        $parents = $_POST['parent_id'] ?? [];
        if (!is_array($ids)) {
            echo json_encode(['ok' => false, 'error' => 'bad_request']);
            return;
        }

        $rows = [];
        foreach (array_values($ids) as $i => $id) {
            $parent = $parents[$i] ?? '';
            $rows[] = [
                'id' => (int) $id,
                'parent_id' => ($parent === '' || $parent === '0') ? null : (int) $parent,
                'sort_order' => $i + 1,
            ];
        }

        try {
            MenuItem::reorder($rows);
            echo json_encode(['ok' => true]);
        } catch (\DomainException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Не удалось сохранить порядок меню', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'server_error']);
        }
    }

    public function synchronize(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $sourceLang = trim((string) ($_POST['source_lang'] ?? ''));
        $targetLang = trim((string) ($_POST['target_lang'] ?? ''));
        try {
            $result = MenuItem::synchronizeLanguage($sourceLang, $targetLang);
            $message = sprintf(
                'Меню синхронизировано: создано %d, заменено %d.',
                $result['created'],
                $result['replaced']
            );
            if ($result['skipped'] > 0) {
                $message .= sprintf(
                    ' Пропущено %d пунктов без опубликованной страницы целевого языка.',
                    $result['skipped']
                );
            }
            Flash::success($message);
        } catch (\DomainException $e) {
            Flash::error($e->getMessage());
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Не удалось синхронизировать меню', ['error' => $e->getMessage()]);
            Flash::error('Не удалось синхронизировать меню. Проверьте структуру и повторите попытку.');
        }

        $this->redirectToMenu($targetLang);
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $item = MenuItem::findById($id);
        MenuItem::delete($id);
        Flash::success('Пункт меню удалён.');
        $this->redirectToMenu((string) ($item['lang'] ?? ''));
    }

    /** @param array<string, string> $params */
    public function move(array $params): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $item = MenuItem::findById($id);
        $direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';
        MenuItem::move($id, $direction);
        $this->redirectToMenu((string) ($item['lang'] ?? ''), $id);
    }

    /**
     * @return array{0: array, 1: string|null}
     */
    private function collectInput(): array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        // Меню теперь всегда привязано к конкретному языку (без «Все языки»):
        // пустой/неактивный — на язык по умолчанию.
        $lang = (string) ($_POST['lang'] ?? '');
        if ($lang === '' || !Language::isActive($lang)) {
            $lang = Language::defaultCode();
        }
        $isDivider = !empty($_POST['is_divider']);
        $iconSvg = (string) ($_POST['icon_svg'] ?? '');
        $urlType = in_array($_POST['url_type'] ?? '', ['page', 'news_index', 'custom'], true) ? $_POST['url_type'] : 'custom';
        $urlValue = match ($urlType) {
            'page' => trim((string) ($_POST['page_slug'] ?? $_POST['url_value'] ?? '')),
            'custom' => trim((string) ($_POST['custom_url'] ?? $_POST['url_value'] ?? '')),
            default => '',
        };

        // Разделитель — визуальный элемент без ссылки и, как правило, без названия.
        if ($isDivider) {
            return [[
                'title' => $title !== '' ? $title : '—',
                'lang' => $lang,
                'icon_svg' => null,
                'is_divider' => true,
                'url_type' => 'custom',
                'url_value' => null,
                'parent_id' => null,
                'is_active' => !empty($_POST['is_active']),
            ], null];
        }

        if ($title === '') {
            return [[], 'Укажите название пункта меню.'];
        }
        if ($urlType === 'page' && $urlValue === '') {
            return [[], 'Выберите страницу для пункта меню.'];
        }
        if ($urlType === 'page') {
            $page = Page::findPublishedMenuTarget($urlValue, $lang);
            if ($page === null) {
                return [[], 'Для выбранного языка нет опубликованной версии этой страницы.'];
            }
            $urlValue = Page::menuTargetValue($page);
        }
        if ($urlType === 'custom' && $urlValue === '') {
            return [[], 'Укажите URL для пункта меню.'];
        }
        if ($urlType === 'custom' && !\App\Core\UrlGuard::isSafeLink($urlValue)) {
            return [[], 'Недопустимый URL: разрешены http(s)-ссылки, относительные пути, mailto/tel.'];
        }
        if ($urlType === 'news_index') {
            $urlValue = '';
        }

        $parentRaw = trim((string) ($_POST['parent_id'] ?? ''));
        $parentId = ($parentRaw === '' || $parentRaw === '0') ? null : (int) $parentRaw;
        $badgeText = trim((string) ($_POST['badge_text'] ?? ''));
        $badgeColor = trim((string) ($_POST['badge_color'] ?? 'red'));
        $badgePos = trim((string) ($_POST['badge_pos'] ?? 'right'));

        return [[
            'title' => $title,
            'lang' => $lang,
            'icon_svg' => $iconSvg,
            'hide_title' => !empty($_POST['hide_title']),
            'badge_text' => $badgeText !== '' ? $badgeText : null,
            'badge_color' => $badgeColor,
            'badge_pos' => $badgePos,
            'is_divider' => false,
            'url_type' => $urlType,
            'url_value' => $urlValue !== '' ? $urlValue : null,
            'parent_id' => $parentId,
            // Раскладка подменю: 0 — обычная выпадашка, 2..4 — мега-меню.
            'mega_columns' => \App\Models\MenuItem::megaColumns($_POST['mega_columns'] ?? 0, $parentId),
            'is_active' => !empty($_POST['is_active']),
        ], null];
    }

    private function redirectToMenu(string $lang = '', ?int $selectedId = null): never
    {
        $lang = Language::isActive($lang) ? $lang : Language::defaultCode();
        $query = ['lang' => $lang];
        if ($selectedId !== null && $selectedId > 0) {
            $query['selected'] = $selectedId;
        }
        header('Location: /admin/menu?' . http_build_query($query));
        exit;
    }
}
