<?php

use App\Core\AdminUi;
use App\Core\Auth;
use App\Core\Csrf;
use App\Models\Language;

$pageTitle = 'Видео';
$activeNav = 'videos';
require __DIR__ . '/../layout/header.php';

/** @var array $items */
/** @var array $youtube */
/** @var bool $youtubeConfigured */
$appRoot = defined('APP_ROOT') ? APP_ROOT : '/path/to/site';
$langs = Language::active();
$siteLangs = array_map(static fn (array $l): string => (string) $l['code'], $langs);
$langMap = \App\Models\Video::availableLangsForIds(array_map(static fn ($i): int => (int) $i['id'], $items));
?>
<p class="form-hint">Видео можно выводить на главной в блоке «Медиа» (источник «Видео») — отмеченные галочкой «Показать на главной» подтягиваются автоматически.</p>

<details class="form-card" id="youtube-import"<?= $youtubeConfigured ? ' open' : '' ?>>
    <summary><?= AdminUi::icon('brand-youtube') ?> Автозагрузка с YouTube-канала</summary>
    <p class="form-hint">
        С канала берутся название, описание, ссылка и кадр-превью ролика — само видео остаётся
        на YouTube. Повторный проход узнаёт ролик по его id и не создаёт дубль, поэтому импорт
        можно запускать сколько угодно раз. Ролик, заведённый вручную, импорт не дублирует:
        он привязывает существующую карточку к каналу.
    </p>

    <p>
        <?php if ($youtube['last_sync'] !== ''): ?>
            <span class="badge">последний проход: <?= htmlspecialchars($youtube['last_sync'], ENT_QUOTES) ?></span>
            <?php if ($youtube['last_result'] !== ''): ?>
                <span class="form-hint"><?= htmlspecialchars($youtube['last_result'], ENT_QUOTES) ?></span>
            <?php endif; ?>
        <?php else: ?>
            <span class="badge badge--draft">импорт ещё не запускался</span>
        <?php endif; ?>
    </p>

    <?php if ($youtubeConfigured): ?>
        <form method="post" action="/admin/videos/youtube/sync" class="form-actions">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn--primary"><?= AdminUi::icon('refresh') ?>Загрузить сейчас</button>
        </form>
    <?php endif; ?>

    <?php if (Auth::isSuperAdmin()): ?>
        <form method="post" action="/admin/videos/youtube/settings" class="form-grid">
            <?= Csrf::field() ?>
            <div class="form-field">
                <label for="youtube_channel">Канал</label>
                <input type="text" id="youtube_channel" name="youtube_channel"
                       value="<?= htmlspecialchars($youtube['channel'], ENT_QUOTES) ?>"
                       placeholder="https://www.youtube.com/@nazvanie-kanala">
                <span class="form-hint">
                    Ссылка на канал, @псевдоним, id канала UC… или ссылка на плейлист.
                    <?php if ($youtube['channel_id'] !== ''): ?>
                        Найденный id канала: <code><?= htmlspecialchars($youtube['channel_id'], ENT_QUOTES) ?></code>.
                    <?php endif; ?>
                </span>
            </div>
            <div class="form-field">
                <label for="youtube_import_limit">Сколько последних роликов проверять</label>
                <input type="number" id="youtube_import_limit" name="youtube_import_limit" min="1" max="50"
                       value="<?= (int) $youtube['limit'] ?>">
                <span class="form-hint">Без ключа API канал отдаёт последние 15 роликов — большее значение сработает только с ключом.</span>
            </div>
            <div class="form-field">
                <label for="youtube_import_status">Что делать с новыми роликами</label>
                <select id="youtube_import_status" name="youtube_import_status">
                    <option value="draft" <?= $youtube['status'] === 'draft' ? 'selected' : '' ?>>Создавать черновиками (проверю сам)</option>
                    <option value="published" <?= $youtube['status'] === 'published' ? 'selected' : '' ?>>Публиковать сразу</option>
                </select>
            </div>
            <div class="form-field">
                <label for="youtube_import_thumbs">Кадр-превью</label>
                <select id="youtube_import_thumbs" name="youtube_import_thumbs">
                    <option value="local" <?= $youtube['thumbs'] === 'local' ? 'selected' : '' ?>>Скачивать в медиатеку (рекомендуется)</option>
                    <option value="remote" <?= $youtube['thumbs'] === 'remote' ? 'selected' : '' ?>>Ссылаться на картинку YouTube</option>
                </select>
                <span class="form-hint">Скачанный кадр отдаётся с сайта и переживёт смену адресов у YouTube.</span>
            </div>
            <div class="form-field">
                <label for="youtube_api_key">Ключ YouTube Data API (необязательно)</label>
                <input type="password" id="youtube_api_key" name="youtube_api_key" value=""
                       placeholder="<?= $youtube['api_key'] !== '' ? 'Сохранён — оставьте пустым без изменений' : 'AIzaSy…' ?>">
                <span class="form-hint">Нужен только для длительности ролика и выборки больше 15 записей за проход. Без ключа импорт работает по открытой ленте канала.</span>
                <?php if ($youtube['api_key'] !== ''): ?>
                    <label class="form-hint"><input type="checkbox" name="youtube_api_key_clear" value="1"> Удалить сохранённый ключ</label>
                <?php endif; ?>
            </div>
            <div class="form-field form-field--checkbox">
                <input type="checkbox" id="youtube_import_featured" name="youtube_import_featured" value="1" <?= $youtube['featured'] ? 'checked' : '' ?>>
                <label for="youtube_import_featured">Отмечать новые ролики «Показать на главной»</label>
            </div>
            <div class="form-field form-field--checkbox">
                <input type="checkbox" id="youtube_import_update" name="youtube_import_update" value="1" <?= $youtube['update'] ? 'checked' : '' ?>>
                <label for="youtube_import_update">Обновлять название и описание уже загруженных роликов</label>
            </div>
            <p class="form-hint">Пока обновление выключено, правки редактора (в том числе переводы) импорт не трогает.</p>
            <div class="form-field form-field--checkbox">
                <input type="checkbox" id="youtube_import_enabled" name="youtube_import_enabled" value="1" <?= $youtube['enabled'] ? 'checked' : '' ?>>
                <label for="youtube_import_enabled">Загружать автоматически по расписанию (Cron)</label>
            </div>
            <p class="form-hint">
                Расписание задаётся на хостинге — например, раз в час:<br>
                <code>0 * * * * php <?= htmlspecialchars($appRoot, ENT_QUOTES) ?>/app/Console/youtube_worker.php &gt;&gt; <?= htmlspecialchars($appRoot, ENT_QUOTES) ?>/storage/logs/youtube_worker.log 2&gt;&amp;1</code><br>
                Пока задания нет — пользуйтесь кнопкой «Загрузить сейчас».
            </p>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary"><?= AdminUi::icon('save') ?>Сохранить настройки</button>
            </div>
        </form>
    <?php else: ?>
        <p class="form-hint">Настройки импорта меняет супер-администратор.</p>
    <?php endif; ?>
