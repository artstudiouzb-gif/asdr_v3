<?php

use App\Core\AdminUi;
use App\Core\Csrf;

$pageTitle = 'Шаблоны страниц';
$activeNav = 'snippets';
require __DIR__ . '/../layout/header.php';

/** @var array<int, array<string, mixed>>|null $snippets */
/** @var array<string, array<int, array{id:int, title:string}>> $targets */
/** @var array<int, array<string, mixed>> $langs */
?>
<?php if ($snippets === null): ?>
    <div class="form-card u-inline-7dde5e56b3">
        <p class="form-hint">Раздел недоступен: не применена миграция базы данных. Выполните <code>php database/migrate.php</code> на сервере.</p>
    </div>
<?php else: ?>
<p class="form-hint">Шаблон — снимок всех блоков одной страницы на одном языке, включая содержимое колонок. Сохраняются они в конструкторе страницы или проекта («Шаблоны страницы» под списком блоков), а здесь видны целиком: состав, вес и дата. Отсюда шаблон скачивается файлом, применяется к любой странице и удаляется. «Автокопия: …» — снимок, снятый автоматически перед заменой блоков; хранятся последние пять.</p>

<div class="form-card u-inline-7dde5e56b3">
    <h2 class="u-inline-291b7bbb01">Загрузить шаблон из файла</h2>
    <form method="post" action="/admin/snippets/import" enctype="multipart/form-data" class="snippet-tools__row">
        <?= Csrf::field() ?>
        <input type="file" name="template" accept="application/json,.json" required aria-label="Файл шаблона">
        <input type="text" name="snippet_name" placeholder="Название (пусто — из файла)">
        <button type="submit" class="btn btn--small"><?= AdminUi::icon('upload') ?>Загрузить</button>
    </form>
    <p class="form-hint">
        Файл — JSON с пометкой <code>artstudio.page-template</code>, такой же, какой отдаёт кнопка «Скачать» в таблице ниже.
        При загрузке проверяются типы блоков и их поля: что не подошло, попадёт в сообщение, а не пропадёт молча.
        <?php if (!\App\Core\Auth::isSuperAdmin()): ?>
            Блок «HTML-код» и «Свой CSS» из файла не принимаются — их правит только супер-администратор.
        <?php endif; ?>
    </p>
</div>

<?php if (!empty($snippets)): ?>
<div class="form-card u-inline-7dde5e56b3">
    <h2 class="u-inline-291b7bbb01">Применить к странице или проекту</h2>
    <form method="post" action="/admin/snippets/apply" class="snippet-tools__row">
        <?= Csrf::field() ?>
        <select name="snippet_id" required aria-label="Шаблон">
            <option value="">— шаблон —</option>
            <?php foreach ($snippets as $s): ?>
                <option value="<?= (int) $s['id'] ?>"><?= htmlspecialchars((string) $s['name'], ENT_QUOTES) ?><?= ($s['summary'] ?? '') !== '' ? ' — ' . htmlspecialchars((string) $s['summary'], ENT_QUOTES) : '' ?></option>
            <?php endforeach; ?>
        </select>
        <select name="page_id" required aria-label="Куда применить">
            <option value="">— страница или проект —</option>
            <?php foreach ($targets as $groupLabel => $rows): ?>
                <?php if ($rows === []) { continue; } ?>
                <optgroup label="<?= htmlspecialchars((string) $groupLabel, ENT_QUOTES) ?>">
                    <?php foreach ($rows as $row): ?>
                        <option value="<?= (int) $row['id'] ?>"><?= htmlspecialchars((string) $row['title'], ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endforeach; ?>
        </select>
        <?php if (count($langs) > 1): ?>
            <select name="block_lang" aria-label="Язык блоков">
                <?php foreach ($langs as $lang): ?>
                    <option value="<?= htmlspecialchars((string) $lang['code'], ENT_QUOTES) ?>"><?= htmlspecialchars((string) $lang['name'], ENT_QUOTES) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <select name="mode" aria-label="Как применить">
            <option value="append">Добавить к текущим блокам</option>
            <option value="replace">Заменить текущие блоки</option>
        </select>
        <button type="submit" class="btn btn--small btn--primary"><?= AdminUi::icon('layout') ?>Применить</button>
    </form>
    <p class="form-hint">Блоки уедут на выбранный язык записи; после применения откроется её конструктор. Перед заменой прежние блоки сохраняются автокопией — вернуть их можно тем же действием с режимом «Заменить».</p>
</div>
<?php endif; ?>

<?php if (empty($snippets)): ?>
    <p class="form-hint">Сохранённых шаблонов пока нет. Откройте страницу или проект, прокрутите конструктор до секции «Шаблоны страницы» и сохраните сборку — она появится здесь.</p>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr><th>Название</th><th>Состав</th><th>Вес</th><th>Создан</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($snippets as $s): ?>
                <?php $name = (string) $s['name']; ?>
                <tr>
                    <td>
                        <form method="post" action="/admin/snippets/<?= (int) $s['id'] ?>/rename" class="snippet-tools__row">
                            <?= Csrf::field() ?>
                            <input type="text" name="name" value="<?= htmlspecialchars($name, ENT_QUOTES) ?>" maxlength="190" required aria-label="Название шаблона">
                            <button type="submit" class="btn btn--small">Переименовать</button>
                        </form>
                        <?php if (!empty($s['is_auto'])): ?>
                            <span class="badge">Автокопия</span>
                        <?php endif; ?>
                        <?php if (!empty($s['is_broken'])): ?>
                            <span class="badge badge--danger">Повреждён</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string) ($s['summary'] ?? ''), ENT_QUOTES) ?></td>
                    <td class="u-inline-a9efa5449f"><?= number_format((int) ($s['bytes'] ?? 0) / 1024, 1, ',', ' ') ?> КБ</td>
                    <td class="u-inline-a9efa5449f"><?= htmlspecialchars((string) ($s['created_at'] ?? '—'), ENT_QUOTES) ?></td>
                    <td class="data-table__actions u-inline-a9efa5449f">
                        <a class="btn btn--small" href="/admin/snippets/export?id=<?= (int) $s['id'] ?>"><?= AdminUi::icon('download') ?>Скачать</a>
                        <form class="u-inline-0cd28ce9ba" method="post" action="/admin/snippets/<?= (int) $s['id'] ?>/delete" data-confirm="Удалить шаблон «<?= htmlspecialchars($name, ENT_QUOTES) ?>»?">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--danger"><?= AdminUi::icon('trash') ?>Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../layout/footer.php'; ?>
