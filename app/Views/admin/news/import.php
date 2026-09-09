<?php

use App\Core\AdminUi;
use App\Core\Asset;
use App\Core\Csrf;

$pageTitle = 'Импорт новостей';
$activeNav = 'news';
$pageActions = '<a href="/admin/news" class="btn">' . AdminUi::icon('arrow-left') . 'К списку новостей</a>';
require __DIR__ . '/../layout/header.php';

/** @var int $maxUploadMb */
/** @var int $batchSeconds */
?>
<link rel="stylesheet" href="<?= htmlspecialchars(Asset::url('/assets/css/admin-news-import.css'), ENT_QUOTES) ?>">

<div class="news-import" data-news-import data-endpoint="/admin/news/import" data-max-mb="<?= (int) $maxUploadMb ?>" data-batch-seconds="<?= (int) $batchSeconds ?>">
    <div class="form-card news-import__intro">
        <?= AdminUi::cardHeader('Импорт из WXR/XML', 'database-import') ?>
        <p>Загрузите стандартный WXR/XML-экспорт старого сайта. Система сначала только проверит файл и покажет план. Запись в базу начнётся только после отдельного подтверждения.</p>
        <div class="news-import__safety">
            <span><?= AdminUi::icon('shield-check', 18) ?></span>
            <div><strong>Безопасный режим</strong><small>Исходные черновики и комментарии не импортируются. Неоднозначные связи переводов блокируют запуск.</small></div>
        </div>
    </div>

    <div class="form-card" data-stage="upload">
        <?= AdminUi::cardHeader('1. Файл экспорта', 'file-upload') ?>
        <form data-upload-form>
            <?= Csrf::field() ?>
            <label class="news-import__drop" data-drop-zone for="wxr_file">
                <span class="news-import__drop-icon"><?= AdminUi::icon('file-type-xml', 34) ?></span>
                <strong>Перетащите WXR/XML сюда</strong>
                <span>или нажмите, чтобы выбрать файл · максимум <?= (int) $maxUploadMb ?> МБ</span>
                <input type="file" id="wxr_file" accept=".xml,text/xml,application/xml" data-file-input>
            </label>
            <div class="news-import__file" data-file-summary hidden>
                <div><strong data-file-name></strong><small data-file-size></small></div>
                <button type="button" class="btn btn--small" data-reset-file>Выбрать другой</button>
            </div>
            <div class="news-import__progress" data-upload-progress hidden>
                <div class="news-import__progress-head"><span>Загрузка XML</span><strong data-upload-percent>0%</strong></div>
                <progress max="100" value="0" data-upload-bar></progress>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn--primary" data-upload-button disabled><?= AdminUi::icon('upload') ?>Загрузить и проверить</button>
            </div>
        </form>
    </div>

    <div class="form-card" data-stage="report" hidden>
        <?= AdminUi::cardHeader('2. Результат проверки', 'clipboard-check') ?>
        <div class="news-import__source"><span>Источник</span><strong data-report-site>—</strong></div>

        <div class="news-import__stats">
            <div class="news-import__stat"><span>Опубликовано в WXR</span><strong data-stat="published">0</strong></div>
            <div class="news-import__stat"><span>Базовых новостей</span><strong data-stat="planned_news">0</strong></div>
            <div class="news-import__stat"><span>Переводов</span><strong data-stat="translations">0</strong></div>
            <div class="news-import__stat"><span>Нужно импортировать</span><strong data-stat="to_import">0</strong></div>
            <div class="news-import__stat"><span>Уже существуют</span><strong data-stat="already_exists">0</strong></div>
            <div class="news-import__stat"><span>Неопределённых</span><strong data-stat="unresolved">0</strong></div>
        </div>

        <div class="news-import__details">
            <div><span>UZ</span><strong data-lang="uz">0</strong></div>
            <div><span>RU</span><strong data-lang="ru">0</strong></div>
            <div><span>EN</span><strong data-lang="en">0</strong></div>
            <div><span>Медиа-вложений</span><strong data-stat="attachments">0</strong></div>
            <div><span>Новостей с gallery</span><strong data-stat="gallery_posts">0</strong></div>
            <div><span>Фото в gallery</span><strong data-stat="gallery_images">0</strong></div>
            <div><span>Черновиков пропущено</span><strong data-stat="drafts">0</strong></div>
            <div><span>Комментариев пропущено</span><strong data-stat="comments">0</strong></div>
        </div>

        <div class="news-import__notice" data-report-ok hidden>
            <?= AdminUi::icon('circle-check', 20) ?>
            <div><strong>Файл готов к импорту</strong><span>Все переводы связаны однозначно. Можно переходить к импорту.</span></div>
        </div>
        <div class="news-import__notice news-import__notice--error" data-report-error hidden>
            <?= AdminUi::icon('alert-triangle', 20) ?>
            <div><strong>Импорт заблокирован</strong><span data-report-error-text></span></div>
        </div>

        <div class="news-import__options" data-import-options hidden>
            <div class="form-field">
                <label for="import_status">Статус новых новостей</label>
                <select id="import_status" data-import-status>
                    <option value="draft" selected>Черновики — рекомендуется</option>
                    <option value="published">Сразу опубликованные</option>
                </select>
                <span class="form-hint">Исходные даты публикации сохраняются в обоих режимах.</span>
            </div>
            <label class="news-import__check">
                <input type="checkbox" checked data-import-backup>
                <span><strong>Создать резервную копию перед импортом</strong><small>Снимается отдельным шагом, до первого пакета. На большой базе дамп может не уложиться в лимит времени сервера — тогда импорт можно продолжить без копии или снять её заранее в «Базах данных».</small></span>
            </label>
        </div>

        <div class="form-actions" data-import-actions hidden>
            <button type="button" class="btn btn--primary" data-start-import><?= AdminUi::icon('database-import') ?>Начать импорт</button>
            <button type="button" class="btn" data-discard>Отменить</button>
        </div>
    </div>

    <div class="form-card" data-stage="import" hidden>
        <?= AdminUi::cardHeader('3. Импорт', 'loader') ?>
        <div class="news-import__progress">
            <div class="news-import__progress-head"><span data-import-status-text>Подготовка…</span><strong data-import-percent>0%</strong></div>
            <progress max="100" value="0" data-import-bar></progress>
        </div>
        <p class="form-hint">Импорт идёт пакетами примерно по <?= (int) $batchSeconds ?> секунд, чтобы не упираться в лимит времени сервера. Не закрывайте вкладку до завершения; если пакет всё же оборвётся, перенесённое сохранится и импорт продолжится с того же места.</p>
        <div class="news-import__running-stats">
            <span>Создано: <strong data-run="imported">0</strong></span>
            <span>Переводов: <strong data-run="translations">0</strong></span>
            <span>Фото: <strong data-run="images">0</strong></span>
            <span>Редиректов: <strong data-run="redirects">0</strong></span>
        </div>
        <div class="news-import__log" data-import-log aria-live="polite"></div>
        <div class="form-actions" data-resume-actions hidden>
            <button type="button" class="btn btn--primary" data-resume-import><?= AdminUi::icon('player-play') ?>Продолжить импорт</button>
            <a href="/admin/news?status=draft" class="btn">Остановиться и открыть новости</a>
        </div>
    </div>

    <div class="form-card" data-stage="done" hidden>
        <?= AdminUi::cardHeader('Импорт завершён', 'circle-check') ?>
        <p data-done-text>Новости перенесены.</p>
        <div class="form-actions">
            <a href="/admin/news?status=draft" class="btn btn--primary">Открыть новости</a>
            <a href="/admin/news/import" class="btn">Новый импорт</a>
        </div>
    </div>

    <div class="news-import__toast" data-error-toast hidden role="alert"></div>
</div>

<script src="<?= htmlspecialchars(Asset::url('/assets/js/admin-news-import.js'), ENT_QUOTES) ?>"></script>
<?php require __DIR__ . '/../layout/footer.php'; ?>
