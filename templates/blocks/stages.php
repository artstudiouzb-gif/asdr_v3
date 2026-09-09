<?php
use App\Core\Icon;

/** @var array $data */
$title = trim((string) ($data['title'] ?? ''));
$description = trim(\App\Core\HtmlSanitizer::sanitizeText((string) ($data['description'] ?? '')));
$allText = trim((string) ($data['all_text'] ?? ''));
$allUrl = trim((string) ($data['all_url'] ?? ''));
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$variant = (string) $data['variant'];
$items = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
$columns = (int) $data['columns'];
$autoplay = (int) $data['autoplay'];
$carousel = count($items) > 1;
// Полосой хронология становится, когда этапов больше, чем помещается в ряд.
$desktopCarousel = count($items) > ($columns > 0 ? $columns : 5);
$statusLabels = ['done' => t('Завершён'), 'active' => t('В процессе'), 'planned' => t('Запланирован')];
$statuses = array_map(
    static fn ($item): string => is_array($item) && in_array($item['status'] ?? '', ['done', 'active', 'planned'], true)
        ? (string) $item['status']
        : 'planned',
    $items
);
// Колонок ровно по числу этапов (до пяти), пока редактор не задал своё:
// иначе четыре этапа занимают четыре колонки из пяти, и хронология
// обрывается посреди ряда.
$templateCss = '#block-' . $blockId . ' .stages{--stages-count:'
    . ($columns > 0 ? $columns : max(1, min(5, count($items)))) . '}';

$navHtml = '';
if ($carousel) {
    ob_start(); ?>
    <span class="carousel-nav" data-carousel-nav hidden>
        <button type="button" class="carousel-nav__btn" data-carousel-prev aria-label="<?= htmlspecialchars(t('Назад'), ENT_QUOTES) ?>"><?= Icon::render('chevron-left', 18) ?></button>
        <span class="carousel-nav__dots" data-carousel-dots role="group" aria-label="<?= htmlspecialchars(t('Выбор слайда'), ENT_QUOTES) ?>"></span>
        <button type="button" class="carousel-nav__btn" data-carousel-next aria-label="<?= htmlspecialchars(t('Вперёд'), ENT_QUOTES) ?>"><?= Icon::render('chevron-right', 18) ?></button>
    </span>
    <?php $navHtml = (string) ob_get_clean();
}
?>
<div class="block-stages block-stages--<?= $variant ?>"<?= $carousel ? ' data-carousel' : '' ?><?= $carousel && $autoplay > 0 ? ' data-carousel-autoplay="' . $autoplay . '"' : '' ?>>
    <?= \App\Core\SectionHead::render([
        'title' => $title,
        'description' => $description,
        'description_html' => true,
        'all_text' => $allText,
        'all_url' => $allUrl,
        'tools' => $navHtml,
    ]) ?>
    <?php if (empty($items)): ?>
        <p class="block-stages__empty"><?= htmlspecialchars(t('Этапы ещё не добавлены.'), ENT_QUOTES) ?></p>
    <?php else: ?>
        <?php // role="group" здесь ставить нельзя: он вытесняет роль списка у <ol>,
              // и каждый <li> остаётся без родителя-списка (axe: listitem).
              // Прокручиваемая область и без роли доступна с клавиатуры
              // (tabindex) и подписана (aria-label). ?>
        <ol class="stages stages--<?= $variant ?><?= $desktopCarousel ? ' stages--carousel' : '' ?>"<?= $carousel ? ' data-carousel-track tabindex="0" aria-label="' . htmlspecialchars(t('Этапы — прокрутка вбок'), ENT_QUOTES) . '"' : '' ?>>
            <?php foreach ($items as $index => $item): ?>
                <?php
                $status = $statuses[$index];
                $nextStatus = $statuses[$index + 1] ?? '';
                $stageUrl = trim((string) ($item['url'] ?? ''));
                if ($stageUrl !== '' && !\App\Core\UrlGuard::isSafeLink($stageUrl)) {
                    $stageUrl = '';
                }
                // Ссылку вешаем на внутреннюю обёртку, а не на <li>: точка и
                // соединительная линия хронологии рисуются на самом пункте.
                $stageTag = $stageUrl !== '' ? 'a' : 'span';
                ?>
                <li class="stage stage--<?= $status ?><?= $nextStatus !== '' ? ' stage--next-' . $nextStatus : '' ?>"<?= $carousel ? ' data-carousel-item' : '' ?>>
                    <span class="stage__dot"></span>
                    <<?= $stageTag ?> class="stage__body<?= $stageUrl !== '' ? ' stage__body--link' : '' ?>"<?= $stageUrl !== '' ? ' href="' . htmlspecialchars($stageUrl, ENT_QUOTES) . '"' : '' ?>>
                        <span class="stage__year"><?= htmlspecialchars((string) ($item['year'] ?? ''), ENT_QUOTES) ?></span>
                        <?php if (!empty($item['stage'])): ?><span class="stage__label"><?= htmlspecialchars((string) $item['stage'], ENT_QUOTES) ?></span><?php endif; ?>
                        <?php if (!empty($item['title'])): ?><span class="stage__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></span><?php endif; ?>
                        <?php if (!empty($item['text'])): ?><span class="stage__text"><?= htmlspecialchars((string) $item['text'], ENT_QUOTES) ?></span><?php endif; ?>
                        <span class="stage__status"><?= htmlspecialchars((string) ($item['status_text'] ?? '') !== '' ? (string) $item['status_text'] : $statusLabels[$status], ENT_QUOTES) ?></span>
                    </<?= $stageTag ?>>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</div>
