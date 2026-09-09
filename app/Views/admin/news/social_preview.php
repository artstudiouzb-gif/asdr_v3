<?php

use App\Core\AdminUi;

/** @var array $news */
/** @var array $post */
/** @var array $doc */
/** @var array $cfg */
/** @var array $missingLangs */
/** @var int $length */
/** @var bool $fits */

$pageTitle = 'Пост в Telegram';
$activeNav = 'news';
$pageActions = '<a href="/admin/news/' . (int) $news['id'] . '/edit" class="btn btn--secondary">'
    . AdminUi::icon('arrow-left') . 'К новости</a>';
require __DIR__ . '/../layout/header.php';

// Снимки в разметке указаны ссылками tg://photo?id=…, файлы уходят отдельным
// полем. Для показа подставляем настоящие адреса — так и выглядит пост.
$media = [];
foreach ($doc['media'] as $item) {
    $media[(string) $item['id']] = (string) $item['media']['media'];
}
$html = preg_replace_callback(
    '#tg://photo\?id=([A-Za-z0-9_-]+)#',
    static fn (array $m): string => htmlspecialchars($media[$m[1]] ?? '', ENT_QUOTES),
    (string) $doc['html']
) ?? '';

$format = (string) ($cfg['format'] ?? 'auto');
$langs = (array) $post['langs'];
?>

<div class="form-card u-inline-6c9f9d2560">
    <h4 class="u-inline-3696404b96">Что уйдёт в канал</h4>
    <ul class="tg-preview__facts">
        <li>
            Языковых версий: <b><?= count($langs) ?></b>
            <?php if ($langs !== []): ?>
                (<?= htmlspecialchars(implode(', ', array_map(
                    static fn (array $l): string => (string) ($l['label'] ?? $l['code']),
                    $langs
                )), ENT_QUOTES) ?>)
            <?php endif; ?>
        </li>
        <?php if ($missingLangs !== []): ?>
            <li class="tg-preview__warn">
                Нет перевода: <b><?= htmlspecialchars(implode(', ', array_map('mb_strtoupper', $missingLangs)), ENT_QUOTES) ?></b>
                — этой версии в посте не будет.
            </li>
        <?php endif; ?>
        <li>Снимков: <b><?= count($doc['media']) ?></b> (обложка и галерея новости)</li>
        <li>
            Длина текста: <b><?= $length ?></b> из <?= \App\Core\TelegramRichMessage::TEXT_LIMIT ?>
            <?php if (!$fits): ?>
                <span class="tg-preview__warn">— не влезает, пост уйдёт обычным форматом</span>
            <?php endif; ?>
        </li>
        <li>
            Формат: <b><?= htmlspecialchars(match ($format) {
                'rich' => 'только расширенный',
                'classic' => 'только обычный',
                default => 'расширенный, с откатом на обычный',
            }, ENT_QUOTES) ?></b>,
            вторая версия — <?= (string) ($cfg['second_lang'] ?? '') === 'details' ? 'под «развернуть»' : 'подряд в тексте' ?>,
            кнопки — <?= (string) ($cfg['buttons'] ?? '1') === '0' ? 'выключены' : 'включены' ?>
        </li>
    </ul>
    <p class="form-hint">
        Показан расширенный формат. Если Telegram его не примет, уйдёт обычный пост
        (фото с подписью), и в нём действует лимит подписи в 1024 знака.
    </p>
</div>

<div class="form-card u-inline-6c9f9d2560">
    <h4 class="u-inline-3696404b96">Предпросмотр</h4>
    <?php if (trim((string) $doc['html']) === ''): ?>
        <p class="form-hint">Пост пуст: у новости нет заголовка ни на одном языке.</p>
    <?php else: ?>
        <div class="tg-preview"><?= $html ?></div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../layout/footer.php'; ?>
