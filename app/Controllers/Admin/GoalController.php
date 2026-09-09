<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\AdminListQuery;
use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
use App\Models\Goal;
use App\Models\GoalTranslation;
use App\Models\Language;

/**
 * Раздел «Цели». Записей сотни, поэтому список сделан постраничным с поиском
 * по названию, а форма — короткой: название, описание, снимки и переводы.
 */
final class GoalController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = AdminListQuery::normalize($_GET, ['newest'], 'newest');
        $search = (string) $filters['q'];

        View::render('admin/goals/index', [
            'goals' => Goal::page((int) $filters['per_page'], (int) $filters['offset'], $search),
            'total' => Goal::countAll($search),
            'filters' => $filters,
        ]);
    }

    public function create(): void
    {
        Auth::requireLogin();
        View::render('admin/goals/form', [
            'goal' => null,
            'images' => [],
            'translations' => [],
            'error' => null,
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $name = $this->name();
        if ($name === '') {
            View::render('admin/goals/form', [
                'goal' => null,
                'images' => $this->images(),
                'translations' => [],
                'error' => 'У цели должно быть название — оно видно на сайте и по нему она ищется в списке.',
            ]);
            return;
        }

        $id = Goal::create($name, $this->description(), !empty($_POST['is_active']));
        Goal::replaceImages($id, $this->images());
        $this->saveTranslations($id);
        Cache::flush();
        Flash::success('Цель добавлена.');
        header('Location: /admin/goals/' . $id . '/edit');
        exit;
    }

    public function edit(string $id): void
    {
        Auth::requireLogin();
        $goal = Goal::find((int) $id);
        if ($goal === null) {
            http_response_code(404);
            View::render('errors/404', []);
            return;
        }

        View::render('admin/goals/form', [
            'goal' => $goal,
            'images' => Goal::images((int) $id),
            'translations' => GoalTranslation::forGoal((int) $id),
            'error' => null,
        ]);
    }

    public function update(string $id): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $goal = Goal::find((int) $id);
        if ($goal === null) {
            http_response_code(404);
            View::render('errors/404', []);
            return;
        }

        $name = $this->name();
        if ($name === '') {
            View::render('admin/goals/form', [
                'goal' => array_merge($goal, ['name' => '']),
                'images' => $this->images(),
                'translations' => GoalTranslation::forGoal((int) $id),
                'error' => 'У цели должно быть название — оно видно на сайте и по нему она ищется в списке.',
            ]);
            return;
        }

        Goal::update((int) $id, $name, $this->description(), !empty($_POST['is_active']));
        Goal::replaceImages((int) $id, $this->images());
        $this->saveTranslations((int) $id);
        Cache::flush();
        Flash::success('Цель сохранена.');
        header('Location: /admin/goals/' . (int) $id . '/edit');
        exit;
    }

    public function destroy(string $id): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        Goal::delete((int) $id);
        Cache::flush();
        Flash::success('Цель удалена.');
        header('Location: /admin/goals');
        exit;
    }

    private function name(): string
    {
        return mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 255);
    }

    /** Описание — простой текст: цель показывается в узкой колонке виджета. */
    private function description(): string
    {
        return mb_substr(trim(strip_tags((string) ($_POST['description'] ?? ''))), 0, 2000);
    }

    /**
     * Переводы названия и описания для всех НЕ-основных активных языков.
     * Пустое поле сохраняется пустым и на сайте откатывается к основному
     * языку — так недописанный перевод оставляет текст, а не дыру.
     */
    private function saveTranslations(int $goalId): void
    {
        $defaultCode = Language::defaultCode();
        $input = (array) ($_POST['translations'] ?? []);
        foreach (Language::active() as $lang) {
            $code = (string) $lang['code'];
            if ($code === $defaultCode) {
                continue;
            }
            $row = (array) ($input[$code] ?? []);
            GoalTranslation::upsert($goalId, $code, [
                'name' => mb_substr(trim((string) ($row['name'] ?? '')), 0, 255),
                'description' => mb_substr(trim(strip_tags((string) ($row['description'] ?? ''))), 0, 2000),
            ]);
        }
    }

    /**
     * Снимки из репитера формы. Пустая строка адреса — удалённая строка, а не
     * кадр: такие пропускает уже модель, здесь важен только порядок.
     *
     * Поля названы slides[...] так же, как у блока «Слайдер» и у виджета:
     * имя с «image»/«photo» админский тест считает полем картинки без
     * медиапикера, а у соседнего поля подписи пикера и не должно быть.
     *
     * @return array<int, array{image: string, alt: string}>
     */
    private function images(): array
    {
        $images = [];
        foreach ((array) ($_POST['slides'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $images[] = [
                'image' => (string) ($row['image'] ?? ''),
                'alt' => (string) ($row['alt'] ?? ''),
            ];
        }

        return $images;
    }
}
