<?php

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Models\Language;

/** @var array $video */
/** @var array $translations */

$pageTitle = 'Видео: ' . $video['title'];
$activeNav = 'videos';
require __DIR__ . '/../layout/header.php';

$defaultCode = Language::defaultCode();
$translationLangs = array_values(array_filter(
    Language::active(),
    static fn (array $l): bool => (string) $l['code'] !== $defaultCode
));
?>
<p><a href="/admin/videos" class="btn btn--small">← Все видео</a></p>

<div class="form-card">
    <h2 class="u-inline-291b7bbb01">Свойства видео</h2>
    <form method="post" action="/admin/videos/<?= (int) $video['id'] ?>/update" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="title">Название (<?= htmlspecialchars(strtoupper($defaultCode), ENT_QUOTES) ?> — основной язык)</label>
            <input type="text" id="title" name="title" value="<?= htmlspecialchars((string) $video['title'], ENT_QUOTES) ?>" required>
        </div>
        <div class="form-field">
            <label for="description">Описание (<?= htmlspecialchars(strtoupper($defaultCode), ENT_QUOTES) ?>)</label>
            <textarea id="description" name="description" rows="3"><?= htmlspecialchars((string) ($video['description'] ?? ''), ENT_QUOTES) ?></textarea>
        </div>
        <?= AdminUi::imageField('cover_url', (string) ($video['cover_url'] ?? ''), [
            'label' => 'Обложка',
            'file' => 'cover_file',
            'hint' => 'Кадр-превью видео. Показывается в блоке «Медиа» на главной.',
        ]) ?>
        <div class="form-field">
            <label for="video_url">Ссылка на видео</label>
            <input type="text" id="video_url" name="video_url" value="<?= htmlspecialchars((string) ($video['video_url'] ?? ''), ENT_QUOTES) ?>" placeholder="https://youtube.com/watch?v=…">
            <span class="form-hint">YouTube или прямая ссылка. По ней открывается видео из карточки.</span>
        </div>
        <div class="form-field">
            <label for="duration">Длительность</label>
            <input type="text" id="duration" name="duration" value="<?= htmlspecialchars((string) ($video['duration'] ?? ''), ENT_QUOTES) ?>" placeholder="напр. 02:35">
        </div>
        <div class="form-field">
            <label for="sort_order">Порядок сортировки</label>
            <input type="number" id="sort_order" name="sort_order" value="<?= (int) ($video['sort_order'] ?? 0) ?>">
        </div>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="is_published" name="is_published" value="1" <?= (int) $video['is_published'] === 1 ? 'checked' : '' ?>>
            <label for="is_published">Опубликовано (видно на сайте)</label>
        </div>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="is_featured" name="is_featured" value="1" <?= (int) ($video['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>>
            <label for="is_featured">Показать на главной (блок «Медиа»)</label>
        </div>
        <?php if ($translationLangs !== []): ?>
            <section id="translations" class="u-inline-d31dcf37c0">
                <h3 class="u-inline-c35d85373e">
                    <?= AdminUi::icon('globe') ?> Переводы видео
                </h3>
                <p class="form-hint">Ссылка, обложка и длительность общие для всех языков. Здесь переводятся название и описание.</p>
                <?php foreach ($translationLangs as $language): ?>
                    <?php
                    $code = (string) $language['code'];
                    $translation = $translations[$code] ?? [];
                    ?>
                    <div class="form-card u-inline-7dde5e56b3">
                        <h4 class="u-inline-c35d85373e">
                            <?= htmlspecialchars((string) $language['name'], ENT_QUOTES) ?>
                            (<?= htmlspecialchars(strtoupper($code), ENT_QUOTES) ?>)
                        </h4>
                        <div class="form-field">
                            <label for="video-title-<?= htmlspecialchars($code, ENT_QUOTES) ?>">Название (<?= htmlspecialchars(strtoupper($code), ENT_QUOTES) ?>)</label>
                            <input
                                type="text"
                                id="video-title-<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                                name="translations[<?= htmlspecialchars($code, ENT_QUOTES) ?>][title]"
                                value="<?= htmlspecialchars((string) ($translation['title'] ?? ''), ENT_QUOTES) ?>"
                                placeholder="Перевод названия на <?= htmlspecialchars((string) $language['name'], ENT_QUOTES) ?>"
                            >
                        </div>
                        <div class="form-field">
                            <label for="video-description-<?= htmlspecialchars($code, ENT_QUOTES) ?>">Описание (<?= htmlspecialchars(strtoupper($code), ENT_QUOTES) ?>)</label>
                            <textarea
                                id="video-description-<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                                name="translations[<?= htmlspecialchars($code, ENT_QUOTES) ?>][description]"
                                rows="3"
                                placeholder="Перевод описания на <?= htmlspecialchars((string) $language['name'], ENT_QUOTES) ?>"
                            ><?= htmlspecialchars((string) ($translation['description'] ?? ''), ENT_QUOTES) ?></textarea>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
        <div class="form-actions form-actions--sticky">
            <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить</button>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
