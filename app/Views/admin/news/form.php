<?php

use App\Core\Csrf;
use App\Core\NewsLead;
use App\Core\TranslationGroupHelper;
use App\Models\Language;

$isEdit = !empty($news['id']);
$pageTitle = $isEdit ? 'Редактирование новости' : 'Новая новость';
$activeNav = 'news';
require __DIR__ . '/../layout/header.php';

/** @var array|null $news */
/** @var string|null $error */
/** @var array $gallery */
$gallery = $gallery ?? [];
$layout = $news['layout_type'] ?? 'standard';
$layoutLabels = [
    'standard' => 'Умный макет (авто-галерея при нескольких фото)',
    'video' => 'Видео (YouTube)',
    'side_image' => 'Изображение сбоку',
    'premium' => 'Премиум (тёмный hero)',
    'card' => 'Карточка (текст в стеклянной панели на фото)',
];

$action = $isEdit ? '/admin/news/' . (int) $news['id'] . '/edit' : '/admin/news/create';
$publishedAtValue = '';
if (!empty($news['published_at'])) {
    $publishedAtValue = str_replace(' ', 'T', substr((string) $news['published_at'], 0, 16));
}
$defaultCode = Language::defaultCode();
$slugValue = (string) ($news['slug'] ?? '');
if (TranslationGroupHelper::isProvisionalNewsSlug($slugValue)) {
    $slugValue = '';
}

$keyPoints = (string) ($news['key_points'] ?? '');
$eventMeta = (string) ($news['event_meta'] ?? '');
$leadEditorValue = NewsLead::editorValue($news['lead_html'] ?? null, $news['excerpt'] ?? null);

$docsRaw = $news['docs'] ?? [];
if (is_string($docsRaw)) {
    $docsRaw = json_decode($docsRaw, true) ?: [];
}
$docs = is_array($docsRaw) ? $docsRaw : [];

// Миграция старого поля press_release_url в документы при необходимости
$legacyPressUrl = trim((string) ($news['press_release_url'] ?? ''));
if ($legacyPressUrl !== '') {
    $alreadyInDocs = false;
    foreach ($docs as $d) {
        if (is_array($d) && trim((string) ($d['url'] ?? '')) === $legacyPressUrl) {
            $alreadyInDocs = true;
            break;
        }
    }
    if (!$alreadyInDocs) {
        $docs[] = ['title' => 'Пресс-релиз', 'meta' => 'PDF', 'url' => $legacyPressUrl];
    }
}

if (empty($docs)) {
    $docs = [['title' => '', 'meta' => '', 'url' => '']];
}

$timelineText = '';
if (!empty($news['timeline_json'])) {
    $tEvents = json_decode((string) $news['timeline_json'], true);
    if (is_array($tEvents)) {
        $lines = [];
        foreach ($tEvents as $e) {
            $lines[] = ($e['date'] ?? '') . ' | ' . ($e['title'] ?? '') . ' | ' . ($e['text'] ?? '');
        }
        $timelineText = implode("\n", $lines);
    }
}
$existingPoll = !empty($news['id']) ? \App\Models\NewsPoll::findByNews((int) $news['id']) : null;
?>
<?php if ($error): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div><?php endif; ?>
<?php if ($isEdit): ?>
    <div class="u-inline-79a1c5a5db"><a class="btn btn--small" href="/admin/revisions/news/<?= (int) $news['id'] ?>">История версий</a></div>
<?php endif; ?>

