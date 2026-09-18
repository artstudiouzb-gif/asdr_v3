<?php

use App\Core\Csrf;

$pageTitle = 'Переводы интерфейса';
$activeNav = 'languages';
require __DIR__ . '/../layout/header.php';

/** @var array<int,array<string,mixed>> $languages */
/** @var string $lang */
/** @var array<int,array{key:string,base:string,custom:string,effective:string,missing:bool}> $rows */
/** @var string $query */
/** @var string $status */
/** @var int $page */
/** @var int $pages */
/** @var int $totalFiltered */
/** @var int $total */
/** @var int $translated */
/** @var int $customCount */

$langNames = [];
foreach ($languages as $language) {
    $langNames[(string) $language['code']] = (string) $language['name'];
}
$currentName = $langNames[$lang] ?? strtoupper($lang);
$missing = max(0, $total - $translated);

$queryFor = static function (array $replace = []) use ($lang, $query, $status, $page): string {
    $params = array_merge([
        'lang' => $lang,
        'q' => $query,
        'status' => $status,
        'page' => $page,
    ], $replace);
    $params = array_filter($params, static fn ($value): bool => $value !== '' && $value !== 'all' && $value !== 1);

    return '/admin/languages/translations' . ($params !== [] ? '?' . http_build_query($params) : '');
};
?>
<div class="admin-page-actions">
    <a class="btn btn--secondary" href="/admin/languages"><?= \App\Core\AdminUi::icon('arrow-left') ?>Языки</a>
</div>

<div class="form-card">
    <h2>Системные фразы сайта</h2>
    <p class="form-hint">
        Русская фраза — системный ключ <code>t()</code>. Штатный перевод берётся из
        <code>app/Core/lang/<?= htmlspecialchars($lang, ENT_QUOTES) ?>.php</code>.
        Поле «Свой перевод» переопределяет его без изменения файлов проекта.
        Оставьте поле пустым, чтобы использовать штатный перевод.
    </p>

    <div class="form-grid">
        <div class="form-field">
            <label for="translation-lang">Язык</label>
            <form method="get" action="/admin/languages/translations">
                <div class="form-field">
                    <select id="translation-lang" name="lang">
                        <?php foreach ($languages as $language): ?>
                            <?php $code = (string) $language['code']; ?>
                            <option value="<?= htmlspecialchars($code, ENT_QUOTES) ?>" <?= $code === $lang ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $language['name'], ENT_QUOTES) ?> (<?= htmlspecialchars($code, ENT_QUOTES) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn--small btn--secondary" type="submit">Открыть</button>
            </form>
        </div>
        <div class="form-field">
            <label>Покрытие <?= htmlspecialchars($currentName, ENT_QUOTES) ?></label>
            <div class="form-hint">
                <?= $translated ?> / <?= $total ?> переведено ·
                <?= $missing ?> без перевода ·
                <?= $customCount ?> изменено в админке
            </div>
        </div>
    </div>
</div>

<form method="get" action="/admin/languages/translations" class="form-card">
    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang, ENT_QUOTES) ?>">
    <div class="form-grid">
        <div class="form-field">
            <label for="translation-q">Поиск</label>
            <input id="translation-q" type="search" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES) ?>" placeholder="Русская фраза или перевод">
        </div>
        <div class="form-field">
            <label for="translation-status">Показать</label>
            <select id="translation-status" name="status">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Все строки</option>
                <option value="missing" <?= $status === 'missing' ? 'selected' : '' ?>>Только без перевода</option>
                <option value="custom" <?= $status === 'custom' ? 'selected' : '' ?>>Изменённые в админке</option>
            </select>
        </div>
        <div class="form-actions">
            <button class="btn btn--secondary" type="submit"><?= \App\Core\AdminUi::icon('search') ?>Найти</button>
            <?php if ($query !== '' || $status !== 'all'): ?>
                <a class="btn btn--secondary" href="<?= htmlspecialchars($queryFor(['q' => '', 'status' => 'all', 'page' => 1]), ENT_QUOTES) ?>">Сбросить</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<form method="post" action="/admin/languages/translations" class="form-card">
    <?= Csrf::field() ?>
    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang, ENT_QUOTES) ?>">
    <input type="hidden" name="q" value="<?= htmlspecialchars($query, ENT_QUOTES) ?>">
    <input type="hidden" name="status" value="<?= htmlspecialchars($status, ENT_QUOTES) ?>">
    <input type="hidden" name="page" value="<?= $page ?>">

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Русский / ключ</th>
                    <th>Штатный перевод</th>
                    <th>Свой перевод</th>
                    <th>Статус</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="4">По выбранному фильтру строк нет.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($row['key'], ENT_QUOTES) ?></strong>
                            <input type="hidden" name="translation_key[]" value="<?= htmlspecialchars($row['key'], ENT_QUOTES) ?>">
                        </td>
                        <td>
                            <?php if ($row['base'] !== ''): ?>
                                <?= htmlspecialchars($row['base'], ENT_QUOTES) ?>
                            <?php else: ?>
                                <span class="form-hint">Нет в языковом файле</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input
                                type="text"
                                name="translation_value[]"
                                value="<?= htmlspecialchars($row['custom'], ENT_QUOTES) ?>"
                                placeholder="<?= htmlspecialchars($row['base'] !== '' ? $row['base'] : 'Введите перевод', ENT_QUOTES) ?>"
                                maxlength="4000"
                                aria-label="Перевод: <?= htmlspecialchars($row['key'], ENT_QUOTES) ?>"
                            >
                        </td>
                        <td>
                            <?php if ($row['custom'] !== ''): ?>
                                <span class="badge">Изменён</span>
                            <?php elseif ($row['missing']): ?>
                                <span class="badge badge--warning">Нет перевода</span>
                            <?php else: ?>
                                <span class="badge">Файл</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($rows !== []): ?>
        <div class="form-actions">
            <button class="btn btn--primary" type="submit"><?= \App\Core\AdminUi::icon('save') ?>Сохранить эту страницу</button>
            <span class="form-hint">Пустое поле удаляет переопределение и возвращает штатный перевод из файла.</span>
        </div>
    <?php endif; ?>
</form>

<?php if ($pages > 1): ?>
    <nav class="pagination" aria-label="Страницы переводов">
        <?php if ($page > 1): ?>
            <a class="btn btn--small btn--secondary" href="<?= htmlspecialchars($queryFor(['page' => $page - 1]), ENT_QUOTES) ?>">← Назад</a>
        <?php endif; ?>
        <span class="form-hint">Страница <?= $page ?> из <?= $pages ?> · найдено <?= $totalFiltered ?></span>
        <?php if ($page < $pages): ?>
            <a class="btn btn--small btn--secondary" href="<?= htmlspecialchars($queryFor(['page' => $page + 1]), ENT_QUOTES) ?>">Далее →</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
