<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Ai\AiAltText;
use App\Core\Ai\AiClient;
use App\Core\Ai\AiEditor;
use App\Core\Ai\AiLogDoctor;
use App\Core\Ai\AiPageDraft;
use App\Core\AiAssistantService;
use App\Core\Auth;
use App\Core\Cache;
use App\Core\Csrf;
use App\Core\RateLimiter;
use App\Models\BlockSnippet;
use App\Models\FileEntry;
use App\Models\NewsCategory;

/**
 * Помощник редактора: задачи, которые делает модель.
 *
 * Все они устроены одинаково и потому живут в одном контроллере: POST с
 * CSRF-токеном, ограничитель частоты (запрос к модели стоит денег и квоты),
 * ответ — JSON с полями и строкой `notice`, которую панель показывает как
 * есть. Своей логики здесь нет: она в `App\Core\Ai\*`, а контроллер решает
 * только, кому что можно и что считать входными данными.
 *
 * Ни одна задача ничего не публикует: результат уезжает в поле формы, и
 * решение остаётся за редактором.
 */
final class AiController
{
    /** Задач на пользователя в час: черновики недорогие, но и не бесплатные. */
    private const LIMIT_PER_HOUR = 60;

    public function alt(): void
    {
        $this->begin();

        $file = null;
        $fileId = (int) ($_POST['file_id'] ?? 0);
        if ($fileId > 0) {
            $file = FileEntry::findById($fileId);
        } elseif (($url = trim((string) ($_POST['url'] ?? ''))) !== '') {
            // Адрес из галереи новости сопоставляется с медиатекой тем же
            // методом, что и везде: произвольный путь в запросе позволил бы
            // читать чужие файлы сервера.
            $file = FileEntry::findPublicByUrl($url);
        }

        if ($file === null) {
            $this->json(['ok' => false, 'error' => 'Файл не найден в медиатеке.']);
        }

        $result = AiAltText::forFile((int) $file['id']);
        $this->json([
            'ok' => true,
            'alt' => $result['alt'],
            'notice' => $result['notice'],
        ]);
    }

    /** SEO-заголовок и описание для страницы, проекта или записи каталога. */
    public function seo(): void
    {
        $this->begin();

        $target = (string) ($_POST['target'] ?? 'meta_description');
        $kind = (string) ($_POST['kind'] ?? AiAssistantService::KIND_NEWS);
        // У страницы тела в форме нет — содержимое собирается блоками, а в
        // запросе есть лид. Пустой текст оставил бы генератору один заголовок.
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($content === '') {
            $content = trim((string) ($_POST['lead'] ?? ''));
        }

        $result = AiAssistantService::generateField(
            trim((string) ($_POST['title'] ?? '')),
            $content,
            $target,
            mb_substr($kind, 0, 40)
        );

        $this->json([
            'ok' => true,
            'meta_title' => $result['meta_title'],
            'meta_description' => $result['meta_description'],
            'excerpt' => $result['excerpt'],
            'hashtags' => $result['hashtags'],
            'notice' => $result['notice'],
        ]);
    }

    public function review(): void
    {
        $this->begin();

        $result = AiEditor::review(
            trim((string) ($_POST['title'] ?? '')),
            trim((string) ($_POST['lead'] ?? '')),
            trim((string) ($_POST['content'] ?? '')),
            mb_substr((string) ($_POST['kind'] ?? 'новость'), 0, 40)
        );

        $this->json(['ok' => true, 'remarks' => $result['remarks'], 'notice' => $result['notice']]);
    }

    public function classify(): void
    {
        $this->begin();

        $categories = [];
        foreach (NewsCategory::all() as $category) {
            $categories[] = [
                'id' => (int) ($category['id'] ?? 0),
                'slug' => (string) ($category['slug'] ?? ''),
                'name' => (string) ($category['name'] ?? ''),
            ];
        }

        $result = AiEditor::classify(
            trim((string) ($_POST['title'] ?? '')),
            trim((string) ($_POST['content'] ?? '')),
            $categories
        );

        $this->json([
            'ok' => true,
            'category_id' => $result['category_id'],
            'badge' => $result['badge'],
            'hashtags' => $result['hashtags'],
            'notice' => $result['notice'],
        ]);
    }

    /**
     * Разбор записи журнала — только супер-админу: в журнале видны пути на
     * диске, имена таблиц и внутренние сообщения, и раздел закрыт по той же
     * причине.
     */
    public function explain(): void
    {
        $this->begin(true);

        $result = AiLogDoctor::explain((string) ($_POST['message'] ?? ''));
        $this->json([
            'ok' => true,
            'meaning' => $result['meaning'],
            'action' => $result['action'],
            'notice' => $result['notice'],
        ]);
    }

    /**
     * Каркас страницы по описанию. Попадает в библиотеку шаблонов, а не на
     * страницу: применение остаётся отдельным действием редактора.
     */
    public function pageDraft(): void
    {
        $this->begin();

        $result = AiPageDraft::fromDescription(
            (string) ($_POST['description'] ?? ''),
            Auth::isSuperAdmin()
        );

        if ($result['blocks'] === []) {
            $this->json(['ok' => false, 'error' => $result['notice']]);
        }

        BlockSnippet::create($result['name'], $result['blocks']);
        Cache::flush();

        $this->json([
            'ok' => true,
            'name' => $result['name'],
            'blocks' => count($result['blocks']),
            'notice' => $result['warnings'] === []
                ? ''
                : 'Замечания при сборке: ' . implode('; ', $result['warnings']) . '.',
        ]);
    }

    /** Доступ, CSRF, частота и заголовок ответа — одинаковы у всех задач. */
    private function begin(bool $superAdminOnly = false): void
    {
        if ($superAdminOnly) {
            Auth::requireSuperAdmin();
        } else {
            Auth::requireLogin();
        }
        Csrf::verifyRequest();
        header('Content-Type: application/json; charset=utf-8');

        if (!AiClient::configured()) {
            // Отвечаем успехом с объяснением, а не ошибкой: ненастроенная
            // интеграция — это не поломка кнопки, и говорить о ней надо
            // словами, а не красным «HTTP 500».
            $this->json(['ok' => true, 'notice' => 'Ключ Gemini не настроен: «Настройки сайта» → ИИ-интеграция.']);
        }

        $key = (string) Auth::id() . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!RateLimiter::throttle('admin_ai_task', $key, self::LIMIT_PER_HOUR, 60, false)) {
            http_response_code(429);
            header('Retry-After: 600');
            $this->json(['ok' => false, 'error' => 'Слишком много запросов к ИИ. Повторите позже.']);
        }
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): never
    {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"ok":false}';
        exit;
    }
}
