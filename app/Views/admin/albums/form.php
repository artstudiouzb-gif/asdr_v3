<?php

use App\Core\Csrf;
use App\Models\Language;

/** @var array $album */
/** @var array $images */
/** @var array $translations */

$pageTitle = 'Альбом: ' . $album['title'];
$activeNav = 'albums';
require __DIR__ . '/../layout/header.php';

$defaultCode = Language::defaultCode();
$translationLangs = array_values(array_filter(
    Language::active(),
    static fn (array $l): bool => (string) $l['code'] !== $defaultCode
));
?>
<p><a href="/admin/albums" class="btn btn--small">← Все альбомы</a>
   <a href="/albums/<?= htmlspecialchars((string) $album['slug'], ENT_QUOTES) ?>" class="btn btn--small" target="_blank" rel="noopener">Открыть на сайте</a></p>

<div class="form-card u-inline-7dde5e56b3">
    <h2 class="u-inline-291b7bbb01">Свойства альбома</h2>
    <form method="post" action="/admin/albums/<?= (int) $album['id'] ?>/update" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="title">Название (<?= htmlspecialchars(strtoupper($defaultCode), ENT_QUOTES) ?> — основной язык)</label>
            <input type="text" id="title" name="title" value="<?= htmlspecialchars((string) $album['title'], ENT_QUOTES) ?>" required>
        </div>
        <div class="form-field">
            <label for="description">Описание (<?= htmlspecialchars(strtoupper($defaultCode), ENT_QUOTES) ?>, необязательно)</label>
            <textarea id="description" name="description" rows="3"><?= htmlspecialchars((string) ($album['description'] ?? ''), ENT_QUOTES) ?></textarea>
        </div>
        <div class="form-field">
            <label for="cover_url">Обложка (URL)</label>
            <input type="text" id="cover_url" name="cover_url" value="<?= htmlspecialchars((string) $album['cover_url'], ENT_QUOTES) ?>">
            <button type="button" class="btn btn--small" data-media-pick data-media-target="[name='cover_url']">Из медиатеки</button>
            <span class="form-hint">Пусто — обложкой станет первое фото альбома.</span>
        </div>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="is_published" name="is_published" value="1" <?= (int) $album['is_published'] === 1 ? 'checked' : '' ?>>
            <label for="is_published">Опубликован (виден на сайте)</label>
        </div>
        <div class="form-field form-field--checkbox">
            <input type="checkbox" id="is_featured" name="is_featured" value="1" <?= (int) ($album['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>>
            <label for="is_featured">Показать на главной (блок «Медиа»)</label>
        </div>
        <?php if ($translationLangs !== []): ?>
            <section id="translations" class="u-inline-d31dcf37c0">
                <h3 class="u-inline-c35d85373e">
                    <?= \App\Core\AdminUi::icon('globe') ?> Переводы альбома
                </h3>
                <p class="form-hint">Заполните название и описание для других активных языков. Пустое поле использует текст основного языка.</p>
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
                            <label for="album-title-<?= htmlspecialchars($code, ENT_QUOTES) ?>">Название (<?= htmlspecialchars(strtoupper($code), ENT_QUOTES) ?>)</label>
                            <input
                                type="text"
                                id="album-title-<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                                name="translations[<?= htmlspecialchars($code, ENT_QUOTES) ?>][title]"
                                value="<?= htmlspecialchars((string) ($translation['title'] ?? ''), ENT_QUOTES) ?>"
                                placeholder="Перевод названия на <?= htmlspecialchars((string) $language['name'], ENT_QUOTES) ?>"
                            >
                        </div>
                        <div class="form-field">
                            <label for="album-description-<?= htmlspecialchars($code, ENT_QUOTES) ?>">Описание (<?= htmlspecialchars(strtoupper($code), ENT_QUOTES) ?>)</label>
                            <textarea
                                id="album-description-<?= htmlspecialchars($code, ENT_QUOTES) ?>"
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

<div class="form-card u-inline-7dde5e56b3" id="album-photos">
    <h2 class="u-inline-291b7bbb01">Фотографии (<?= count($images) ?>)</h2>
    <form method="post" action="/admin/albums/<?= (int) $album['id'] ?>/images/save" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <div class="form-field" data-media-gallery>
            <div class="file-preview" data-media-gallery-selection hidden></div>
            <div data-file-preview>
                <button type="button" class="btn btn--small btn--secondary" data-media-gallery-pick>
                    <?= \App\Core\AdminUi::icon('photo-plus', 16) ?>Из медиатеки
                </button>
                <input type="file" name="album_gallery[]" accept="image/*" multiple data-file-preview-input aria-label="Загрузить фото с компьютера">
                <span class="form-hint">Можно выбрать сразу несколько фото — из медиатеки или с компьютера. Новые снимки встанут в конец альбома, файлы сжимаются в WebP.</span>
                <div class="file-preview" data-file-preview-list hidden></div>
            </div>
        </div>

        <?php if (empty($images)): ?>
            <p class="form-hint">В альбоме пока нет фотографий.</p>
        <?php else: ?>
            <p class="form-hint">Порядок — число: меньшее стоит раньше. Подпись выводится под фото, автор — как «Фото: …» и уходит в подпись снимка в Telegram.</p>
            <div class="u-inline-34f05f7385">
                <?php foreach ($images as $img):
                    $imgId = (int) $img['id'];
                ?>
                    <div class="form-card u-inline-481ed7e754">
                        <img class="u-inline-0021310c98" src="<?= htmlspecialchars((string) $img['image_url'], ENT_QUOTES) ?>" alt="" loading="lazy">
                        <div class="form-field">
                            <label for="caption-<?= $imgId ?>">Подпись</label>
                            <input type="text" id="caption-<?= $imgId ?>" name="caption[<?= $imgId ?>]" maxlength="255" value="<?= htmlspecialchars((string) $img['caption'], ENT_QUOTES) ?>">
                        </div>
                        <div class="form-field">
                            <label for="credit-<?= $imgId ?>">Автор/источник</label>
                            <input type="text" id="credit-<?= $imgId ?>" name="credit[<?= $imgId ?>]" maxlength="255" placeholder="Пресс-служба Агентства" value="<?= htmlspecialchars((string) $img['credit'], ENT_QUOTES) ?>">
                        </div>
                        <div class="form-field">
                            <label for="sort-<?= $imgId ?>">Порядок</label>
                            <input type="number" id="sort-<?= $imgId ?>" name="sort[<?= $imgId ?>]" value="<?= (int) $img['sort_order'] ?>">
                        </div>
                        <label class="form-field--checkbox">
                            <input type="checkbox" name="delete[]" value="<?= $imgId ?>"> Убрать из альбома
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary"><?= \App\Core\AdminUi::icon('save') ?>Сохранить фото</button>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../layout/footer.php'; ?>
