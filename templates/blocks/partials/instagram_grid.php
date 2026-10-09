<?php

use App\Core\Icon;
use App\Core\Media;

/**
 * Сетка кадров ленты Instagram — общая у блока страницы и виджета сайдбара:
 * вторая копия разметки разъехалась бы с первой при первой правке.
 *
 * @var list<array{type: string, caption: string, permalink: string, image: string}> $posts
 * @var bool|null $igCaptions подписи под кадрами
 * @var string|null $igSizes атрибут sizes для кадров
 */
$igCaptions = !empty($igCaptions ?? ($data['captions'] ?? false));
$igSizes = (string) ($igSizes ?? '(max-width: 720px) 33vw, 240px');
?>
<ul class="ig-feed__grid">
    <?php foreach ($posts as $post): ?>
        <?php
        $caption = trim((string) $post['caption']);
        // Имя ссылки — начало подписи: «пост в Instagram» восемь раз подряд
        // диктору не говорит ничего. Новую вкладку называем явно.
        $label = ($caption !== '' ? excerpt($caption, 120) : t('Публикация в Instagram'))
            . ' — ' . t('откроется в Instagram');
        $kind = match ((string) $post['type']) {
            'VIDEO' => 'player-play',
            'CAROUSEL_ALBUM' => 'stack-2',
            default => '',
        };
        ?>
        <li class="ig-feed__cell">
            <a class="ig-feed__item" href="<?= htmlspecialchars((string) $post['permalink'], ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= htmlspecialchars($label, ENT_QUOTES) ?>">
                <span class="ig-feed__frame">
                    <?= Media::picture((string) $post['image'], '', null, null, 'ig-feed__img', true, $igSizes) ?>
                    <?php if ($kind !== ''): ?>
                        <span class="ig-feed__kind" aria-hidden="true"><?= Icon::render($kind, 18) ?></span>
                    <?php endif; ?>
                </span>
                <?php if ($igCaptions && $caption !== ''): ?>
                    <span class="ig-feed__caption" aria-hidden="true"><?= htmlspecialchars(excerpt($caption, 140), ENT_QUOTES) ?></span>
                <?php endif; ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
