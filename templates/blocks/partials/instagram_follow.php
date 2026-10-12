<?php

/**
 * Кнопка «Подписаться» под лентой Instagram — общая у блока и виджета.
 * Стоит под кадрами, а не в шапке рядом с заголовком: в узкой колонке ссылка
 * «Подписаться →» под заголовком читалась подписью к нему, а не действием, и
 * до неё доходили раньше, чем до самих публикаций.
 *
 * @var string $igFollowText
 * @var string $igFollowUrl
 */
if ($igFollowText === '' || $igFollowUrl === '') {
    return;
}
?>
<a class="ig-feed__follow btn-fill" href="<?= htmlspecialchars($igFollowUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer"><?= \App\Core\Icon::render('brand-instagram', 20) ?><span><?= htmlspecialchars($igFollowText, ENT_QUOTES) ?></span><span class="visually-hidden"> — <?= htmlspecialchars(t('откроется в Instagram'), ENT_QUOTES) ?></span></a>
