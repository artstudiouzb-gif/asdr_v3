<?php

use App\Core\Locale;
use App\Core\PageHero;
use App\Models\Project;

/** @var array $project */
/** @var string $content */
/** @var string $blockCss */
$content = $content ?? '';
$metaTitle = (string) $project['title'];
$metaDescription = mb_substr(strip_tags((string) ($project['description'] ?? '')), 0, 200);
$ogImage = trim((string) ($project['cover_image'] ?? ''));
// Scoped CSS блоков проекта уходит в <head> тем же путём, что и у страницы.
$extraHeadCss = $blockCss ?? '';
// Прозрачная шапка — та же настройка записи, что и у страницы, и прочитать её
// надо до шапки: _header.php решает про режим один раз, при выводе.
$transparentHeader = !empty($project['transparent_header']);
require __DIR__ . '/_header.php';

// Первый блок бывает шапкой сам: обложка несёт h1, лид и собственную
// композицию во всю ширину. Тогда паспорт записи — метка, заголовок, анонс и
// обложка-картинка — не печатается, иначе на странице два заголовка подряд, а
// сама обложка уезжает вторым экраном. Правило общее со страницей — PageHero.
$firstIsHero = PageHero::isHero($content);
$firstOwnsHeading = PageHero::ownsHeading($content);

$crumbs = [
    ['label' => t('Главная'), 'url' => Locale::url('/')],
    ['label' => t('Проекты'), 'url' => Locale::url('projects')],
    ['label' => (string) $project['title']],
];
if ($firstIsHero) {
    $crumbsClass = PageHero::ON_HERO_CLASS;
}
ob_start();
require __DIR__ . '/_crumbs.php';
$crumbsHtml = (string) ob_get_clean();
unset($crumbsClass);
if ($firstIsHero) {
    [$content, $crumbsHtml] = PageHero::withCrumbs($content, $crumbsHtml);
}
echo $crumbsHtml;

$cover = trim((string) ($project['cover_image'] ?? ''));
$others = array_values(array_filter(
    Project::published(Locale::current()),
    fn (array $p) => (int) $p['id'] !== (int) $project['id']
));
?>
<article class="projdetail<?= $firstIsHero ? ' projdetail--hero' : '' ?>">
    <?php if (!$firstOwnsHeading): ?>
        <div class="projdetail-head<?= $cover === '' ? ' projdetail-head--no-media' : '' ?>">
            <div class="projdetail-head__info">
                <span class="newsdetail__badge"><?= htmlspecialchars(t('Проект'), ENT_QUOTES) ?></span>
                <h1 class="projdetail__title"><?= htmlspecialchars((string) $project['title'], ENT_QUOTES) ?></h1>
            </div>
            <?php if ($cover !== ''): ?>
                <?= \App\Core\Media::picture($cover, (string) $project['title'], null, null, 'projdetail__media', false, '(max-width: 900px) 100vw, 55vw') ?>
            <?php endif; ?>
        </div>
        <?php $lead = trim((string) ($project['description'] ?? '')); ?>
        <?php if ($lead !== ''): ?>
            <p class="projdetail__lead"><?= htmlspecialchars($lead, ENT_QUOTES) ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (trim($content) !== ''): ?>
        <div class="projdetail__content"><?= $content ?></div>
    <?php endif; ?>

    <?php if (!empty($others)): ?>
        <section class="projdetail-related">
            <div class="section-head">
                <h2 class="section-head__title"><?= htmlspecialchars(t('Другие проекты'), ENT_QUOTES) ?></h2>
                <a class="section-head__all" href="<?= htmlspecialchars(Locale::url('projects'), ENT_QUOTES) ?>"><?= htmlspecialchars(t('Все проекты'), ENT_QUOTES) ?> →</a>
            </div>
            <div class="projects-grid projects-grid--compact">
                <?php foreach (array_slice($others, 0, 4) as $item): ?>
                    <?php $c = trim((string) ($item['cover_image'] ?? '')); ?>
                    <a class="imgcard" href="<?= htmlspecialchars(Locale::url('projects/' . $item['slug']), ENT_QUOTES) ?>">
                        <?php if ($c !== ''): ?>
                            <?= \App\Core\Media::picture($c, (string) $item['title'], null, null, 'imgcard__media', true, '(max-width: 700px) 100vw, 25vw') ?>
                        <?php else: ?>
                            <span class="imgcard__media" aria-hidden="true"></span>
                        <?php endif; ?>
                        <span class="imgcard__overlay"></span>
                        <span class="imgcard__body">
                            <h3 class="imgcard__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></h3>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</article>
<?php require __DIR__ . '/_footer.php'; ?>