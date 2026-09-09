<?php
/** @var array $data */
/** @var int $blockId */
$items = is_array($data['items'] ?? null) ? $data['items'] : [];
// Значение проверено схемой полей (BlockFieldSchema) — читаем как есть.
$columns = (int) $data['columns'];
$head = \App\Core\SectionHead::render([
    'title' => (string) ($data['title'] ?? ''),
    'description' => (string) ($data['description'] ?? ''),
    'all_text' => (string) ($data['all_text'] ?? ''),
    'all_url' => (string) ($data['all_url'] ?? ''),
]);
// Сетка подстраивается под число карточек: две персоны в жёстких
// четырёх колонках занимали половину ширины, справа зияла пустота.
$templateCss = \App\Core\GridBalance::css($blockId, '.persons-grid', '.person-card', count($items), $columns);
?>
<div class="block-persons">
    <?= $head ?>
    <?php if (empty($items)): ?>
        <p class="block-persons__empty"><?= htmlspecialchars(t('Карточки ещё не добавлены.'), ENT_QUOTES) ?></p>
    <?php else: ?>
        <div class="persons-grid">
            <?php foreach ($items as $item): ?>
                <?php
                $photo = trim((string) ($item['photo'] ?? ''));
                $name = trim((string) ($item['name'] ?? ''));
                $url = trim((string) ($item['url'] ?? ''));
                $phone = trim((string) ($item['phone'] ?? ''));
                $email = trim((string) ($item['email'] ?? ''));
                $vacant = $photo === '' && $name === '';
                ?>
                <div class="person-card<?= $vacant ? ' person-card--vacant' : '' ?>">
                    <?php if ($photo !== ''): ?>
                        <?= \App\Core\Media::picture($photo, $name !== '' ? $name : t('Фото'), null, null, 'person-card__img', true, '(max-width: 700px) 100vw, ' . (int) round(100 / $columns) . 'vw', false, 'person-card__photo') ?>
                    <?php else: ?>
                        <?php // Заглушка без фотографии — фирменная эмблема (её рисует CSS). ?>
                        <?php // Подпись только у по-настоящему пустой карточки: раньше её ?>
                        <?php // получал и руководитель, у которого просто нет фотографии. ?>
                        <span class="person-card__placeholder">
                            <?php if ($vacant): ?>
                                <span class="person-card__vacant"><?= htmlspecialchars(t('Вакантно'), ENT_QUOTES) ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <span class="person-card__body">
                        <?php if ($name !== ''): ?><span class="person-card__name"><?= htmlspecialchars($name, ENT_QUOTES) ?></span><?php endif; ?>
                        <?php if (!empty($item['role'])): ?><span class="person-card__role"><?= htmlspecialchars((string) $item['role'], ENT_QUOTES) ?></span><?php endif; ?>
                        <?php if ($phone !== '' || $email !== ''): ?>
                            <?php // Телефон и почта кликабельны: со страницы руководства звонят и пишут. ?>
                            <span class="person-card__contacts">
                                <?php if ($phone !== ''): ?><span class="person-card__contact"><?= \App\Core\ContactLink::linkify($phone) ?></span><?php endif; ?>
                                <?php if ($email !== ''): ?><span class="person-card__contact"><?= \App\Core\ContactLink::linkify($email) ?></span><?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($url !== ''): ?><a class="person-card__more" href="<?= htmlspecialchars($url, ENT_QUOTES) ?>"><?= htmlspecialchars(t('Подробнее'), ENT_QUOTES) ?> →</a><?php endif; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
