<?php

/**
 * Подсказки живого поиска: короткий список найденного под полем ввода.
 * Отдаётся фрагментом (SearchController::suggest) и вставляется как есть.
 *
 * @var list<array{type:string,title:string,url:string,excerpt:string,image?:string,date?:string}> $results
 * @var string $query
 * @var string $allUrl
 */
?>
<?php if ($results === []): ?>
    <p class="search-suggest__empty"><?= htmlspecialchars(t('Ничего не найдено'), ENT_QUOTES) ?></p>
<?php else: ?>
    <ul class="search-suggest__list">
        <?php foreach ($results as $item): ?>
            <li>
                <a class="search-suggest__item" href="<?= htmlspecialchars($item['url'], ENT_QUOTES) ?>">
                    <?php if (!empty($item['image'])): ?>
                        <img src="<?= htmlspecialchars((string) $item['image'], ENT_QUOTES) ?>" class="search-suggest__thumb" alt="" loading="lazy">
                    <?php endif; ?>
                    <div class="search-suggest__info">
                        <span class="search-suggest__title"><?= htmlspecialchars($item['title'], ENT_QUOTES) ?></span>
                        <div class="search-suggest__meta">
                            <span class="search-suggest__type"><?= htmlspecialchars($item['type'], ENT_QUOTES) ?></span>
                            <?php if (!empty($item['date'])): ?>
                                <span class="search-suggest__date"><?= htmlspecialchars((string) $item['date'], ENT_QUOTES) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <a class="search-suggest__all" href="<?= htmlspecialchars($allUrl, ENT_QUOTES) ?>">
        <?= htmlspecialchars(t('Все результаты'), ENT_QUOTES) ?> →
    </a>
<?php endif; ?>