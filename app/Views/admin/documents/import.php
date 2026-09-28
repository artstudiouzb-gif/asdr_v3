<?php

use App\Core\AdminUi;
use App\Core\Asset;
use App\Core\Csrf;

/** @var string $inbox */
/** @var array<string, mixed>|null $summary */
/** @var string $problem */

$pageTitle = 'Импорт документов';
$activeNav = 'settings';
$pageActions = '<a href="/admin/settings#import-section" class="btn">' . AdminUi::icon('arrow-left') . 'К настройкам</a>';
require __DIR__ . '/../layout/header.php';

$langNames = ['ru' => 'Русский', 'uz' => 'Oʻzbekcha', 'en' => 'English'];
?>

<div class="form-card">
    <?= AdminUi::cardHeader('1. Положите файлы на сервер', 'folder') ?>
    <p class="form-hint">
        Экспорт документов старого сайта содержит только описания — названия,
        рубрики и имена файлов. Сами файлы нужно перенести отдельно:
    </p>
    <ol class="form-hint">
        <li>На старом хостинге заархивируйте папку <code>wp-content/uploads/download-manager-files</code> и скачайте архив.</li>
        <li>В файловом менеджере этого хостинга создайте папку <code><?= htmlspecialchars($inbox, ENT_QUOTES) ?></code> (внутри каталога сайта), загрузите туда архив и распакуйте его. Вложенные папки не мешают.</li>
        <li>Туда же положите XML-экспорт — один файл <code>.xml</code>.</li>
    </ol>
    <p class="form-hint">
        Папка закрыта от посетителей, и выкладка сайта её не трогает. По ходу
        импорта файлы <strong>переносятся</strong> из неё в медиатеку, поэтому
        она пустеет — это нормально: перенос мгновенный при любом размере.
        После импорта папку можно удалить.
    </p>
</div>

<div class="form-card admin-mt-24">
    <?= AdminUi::cardHeader('2. Что найдено', 'list-search') ?>
    <?php if ($summary === null): ?>
        <div class="alert alert--error"><?= htmlspecialchars($problem, ENT_QUOTES) ?></div>
        <p class="form-hint">Разложите файлы по инструкции выше и обновите страницу.</p>
    <?php else: ?>
        <p>
            Файл <code><?= htmlspecialchars((string) $summary['xml'], ENT_QUOTES) ?></code>:
            документов к переносу — <strong><?= (int) $summary['documents'] ?></strong>,
            файлов в папке — <strong><?= (int) $summary['files_on_disk'] ?></strong>
            <?php if ((int) $summary['already'] > 0): ?>,
                уже перенесено — <strong><?= (int) $summary['already'] ?></strong>
            <?php endif; ?>.
        </p>
        <p class="form-hint">
            Каждая рубрика станет блоком «Документы» на черновике страницы
            своего языка. Язык рубрики определяется по её названию (в экспорте
            его нет): кириллица — русский, «Davra suhbati», «yil» — узбекский,
            остальное — английский. Документ из двух рубрик попадёт в обе.
        </p>
        <table class="data-table">
            <thead><tr><th>Рубрика</th><th>Страница</th><th>Документов</th></tr></thead>
            <tbody>
            <?php foreach ((array) $summary['categories'] as $category): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $category['name'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($langNames[(string) $category['lang']] ?? strtoupper((string) $category['lang']), ENT_QUOTES) ?></td>
                    <td><?= (int) $category['count'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ((array) $summary['skipped'] !== []): ?>
            <p class="form-hint admin-mt-12">Не переносятся:</p>
            <ul class="form-hint">
                <?php foreach ((array) $summary['skipped'] as $row): ?>
                    <li><?= htmlspecialchars((string) $row['title'], ENT_QUOTES) ?> — <?= htmlspecialchars((string) $row['reason'], ENT_QUOTES) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($summary !== null): ?>
<div class="form-card admin-mt-24">
    <?= AdminUi::cardHeader('3. Перенос', 'database-import') ?>
    <p class="form-hint">
        Сначала проверьте, все ли файлы нашлись. Перенос идёт пакетами и
        останавливается в любой момент — повторный запуск продолжит без дублей.
        В конце появятся черновики страниц «Документы»; пока страница в
        черновике, повторный импорт (например, после докладки недостающих
        файлов) пересобирает её блоки, опубликованную не трогает. Старые адреса
        <code>/download/…</code> получают редирект на файл.
    </p>
    <?= Csrf::field() ?>
    <div class="form-actions" data-batch-task="documents" data-endpoint="/admin/documents/import/run">
        <button type="button" class="btn btn--small" data-batch="dry">
            <?= AdminUi::icon('search') ?>Проверить файлы
        </button>
        <button type="button" class="btn btn--small btn--primary" data-batch="run">
            <?= AdminUi::icon('database-import') ?>Импортировать
        </button>
        <button type="button" class="btn btn--small" data-batch="stop" hidden>Остановить</button>
    </div>
    <div class="admin-progress" data-batch-progress="documents" hidden>
        <div class="admin-progress__bar" data-batch-bar></div>
    </div>
    <p class="form-hint" data-batch-status="documents" aria-live="polite" hidden></p>
    <ul class="form-hint" data-batch-details="documents" hidden></ul>
</div>
<?php endif; ?>

<script src="<?= htmlspecialchars(Asset::url('/assets/js/admin-media-batch.js'), ENT_QUOTES) ?>"></script>
<?php require __DIR__ . '/../layout/footer.php'; ?>
