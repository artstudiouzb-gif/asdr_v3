<?php
/**
 * Виджет «Лента Instagram»: квадратные кадры последних публикаций и ссылка на
 * профиль. Разметка сетки общая с блоком страницы (partials/instagram_grid).
 * Пустая лента не выводит ничего — и виджет пропадает целиком.
 *
 * @var array $data
 * @var string $lang
 */
$posts = is_array($data['posts'] ?? null) ? $data['posts'] : [];
if ($posts === []) {
    return;
}
// Сайдбар собирается до шапки страницы, поэтому стили ещё можно попросить.
\App\Core\AssetCollector::requireThemePart('instagram_feed');
$igCaptions = false;
$igSizes = '96px';
$profileUrl = (string) ($data['profile_url'] ?? '');
?>
<div class="widget-instagram">
    <div class="ig-feed">
        <?php require dirname(__DIR__) . '/blocks/partials/instagram_grid.php'; ?>
    </div>
    <?php
    $igFollowText = t('Подписаться в Instagram');
    $igFollowUrl = $profileUrl;
    ?>
    <?php if ($profileUrl !== ''): ?>
        <div class="ig-feed__foot"><?php require dirname(__DIR__) . '/blocks/partials/instagram_follow.php'; ?></div>
    <?php endif; ?>
</div>
