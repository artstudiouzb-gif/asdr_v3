<?php

use App\Core\Icon;

/**
 * Файлы документа. Главный — версия на языке страницы (или общий файл), она
 * же ссылка всей карточки; переводы стоят рядом подписями языков. Ссылка
 * всей карточки — растянутый псевдоэлемент главной кнопки: вложить ссылки
 * переводов в карточку-ссылку нельзя, `<a>` в `<a>` браузер разрывает.
 *
 * @var array{title:string,meta:string,files:list<array{lang:string,label:string,name:string,url:string,meta:string,extension:string,direct_file:bool,current:bool}>} $presented
 * @var string $filesClass
 */
$docFiles = $presented['files'];
if ($docFiles === []) {
    return;
}
$mainFile = $docFiles[0];
$otherFiles = array_slice($docFiles, 1);
// Версии на языке страницы нет — главная кнопка называет язык файла, иначе
// посетитель узнал бы о нём, только открыв документ.
// Подпись главной кнопки — подготовленная: своя подпись редактора главнее
// автоматической («PDF · 2,4 МБ» по файлу).
$mainMeta = $presented['meta'];
if ($mainFile['lang'] !== '' && !$mainFile['current']) {
    $mainMeta = trim($mainFile['label'] . ($mainMeta !== '' ? ', ' . $mainMeta : ''));
}
$fileAttrs = static function (array $file): string {
    return 'href="' . htmlspecialchars($file['url'], ENT_QUOTES) . '"'
        . ($file['direct_file'] ? ' download' : '')
        . ($file['lang'] !== '' ? ' hreflang="' . htmlspecialchars($file['lang'], ENT_QUOTES) . '"' : '');
};
?>
<span class="doc-files<?= isset($filesClass) && $filesClass !== '' ? ' ' . $filesClass : '' ?>">
    <a class="doc-files__main" <?= $fileAttrs($mainFile) ?>>
        <?= Icon::render($mainFile['direct_file'] ? 'download' : 'external-link', 18, 'doc-files__icon', 1.8) ?>
        <span class="doc-files__action"><?= htmlspecialchars($mainFile['name'] !== '' ? $mainFile['name'] : t($mainFile['direct_file'] ? 'Скачать' : 'Открыть'), ENT_QUOTES) ?></span>
        <span class="visually-hidden"><?= htmlspecialchars(': ' . $presented['title'], ENT_QUOTES) ?></span>
        <?php if ($mainMeta !== ''): ?><span class="doc-files__meta"><?= htmlspecialchars($mainMeta, ENT_QUOTES) ?></span><?php endif; ?>
    </a>
    <?php if ($otherFiles !== []): ?>
        <span class="doc-files__langs" role="group" aria-label="<?= htmlspecialchars(t('Другие языки'), ENT_QUOTES) ?>">
            <?php foreach ($otherFiles as $otherFile): ?>
                <?php $otherLabel = $otherFile['name'] !== '' ? $otherFile['name'] : ($otherFile['label'] !== '' ? $otherFile['label'] : t('Общая версия')); ?>
                <a class="doc-files__lang" <?= $fileAttrs($otherFile) ?> title="<?= htmlspecialchars(trim($otherLabel . ($otherFile['meta'] !== '' ? ' — ' . $otherFile['meta'] : '')), ENT_QUOTES) ?>">
                    <?= htmlspecialchars($otherLabel, ENT_QUOTES) ?>
                    <?php if ($otherFile['meta'] !== ''): ?><span class="visually-hidden"><?= htmlspecialchars(', ' . $otherFile['meta'], ENT_QUOTES) ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </span>
    <?php endif; ?>
</span>
