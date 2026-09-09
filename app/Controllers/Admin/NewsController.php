<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\AdminListQuery;
use App\Core\AiAssistantService;
use App\Core\AppUrl;
use App\Core\Cache;
use App\Core\ConcurrencyException;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\ImageField;
use App\Core\Locale;
use App\Core\NewsLead;
use App\Core\RateLimiter;
use App\Core\Slug;
use App\Core\TextProcessor;
use App\Core\TranslationGroupHelper;
use App\Core\View;
use App\Models\Language;
use App\Models\News;
use App\Models\NewsTranslation;
use App\Models\ContentRevision;

final class NewsController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = AdminListQuery::normalize(
            $_GET,
            ['newest', 'oldest', 'title_asc', 'title_desc', 'published_desc'],
            'newest',
            true
        );
        // Категория — фильтр только этого списка, поэтому её не нормализует
        // общий AdminListQuery: остальным спискам такой ключ не нужен.
        // Значения: '' — не фильтровать, 'none' — без категории, иначе id.
        $category = trim((string) ($_GET['category'] ?? ''));
        $filters['category'] = $category === 'none' ? 'none' : ((int) $category > 0 ? (string) (int) $category : '');

        $total = News::adminCount($filters);
        [$filters, $pages] = AdminListQuery::fitPage($filters, $total);
        $items = News::adminList($filters);

        // Названия категорий берём на языке, выбранном фильтром списка: рядом
        // уже стоят заголовки новостей на этом же языке.
        $categoryLang = $filters['lang'] !== 'all' ? $filters['lang'] : Language::defaultCode();

        View::render('admin/news/index', [
            'items' => $items,
            'filters' => $filters,
            'filterParams' => AdminListQuery::urlParams($filters),
            'total' => $total,
            'pages' => $pages,
            'langCounts' => News::langCounts(),
            'categories' => \App\Models\NewsCategory::all($categoryLang),
            'categoryNames' => \App\Models\NewsCategory::namesForIds(
                array_map(static fn (array $item): int => (int) ($item['category_id'] ?? 0), $items),
                $categoryLang
            ),
        ]);
    }

    /** @param array<string, string> $params */
    public function duplicate(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();
        $newId = News::duplicate((int) $params['id']);
        if ($newId === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        Flash::success('Новость дублирована как черновик.');
        header('Location: /admin/news/' . $newId . '/edit');
        exit;
    }

    public function create(): void
    {
        Auth::requireLogin();
        View::render('admin/news/form', ['news' => null, 'translations' => [], 'error' => null]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        [$data, $error] = $this->collectInput(null);

        if ($error !== null) {
            View::render('admin/news/form', ['news' => $data, 'translations' => [], 'error' => $error]);
            return;
        }

        $extras = $this->collectExtras();
        [$id, $purgeAfterCommit] = Database::transaction(function (\PDO $_pdo) use ($data, $extras): array {
            $id = News::create($data);
            News::updateExtras($id, $extras);
            $this->saveTranslations($id);
            $purge = $this->handleGallery($id);
            $this->handlePollAndTimeline($id);

            return [$id, $purge];
        });
        if ($purgeAfterCommit !== []) {
            \App\Core\MediaCleaner::purgeUnreferenced($purgeAfterCommit);
        }
        Cache::forgetPrefix('page:');

        // Публикация в соцсети выполняется ТОЛЬКО при явном подтверждении админом (флажок publish_to_social).
        if ($data['status'] === 'published') {
            if (!empty($_POST['publish_to_social'])) {
                \App\Core\SocialSettings::enqueueForNews(
                    $id,
                    null,
                    false,
                    \App\Core\SocialSettings::normalizeScheduleTime((string) ($_POST['schedule_at'] ?? ''))
                );
            }
            if (\App\Core\WebPush::isEnabled()) {
                \App\Models\WebPushSubscription::enqueueNews($id);
            }
            $this->dispatchNewsPublished($id, $data);
        }

        Flash::success('Новость создана.');
        header('Location: /admin/news/' . $id . '/edit?draft_saved=news%3Anew');
        exit;
    }

    /** @param array<string, string> $params */
    public function edit(array $params): void
    {
        Auth::requireLogin();
        $news = News::findById((int) $params['id']);
        if (!$news) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $activeLang = (string) ($_GET['lang'] ?? Language::defaultCode());
        if (!Language::isActive($activeLang)) {
            $activeLang = Language::defaultCode();
        }

        $translations = NewsTranslation::forNews((int) $news['id']);
        $gallery = \App\Models\NewsImage::forNews((int) $news['id']);

        View::render('admin/news/form', [
            'news' => $news,
            'translations' => $translations,
            'gallery' => $gallery,
            'activeLang' => $activeLang,
            // Напоминания о незаполненном: ничего не блокируют, просто список.
            'checklist' => \App\Core\ContentChecklist::forNews(
                $news,
                $gallery,
                array_map('strval', array_keys($translations))
            ),
            'error' => null,
        ]);
    }

    /** @param array<string, string> $params */
    public function createTranslation(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();
        $id = (int) $params['id'];
        $targetLang = trim((string) ($_POST['target_lang'] ?? ''));
        if ($targetLang === '' || !Language::isActive($targetLang)) {
            Flash::error('Указан некорректный язык перевода.');
            header('Location: /admin/news/' . $id . '/edit');
            exit;
        }

        try {
            $newId = \App\Core\TranslationGroupHelper::createTranslation('news', $id, $targetLang);
            Flash::success("Создан отдельный пост перевода новости на язык «{$targetLang}».");
            header('Location: /admin/news/' . $newId . '/edit');
            exit;
        } catch (\Throwable $e) {
            Flash::error('Не удалось создать перевод: ' . $e->getMessage());
            header('Location: /admin/news/' . $id . '/edit');
            exit;
        }
    }

    /**
     * Предпросмотр новости до публикации (группа 5.2): рендер как на сайте,
     * но только для авторизованных, с noindex и вне кэша/sitemap.
     * @param array<string, string> $params
     */
    public function preview(array $params): void
    {
        Auth::requireLogin();
        $news = News::findById((int) $params['id']);
        if (!$news) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $lang = (string) ($_GET['lang'] ?? Language::defaultCode());
        if (!Language::isActive($lang)) {
            $lang = Language::defaultCode();
        }
        $news = News::localize($news, $lang);

        View::render('site/news_show', [
            'news' => $news,
            'gallery' => \App\Models\NewsImage::forNews((int) $news['id']),
            // Предпросмотр показывает ту же колонку виджетов, что и сайт.
            'sidebar' => \App\Core\WidgetRenderer::sidebarFor($news['sidebar_layout'] ?? 'right_sidebar', $lang),
            'robotsNoindex' => true,
            'previewNotice' => true,
        ]);
    }

    /** @param array<string, string> $params */
    public function update(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $id = (int) $params['id'];
        $news = News::findById($id);
        if (!$news) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $activeLang = (string) ($_POST['active_lang_tab'] ?? $_GET['lang'] ?? Language::defaultCode());
        if (!Language::isActive($activeLang)) {
            $activeLang = Language::defaultCode();
        }

        $expectedLockVersion = (int) ($_POST['expected_lock_version'] ?? 0);
        if (!ContentRevision::isFresh('news', $id, (string) ($_POST['expected_updated_at'] ?? ''), $expectedLockVersion)) {
            View::render('admin/news/form', [
                'news' => $news,
                'translations' => NewsTranslation::forNews($id),
                'gallery' => \App\Models\NewsImage::forNews($id),
                'activeLang' => $activeLang,
                'error' => 'Новость уже была изменена в другой вкладке или другим пользователем. Текущие данные перезагружены; восстановите локальный черновик и проверьте изменения.',
            ]);
            return;
        }

        [$data, $error] = $this->collectInput($id, $news);

        if ($error !== null) {
            View::render('admin/news/form', [
                'news' => array_merge($news, $data),
                'translations' => NewsTranslation::forNews($id),
                'activeLang' => $activeLang,
                'error' => $error,
            ]);
            return;
        }

        $wasPublished = ($news['status'] ?? '') === 'published';
        $extras = $this->collectExtras();
        $expectedVersion = (int) ($_POST['expected_lock_version'] ?? 0);
        try {
            $purgeAfterCommit = Database::transaction(function (\PDO $_pdo) use ($id, $data, $extras, $expectedVersion): array {
                ContentRevision::capture('news', $id, Auth::id());
                News::update($id, $data, $expectedVersion);
                News::updateExtras($id, $extras);
                $this->saveTranslations($id);
                $this->handlePollAndTimeline($id);

                return $this->handleGallery($id);
            });
        } catch (ConcurrencyException) {
            $news = News::findById($id) ?? $news;
            View::render('admin/news/form', [
                'news' => $news,
                'translations' => NewsTranslation::forNews($id),
                'gallery' => \App\Models\NewsImage::forNews($id),
                'activeLang' => $activeLang,
                'error' => 'Новость уже была изменена в другой вкладке или другим пользователем. Текущие данные перезагружены; восстановите локальный черновик и проверьте изменения.',
            ]);
            return;
        }
        if ($purgeAfterCommit !== []) {
            \App\Core\MediaCleaner::purgeUnreferenced($purgeAfterCommit);
        }
        Cache::forgetPrefix('page:');

        // Публикация в соцсети выполняется ТОЛЬКО при явном подтверждении админом (флажок publish_to_social).
        if ($data['status'] === 'published') {
            if (!empty($_POST['publish_to_social'])) {
                \App\Core\SocialSettings::enqueueForNews(
                    $id,
                    null,
                    false,
                    \App\Core\SocialSettings::normalizeScheduleTime((string) ($_POST['schedule_at'] ?? ''))
                );
            }
            if (!$wasPublished) {
                if (\App\Core\WebPush::isEnabled()) {
                    \App\Models\WebPushSubscription::enqueueNews($id);
                }
                $this->dispatchNewsPublished($id, $data);
            }
        }

        $langParam = $activeLang !== '' ? '&lang=' . urlencode($activeLang) : '';
        Flash::success('Новость обновлена.');
        header('Location: /admin/news/' . $id . '/edit?draft_saved=news%3A' . $id . $langParam);
        exit;
    }

    /** Отправляет событие news.published в исходящие вебхуки (задача 136). */
    private function dispatchNewsPublished(int $id, array $data): void
    {
        $base = AppUrl::base();
        \App\Core\WebhookDispatcher::dispatch('news.published', [
            'id' => $id,
            'title' => (string) ($data['title'] ?? ''),
            'slug' => (string) ($data['slug'] ?? ''),
            'lang' => (string) ($data['lang'] ?? Language::defaultCode()),
            'url' => $base . Locale::url(
                'news/' . rawurlencode((string) ($data['slug'] ?? '')),
                (string) ($data['lang'] ?? Language::defaultCode())
            ),
        ]);
    }

    /**
     * Предпросмотр поста Telegram. Раньше содержимое выяснялось по факту в
     * канале: сколько языков вошло, что попало в текст, влезает ли он в лимит.
     * @param array<string, string> $params
     */
    public function socialPreview(array $params): void
    {
        Auth::requireLogin();

        $news = News::findById((int) $params['id']);
        if (!$news) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $cfg = \App\Core\SocialSettings::configFor('telegram');
        $post = \App\Core\SocialSettings::buildPost($news);
        $doc = \App\Core\TelegramRichMessage::build(
            (array) $post['langs'],
            \App\Core\SocialSettings::telegramPhotoUrls($post),
            (string) ($cfg['signature'] ?? ''),
            (string) ($post['category'] ?? ''),
            (string) ($post['date'] ?? ''),
            (string) ($post['hashtags'] ?? ''),
            (array) ($post['gallery_meta'] ?? []),
            (string) ($cfg['second_lang'] ?? '') === 'details' ? 'details' : 'inline'
        );

        View::render('admin/news/social_preview', [
            'news' => $news,
            'post' => $post,
            'doc' => $doc,
            'cfg' => $cfg,
            'missingLangs' => \App\Core\SocialSettings::missingPostLangs($news),
            'length' => \App\Core\TelegramRichMessage::textLength($doc['html']),
            'fits' => \App\Core\TelegramRichMessage::fits($doc['html']),
        ]);
    }

    /**
     * Ручная постановка новости в очередь публикации во все готовые сети.
     * @param array<string, string> $params
     */
    public function pushSocial(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        $news = News::findById((int) $params['id']);
        if (!$news) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        // Кнопка конкретной сети передаёт network; пусто — во все готовые.
        $only = trim((string) ($_POST['network'] ?? ''));
        $only = in_array($only, \App\Core\SocialPublisher::NETWORKS, true) ? $only : null;

        // Отложенная отправка: задача ложится в очередь, но воркер возьмёт её
        // не раньше указанного времени. Пустое поле и прошедшее время — как
        // раньше, при ближайшем запуске.
        $scheduledAt = \App\Core\SocialSettings::normalizeScheduleTime((string) ($_POST['schedule_at'] ?? ''));

        // Кнопка публикации в админке — осознанное действие человека, поэтому
        // публикуем и повторно. Автопубликация при сохранении новости (выше по
        // коду) остаётся идемпотентной, иначе правки плодили бы посты.
        $count = \App\Core\SocialSettings::enqueueForNews((int) $news['id'], $only, true, $scheduledAt);
        if ($count <= 0) {
            Flash::error($only !== null
                ? 'Сеть не настроена. Проверьте её в разделе «Соцсети».'
                : 'Нет настроенных соцсетей. Включите их в разделе «Соцсети».');
        } elseif ($scheduledAt !== null) {
            // Отложенную задачу не отправляем сразу — в этом весь смысл.
            Flash::success('Публикация запланирована на '
                . \App\Core\DateFormatter::short($scheduledAt) . ' '
                . date('H:i', (int) strtotime($scheduledAt))
                . '. Воркер отправит её в это время.');
        } else {
            // Пытаемся отправить сразу. Что не ушло — остаётся в очереди,
            // и воркер дошлёт по расписанию.
            $res = \App\Core\SocialSettings::dispatchPendingForNews((int) $news['id'], $only);
            if (!empty($res['busy'])) {
                Flash::success("Поставлено в очередь публикации: {$count}. Воркер уже занят и отправит её по расписанию.");
            } elseif ($res['sent'] > 0 && $res['failed'] === 0) {
                Flash::success("Опубликовано в соцсети: {$res['sent']}.");
            } elseif ($res['sent'] > 0) {
                Flash::success("Опубликовано: {$res['sent']}, не удалось: {$res['failed']} — оставлено в очереди. " . implode('; ', $res['errors']));
            } elseif ($res['failed'] > 0) {
                Flash::error('Не удалось опубликовать: ' . implode('; ', $res['errors']) . '. Оставлено в очереди — воркер повторит.');
            } else {
                Flash::success("Поставлено в очередь публикации: {$count}. Отправка — по расписанию воркера.");
            }

            // Пост собирается из заполненных переводов: языка нет — версии в
            // канале не будет. Молча это видно только в самом канале, поэтому
            // говорим сразу и здесь, а не только в напоминаниях над формой.
            $missing = \App\Core\SocialSettings::missingPostLangs($news);
            if ($missing !== []) {
                Flash::set('info', 'В посте не будет версии на ' . implode(', ', array_map('mb_strtoupper', $missing))
                    . ': перевод новости не заполнен. Заполните вкладку языка и опубликуйте ещё раз.');
            }
        }
        // Из списка возвращаемся в список (с теми же фильтрами), из формы — в форму.
        if (($_POST['from'] ?? '') === 'list') {
            $query = trim((string) ($_POST['return_query'] ?? ''));
            header('Location: /admin/news' . ($query !== '' ? '?' . $query : ''));
            exit;
        }
        $activeLang = (string) ($_POST['active_lang_tab'] ?? $_GET['lang'] ?? '');
        $langParam = $activeLang !== '' ? '?lang=' . urlencode($activeLang) : '';
        header('Location: /admin/news/' . (int) $news['id'] . '/edit' . $langParam);
        exit;
    }

    private function parseDocs(array $rawDocs): array
    {
        $safeUrl = static function (string $u): string {
            return ($u !== '' && \App\Core\UrlGuard::isSafeLink($u)) ? $u : '';
        };
        $docs = [];
        foreach ($rawDocs as $doc) {
            $title = trim((string) ($doc['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $docs[] = [
                'title' => $title,
                'meta' => trim((string) ($doc['meta'] ?? '')),
                'url' => $safeUrl(trim((string) ($doc['url'] ?? ''))),
            ];
        }
        return $docs;
    }

    private function parseTimeline(string $raw): array
    {
        $events = [];
        if (trim($raw) !== '') {
            foreach (explode("\n", $raw) as $line) {
                $parts = array_map('trim', explode('|', $line));
                if (count($parts) >= 2 && $parts[1] !== '') {
                    $events[] = [
                        'date' => $parts[0] !== '' ? $parts[0] : null,
                        'title' => $parts[1],
                        'text' => $parts[2] ?? null,
                    ];
                }
            }
        }
        return $events;
    }

    /**
     * Варианты ответа опроса из формы: массивом или строкой по разделителям.
     *
     * Присланное значение приводится к строкам поимённо, а не `array_map('trim')`
     * по сырому массиву: в форму можно прислать вложенный массив, и `trim()`
     * получил бы не строку — в файле со `strict_types` это `TypeError`, то есть
     * подделанная форма роняла бы сохранение новости. По той же причине
     * результат `preg_split()` не приводится к массиву приведением типа:
     * `(array) false` — это `[false]`, и `false` уходил бы дальше как значение.
     *
     * @return list<string>
     */
    private function parsePollOptions(mixed $raw): array
    {
        $items = is_array($raw)
            ? $raw
            : (preg_split('/[\r\n,]+/u', trim((string) $raw)) ?: []);

        $options = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $value = trim((string) $item);
            if ($value !== '') {
                $options[] = $value;
            }
        }

        return $options;
    }

    /**
     * Сохраняет переводы для всех НЕ-дефолтных активных языков из полей
     * translations[<lang>][...].
     */
    private function saveTranslations(int $newsId): void
    {
        $defaultCode = Language::defaultCode();
        $activeLang = (string) ($_POST['active_lang_tab'] ?? $_GET['lang'] ?? '');
        $input = (array) ($_POST['translations'] ?? []);

        // Если форма отправлена с отдельной языковой страницы альтернативного языка
        if ($activeLang !== '' && $activeLang !== $defaultCode) {
            $t = $_POST;
            $lead = NewsLead::fromInput(
                (string) ($t['lead_html'] ?? ''),
                (string) ($t['excerpt'] ?? ''),
                $activeLang
            );
            $docs = $this->parseDocs((array) ($t['docs'] ?? []));
            $timeline = $this->parseTimeline((string) ($t['timeline_raw'] ?? ''));
            $pollQuestion = trim((string) ($t['poll_question'] ?? ''));
            $pollOptions = $this->parsePollOptions($t['poll_options'] ?? '');

            NewsTranslation::upsert($newsId, $activeLang, [
                'title' => trim((string) ($t['title'] ?? '')),
                'badge' => trim((string) ($t['badge'] ?? '')),
                'excerpt' => $lead['text'],
                'lead_html' => $lead['html'],
                'content' => TextProcessor::process((string) ($t['content'] ?? ''), $activeLang),
                'hashtags' => trim((string) ($t['hashtags'] ?? '')),
                'key_points' => trim((string) ($t['key_points'] ?? '')),
                'event_meta' => trim((string) ($t['event_meta'] ?? '')),
                'docs' => $docs,
                'timeline_json' => $timeline,
                'poll_question' => $pollQuestion,
                'poll_options_json' => $pollOptions,
                'meta_title' => trim((string) ($t['meta_title'] ?? '')),
                'meta_description' => trim((string) ($t['meta_description'] ?? '')),
            ] + \App\Core\NewsCard::fromInput($t));
        }

        foreach (Language::active() as $lang) {
            $code = (string) $lang['code'];
            if ($code === $defaultCode || ($activeLang !== '' && $code === $activeLang)) {
                continue;
            }
            if (!isset($input[$code])) {
                continue;
            }
            $t = (array) $input[$code];
            $lead = NewsLead::fromInput(
                (string) ($t['lead_html'] ?? ''),
                (string) ($t['excerpt'] ?? ''),
                $code
            );

            $docs = $this->parseDocs((array) ($t['docs'] ?? []));
            $timeline = $this->parseTimeline((string) ($t['timeline_raw'] ?? ''));
            $pollQuestion = trim((string) ($t['poll_question'] ?? ''));
            $pollOptions = $this->parsePollOptions($t['poll_options'] ?? '');

            NewsTranslation::upsert($newsId, $code, [
                'title' => trim((string) ($t['title'] ?? '')),
                'badge' => trim((string) ($t['badge'] ?? '')),
                'excerpt' => $lead['text'],
                'lead_html' => $lead['html'],
                'content' => TextProcessor::process((string) ($t['content'] ?? ''), $code),
                'hashtags' => trim((string) ($t['hashtags'] ?? '')),
                'key_points' => trim((string) ($t['key_points'] ?? '')),
                'event_meta' => trim((string) ($t['event_meta'] ?? '')),
                'docs' => $docs,
                'timeline_json' => $timeline,
                'poll_question' => $pollQuestion,
                'poll_options_json' => $pollOptions,
                'meta_title' => trim((string) ($t['meta_title'] ?? '')),
                'meta_description' => trim((string) ($t['meta_description'] ?? '')),
            ] + \App\Core\NewsCard::fromInput($t));
        }
    }

    /** @param array<string, string> $params */
    public function destroy(array $params): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();

        News::delete((int) $params['id']);
        Flash::success('Новость удалена.');
        header('Location: ' . AdminListQuery::returnPath('/admin/news', $_POST['return_query'] ?? ''));
        exit;
    }

    /**
     * @return array{0: array, 1: string|null}
     */
    /** Поля детальной страницы (эскиз): бейдж, тезисы, мероприятие, документы. */
    private function collectExtras(): array
    {
        return [
            'badge' => trim((string) ($_POST['badge'] ?? '')),
            // Галочка «Цвет темы» у поля цвета важнее самого поля: браузер
            // всегда присылает какое-то значение из <input type="color">.
            'badge_color' => empty($_POST['badge_color_off']) ? (string) ($_POST['badge_color'] ?? '') : '',
            'key_points' => trim((string) ($_POST['key_points'] ?? '')),
            'event_meta' => trim((string) ($_POST['event_meta'] ?? '')),
            'docs' => $this->parseDocs((array) ($_POST['docs'] ?? [])),
            'source_note' => trim((string) ($_POST['source_note'] ?? '')),
        ] + \App\Core\NewsCard::fromInput($_POST);
    }

    private function collectInput(?int $id, ?array $existing = null): array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $lang = (string) ($_POST['lang'] ?? $_GET['lang'] ?? Language::defaultCode());
        if (!Language::isActive($lang)) {
            $lang = Language::defaultCode();
        }
        $lead = NewsLead::fromInput(
            (string) ($_POST['lead_html'] ?? ''),
            (string) ($_POST['excerpt'] ?? ''),
            $lang
        );
        $excerpt = $lead['text'];
        $leadHtml = $lead['html'];
        // WYSIWYG-контент прогоняем через типограф/санитайзер (задача 75).
        $content = TextProcessor::process((string) ($_POST['content'] ?? ''), $lang);
        $metaTitle = trim((string) ($_POST['meta_title'] ?? ''));
        $metaDescription = trim((string) ($_POST['meta_description'] ?? ''));
        $status = (isset($_POST['publish_action']) || ($_POST['status'] ?? 'draft') === 'published') ? 'published' : 'draft';
        $publishedAtInput = trim((string) ($_POST['published_at'] ?? ''));

        $videoUrl = trim((string) ($_POST['video_url'] ?? ''));
        $audioUrl = trim((string) ($_POST['audio_url'] ?? ''));
        $audioTitle = trim((string) ($_POST['audio_title'] ?? ''));
        $hashtags = trim((string) ($_POST['hashtags'] ?? ''));
        $layoutType = in_array($_POST['layout_type'] ?? 'standard', News::LAYOUTS, true) ? (string) $_POST['layout_type'] : 'standard';
        $sidebarLayout = in_array($_POST['sidebar_layout'] ?? 'right_sidebar', ['no_sidebar', 'left_sidebar', 'right_sidebar'], true) ? (string) $_POST['sidebar_layout'] : 'right_sidebar';
        $focalX = self::clampPercent($_POST['focal_x'] ?? null);
        $focalY = self::clampPercent($_POST['focal_y'] ?? null);

        if ($title === '') {
            return [['title' => $title, 'slug' => $slugInput, 'excerpt' => $excerpt, 'lead_html' => $leadHtml, 'content' => $content, 'status' => $status, 'sidebar_layout' => $sidebarLayout], 'Укажите заголовок новости.'];
        }

        if ($videoUrl !== '' && !\App\Core\Video::isYoutube($videoUrl)) {
            return [
                ['title' => $title, 'slug' => $slugInput, 'excerpt' => $excerpt, 'lead_html' => $leadHtml, 'content' => $content, 'status' => $status, 'video_url' => $videoUrl, 'layout_type' => $layoutType, 'sidebar_layout' => $sidebarLayout],
                'Ссылка на видео должна быть YouTube-адресом (youtube.com/watch, youtu.be и т.п.).',
            ];
        }
        if ($audioUrl !== ''
            && (str_starts_with($audioUrl, '//') || !\App\Core\UrlGuard::isSafeMedia($audioUrl))) {
            return [
                ['title' => $title, 'slug' => $slugInput, 'excerpt' => $excerpt, 'lead_html' => $leadHtml, 'content' => $content, 'status' => $status, 'audio_url' => $audioUrl, 'layout_type' => $layoutType, 'sidebar_layout' => $sidebarLayout],
                'Ссылка на аудио должна быть локальным путём или HTTP(S)-адресом.',
            ];
        }

        $existingSlug = trim((string) ($existing['slug'] ?? ''));
        if (TranslationGroupHelper::isProvisionalNewsSlug($existingSlug)
            && ($slugInput === '' || $slugInput === $existingSlug)) {
            $slugInput = '';
        }
        $rawSlug = $slugInput !== '' ? $slugInput : $title;
        $slug = Slug::unique(
            $rawSlug,
            static fn (string $candidate, ?int $excludeId): bool => News::slugExists($candidate, $excludeId, $lang),
            $id
        );

        $publishedAt = date('Y-m-d H:i:s');
        if ($publishedAtInput !== '') {
            $publishedAtDate = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $publishedAtInput);
            $publishedAtErrors = \DateTimeImmutable::getLastErrors();
            if ($publishedAtDate === false
                || ($publishedAtErrors !== false
                    && ($publishedAtErrors['warning_count'] > 0 || $publishedAtErrors['error_count'] > 0))) {
                return [
                    ['title' => $title, 'slug' => $slugInput, 'excerpt' => $excerpt, 'lead_html' => $leadHtml, 'content' => $content, 'status' => $status, 'published_at' => $publishedAtInput, 'layout_type' => $layoutType, 'sidebar_layout' => $sidebarLayout],
                    'Укажите корректную дату и время публикации.',
                ];
            }
            $publishedAt = $publishedAtDate->format('Y-m-d H:i:s');
        }

        $image = ImageField::resolve('image_file', 'image_url', $existing['image'] ?? null, Auth::id());

        $timelineRaw = trim((string) ($_POST['timeline_raw'] ?? ''));
        $timelineEvents = [];
        if ($timelineRaw !== '') {
            foreach (explode("\n", $timelineRaw) as $line) {
                $parts = array_map('trim', explode('|', $line));
                if (count($parts) >= 2) {
                    $timelineEvents[] = [
                        'date' => $parts[0],
                        'title' => $parts[1],
                        'text' => $parts[2] ?? '',
                    ];
                }
            }
        }
        $timelineJson = $timelineEvents !== [] ? json_encode($timelineEvents, JSON_UNESCAPED_UNICODE) : null;

        $data = [
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt !== '' ? $excerpt : null,
            'lead_html' => $leadHtml,
            'content' => $content,
            'image' => $image,
            'video_url' => $videoUrl !== '' ? $videoUrl : null,
            'audio_url' => $audioUrl !== '' ? $audioUrl : null,
            'audio_title' => $audioTitle !== '' ? $audioTitle : null,
            'hashtags' => $hashtags !== '' ? $hashtags : null,
            'timeline_json' => $timelineJson,
            'layout_type' => $layoutType,
            'sidebar_layout' => $sidebarLayout,
            'focal_x' => $focalX,
            'focal_y' => $focalY,
            'category_id' => (int) ($_POST['category_id'] ?? 0),
            'meta_title' => $metaTitle !== '' ? $metaTitle : null,
            'meta_description' => $metaDescription !== '' ? $metaDescription : null,
            'status' => $status,
            'published_at' => $publishedAt,
            'author_id' => Auth::id(),
            'lang' => $lang,
        ];

        return [$data, null];
    }

    private static function clampPercent(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (int) $value;
        return max(0, min(100, $n));
    }

    /**
     * Обрабатывает галерею новости: удаление отмеченных фото, правку alt/порядка/
     * фокальной точки существующих и загрузку новых файлов (news_gallery[]).
     */
    /** @return list<string> пути, которые можно удалить только после COMMIT */
    private function handleGallery(int $newsId): array
    {
        $purgeAfterCommit = [];
        // 1. Правки/удаления существующих.
        foreach ((array) ($_POST['gallery'] ?? []) as $imgId => $meta) {
            $imgId = (int) $imgId;
            if ($imgId <= 0) {
                continue;
            }
            if (!empty($meta['delete'])) {
                $path = \App\Models\NewsImage::delete($imgId, $newsId);
                if ($path !== null) {
                    $purgeAfterCommit[] = $path;
                }
                continue;
            }
            \App\Models\NewsImage::updateMeta(
                $imgId,
                $newsId,
                trim((string) ($meta['alt'] ?? '')) ?: null,
                (int) ($meta['sort'] ?? 0),
                self::clampPercent($meta['focal_x'] ?? null),
                self::clampPercent($meta['focal_y'] ?? null),
                mb_substr(trim((string) ($meta['caption'] ?? '')), 0, 255) ?: null,
                mb_substr(trim((string) ($meta['credit'] ?? '')), 0, 255) ?: null
            );
        }

        // 2. Уже загруженные изображения, выбранные в медиабиблиотеке.
        $existing = \App\Models\NewsImage::forNews($newsId);
        $sort = count($existing);
        $knownPaths = array_fill_keys(array_map(
            static fn (array $image): string => trim((string) ($image['path'] ?? '')),
            $existing
        ), true);
        foreach ((array) ($_POST['gallery_library'] ?? []) as $libraryPath) {
            $libraryPath = trim((string) $libraryPath);
            if ($libraryPath === ''
                || str_starts_with($libraryPath, '//')
                || !\App\Core\UrlGuard::isSafeMedia($libraryPath)
                || isset($knownPaths[$libraryPath])) {
                continue;
            }
            \App\Models\NewsImage::create($newsId, $libraryPath, null, $sort++);
            $knownPaths[$libraryPath] = true;
        }

        // 3. Новые загрузки с компьютера (множественный input news_gallery[]).
        if (empty($_FILES['news_gallery']) || !is_array($_FILES['news_gallery']['name'] ?? null)) {
            return $purgeAfterCommit;
        }

        $names = $_FILES['news_gallery']['name'];
        foreach ($names as $i => $name) {
            if (($_FILES['news_gallery']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $single = [
                'name' => $_FILES['news_gallery']['name'][$i],
                'type' => $_FILES['news_gallery']['type'][$i],
                'tmp_name' => $_FILES['news_gallery']['tmp_name'][$i],
                'error' => $_FILES['news_gallery']['error'][$i],
                'size' => $_FILES['news_gallery']['size'][$i],
            ];
            try {
                $file = \App\Core\Uploader::store($single, 'public', Auth::id());
                \App\Models\NewsImage::create($newsId, \App\Models\FileEntry::publicUrl($file), null, $sort++);
            } catch (\Throwable $e) {
                Flash::error('Не удалось загрузить фото галереи: ' . $e->getMessage());
            }
        }

        return $purgeAfterCommit;
    }

    private function handlePollAndTimeline(int $newsId): void
    {
        $pollQuestion = trim((string) ($_POST['poll_question'] ?? ''));
        $pollOptionsRaw = trim((string) ($_POST['poll_options'] ?? ''));
        $pollOptions = array_values(array_filter(array_map('trim', (array) preg_split('/[\r\n,]+/u', $pollOptionsRaw)), static fn ($v) => $v !== ''));
        \App\Models\NewsPoll::saveForNews($newsId, $pollQuestion, $pollOptions);
    }

    public function generateAiSummary(): void
    {
        Auth::requireLogin();
        Csrf::verifyRequest();
        header('Content-Type: application/json; charset=utf-8');

        $limitKey = (string) Auth::id() . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!RateLimiter::throttle('admin_ai_summary', $limitKey, 10, 10, false)) {
            http_response_code(429);
            header('Retry-After: 600');
            echo json_encode(['ok' => false, 'error' => 'Слишком много запросов. Повторите позже.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $content = trim((string) ($_POST['content'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $target = trim((string) ($_POST['target'] ?? 'summary'));
        if (!in_array($target, ['summary', 'meta_title', 'meta_description'], true)) {
            $target = 'summary';
        }

        \App\Models\ErrorLog::record(
            'INFO',
            'ИИ-редактор: запрос "' . $target . '". Заголовок: "' . mb_substr($title, 0, 60) . '...", Длина текста: ' . mb_strlen($content) . ' симв.',
            __FILE__,
            __LINE__
        );

        if ($content === '' && $title === '') {
            \App\Models\ErrorLog::record('WARNING', 'ИИ-редактор: заголовок и текст новости пустые.', __FILE__, __LINE__);
            echo json_encode(['ok' => false, 'error' => 'Текст новости и заголовок пустые.'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $result = AiAssistantService::generateNewsField($title, $content, $target);
        \App\Models\ErrorLog::record(
            'INFO',
            'ИИ-редактор: поле "' . $target . '" сформировано через ' . $result['provider']
                . ($result['model'] !== '' ? ' (' . $result['model'] . ')' : '') . '.',
            __FILE__,
            __LINE__
        );

        echo json_encode([
            'ok' => true,
            'excerpt' => $result['excerpt'],
            'hashtags' => $result['hashtags'],
            'meta_title' => $result['meta_title'],
            'meta_description' => $result['meta_description'],
            'provider' => $result['provider'],
            'notice' => $result['notice'],
        ], JSON_UNESCAPED_UNICODE);
    }
}
