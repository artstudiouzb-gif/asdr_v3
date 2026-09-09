<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Models\Language;
use App\Models\NewsCategory;
use App\Models\NewsCategoryTranslation;

/**
 * Категории новостей: список, создание, редактирование (включая переводы
 * названия), удаление.
 *
 * Категория — не бейдж: она хранится ссылкой (news.category_id), поэтому
 * переименование правит одно место, а не каждую новость.
 */
final class NewsCategoryController
{
    public function index(): void
    {
        Auth::requireLogin();

        $items = NewsCategory::all();
        View::render('admin/news_categories/index', [
            'items' => $items,
            'counts' => NewsCategory::newsCounts(),
            'langMap' => NewsCategoryTranslation::availableLangsForIds(
                array_map(static fn (array $item): int => (int) $item['id'], $items)
            ),
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = NewsCategory::create(
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['slug'] ?? '')
        );
        if ($id === null) {
            Flash::error('Укажите название категории.');
            header('Location: /admin/news-categories');
            exit;
        }

        Flash::success('Категория создана — добавьте переводы названия.');
        header('Location: /admin/news-categories/' . $id . '/edit');
        exit;
    }

    /** @param array<string, string> $params */
    public function edit(array $params): void
    {
        Auth::requireLogin();

        $category = NewsCategory::find((int) $params['id']);
        if ($category === null) {
            http_response_code(404);
            View::render('errors/404');

            return;
        }

        View::render('admin/news_categories/form', [
            'category' => $category,
            'translations' => NewsCategoryTranslation::forCategory((int) $category['id']),
            'newsCount' => NewsCategory::newsCounts()[(int) $category['id']] ?? 0,
        ]);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        if (NewsCategory::find($id) === null) {
            http_response_code(404);
            View::render('errors/404');

            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::error('Название категории не может быть пустым.');
            header('Location: /admin/news-categories/' . $id . '/edit');
            exit;
        }

        NewsCategory::update(
            $id,
            $name,
            (string) ($_POST['slug'] ?? ''),
            !empty($_POST['is_active']),
            (int) ($_POST['sort_order'] ?? 0),
            (string) ($_POST['icon'] ?? '')
        );
        $this->saveTranslations($id);

        Flash::success('Категория сохранена.');
        header('Location: /admin/news-categories/' . $id . '/edit');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        NewsCategory::delete($id);

        // Новости не удаляются вместе с категорией: внешний ключ обнуляет
        // category_id, запись остаётся в ленте без рубрики.
        Flash::success('Категория удалена. Новости остались — у них снята категория.');
        header('Location: /admin/news-categories');
        exit;
    }

    /** Переводы названия для всех НЕ-основных активных языков. */
    private function saveTranslations(int $categoryId): void
    {
        $defaultCode = Language::defaultCode();
        $input = (array) ($_POST['translations'] ?? []);
        foreach (Language::active() as $language) {
            $code = (string) $language['code'];
            if ($code === $defaultCode) {
                continue;
            }
            NewsCategoryTranslation::upsert($categoryId, $code, (string) ($input[$code]['name'] ?? ''));
        }
    }
}
