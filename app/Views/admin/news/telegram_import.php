<?php

use App\Core\AdminUi;
use App\Core\Asset;
use App\Core\Csrf;

/** @var string $inbox */
/** @var array<string, mixed>|null $summary */
/** @var string $problem */

$pageTitle = 'Импорт из Telegram-канала';
$activeNav = 'news';
$pageActions = '<a href="/admin/news" class="btn">' . AdminUi::icon('arrow-left') . 'К списку новостей</a>';
require __DIR__ . '/../layout/header.php';

$langNames = ['ru' => 'Русский', 'uz' => 'Oʻzbekcha', 'en' => 'English'];
?>

<div class="form-card">
    <?= AdminUi::cardHeader('1. Выгрузите канал и положите на сервер', 'telegram') ?>
    <ol class="form-hint">
        <li>В <strong>Telegram Desktop</strong> (приложение для компьютера) откройте канал → «⋮» → <strong>«Экспорт истории канала»</strong>.</li>
        <li>Оставьте галочку <strong>«Фотографии»</strong>, видео и файлы снимите. Формат — <strong>«Машиночитаемый JSON»</strong>. Период — за всё время или нужный.</li>
        <li>Telegram сохранит папку <code>ChatExport_…</code> с файлом <code>result.json</code> и папкой <code>photos</code>. Заархивируйте её.</li>
        <li>В файловом менеджере хостинга создайте папку <code><?= htmlspecialchars($inbox, ENT_QUOTES) ?></code> (внутри каталога сайта), загрузите туда архив и распакуйте.</li>
    </ol>
    <p class="form-hint">
        Папка закрыта от посетителей. По ходу импорта фотографии
        <strong>переносятся</strong> из неё в медиатеку, поэтому она пустеет.
        После импорта папку можно удалить.
    </p>
</div>

<div class="form-card admin-mt-24">
    <?= AdminUi::cardHeader('2. Что найдено', 'list-search') ?>
    <?php if ($summary === null): ?>
        <div class="alert alert--error"><?= htmlspecialchars($problem, ENT_QUOTES) ?></div>
        <p class="form-hint">Разложите выгрузку по инструкции выше и обновите страницу.</p>
    <?php else: ?>
        <p>
            <?php if ((string) $summary['channel'] !== ''): ?>Канал «<?= htmlspecialchars((string) $summary['channel'], ENT_QUOTES) ?>», <?php endif; ?>
            файл <code><?= htmlspecialchars((string) $summary['file'], ENT_QUOTES) ?></code>:
            постов к переносу — <strong><?= (int) $summary['posts'] ?></strong>
            <?php if ((string) $summary['from'] !== ''): ?>
                (с <?= htmlspecialchars((string) $summary['from'], ENT_QUOTES) ?> по <?= htmlspecialchars((string) $summary['to'], ENT_QUOTES) ?>)
            <?php endif; ?>,
            фотографий — <strong><?= (int) $summary['photos'] ?></strong>
            <?php if ((int) $summary['already'] > 0): ?>,
                уже перенесено — <strong><?= (int) $summary['already'] ?></strong>
            <?php endif; ?>.
        </p>
        <?php if ((array) $summary['langs'] !== []): ?>
            <p class="form-hint">
                По языкам:
                <?php $parts = []; foreach ((array) $summary['langs'] as $code => $count) {
                    $parts[] = htmlspecialchars($langNames[(string) $code] ?? strtoupper((string) $code), ENT_QUOTES) . ' — ' . (int) $count;
                } echo implode(', ', $parts); ?>.
                Язык поста определяется по тексту; пост на двух языках сразу попадёт в один.
            </p>
        <?php endif; ?>
        <?php if ((int) $summary['skipped'] > 0 || (int) $summary['videos'] > 0): ?>
            <p class="form-hint">
                Не переносятся:
                <?php if ((int) $summary['skipped'] > 0): ?>репосты из других каналов, опросы и сообщения без текста — <?= (int) $summary['skipped'] ?><?php endif; ?><?php if ((int) $summary['skipped'] > 0 && (int) $summary['videos'] > 0): ?>; <?php endif; ?>
                <?php if ((int) $summary['videos'] > 0): ?>видео — <?= (int) $summary['videos'] ?> (текст поста переносится, сам ролик — нет)<?php endif; ?>.
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($summary !== null): ?>
<div class="form-card admin-mt-24">
    <?= AdminUi::cardHeader('3. Перенос', 'database-import') ?>
    <p class="form-hint">
        Каждый пост станет <strong>черновиком</strong> новости с датой поста:
        первая строка — заголовок, остальное — текст, первое фото — обложка,
        несколько фото — галерея, хештеги — в поле хештегов. Пост, уже
        опубликованный на сайте под тем же заголовком, пропускается. Перенос
        идёт пакетами и останавливается в любой момент — повторный запуск
        продолжит без дублей. Проверенные черновики можно опубликовать разом
        в списке новостей.
    </p>
    <?= Csrf::field() ?>
    <div class="form-actions" data-batch-task="telegram" data-endpoint="/admin/news/telegram-import/run">
        <button type="button" class="btn btn--small" data-batch="dry">
            <?= AdminUi::icon('search') ?>Проверить
        </button>
        <button type="button" class="btn btn--small btn--primary" data-batch="run">
            <?= AdminUi::icon('database-import') ?>Импортировать
        </button>
        <button type="button" class="btn btn--small" data-batch="stop" hidden>Остановить</button>
    </div>
    <div class="admin-progress" data-batch-progress="telegram" hidden>
        <div class="admin-progress__bar" data-batch-bar></div>
    </div>
    <p class="form-hint" data-batch-status="telegram" aria-live="polite" hidden></p>
    <ul class="form-hint" data-batch-details="telegram" hidden></ul>
</div>
<?php endif; ?>

<script src="<?= htmlspecialchars(Asset::url('/assets/js/admin-media-batch.js'), ENT_QUOTES) ?>"></script>
<?php require __DIR__ . '/../layout/footer.php'; ?>