</details>

<div class="form-card u-inline-7dde5e56b3">
    <h2 class="u-inline-291b7bbb01">Новое видео</h2>
    <form method="post" action="/admin/videos/create" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="title">Название</label>
            <input type="text" id="title" name="title" placeholder="Например: Форум «Стратегия-2030»" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Создать и заполнить</button>
        </div>
    </form>
</div>

<?php if (empty($items)): ?>
    <p class="form-hint">Видео пока нет.</p>
<?php else: ?>
    <form id="videos-bulk" method="post" action="/admin/videos/bulk" class="bulk-bar" data-bulk-form>
        <?= Csrf::field() ?>
        <select name="bulk_action" required aria-label="Действие с выбранными">
            <option value="">С выбранными…</option>
            <option value="publish">Опубликовать</option>
            <option value="unpublish">Снять с публикации</option>
            <option value="feature">Показать на главной</option>
            <option value="unfeature">Убрать с главной</option>
            <option value="delete">Удалить</option>
        </select>
        <button type="submit" class="btn">Применить</button>
        <span class="bulk-bar__count" data-bulk-count>0 выбрано</span>
    </form>

    <table class="data-table">
        <thead>
            <tr>
                <th class="u-inline-5aec6ffae3"><input type="checkbox" data-select-all form="videos-bulk" aria-label="Выбрать все"></th>
                <th>Название</th><th>Языки</th><th>Длительность</th><th>Статус</th><th>Дата</th><th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td class="u-inline-5aec6ffae3"><input type="checkbox" name="ids[]" value="<?= (int) $item['id'] ?>" form="videos-bulk" data-bulk-item aria-label="Выбрать видео"></td>
                    <td>
                        <?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?>
                        <?php if ((string) ($item['source'] ?? '') === 'youtube'): ?>
                            <span class="badge" title="Загружено с YouTube-канала"><?= AdminUi::icon('brand-youtube', 13) ?> YouTube</span>
                        <?php endif; ?>
                    </td>
                    <td class="u-inline-a9efa5449f"><?= \App\Core\View::renderPartial('admin/layout/lang_badges', [
                        'siteLangs' => $siteLangs,
                        'has' => $langMap[(int) $item['id']] ?? [],
                        'translationEditUrl' => '/admin/videos/' . (int) $item['id'] . '/edit',
                        'translationDefaultCode' => Language::defaultCode(),
                    ]) ?></td>
                    <td><?= htmlspecialchars((string) ($item['duration'] ?? ''), ENT_QUOTES) ?></td>
                    <td>
                        <?php if ((int) $item['is_published'] === 1): ?>
                            <span class="badge badge--success">Опубликовано</span>
                        <?php else: ?>
                            <span class="badge">Черновик</span>
                        <?php endif; ?>
                        <?php if (!empty($item['is_featured'])): ?><span class="badge badge--success" title="Показывается в блоке «Медиа» на главной"><?= \App\Core\AdminUi::icon('home', 13) ?> на главной</span><?php endif; ?>
                    </td>
                    <td class="u-inline-a9efa5449f"><?= htmlspecialchars((string) (($item['published_at'] ?? '') ?: ($item['created_at'] ?? '')), ENT_QUOTES) ?></td>
                    <td class="data-table__actions">
                        <a href="/admin/videos/<?= (int) $item['id'] ?>/edit" class="btn btn--small btn--icon" title="Редактировать" aria-label="Редактировать"><?= \App\Core\AdminUi::icon('edit') ?></a>
                        <form class="u-inline-0cd28ce9ba" method="post" action="/admin/videos/<?= (int) $item['id'] ?>/delete" data-confirm="Удалить видео «<?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?>»?">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--icon btn--danger" title="Удалить" aria-label="Удалить"><?= \App\Core\AdminUi::icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
