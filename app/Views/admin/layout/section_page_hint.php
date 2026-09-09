<?php

/**
 * Подсказка «шапка раздела» над списком новостей и проектов.
 *
 * Настройка живёт в сайдбаре формы страницы, и найти её оттуда, где она
 * применяется (список новостей, список проектов), было нечем: пока ни одна
 * страница не назначена, в интерфейсе не остаётся ни следа. Подсказка либо
 * показывает назначенную страницу и ведёт в неё, либо объясняет, где назначить.
 *
 * @var string $section ключ раздела: news|projects
 */

use App\Models\Page;

$sectionKey = (string) ($section ?? '');
if (!isset(Page::SECTIONS[$sectionKey])) {
    return;
}

$sectionPage = Page::forSection($sectionKey, \App\Models\Language::defaultCode());
$publicPath = $sectionKey === 'news' ? '/news' : '/projects';
?>
<p class="form-hint">
    <?= \App\Core\AdminUi::icon('info-circle', 14) ?>
    <?php if ($sectionPage !== null): ?>
        Шапку раздела <code><?= htmlspecialchars($publicPath, ENT_QUOTES) ?></code> отдаёт страница
        «<a href="/admin/pages/<?= (int) $sectionPage['id'] ?>/edit"><?= htmlspecialchars((string) $sectionPage['title'], ENT_QUOTES) ?></a>»:
        от неё берутся заголовок, лид и SEO, а её блоки выводятся под списком.
    <?php else: ?>
        Заголовок, лид и SEO раздела <code><?= htmlspecialchars($publicPath, ENT_QUOTES) ?></code> сейчас берутся из шаблона.
        Чтобы задать их из админки, откройте нужную <a href="/admin/pages">страницу</a> и выберите
        «Шапка раздела» → «<?= htmlspecialchars(Page::SECTIONS[$sectionKey], ENT_QUOTES) ?>» в сайдбаре формы.
    <?php endif; ?>
</p>
