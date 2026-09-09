<?php

use App\Core\Csrf;

$pageTitle = 'Редиректы';
$activeNav = 'redirects';
require __DIR__ . '/../layout/header.php';

/** @var array $items */
/** @var array $notFound */
/** @var string $prefillFrom */
?>
<p class="form-hint">301/302-редиректы сохраняют посетителей и SEO-вес при переезде со старого сайта: старый адрес автоматически ведёт на новый. Совпадение — по пути (query-строка переносится). Редирект срабатывает раньше страниц, поэтому им можно переопределить и существующий адрес.</p>

<div class="form-card u-inline-7dde5e56b3">
    <h2 class="u-inline-291b7bbb01">Добавить редирект</h2>
    <form method="post" action="/admin/redirects/create" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="from_path">Старый адрес (откуда)</label>
            <input type="text" id="from_path" name="from_path" value="<?= htmlspecialchars($prefillFrom ?? '', ENT_QUOTES) ?>" placeholder="/old-page или https://asdr.gov.uz/old-page" required>
            <span class="form-hint">Можно вставить полный URL старого сайта — возьмётся только путь.</span>
        </div>
        <div class="form-field">
            <label for="to_url">Новый адрес (куда)</label>
            <input type="text" id="to_url" name="to_url" placeholder="/new-page или https://site.uz/new" required>
        </div>
        <div class="form-field">
            <label for="code">Тип</label>
            <select id="code" name="code">
                <option value="301">301 — постоянный (переезд навсегда, для SEO)</option>
                <option value="302">302 — временный</option>
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn--primary">Добавить</button>
        </div>
    </form>
</div>

<div class="form-card u-inline-7dde5e56b3">
    <h2 class="u-inline-291b7bbb01">Массовый импорт</h2>
    <form method="post" action="/admin/redirects/import" class="form-grid">
        <?= Csrf::field() ?>
        <div class="form-field">
            <label for="list">Список (по одному редиректу в строке)</label>
            <textarea id="list" name="list" rows="6" placeholder="/old-1 /new-1&#10;/old-2 -> /new-2 302&#10;https://asdr.gov.uz/page /o-nas"></textarea>
            <span class="form-hint">Формат: «откуда куда [302]», разделитель — пробел или «-&gt;». Строки с «#» — комментарии. Дубли и ошибки пропускаются.</span>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Импортировать</button>
        </div>
    </form>
</div>

<?php if (empty($items)): ?>
    <p class="form-hint">Редиректов пока нет.</p>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr><th>Откуда</th><th>Куда</th><th>Тип</th><th>Переходов</th><th>Последний</th><th>Статус</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><code class="u-inline-e71ae94b55"><?= htmlspecialchars((string) $item['from_path'], ENT_QUOTES) ?></code></td>
                    <td><code class="u-inline-e71ae94b55"><?= htmlspecialchars((string) $item['to_url'], ENT_QUOTES) ?></code></td>
                    <td><?= (int) $item['code'] ?></td>
                    <td><?= (int) $item['hits'] ?></td>
                    <td class="u-inline-a9efa5449f"><?= htmlspecialchars((string) ($item['last_hit_at'] ?? '—'), ENT_QUOTES) ?></td>
                    <td>
                        <?php if ((int) $item['is_active'] === 1): ?>
                            <span class="badge badge--success">Активен</span>
                        <?php else: ?>
                            <span class="badge">Выключен</span>
                        <?php endif; ?>
                    </td>
                    <td class="data-table__actions u-inline-a9efa5449f">
                        <form class="u-inline-0cd28ce9ba" method="post" action="/admin/redirects/<?= (int) $item['id'] ?>/toggle">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="active" value="<?= (int) $item['is_active'] === 1 ? '0' : '1' ?>">
                            <button type="submit" class="btn btn--small"><?= (int) $item['is_active'] === 1 ? 'Выключить' : 'Включить' ?></button>
                        </form>
                        <form class="u-inline-0cd28ce9ba" method="post" action="/admin/redirects/<?= (int) $item['id'] ?>/delete" data-confirm="Удалить редирект «<?= htmlspecialchars((string) $item['from_path'], ENT_QUOTES) ?>»?">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn--small btn--danger"><?= \App\Core\AdminUi::icon('trash') ?>Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<section class="u-inline-f0778f9dce">
    <h2>Недавние 404 (кандидаты в редиректы)</h2>
    <p class="form-hint">Пути, по которым посетители получили «страница не найдена». Записи с внешним источником перехода — вверху: это живые старые ссылки, их стоит закрыть 301-редиректом. Статика и следы сканеров не учитываются; записи старше 90 дней удаляются автоматически.</p>
    <?php if (empty($notFound)): ?>
        <p class="form-hint">Пока пусто — значит, битых входящих ссылок не замечено.</p>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr><th>Путь</th><th>Обращений</th><th>Внешний источник</th><th>Последний раз</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($notFound as $nf): ?>
                    <tr>
                        <td><code class="u-inline-e71ae94b55"><?= htmlspecialchars((string) $nf['path'], ENT_QUOTES) ?></code></td>
                        <td><?= (int) $nf['hits'] ?></td>
                        <td class="u-inline-3a618ca4cc">
                            <?= $nf['last_referer'] !== null ? htmlspecialchars((string) $nf['last_referer'], ENT_QUOTES) : '—' ?>
                        </td>
                        <td class="u-inline-a9efa5449f"><?= htmlspecialchars((string) $nf['last_hit_at'], ENT_QUOTES) ?></td>
                        <td class="data-table__actions u-inline-a9efa5449f">
                            <a class="btn btn--small btn--primary" href="/admin/redirects?from=<?= urlencode((string) $nf['path']) ?>#from_path">Создать 301</a>
                            <form class="u-inline-0cd28ce9ba" method="post" action="/admin/redirects/404/<?= (int) $nf['id'] ?>/delete">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn btn--small">Скрыть</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../layout/footer.php'; ?>