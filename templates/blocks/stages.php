<?php

use App\Core\Icon;

/**
 * «Хронология и этапы»: события во времени.
 *
 * Раскладок две, и это настройка, а не два блока. Лента (`track`) ставит
 * этапы в ряд карточками и на узком месте становится полосой прокрутки;
 * вертикальный список (`list`) кладёт годы слева, события справа и умеет
 * карточку-призыв сбоку. Прежде это были два типа — «Этапы» и «Хронология»,
 * — оба подписанные словом «таймлайн»: выбрать между ними по описанию было
 * нельзя, а правка одного молча расходилась со вторым.
 *
 * @var array $data
 * @var int $blockId
 */
$title = trim((string) ($data['title'] ?? ''));
$description = trim(\App\Core\HtmlSanitizer::sanitizeText((string) ($data['description'] ?? '')));
$allText = trim((string) ($data['all_text'] ?? ''));
$allUrl = trim((string) ($data['all_url'] ?? ''));
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$layout = (string) $data['layout'];
$variant = (string) $data['variant'];
$items = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
$columns = (int) $data['columns'];
$autoplay = (int) $data['autoplay'];
$statusLabels = ['done' => t('Завершён'), 'active' => t('В процессе'), 'planned' => t('Запланирован')];

/*
 * Статус события. У записей, заведённых до его появления, статуса нет вовсе:
 * тогда пройденными считаются все, кроме последнего, — иначе вся хронология
 * разом становилась «запланированной». Если хоть у одного элемента статус
 * проставлен, догадка выключается: редактор уже сказал своё.
 */
$hasExplicitStatuses = false;
foreach ($items as $item) {
    if (is_array($item) && in_array($item['status'] ?? '', ['done', 'active', 'planned'], true)) {
        $hasExplicitStatuses = true;
        break;
    }
}
$lastIndex = count($items) - 1;
$statuses = [];
foreach ($items as $index => $item) {
    $saved = is_array($item) ? ($item['status'] ?? '') : '';
    if (in_array($saved, ['done', 'active', 'planned'], true)) {
        $statuses[] = (string) $saved;
    } elseif (!$hasExplicitStatuses) {
        $statuses[] = $index === $lastIndex ? 'active' : 'done';
    } else {
        $statuses[] = 'planned';
    }
}

/** Ссылка элемента, проверенная так же, как в остальных блоках. */
$itemUrl = static function (array $item): string {
    $url = trim((string) ($item['url'] ?? ''));

    return $url !== '' && \App\Core\UrlGuard::isSafeLink($url) ? $url : '';
};

$templateCss = '';

if ($layout === 'list') {
    $ctaTitle = trim((string) ($data['cta_title'] ?? ''));
    $hasCta = $ctaTitle !== '';
    $ctaImage = trim((string) ($data['cta_image'] ?? ''));
    if ($ctaImage !== '' && !\App\Core\UrlGuard::isSafeMedia($ctaImage)) {
        $ctaImage = '';
    }
    $ctaBtnText = trim((string) ($data['cta_button_text'] ?? ''));
    $ctaBtnUrl = trim((string) ($data['cta_button_url'] ?? ''));
    if ($ctaBtnUrl !== '' && !\App\Core\UrlGuard::isSafeLink($ctaBtnUrl)) {
        $ctaBtnUrl = '';
    }
    if ($ctaImage !== '') {
        $ctaImageCss = str_replace(["\\", "'"], ["\\\\", "\\'"], $ctaImage);
        $templateCss = '#block-' . $blockId . " .timeline-cta{--timeline-cta-image:url('" . $ctaImageCss . "')}";
    }
    ?>
    <div class="block-timeline<?= $hasCta ? ' block-timeline--with-cta' : '' ?>">
        <?= \App\Core\SectionHead::render([
            'title' => $title,
            'description' => $description,
            'description_html' => true,
            'class' => 'block-timeline__head',
            // Легаси-классы сохраняем: на них висят правила темы.
            'title_class' => 'block-timeline__title',
            'description_class' => 'block-timeline__description',
        ]) ?>
        <div class="block-timeline__layout">
            <div class="timeline-card">
                <?php if ($items === []): ?>
                    <p class="block-timeline__empty"><?= htmlspecialchars(t('События ещё не добавлены.'), ENT_QUOTES) ?></p>
                <?php else: ?>
                    <ol class="timeline-list">
                        <?php foreach ($items as $index => $item): ?>
                            <?php
                            $status = $statuses[$index];
                            $nextStatus = $statuses[$index + 1] ?? '';
                            $url = $itemUrl($item);
                            // Ссылку несёт внутренняя обёртка, а не <li>: точка и
                            // соединительная линия рисуются на самом пункте.
                            $tag = $url !== '' ? 'a' : 'span';
                            ?>
                            <li class="timeline-item timeline-item--<?= $status ?><?= $nextStatus !== '' ? ' timeline-item--next-' . $nextStatus : '' ?>">
                                <span class="timeline-item__year"><?= htmlspecialchars((string) ($item['year'] ?? ''), ENT_QUOTES) ?></span>
                                <<?= $tag ?> class="timeline-item__body<?= $url !== '' ? ' timeline-item__body--link' : '' ?>"<?= $url !== '' ? ' href="' . htmlspecialchars($url, ENT_QUOTES) . '"' : '' ?>>
                                    <?php if (trim((string) ($item['stage'] ?? '')) !== ''): ?>
                                        <span class="timeline-item__label"><?= htmlspecialchars((string) $item['stage'], ENT_QUOTES) ?></span>
                                    <?php endif; ?>
                                    <?php if (trim((string) ($item['title'] ?? '')) !== ''): ?>
                                        <span class="timeline-item__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></span>
                                    <?php endif; ?>
                                    <?php if (trim((string) ($item['text'] ?? '')) !== ''): ?>
                                        <span class="timeline-item__text"><?= htmlspecialchars((string) $item['text'], ENT_QUOTES) ?></span>
                                    <?php endif; ?>
                                </<?= $tag ?>>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
                <?php if ($allText !== '' && $allUrl !== ''): ?>
                    <a class="timeline-card__button" href="<?= htmlspecialchars($allUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($allText, ENT_QUOTES) ?></a>
                <?php endif; ?>
            </div>
            <?php if ($hasCta): ?>
                <div class="timeline-cta">
                    <span class="timeline-cta__overlay"></span>
                    <div class="timeline-cta__body">
                        <h3 class="timeline-cta__title"><?= htmlspecialchars($ctaTitle, ENT_QUOTES) ?></h3>
                        <?php if (trim((string) ($data['cta_text'] ?? '')) !== ''): ?>
                            <p class="timeline-cta__text"><?= htmlspecialchars((string) $data['cta_text'], ENT_QUOTES) ?></p>
                        <?php endif; ?>
                        <?php if ($ctaBtnText !== '' && $ctaBtnUrl !== ''): ?>
                            <a class="timeline-cta__button" href="<?= htmlspecialchars($ctaBtnUrl, ENT_QUOTES) ?>"><?= htmlspecialchars($ctaBtnText, ENT_QUOTES) ?> →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    // Ранний выход: renderTemplate() читает $templateCss после require, и
    // возврат из шаблона ему не мешает. Без него вся лента ниже ушла бы во
    // вложенный else, а это полторы сотни строк разметки под отступом.
    return;
}

$carousel = count($items) > 1;
// Полосой хронология становится, когда этапов больше, чем помещается в ряд.
$desktopCarousel = count($items) > ($columns > 0 ? $columns : 5);
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
    <?php if ($items === []): ?>
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
                $stageUrl = $itemUrl($item);
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