<form method="post" action="<?= $action ?>" id="news_edit_form" enctype="multipart/form-data" data-content-draft="news:<?= $isEdit ? (int) $news['id'] : 'new' ?>" data-record-updated="<?= htmlspecialchars((string) ($news['updated_at'] ?? ''), ENT_QUOTES) ?>">
    <?= Csrf::field() ?>
    <?php if ($isEdit): ?>
        <input type="hidden" name="expected_updated_at" value="<?= htmlspecialchars((string) $news['updated_at'], ENT_QUOTES) ?>">
        <input type="hidden" name="expected_lock_version" value="<?= max(1, (int) ($news['lock_version'] ?? 1)) ?>">
    <?php endif; ?>

    <?php if (!empty($checklist)): ?>
        <?php // Напоминание, а не запрет: материал сохраняется и публикуется
              // с любым числом незаполненных пунктов. ?>
        <div class="content-checklist" data-content-checklist>
            <div class="content-checklist__head">
                <?= \App\Core\AdminUi::icon('info', 18) ?>
                <span>Можно дополнить — <?= count($checklist) ?></span>
                <button type="button" class="content-checklist__toggle" data-checklist-toggle aria-expanded="false">показать</button>
            </div>
            <ul class="content-checklist__list" hidden>
                <?php foreach ($checklist as $item): ?>
                    <li class="content-checklist__item">
                        <strong><?= htmlspecialchars($item['text'], ENT_QUOTES) ?></strong>
                        <span><?= htmlspecialchars($item['why'], ENT_QUOTES) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="entry-grid">
        <div class="entry-main">
            <!-- Блок 1: Основная информация (Текстовый редактор) -->
            <div class="form-card">
                <?= \App\Core\AdminUi::cardHeader('1. Основная информация', 'document') ?>

                <div class="form-field u-inline-79a1c5a5db">
                    <label class="u-inline-e925a44577">Заголовок новости <span class="u-inline-9dd1207e58">*</span></label>
                    <input class="u-inline-bc64d2d1e3" type="text" name="title" value="<?= htmlspecialchars($news['title'] ?? '', ENT_QUOTES) ?>" placeholder="Введите заголовок новости" required>
                </div>

                <div class="form-field u-inline-79a1c5a5db">
                    <div class="u-inline-a42388f688">
                        <label class="u-inline-2e190aa086" for="news_lead_html">Лид (анонс)</label>
                        <button type="button" class="btn btn--sm btn--secondary" data-ai-generate-summary data-ai-generate="summary"><?= \App\Core\AdminUi::icon('sparkles') ?>ИИ-Аннотация</button>
                    </div>
                    <textarea id="news_lead_html" name="lead_html" rows="7" placeholder="Кратко опишите суть новости"
                              data-lead-editor data-lead-field><?= htmlspecialchars($leadEditorValue, ENT_QUOTES) ?></textarea>
                    <span class="form-hint">
                        Можно использовать жирный, курсив, ссылку, цитату и списки. Для карточек, поиска и SEO
                        чистый текст создаётся автоматически. Рекомендуемая длина — 180–360 знаков;
                        жёсткого лимита нет.
                        <span data-lead-count></span>
                    </span>
                    <div class="lead-previews" data-lead-previews>
                        <div class="lead-previews__tabs" role="tablist" aria-label="Предпросмотр лида">
                            <button type="button" class="lead-previews__tab is-active" data-lead-preview-tab="card" role="tab" aria-selected="true">Карточка</button>
                            <button type="button" class="lead-previews__tab" data-lead-preview-tab="telegram" role="tab" aria-selected="false">Telegram</button>
                            <button type="button" class="lead-previews__tab" data-lead-preview-tab="seo" role="tab" aria-selected="false">SEO</button>
                        </div>
                        <div class="lead-previews__panel" data-lead-preview-panel="card" role="tabpanel">
                            <strong data-lead-preview-title>Заголовок новости</strong>
                            <span data-lead-preview-card>Здесь появится текст карточки.</span>
                        </div>
                        <div class="lead-previews__panel" data-lead-preview-panel="telegram" role="tabpanel" hidden>
                            <div data-lead-preview-telegram>Здесь появится форматированный лид Telegram.</div>
                        </div>
                        <div class="lead-previews__panel" data-lead-preview-panel="seo" role="tabpanel" hidden>
                            <strong data-lead-preview-seo-title>Заголовок новости</strong>
                            <span data-lead-preview-seo>Здесь появится описание для поисковика.</span>
                        </div>
                    </div>
                </div>

                <div class="form-field u-inline-79a1c5a5db">
                    <label class="u-inline-e925a44577">Текст новости (Визуальный редактор)</label>
                    <textarea class="u-inline-62b266a36b" name="content" data-wysiwyg><?= htmlspecialchars($news['content'] ?? '', ENT_QUOTES) ?></textarea>
                </div>

                <div class="form-grid-2col u-inline-001013efee">
                    <div class="form-field">
                        <label class="u-inline-e925a44577">Хештеги (#hashtags)</label>
                        <input type="text" name="hashtags" value="<?= htmlspecialchars($news['hashtags'] ?? '', ENT_QUOTES) ?>" placeholder="#культура, #ташкент, #событие">
                    </div>
                    <div class="form-field">
                        <label class="u-inline-e925a44577" for="source_note">Подпись источника</label>
                        <input type="text" id="source_note" name="source_note" value="<?= htmlspecialchars($news['source_note'] ?? '', ENT_QUOTES) ?>" placeholder="Подготовлено пресс-службой Агентства">
                    </div>
                </div>
            </div>

            <!-- Блок 2: Видео, Аудио и Фотогалерея статьи -->
            <div class="form-card u-inline-8a43589152">
                <?= \App\Core\AdminUi::cardHeader('2. Дополнительные медиа (Видео, Аудио, Галерея)', 'media', 'var(--admin-info)') ?>

                <div class="form-grid u-inline-210f32db0a">
                    <!-- Ссылка на видео -->
                    <div class="form-field">
                        <label class="u-inline-1dcf0e84b2" for="video_url">
                            <?= \App\Core\AdminUi::icon('videos', 18) ?>
                            Ссылка на видео (YouTube)
                        </label>
                        <input type="text" id="video_url" name="video_url" value="<?= htmlspecialchars($news['video_url'] ?? '', ENT_QUOTES) ?>" placeholder="https://youtu.be/... или https://youtube.com/watch?v=...">
                        <span class="form-hint">Встраивает интерактивный видеоплеер в новость. При макете «Видео» плеер выводится на месте обложки.</span>
                    </div>

                    <!-- Аудиозапись / Подкаст в 2 колонки -->
                    <div class="form-grid-2col u-inline-85b0fc8a65">
                        <div class="form-field">
                            <label class="u-inline-e925a44577" for="audio_url">Аудиозапись / Подкаст <span class="form-hint">(MP3, AAC, OGG)</span></label>
                            <div class="u-inline-b9bbe540d3">
                                <input type="text" id="audio_url" name="audio_url" value="<?= htmlspecialchars($news['audio_url'] ?? '', ENT_QUOTES) ?>" placeholder="/uploads/... или https://...">
                                <button type="button" class="btn btn--small btn--secondary u-inline-a9efa5449f" data-media-pick data-media-target="#audio_url" data-media-type="audio">Выбрать аудио</button>
                            </div>
                            <span class="form-hint">Выводит аудиоплеер под заголовком новости.</span>
                        </div>

                        <div class="form-field">
                            <label class="u-inline-e925a44577" for="audio_title">Название аудиотрека</label>
                            <input type="text" id="audio_title" name="audio_title" value="<?= htmlspecialchars($news['audio_title'] ?? '', ENT_QUOTES) ?>" placeholder="Аудиоверсия новости">
                        </div>
                    </div>

                    <!-- Фотогалерея -->
                    <div class="form-field u-inline-cbc67de98f" data-media-gallery>
                        <label class="u-inline-1dcf0e84b2">
                            <?= \App\Core\AdminUi::icon('image', 18) ?>
                            Фотогалерея статьи
                        </label>
                        <?php if (!empty($gallery)): ?>
                            <div class="news-gallery-admin u-inline-24384db92e">
                                <?php foreach ($gallery as $gi): ?>
                                    <div class="u-inline-468a6b21bf">
                                        <img class="u-inline-8504468b68" src="<?= htmlspecialchars((string) $gi['path'], ENT_QUOTES) ?>" alt="">
                                        <input class="u-inline-58ee86c404" type="text" name="gallery[<?= (int) $gi['id'] ?>][caption]" value="<?= htmlspecialchars((string) ($gi['caption'] ?? ''), ENT_QUOTES) ?>" maxlength="255" placeholder="Подпись под фото">
                                        <input class="u-inline-58ee86c404" type="text" name="gallery[<?= (int) $gi['id'] ?>][credit]" value="<?= htmlspecialchars((string) ($gi['credit'] ?? ''), ENT_QUOTES) ?>" maxlength="255" placeholder="Автор или источник: пресс-служба Агентства">
                                        <input class="u-inline-58ee86c404" type="text" name="gallery[<?= (int) $gi['id'] ?>][alt]" value="<?= htmlspecialchars((string) ($gi['alt_text'] ?? ''), ENT_QUOTES) ?>" placeholder="alt-текст (для незрячих и поиска)">
                                        <div class="u-inline-c2a25f11a5">
                                            <input class="u-inline-ac5d34f7f3" type="number" name="gallery[<?= (int) $gi['id'] ?>][sort]" value="<?= (int) $gi['sort_order'] ?>" title="Порядок сортировки" placeholder="№">
                                            <input class="u-inline-847548d18c" type="number" name="gallery[<?= (int) $gi['id'] ?>][focal_x]" min="0" max="100" value="<?= htmlspecialchars((string) ($gi['focal_x'] ?? ''), ENT_QUOTES) ?>" placeholder="fx %" title="Фокус X %">
                                            <input class="u-inline-847548d18c" type="number" name="gallery[<?= (int) $gi['id'] ?>][focal_y]" min="0" max="100" value="<?= htmlspecialchars((string) ($gi['focal_y'] ?? ''), ENT_QUOTES) ?>" placeholder="fy %" title="Фокус Y %">
                                        </div>
                                        <label class="u-inline-ecc48d0b89">
                                            <input type="checkbox" name="gallery[<?= (int) $gi['id'] ?>][delete]" value="1"> удалить
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif (!$isEdit): ?>
                            <span class="form-hint u-inline-9f398c70f1">Сохраните новость, чтобы управлять галереей.</span>
                        <?php endif; ?>
                        <div class="file-preview" data-media-gallery-selection hidden></div>
                        <div data-file-preview>
                            <div class="u-inline-ef7f355c70">
                                <button type="button" class="btn btn--small btn--secondary" data-media-gallery-pick>
                                    <?= \App\Core\AdminUi::icon('photo-plus', 16) ?>
                                    Из медиабиблиотеки
                                </button>
                                <input class="u-inline-e596099155" type="file" name="news_gallery[]" accept="image/*" multiple data-file-preview-input>
                                <span class="form-hint u-inline-1da9facb4d">Можно выбрать несколько фото из библиотеки или напрямую с компьютера. Новые файлы сжимаются в WebP.</span>
                            </div>
                            <!-- Превью выбранного до сохранения: подписи и порядок
                                 появятся после загрузки, но что выбрано — видно сразу. -->
                            <div class="file-preview" data-file-preview-list hidden></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Блок 3: Тезисы и Мероприятие -->
            <div class="form-grid-2col u-inline-61286d69bd">
                <div class="form-card u-inline-6c9f9d2560">
                    <div class="u-inline-50960b108b">
                        <span class="admin-section-icon admin-section-icon--success"><?= \App\Core\AdminUi::icon('info', 20) ?></span>
                        <h4 class="u-inline-3696404b96">Ключевые тезисы</h4>
                    </div>
                    <textarea name="key_points" rows="4" placeholder="Главный вывод новости 1&#10;Главный вывод новости 2&#10;Ключевая цифра или цитата"><?= htmlspecialchars($keyPoints, ENT_QUOTES) ?></textarea>
                    <span class="form-hint">Каждый тезис с новой строки.</span>
                </div>

                <div class="form-card u-inline-6c9f9d2560">
                    <div class="u-inline-50960b108b">
                        <span class="admin-section-icon admin-section-icon--violet"><?= \App\Core\AdminUi::icon('calendar', 20) ?></span>
                        <h4 class="u-inline-3696404b96">О мероприятии</h4>
                    </div>
                    <textarea name="event_meta" rows="4" placeholder="Дата: 25 Июля 2026, 14:00&#10;Место: Дворец Симпозиумов&#10;Организатор: Министерство Культуры"><?= htmlspecialchars($eventMeta, ENT_QUOTES) ?></textarea>
                    <span class="form-hint">Формат: Название: Значение.</span>
                </div>
            </div>

            <!-- Блок 4: Таймлайн и Интерактивный опрос -->
            <div class="form-card u-inline-8a43589152">
                <?= \App\Core\AdminUi::cardHeader('4. Опрос и Хронология событий', 'clock', 'var(--admin-violet)') ?>

                <div class="form-grid">
                    <div class="form-field">
                        <label class="u-inline-e925a44577">Вопрос для читателей (Опрос)</label>
                        <input type="text" name="poll_question" value="<?= htmlspecialchars($existingPoll['question'] ?? '', ENT_QUOTES) ?>" placeholder="Поддерживаете ли вы эту инициативу?">
                    </div>
                    <div class="form-field">
                        <label class="u-inline-e925a44577">Варианты ответов (по одному на строку)</label>
                        <textarea name="poll_options" rows="3" placeholder="Да&#10;Нет&#10;Затрудняюсь ответить"><?= htmlspecialchars(implode("\n", $existingPoll['options'] ?? []), ENT_QUOTES) ?></textarea>
                        <span class="form-hint">Оставьте пустым, если опрос не требуется.</span>
                    </div>
                    <?php if (!empty($existingPoll)): ?>
                        <?php $pollStats = \App\Models\NewsPoll::getResults((int) $existingPoll['id'], $existingPoll['options']); ?>
                        <div class="form-field admin-poll-results">
                            <strong class="admin-poll-results__title"><?= \App\Core\AdminUi::icon('stats') ?>Результаты голосования читателей (Всего проголосовало: <?= (int) ($pollStats['total'] ?? 0) ?> чел.)</strong>
                            <?php if (($pollStats['total'] ?? 0) === 0): ?>
                                <span class="form-hint">Голосов пока нет. Посетители смогут голосовать прямо в статье на сайте.</span>
                            <?php else: ?>
                                <?php foreach (($pollStats['items'] ?? []) as $item): ?>
                                    <div class="u-inline-c2e0f45886">
                                        <div class="u-inline-c7cac707db">
                                            <span><?= htmlspecialchars((string) ($item['label'] ?? $item['option'] ?? ''), ENT_QUOTES) ?></span>
                                            <span><?= (int) $item['votes'] ?> гол. (<?= (int) $item['percent'] ?>%)</span>
                                        </div>
                                        <div class="admin-progress">
                                            <div class="admin-progress__bar" data-progress-width="<?= max(0, min(100, (int) $item['percent'])) ?>"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="form-field u-inline-d5b43342a2">
                        <label class="u-inline-e925a44577">Хронология событий (Таймлайн)</label>
                        <textarea name="timeline_raw" rows="4" placeholder="10 Июля 2026 | Анонс проекта | Выпущено официальное заявление&#10;15 Июля 2026 | Начало работ | Подписан договор с подрядчиком"><?= htmlspecialchars($timelineText, ENT_QUOTES) ?></textarea>
                        <span class="form-hint">Формат каждой строки: <code>Дата | Заголовок | Краткий текст</code>. Выводится красивой цепочкой событий.</span>
                    </div>
                </div>
            </div>

            <!-- Блок 5: Прикреплённые документы -->
            <div class="form-card u-inline-8a43589152">
                <?= \App\Core\AdminUi::cardHeader(
                    '5. Прикреплённые документы',
                    'document',
                    'var(--admin-info)',
                    '<button type="button" class="btn btn--small btn--secondary" data-add-doc-row="' . htmlspecialchars($defaultCode, ENT_QUOTES) . '">+ Добавить документ</button>'
                ) ?>
                <div class="docs-container u-inline-2e1ca338d7" data-docs-container="<?= htmlspecialchars($defaultCode, ENT_QUOTES) ?>">
                    <?php foreach ($docs as $idx => $doc): ?>
                        <div class="doc-item-row u-inline-d0009063f3">
                            <input type="text" name="docs[<?= $idx ?>][title]" value="<?= htmlspecialchars($doc['title'] ?? '', ENT_QUOTES) ?>" placeholder="Название документа (например: Указ №124)">
                            <input type="text" name="docs[<?= $idx ?>][meta]" value="<?= htmlspecialchars($doc['meta'] ?? '', ENT_QUOTES) ?>" placeholder="PDF, 2.4 МБ">
                            <div class="u-inline-1d8943fa86">
                                <input type="text" name="docs[<?= $idx ?>][url]" value="<?= htmlspecialchars($doc['url'] ?? '', ENT_QUOTES) ?>" placeholder="/uploads/... или https://">
                                <button type="button" class="btn btn--small btn--secondary" data-media-pick data-media-target="[name='docs[<?= $idx ?>][url]']" data-media-type="all_files">Выбрать</button>
                            </div>
                            <button type="button" class="btn btn--small btn--danger u-inline-22ca54a87e" data-remove-closest=".doc-item-row" title="Удалить"><?= \App\Core\AdminUi::icon('x', 14) ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Блок 6: SEO Оптимизация -->
            <div class="form-card">
                <?= \App\Core\AdminUi::cardHeader('6. SEO Оптимизация', 'seo', 'var(--admin-success)') ?>
                <div class="form-grid-2col u-inline-001013efee">
                    <div class="form-field">
                        <div class="u-inline-a42388f688">
                            <label class="u-inline-e925a44577">SEO: Meta Title</label>
                            <button type="button" class="btn btn--sm btn--secondary" data-ai-generate="meta_title"><?= \App\Core\AdminUi::icon('sparkles') ?>ИИ-заголовок</button>
                        </div>
                        <input type="text" name="meta_title" maxlength="60" value="<?= htmlspecialchars($news['meta_title'] ?? '', ENT_QUOTES) ?>" placeholder="SEO Заголовок для поисковиков">
                    </div>
                    <div class="form-field">
                        <div class="u-inline-a42388f688">
                            <label class="u-inline-e925a44577">SEO: Meta Description</label>
                            <button type="button" class="btn btn--sm btn--secondary" data-ai-generate="meta_description"><?= \App\Core\AdminUi::icon('sparkles') ?>ИИ-описание</button>
                        </div>
                        <input type="text" name="meta_description" maxlength="160" value="<?= htmlspecialchars($news['meta_description'] ?? '', ENT_QUOTES) ?>" placeholder="SEO Краткое описание для поисковиков">
                    </div>
                </div>

                <?= \App\Core\AdminUi::seoPreviewBox($news ?? []) ?>
            </div>
        </div>

        <!-- Правая колонка настройки публикации -->
        <aside class="entry-side">
            <?= \App\Core\TranslationGroupHelper::renderSidebarMetaBox('news', $news ?? []) ?>

            <!-- Рубрика и метка: свойства публикации, а не текст статьи, поэтому
                 стоят в сайдбаре рядом с языком и обложкой. -->
            <?php
            $newsCategories = \App\Models\NewsCategory::all();
            $selectedCategory = (int) ($news['category_id'] ?? 0);
            ?>
            <div class="form-card u-inline-c18e1e5580">
                <h3 class="u-inline-3e52776664">
                    <?= \App\Core\AdminUi::icon('folder', 18) ?>
                    Рубрика и метка
                </h3>

                <div class="form-field">
                    <label for="category_id">Категория</label>
                    <select id="category_id" name="category_id">
                        <option value="0">— без категории —</option>
                        <?php foreach ($newsCategories as $newsCategory): ?>
                            <option value="<?= (int) $newsCategory['id'] ?>" <?= $selectedCategory === (int) $newsCategory['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $newsCategory['name'], ENT_QUOTES) ?><?= (int) $newsCategory['is_active'] === 1 ? '' : ' (скрыта)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="form-hint">
                        Рубрика ленты: фильтр в списке и выборка в блоке «Новости».
                        Общая для всех языковых версий — переводится название самой категории.
                        <a href="/admin/news-categories">Управление категориями</a>.
                    </span>
                </div>

                <div class="form-field">
                    <label for="badge">Метка</label>
                    <input type="text" id="badge" name="badge" list="news-badge-presets" value="<?= htmlspecialchars($news['badge'] ?? '', ENT_QUOTES) ?>" placeholder="Например: Важно">
                    <datalist id="news-badge-presets">
                        <?php foreach (\App\Core\NewsBadge::PRESETS as $badgePreset): ?>
                            <option value="<?= htmlspecialchars($badgePreset, ENT_QUOTES) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <span class="form-hint">Необязательная пометка на карточке. Это не рубрика.</span>
                </div>
                <?= \App\Core\AdminUi::colorField(
                    'badge_color',
                    (string) ($news['badge_color'] ?? ''),
                    'Цвет метки',
                    '#c0392b',
                    'Цвет темы'
                ) ?>
                <p class="form-hint">
                    Цвет текста подбирается автоматически по контрасту с фоном — выбрать нечитаемое сочетание нельзя.
                </p>
            </div>

            <!-- Главная обложка статьи -->
            <div class="form-card u-inline-c18e1e5580">
                <h3 class="u-inline-3e52776664">
                    <?= \App\Core\AdminUi::icon('image', 18) ?>
                    Главная обложка
                </h3>

                <div class="sidebar-cover-widget form-field image-field u-inline-2e1ca338d7" data-image-field>
                    <!-- Превью обложки -->
                    <div class="sidebar-cover-preview" data-image-preview>
                        <?php $hasImg = !empty($news['image']); ?>
                        <img src="<?= htmlspecialchars((string) ($news['image'] ?? ''), ENT_QUOTES) ?>"
                             id="sidebar_cover_img" class="sidebar-cover-image<?= $hasImg ? '' : ' is-hidden' ?>" alt="Обложка">
                        <div id="sidebar_cover_placeholder" class="image-field__placeholder sidebar-cover-placeholder<?= $hasImg ? ' is-hidden' : '' ?>">
                            <span class="u-inline-c8224f825d"><?= \App\Core\AdminUi::icon('image', 36) ?></span>
                            <span class="u-inline-92418d845b">Обложка не выбрана</span>
                        </div>
                    </div>

                    <!-- Индикатор локально выбранного файла -->
                    <div class="u-inline-0cfd3323fe" id="cover_file_badge">
                        <?= \App\Core\AdminUi::icon('check', 14, 'btn__icon', 2.5) ?>
                        <span>Новый файл подготовлен для сохранения</span>
                    </div>

                    <!-- Кнопки выбора, загрузки и удаления СНИЗУ обложки -->
                    <div class="u-inline-78cead6503 sidebar-cover-actions">
                        <button type="button" class="btn btn--small btn--secondary u-inline-8c735c029d" data-media-pick data-media-target="#image_url" data-media-type="image">
                            <?= \App\Core\AdminUi::icon('image', 15) ?>
                            Медиабиблиотека
                        </button>
                        <label class="btn btn--small btn--secondary u-inline-980a1847e5" title="Загрузить файл с компьютера">
                            <?= \App\Core\AdminUi::icon('upload', 15) ?>
                            Загрузить
                            <input class="u-inline-c8be1ccba6" type="file" id="image_file_input" name="image_file" accept="image/*" data-image-file>
                        </label>
                        <button type="button" id="sidebar_cover_clear" class="btn btn--small btn--danger sidebar-cover-clear" data-image-clear title="Удалить обложку"<?= $hasImg ? '' : ' disabled' ?>>
                            <?= \App\Core\AdminUi::icon('trash', 15) ?>
                            Удалить
                        </button>
                    </div>

                    <!-- Поле ввода URL обложки -->
                    <div class="form-field u-inline-1da9facb4d">
                        <input class="u-inline-f6bd23a169" type="text" id="image_url" name="image_url" data-image-input value="<?= htmlspecialchars((string) ($news['image'] ?? ''), ENT_QUOTES) ?>"
                               placeholder="или вставьте URL изображения...">
                    </div>
                </div>
            </div>

            <script nonce="<?= \App\Core\SecurityHeaders::nonce() ?>">
            document.addEventListener('DOMContentLoaded', function () {
                var imgInput = document.getElementById('image_url');
                var fileInput = document.getElementById('image_file_input');
                var coverImg = document.getElementById('sidebar_cover_img');
                var placeholder = document.getElementById('sidebar_cover_placeholder');
                var clearBtn = document.getElementById('sidebar_cover_clear');
                var fileBadge = document.getElementById('cover_file_badge');

                function updateCoverPreview(url, isFromFile) {
                    var val = url ? url.trim() : '';
                    if (val !== '') {
                        if (coverImg) { coverImg.src = val; coverImg.style.display = 'block'; }
                        if (placeholder) placeholder.style.display = 'none';
                        if (clearBtn) clearBtn.disabled = false;
                        if (fileBadge) fileBadge.style.display = isFromFile ? 'flex' : 'none';
                    } else {
                        if (coverImg) { coverImg.src = ''; coverImg.style.display = 'none'; }
                        if (placeholder) placeholder.style.display = 'block';
                        if (clearBtn) clearBtn.disabled = true;
                        if (fileBadge) fileBadge.style.display = 'none';
                    }
                }

                if (imgInput) {
                    ['input', 'change', 'keyup', 'paste'].forEach(function (evt) {
                        imgInput.addEventListener(evt, function () { updateCoverPreview(imgInput.value, false); });
                    });

                    // Мгновенная реакция при вызове из MediaPicker (JS setter)
                    try {
                        var descriptor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
                        if (descriptor && descriptor.set) {
                            var originalSet = descriptor.set;
                            Object.defineProperty(imgInput, 'value', {
                                set: function (val) {
                                    originalSet.call(this, val);
                                    updateCoverPreview(val, false);
                                },
                                get: function () {
                                    return descriptor.get.call(this);
                                }
                            });
                        }
                    } catch (e) {}
                }

                if (fileInput) {
                    fileInput.addEventListener('change', function () {
                        if (fileInput.files && fileInput.files[0]) {
                            var reader = new FileReader();
                            reader.onload = function (e) {
                                updateCoverPreview(e.target.result, true);
                            };
                            reader.readAsDataURL(fileInput.files[0]);
                        }
                    });
                }

                if (clearBtn) {
                    clearBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        if (imgInput) { imgInput.value = ''; }
                        if (fileInput) { fileInput.value = ''; }
                        updateCoverPreview('', false);
                    });
                }
            });
            </script>

            <!-- Параметры публикации и макета -->
            <div class="form-card u-inline-c18e1e5580">
                <h3 class="u-inline-9eb536fb16">Параметры публикации</h3>
                <div class="form-grid u-inline-714787533b">
                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af" for="status">Статус</label>
                        <select id="status" name="status">
                            <option value="draft" <?= ($news['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Черновик</option>
                            <option value="published" <?= ($news['status'] ?? '') === 'published' ? 'selected' : '' ?>>Опубликовано</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af" for="published_at">Дата публикации</label>
                        <input type="datetime-local" id="published_at" name="published_at" value="<?= htmlspecialchars($publishedAtValue, ENT_QUOTES) ?>">
                    </div>
                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af" for="layout_type">Тип макета статьи</label>
                        <select id="layout_type" name="layout_type">
                            <?php foreach ($layoutLabels as $lt => $ltLabel): ?>
                                <option value="<?= $lt ?>" <?= $layout === $lt ? 'selected' : '' ?>><?= htmlspecialchars($ltLabel, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af" for="sidebar_layout">Макет страницы с виджетами</label>
                        <select id="sidebar_layout" name="sidebar_layout">
                            <option value="no_sidebar" <?= ($news['sidebar_layout'] ?? 'right_sidebar') === 'no_sidebar' ? 'selected' : '' ?>>Без сайдбара</option>
                            <option value="left_sidebar" <?= ($news['sidebar_layout'] ?? '') === 'left_sidebar' ? 'selected' : '' ?>>Левый сайдбар</option>
                            <option value="right_sidebar" <?= ($news['sidebar_layout'] ?? 'right_sidebar') === 'right_sidebar' ? 'selected' : '' ?>>Правый сайдбар</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label class="u-inline-0b87e9e0af" for="slug">ЧПУ (slug)</label>
                        <input type="text" id="slug" name="slug" value="<?= htmlspecialchars($slugValue, ENT_QUOTES) ?>" placeholder="сформируется из заголовка при сохранении">
                    </div>
                </div>
            </div>

            <?php
            // Обложка-карточка: поля работают только у макета «Карточка», но
            // прячем их не насовсем — редактор должен видеть, что уже набрано,
            // если макет переключили обратно.
            $cardStats = \App\Core\NewsCard::stats($news['card_stats'] ?? '');
            $cardStats = array_pad($cardStats, \App\Core\NewsCard::MAX_STATS, ['value' => '', 'label' => '']);
            ?>
            <div class="form-card">
                <?= \App\Core\AdminUi::cardHeader('Обложка-карточка', 'photo', 'var(--admin-info)') ?>
                <p class="form-hint">
                    Работает при макете «Карточка». Всё, что здесь набрано, переводится вместе
                    с новостью. В заголовке обложки работает разметка строки: <code>*слово*</code> — выделение,
                    <code>|</code> — принудительный перенос строки.
                </p>
                <div class="form-grid-2col">
                    <div class="form-field">
                        <label for="card_title">Заголовок на обложке</label>
                        <input type="text" id="card_title" name="card_title" maxlength="200"
                               value="<?= htmlspecialchars((string) ($news['card_title'] ?? ''), ENT_QUOTES) ?>"
                               placeholder="пусто — обычный заголовок новости">
                    </div>
                    <div class="form-field">
                        <label for="card_badge">Вторая плашка</label>
                        <input type="text" id="card_badge" name="card_badge" maxlength="100"
                               value="<?= htmlspecialchars((string) ($news['card_badge'] ?? ''), ENT_QUOTES) ?>"
                               placeholder="Стратегический документ">
                        <span class="form-hint">Рядом с обычной меткой, контуром без заливки.</span>
                    </div>
                </div>
                <label>Показатели (до трёх)</label>
                <div class="form-grid-2col">
                    <?php foreach (array_slice($cardStats, 0, \App\Core\NewsCard::MAX_STATS) as $i => $stat): ?>
                        <div class="form-field">
                            <input type="text" name="card_stats[<?= $i ?>][value]" maxlength="24"
                                   value="<?= htmlspecialchars((string) ($stat['value'] ?? ''), ENT_QUOTES) ?>" placeholder="34">
                        </div>
                        <div class="form-field">
                            <input type="text" name="card_stats[<?= $i ?>][label]" maxlength="60"
                                   value="<?= htmlspecialchars((string) ($stat['label'] ?? ''), ENT_QUOTES) ?>" placeholder="нормативных акта">
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-grid-2col">
                    <div class="form-field">
                        <label for="card_signature">Подпись</label>
                        <input type="text" id="card_signature" name="card_signature" maxlength="80"
                               value="<?= htmlspecialchars((string) ($news['card_signature'] ?? ''), ENT_QUOTES) ?>"
                               placeholder="Пресс-служба">
                        <span class="form-hint">Набирается рукописным шрифтом из «Дизайна» → Типографика.</span>
                    </div>
                    <div class="form-field">
                        <label for="card_note">Сноска внизу</label>
                        <input type="text" id="card_note" name="card_note" maxlength="120"
                               value="<?= htmlspecialchars((string) ($news['card_note'] ?? ''), ENT_QUOTES) ?>"
                               placeholder="asdr.uz">
                    </div>
                </div>
            </div>

            <?php require __DIR__ . '/_detail_sidebar.php'; ?>

            <?php if ($isEdit): ?>
                <?php
                $socialPosts = \App\Models\SocialPost::forNews((int) $news['id']);
                $readyNetworks = \App\Core\SocialSettings::readyNetworks();
                $netLabels = ['telegram' => 'Telegram', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'instagram' => 'Instagram'];
                $stBadge = ['sent' => 'published', 'failed' => 'danger', 'pending' => 'draft'];
                ?>
                <div class="form-card u-inline-6c9f9d2560">
                    <h3 class="u-inline-3e8ce2fc5a">Публикация в соцсети</h3>
                    <?php if (empty($readyNetworks)): ?>
                        <p class="form-hint">Ни одна сеть не настроена. Включите их в разделе <a href="/admin/social">«Соцсети»</a>.</p>
                    <?php else: ?>
                        <?php if (!empty($socialPosts)): ?>
                            <table class="data-table u-inline-3ef1fa1aa1">
                                <thead><tr><th>Сеть</th><th>Статус</th><th>Попыток</th><th>Инфо</th></tr></thead>
                                <tbody>
                                    <?php foreach ($socialPosts as $sp): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($netLabels[$sp['network']] ?? $sp['network'], ENT_QUOTES) ?></td>
                                            <td><span class="badge badge--<?= $stBadge[$sp['status']] ?? 'draft' ?>"><?= htmlspecialchars((string) $sp['status'], ENT_QUOTES) ?></span></td>
                                            <td><?= (int) $sp['attempts'] ?></td>
                                            <td><?= htmlspecialchars((string) ($sp['remote_id'] ?: ($sp['last_error'] ?? '')), ENT_QUOTES) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                        <div class="u-inline-ae030cdc90">
                            <div class="u-inline-482f283023">
                                <?= \App\Core\AdminUi::icon('lock', 15) ?>
                                Подтверждение публикации
                            </div>
                            Отправка в Telegram/соцсети выполняется <strong>только при явном согласии администратора</strong> (отметьте флажок при сохранении в нижней панели или нажмите кнопку отправки ниже).
                        </div>
                        <div class="news-social__btns u-inline-e4ad4a163b">
                            <?php foreach ($readyNetworks as $net): ?>
                                <button type="submit" form="news-social-form" name="network" value="<?= htmlspecialchars($net, ENT_QUOTES) ?>"
                                        class="btn btn--small btn--social btn--social-<?= htmlspecialchars($net, ENT_QUOTES) ?>">
                                    <?= \App\Core\AdminUi::icon($net, 16) ?>
                                    <span><?= htmlspecialchars($netLabels[$net] ?? ucfirst($net), ENT_QUOTES) ?></span>
                                </button>
                            <?php endforeach; ?>
                            <?php if (count($readyNetworks) > 1): ?>
                                <button type="submit" form="news-social-form" class="btn btn--small btn--secondary"><?= \App\Core\AdminUi::icon('send', 16) ?> <span>Во все сети</span></button>
                            <?php endif; ?>
                        </div>
                        <p class="form-hint u-inline-76084ee4e5">Отправляет мгновенную публикацию в привязанные каналы соцсетей.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</form>

<?php if ($isEdit): ?>
    <form id="news-social-form" method="post" action="/admin/news/<?= (int) $news['id'] ?>/social" hidden>
        <?= Csrf::field() ?>
    </form>
<?php endif; ?>

<script>
document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-add-doc-row]');
    if (btn) {
        var langCode = btn.getAttribute('data-add-doc-row');
        var container = document.querySelector('[data-docs-container="' + langCode + '"]');
        if (container) {
            var count = container.querySelectorAll('.doc-item-row').length;
            var namePrefix = 'docs[' + count + ']';

            var row = document.createElement('div');
            row.className = 'doc-item-row';
            row.style.cssText = 'display:grid;grid-template-columns:1fr 140px 2fr 36px;gap:10px;align-items:center;background:color-mix(in srgb, var(--admin-border,#e2e8f0) 25%, transparent);padding:10px 14px;border-radius:8px;border:1px solid var(--admin-border,#e2e8f0);';
            row.innerHTML = '<input type="text" name="' + namePrefix + '[title]" placeholder="Название документа">' +
                '<input type="text" name="' + namePrefix + '[meta]" placeholder="PDF, 2.4 МБ">' +
                '<div class="u-inline-1d8943fa86"><input type="text" name="' + namePrefix + '[url]" placeholder="/uploads/... или https://"><button type="button" class="btn btn--small btn--secondary" data-media-pick data-media-target="[name=\'' + namePrefix + '[url]\']" data-media-type="all_files">Выбрать</button></div>' +
                '<button type="button" class="btn btn--small btn--danger u-inline-22ca54a87e" data-remove-closest=".doc-item-row" title="Удалить">Удалить</button>';
            container.appendChild(row);
        }
    }
});
</script>

