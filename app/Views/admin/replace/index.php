<?php
/**
 * «Найти и заменить»: поиск фразы по контенту, замена по отмеченным местам и
 * журнал отмены.
 *
 * @var array{q: string, with: string, case: bool, settings: bool} $form
 * @var array{hits: list<array{ref: string, table: string, column: string, count: int, label: string, url: string, trashed: bool, before: string, match: string, after: string}>, truncated: bool}|null $result
 * @var list<array{id: string, at: string, needle: string, replacement: string, fields: int, undone: string}> $history
 */

use App\Core\AdminUi;
use App\Core\Csrf;
use App\Core\TextReplace;

$pageTitle = 'Найти и заменить';
$activeNav = 'replace';
require __DIR__ . '/../layout/header.php';

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES);
$hits = $result['hits'] ?? [];
$total = array_sum(array_column($hits, 'count'));
?>
<div class="settings-card">
    <p class="settings-card__subtitle">
        Ищет фразу во всём контенте: страницах и их блоках, проектах, новостях, обложках, команде,
        альбомах, видео, целях, каталогах, меню, виджетах и формах — на всех языках. Сначала показывает
        места, ничего не меняя; заменяются только отмеченные. Меняется только текст: адреса ссылок,
        имена файлов, иконки и настройки оформления блоков не трогаются. Пробел в фразе совпадает и с
        неразрывным пробелом, который ставит типограф.
    </p>
    <form method="get" action="/admin/replace">
        <div class="form-field">
            <label for="replace-q">Найти</label>
            <input type="text" id="replace-q" name="q" value="<?= $e($form['q']) ?>" maxlength="<?= TextReplace::MAX_LENGTH ?>" required minlength="<?= TextReplace::MIN_LENGTH ?>">
        </div>
        <div class="form-field">
            <label for="replace-with">Заменить на</label>
            <input type="text" id="replace-with" name="with" value="<?= $e($form['with']) ?>" maxlength="<?= TextReplace::MAX_LENGTH ?>">
            <p class="form-hint">Пусто — фраза будет удалена. Только текст: знаки &lt; и &gt; не допускаются.</p>
        </div>
        <label class="form-check"><input type="checkbox" name="case" value="1"<?= $form['case'] ? ' checked' : '' ?>> Учитывать регистр букв</label>
        <label class="form-check"><input type="checkbox" name="settings" value="1"<?= $form['settings'] ? ' checked' : '' ?>> Искать и в настройках сайта (название, шапка, подвал)</label>
        <div>
            <button type="submit" class="btn btn--primary"><?= AdminUi::icon('search') ?>Найти места</button>
        </div>
    </form>
</div>

<?php if ($result !== null): ?>
    <?php if ($hits === []): ?>
        <div class="alert alert--info">Фраза «<?= $e($form['q']) ?>» не найдена.</div>
    <?php else: ?>
        <form method="post" action="/admin/replace" data-confirm="Заменить «<?= $e($form['q']) ?>» на «<?= $e($form['with']) ?>» в отмеченных местах?">
            <?= Csrf::field() ?>
            <input type="hidden" name="q" value="<?= $e($form['q']) ?>">
            <input type="hidden" name="with" value="<?= $e($form['with']) ?>">
            <?php if ($form['case']): ?><input type="hidden" name="case" value="1"><?php endif; ?>
            <?php if ($form['settings']): ?><input type="hidden" name="settings" value="1"><?php endif; ?>
            <p class="form-hint">
                Найдено совпадений: <strong><?= (int) $total ?></strong> в <strong><?= count($hits) ?></strong> полях.
                <?php if ($result['truncated']): ?>Показаны первые <?= TextReplace::MAX_HITS ?> полей — уточните фразу, чтобы увидеть остальные.<?php endif; ?>
                Снимите галочку там, где фразу менять не нужно.
            </p>
            <table class="data-table">
                <thead>
                    <tr><th>Заменить</th><th>Где</th><th>Текст</th><th>Раз</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($hits as $i => $hit): ?>
                        <tr>
                            <td><input type="checkbox" name="refs[]" value="<?= $e($hit['ref']) ?>" checked aria-label="Заменить в месте <?= $i + 1 ?>"></td>
                            <td>
                                <?php if ($hit['url'] !== ''): ?>
                                    <a href="<?= $e($hit['url']) ?>" target="_blank" rel="noopener"><?= $e($hit['label']) ?></a>
                                <?php else: ?>
                                    <?= $e($hit['label']) ?>
                                <?php endif; ?>
                                <?php if ($hit['trashed']): ?><span class="badge badge--muted">в корзине</span><?php endif; ?>
                                <div class="form-hint"><?= $e($hit['table'] . ' · ' . $hit['column']) ?></div>
                            </td>
                            <td><?= $e($hit['before']) ?><mark><?= $e($hit['match']) ?></mark><?= $e($hit['after']) ?></td>
                            <td><?= (int) $hit['count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <button type="submit" class="btn btn--primary"><?= AdminUi::icon('replace') ?>Заменить в отмеченных</button>
            </p>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php if ($history !== []): ?>
    <h2>Последние замены</h2>
    <p class="form-hint">Отмена возвращает прежний текст только в тех полях, которые после замены никто не правил; блоки страниц, кроме того, сохраняют прежнюю версию в своей истории.</p>
    <table class="data-table">
        <thead>
            <tr><th>Когда</th><th>Что на что</th><th>Полей</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($history as $op): ?>
                <tr>
                    <td><?= $e($op['at']) ?></td>
                    <td>«<?= $e($op['needle']) ?>» → «<?= $e($op['replacement']) ?>»</td>
                    <td><?= (int) $op['fields'] ?></td>
                    <td>
                        <?php if ($op['undone'] !== ''): ?>
                            <span class="badge badge--muted">отменено <?= $e($op['undone']) ?></span>
                        <?php else: ?>
                            <form method="post" action="/admin/replace/undo" data-confirm="Вернуть прежний текст в <?= (int) $op['fields'] ?> полях?">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="log" value="<?= $e($op['id']) ?>">
                                <button type="submit" class="btn btn--small"><?= AdminUi::icon('arrow-back-up') ?>Отменить</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require __DIR__ . '/../layout/footer.php'; ?>
