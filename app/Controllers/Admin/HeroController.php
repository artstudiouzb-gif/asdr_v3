<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Hero\HeroPresets;
use App\Core\Hero\HeroSettings;
use App\Core\Hero\HeroSlideData;
use App\Core\Locale;
use App\Core\View;
use App\Models\Hero;
use App\Models\HeroSlide;
use App\Models\HeroSlideTranslation;
use App\Models\Language;

/**
 * Раздел «Обложки»: сама обложка (общие настройки и расписание) и её слайды.
 *
 * Слайды правятся на отдельной странице, а не в репитере внутри формы
 * обложки: у слайда полсотни полей, и держать десяток таких наборов в одной
 * форме означало бы страницу, которую нельзя ни просмотреть, ни сохранить по
 * частям. В списке остаются превью, порядок и быстрые действия.
 */
final class HeroController
{
    public function index(): void
    {
        Auth::requireLogin();

        View::render('admin/heroes/index', [
            'items' => Hero::all(),
            'counts' => Hero::slideCounts(),
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = Hero::create((string) ($_POST['name'] ?? ''));
        if ($id === null) {
            Flash::error('Укажите название обложки.');
            header('Location: /admin/heroes');
            exit;
        }

        Flash::success('Обложка создана — добавьте слайды.');
        header('Location: /admin/heroes/' . $id . '/edit');
        exit;
    }

    /** @param array<string, string> $params */
    public function edit(array $params): void
    {
        Auth::requireLogin();

        $hero = Hero::find((int) $params['id']);
        if ($hero === null) {
            $this->notFound();

            return;
        }

        $slides = HeroSlide::forHero((int) $hero['id']);
        View::render('admin/heroes/form', [
            'hero' => $hero,
            'settings' => Hero::settings($hero),
            'slides' => $slides,
            'langMap' => HeroSlideTranslation::availableLangsForIds(
                array_map(static fn (array $s): int => (int) $s['id'], $slides)
            ),
            'usage' => Hero::usage((int) $hero['id']),
        ]);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $hero = Hero::find($id);
        if ($hero === null) {
            $this->notFound();

            return;
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            Flash::error('Название обложки не может быть пустым.');
            header('Location: /admin/heroes/' . $id . '/edit');
            exit;
        }

        Hero::update(
            $id,
            $name,
            (string) ($_POST['status'] ?? 'draft'),
            (string) ($_POST['published_from'] ?? ''),
            (string) ($_POST['published_to'] ?? ''),
            (int) ($_POST['priority'] ?? 0),
            HeroSettings::normalize($_POST),
            (string) ($hero['preset'] ?? '')
        );

        Flash::success('Обложка сохранена.');
        header('Location: /admin/heroes/' . $id . '/edit');
        exit;
    }

    /**
     * Применение пресета отдельным действием, а не полем формы: пресет
     * перезаписывает часть настроек, и делать это молча при каждом сохранении
     * значило бы затирать ручные правки.
     * @param array<string, string> $params
     */
    public function applyPreset(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $hero = Hero::find($id);
        if ($hero === null) {
            $this->notFound();

            return;
        }

        $preset = (string) ($_POST['preset'] ?? '');
        if (!HeroPresets::has($preset)) {
            Flash::error('Неизвестный пресет.');
            header('Location: /admin/heroes/' . $id . '/edit');
            exit;
        }

        Hero::saveSettings($id, HeroPresets::apply($preset, Hero::settings($hero)), $preset);
        Flash::success('Пресет применён — настройки можно править дальше вручную.');
        header('Location: /admin/heroes/' . $id . '/edit');
        exit;
    }

    /** @param array<string, string> $params */
    public function duplicate(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $newId = Hero::duplicate((int) $params['id']);
        if ($newId === null) {
            $this->notFound();

            return;
        }

        Flash::success('Обложка скопирована. Копия сохранена черновиком.');
        header('Location: /admin/heroes/' . $newId . '/edit');
        exit;
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        Hero::delete($id);

        Flash::success('Обложка удалена. Блоки, где она стояла, больше её не показывают.');
        header('Location: /admin/heroes');
        exit;
    }

    // --- Слайды ----------------------------------------------------------

    /** @param array<string, string> $params */
    public function slideCreate(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $heroId = (int) $params['id'];
        if (Hero::find($heroId) === null) {
            $this->notFound();

            return;
        }

        $slideId = HeroSlide::create($heroId, HeroSlideData::defaults());
        if ($slideId === null) {
            Flash::error('Больше ' . HeroSlide::MAX_SLIDES . ' слайдов в одной обложке не бывает.');
            header('Location: /admin/heroes/' . $heroId . '/edit');
            exit;
        }

        header('Location: /admin/heroes/' . $heroId . '/slides/' . $slideId . '/edit');
        exit;
    }

    /** @param array<string, string> $params */
    public function slideEdit(array $params): void
    {
        Auth::requireLogin();

        [$hero, $slide] = $this->slideContext($params);
        if ($hero === null || $slide === null) {
            $this->notFound();

            return;
        }

        View::render('admin/heroes/slide_form', [
            'hero' => $hero,
            'settings' => Hero::settings($hero),
            'slide' => $slide,
            'data' => $slide['data'],
            'translations' => HeroSlideTranslation::forSlide((int) $slide['id']),
        ]);
    }

    /** @param array<string, string> $params */
    public function slideUpdate(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        [$hero, $slide] = $this->slideContext($params);
        if ($hero === null || $slide === null) {
            $this->notFound();

            return;
        }

        $slideId = (int) $slide['id'];
        HeroSlide::update($slideId, HeroSlideData::normalize($_POST, Locale::current()));
        $this->saveTranslations($slideId);

        Flash::success('Слайд сохранён.');
        header('Location: /admin/heroes/' . (int) $hero['id'] . '/slides/' . $slideId . '/edit');
        exit;
    }

    /** @param array<string, string> $params */
    public function slideDuplicate(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        [$hero, $slide] = $this->slideContext($params);
        if ($hero === null || $slide === null) {
            $this->notFound();

            return;
        }

        if (HeroSlide::duplicate((int) $slide['id']) === null) {
            Flash::error('Больше ' . HeroSlide::MAX_SLIDES . ' слайдов в одной обложке не бывает.');
        } else {
            Flash::success('Слайд скопирован.');
        }
        header('Location: /admin/heroes/' . (int) $hero['id'] . '/edit');
        exit;
    }

    /** @param array<string, string> $params */
    public function slideToggle(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        [$hero, $slide] = $this->slideContext($params);
        if ($hero === null || $slide === null) {
            $this->notFound();

            return;
        }

        HeroSlide::toggle((int) $slide['id']);
        header('Location: /admin/heroes/' . (int) $hero['id'] . '/edit');
        exit;
    }

    /** @param array<string, string> $params */
    public function slideDestroy(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        [$hero, $slide] = $this->slideContext($params);
        if ($hero === null || $slide === null) {
            $this->notFound();

            return;
        }

        HeroSlide::delete((int) $slide['id']);
        Flash::success('Слайд удалён.');
        header('Location: /admin/heroes/' . (int) $hero['id'] . '/edit');
        exit;
    }

    /**
     * Новый порядок после перетаскивания. Отвечает JSON — форму не перезагружаем.
     * @param array<string, string> $params
     */
    public function reorder(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        header('Content-Type: application/json; charset=utf-8');
        $heroId = (int) $params['id'];
        if (Hero::find($heroId) === null) {
            http_response_code(404);
            echo json_encode(['ok' => false]);
            exit;
        }

        $order = array_map('intval', (array) ($_POST['order'] ?? []));
        HeroSlide::reorder($heroId, $order);
        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * Слайд вместе с обложкой — и только если он действительно её слайд.
     * Иначе чужой id в адресе давал бы правку соседней обложки.
     *
     * @param array<string, mixed> $params
     * @return array{array<string, mixed>|null, array<string, mixed>|null}
     */
    private function slideContext(array $params): array
    {
        $hero = Hero::find((int) $params['id']);
        if ($hero === null) {
            return [null, null];
        }
        $slide = HeroSlide::find((int) $params['slide']);
        if ($slide === null || (int) $slide['hero_id'] !== (int) $hero['id']) {
            return [$hero, null];
        }

        return [$hero, $slide];
    }

    /** Переводы текста слайда для всех НЕ-основных активных языков. */
    private function saveTranslations(int $slideId): void
    {
        $defaultCode = Language::defaultCode();
        $input = (array) ($_POST['translations'] ?? []);
        foreach (Language::active() as $language) {
            $code = (string) $language['code'];
            if ($code === $defaultCode) {
                continue;
            }
            HeroSlideTranslation::upsert($slideId, $code, (array) ($input[$code] ?? []));
        }
    }

    private function notFound(): void
    {
        http_response_code(404);
        View::render('errors/404');
    }
}