<div class="form-actions form-actions--sticky">
    <div class="form-actions-left">
        <?php $nStatus = $news['status'] ?? 'draft'; ?>
        <span class="badge badge--<?= $nStatus === 'published' ? 'success' : 'draft' ?> u-inline-ec08abfabe">
            <span class="publication-status-dot publication-status-dot--<?= $nStatus === 'published' ? 'published' : 'draft' ?>"></span>
            <?= $nStatus === 'published' ? 'Опубликовано' : 'Черновик' ?>
        </span>
        <?php if (!empty($news['language_code'])): ?>
            <span class="u-inline-c1de996030">
                <?= \App\Core\AdminUi::icon('globe', 14) ?>
                Язык: <strong><?= strtoupper(htmlspecialchars((string) $news['language_code'], ENT_QUOTES)) ?></strong>
            </span>
        <?php endif; ?>

        <?php if (!empty(\App\Core\SocialSettings::readyNetworks())): ?>
            <label class="news-social-toggle" title="Отправить публикацию в привязанные каналы (Telegram и др.) только при явном подтверждении">
                <input type="checkbox" name="publish_to_social" value="1" form="news_edit_form">
                <span class="news-social-toggle__icon"><?= \App\Core\AdminUi::icon('send', 15) ?></span>
                <span>Опубликовать в соцсетях</span>
            </label>
            <label class="u-inline-7519b07094 news-schedule" title="Пусто — отправить при ближайшем запуске воркера">
                <?= \App\Core\AdminUi::icon('clock', 14) ?>
                <span>Отложить до</span>
                <input type="datetime-local" name="schedule_at" form="news_edit_form"
                       min="<?= htmlspecialchars(date('Y-m-d\TH:i'), ENT_QUOTES) ?>">
            </label>
            <?php if ($isEdit): ?>
                <a href="/admin/news/<?= (int) $news['id'] ?>/social-preview" class="btn btn--sm btn--secondary"
                   target="_blank" rel="noopener"
                   title="Показать, что уйдёт в канал: языки, снимки, длина текста">
                    <?= \App\Core\AdminUi::icon('eye', 14) ?>Пост в Telegram
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="form-actions-right">
        <?php if ($nStatus !== 'published'): ?>
            <button type="submit" name="publish_action" value="1" form="news_edit_form" class="btn btn--primary"><?= \App\Core\AdminUi::icon('check') ?>Опубликовать</button>
            <button type="submit" form="news_edit_form" class="btn"><?= \App\Core\AdminUi::icon('save') ?>Сохранить черновик</button>
        <?php else: ?>
            <button type="submit" form="news_edit_form" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить изменения</button>
        <?php endif; ?>
        <a href="/admin/news" class="btn">Отмена</a>
        <?php if ($isEdit): ?>
            <?php
            // Предпросмотр рендерит запись под /admin, поэтому работает и для
            // черновика. Ссылка «Открыть на сайте» ведёт на публичный адрес и
            // имеет смысл только у опубликованной записи — у черновика она
            // привела бы на 404. Языковой префикс берём из самой записи:
            // у узбекской новости публичный адрес начинается с /uz.
            $publicPath = $slugValue !== ''
                ? \App\Core\Locale::prefix((string) ($news['lang'] ?? '')) . '/news/' . $slugValue
                : '';
            ?>
            <a href="/admin/news/<?= (int) $news['id'] ?>/preview" class="btn btn--outline u-inline-a512aee2ba" target="_blank" rel="noopener">
                <?= \App\Core\AdminUi::icon('eye', 14) ?>
                Предпросмотр ↗
            </a>
            <?php if ($nStatus === 'published' && $publicPath !== ''): ?>
                <a href="<?= htmlspecialchars($publicPath, ENT_QUOTES) ?>" class="btn btn--outline u-inline-a512aee2ba" target="_blank" rel="noopener">
                    <?= \App\Core\AdminUi::icon('external-link', 14) ?>
                    Открыть на сайте ↗
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
