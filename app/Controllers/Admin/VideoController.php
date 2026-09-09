<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\ImageField;
use App\Core\View;
use App\Core\YoutubeImport;
use App\Models\Language;
use App\Models\Video;
use App\Models\VideoTranslation;

/**
 * Управление видео: список + создание, редактирование (обложка, ссылка,
 * длительность), публикация, флаг «показать на главном», удаление.
 * Здесь же — автозагрузка карточек роликов с YouTube-канала.
 */
final class VideoController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::render('admin/videos/index', [
            'items' => Video::all(),
            'youtube' => YoutubeImport::settings(),
            'youtubeConfigured' => YoutubeImport::isConfigured(),
        ]);
    }

    /**
     * Настройки автозагрузки с канала. Ключ API — секрет, поэтому раздел
     * настраивает только супер-админ (запуск импорта доступен всем админам:
     * он работает уже сохранённой конфигурацией).
     */
    public function importSettings(): void
    {
        Auth::requireSuperAdmin();
        Csrf::verifyRequest();

        YoutubeImport::saveSettings($_POST);
        if (!YoutubeImport::isConfigured()) {
            Flash::error('Не удалось разобрать адрес канала. Укажите ссылку вида https://www.youtube.com/@канал или id канала UC….');
        } else {
            Flash::success('Настройки импорта с YouTube сохранены.');
        }
        header('Location: /admin/videos#youtube-import');
        exit;
    }

    /**
     * Массовые действия из списка: публикация, «показать на главной»,
     * удаление. Импорт с канала приносит записи десятками и черновиками —
     * без этого их пришлось бы открывать по одной.
     */
    public function bulk(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $ids = (array) ($_POST['ids'] ?? []);
        $action = (string) ($_POST['bulk_action'] ?? '');
        if ($ids === []) {
            Flash::error('Не выбрано ни одного видео.');
            header('Location: /admin/videos');
            exit;
        }

        $done = match ($action) {
            'publish' => Video::setPublishedMany($ids, true),
            'unpublish' => Video::setPublishedMany($ids, false),
            'feature' => Video::setFeaturedMany($ids, true),
            'unfeature' => Video::setFeaturedMany($ids, false),
            'delete' => Video::deleteMany($ids),
            default => null,
        };

        if ($done === null) {
            Flash::error('Неизвестное действие.');
            header('Location: /admin/videos');
            exit;
        }

        Flash::success(match ($action) {
            'publish' => 'Опубликовано видео: ' . $done . '.',
            'unpublish' => 'Снято с публикации: ' . $done . '.',
            'feature' => 'Отмечено «на главной»: ' . $done . '.',
            'unfeature' => 'Снято с главной: ' . $done . '.',
            default => 'Удалено видео: ' . $done . '.',
        });
        header('Location: /admin/videos');
        exit;
    }

    /** Ручной запуск импорта («Загрузить сейчас»). */
    public function importRun(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $result = YoutubeImport::sync(Auth::id());
        if (!$result['ok']) {
            Flash::error('Импорт с YouTube не удался. ' . $result['error']);
        } else {
            Flash::success('Импорт с YouTube: ' . $result['summary'] . '.');
        }
        header('Location: /admin/videos#youtube-import');
        exit;
    }

    public function store(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = Video::create((string) ($_POST['title'] ?? ''));
        if ($id === null) {
            Flash::error('Укажите название видео.');
            header('Location: /admin/videos');
            exit;
        }
        Flash::success('Видео создано — добавьте обложку и ссылку.');
        header('Location: /admin/videos/' . $id . '/edit');
        exit;
    }

    /** @param array<string, string> $params */
    public function edit(array $params): void
    {
        Auth::requireLogin();

        $video = Video::findById((int) $params['id']);
        if (!$video) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        View::render('admin/videos/form', [
            'video' => $video,
            'translations' => VideoTranslation::forVideo((int) $video['id']),
        ]);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $existing = Video::findById($id);
        if ($existing === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $cover = ImageField::resolve('cover_file', 'cover_url', (string) ($existing['cover_url'] ?? ''), Auth::id());

        Video::update(
            $id,
            (string) ($_POST['title'] ?? ''),
            (string) ($_POST['description'] ?? ''),
            (string) ($cover ?? ''),
            (string) ($_POST['video_url'] ?? ''),
            (string) ($_POST['duration'] ?? ''),
            !empty($_POST['is_published']),
            !empty($_POST['is_featured']),
            (int) ($_POST['sort_order'] ?? 0)
        );
        $this->saveTranslations($id);
        Flash::success('Видео сохранено.');
        header('Location: /admin/videos/' . $id . '/edit');
        exit;
    }

    /**
     * Сохраняет переводы (title, description) для всех НЕ-основных активных
     * языков из полей translations[<lang>][...].
     */
    private function saveTranslations(int $videoId): void
    {
        $defaultCode = Language::defaultCode();
        $input = (array) ($_POST['translations'] ?? []);
        foreach (Language::active() as $lang) {
            $code = (string) $lang['code'];
            if ($code === $defaultCode) {
                continue;
            }
            $t = (array) ($input[$code] ?? []);
            VideoTranslation::upsert($videoId, $code, [
                'title' => trim((string) ($t['title'] ?? '')),
                'description' => trim((string) ($t['description'] ?? '')),
            ]);
        }
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        Video::delete((int) $params['id']);
        Flash::success('Видео удалено.');
        header('Location: /admin/videos');
        exit;
    }
}
