<?php

use App\Core\AssetCollector;
use App\Core\Media;
use App\Core\UrlGuard;
use App\Core\SliderRatio;

/** @var array $data */
/** @var string $lang */

// У своих снимков подписей и ссылок нет — виджет заводился как витрина кадров.
// Текст появляется только у источника «случайная цель»: название и описание
// принадлежат самой цели, а не виджету.
$images = [];
foreach ((array) ($data['slides'] ?? []) as $row) {
    $src = trim((string) (is_array($row) ? ($row['image'] ?? '') : $row));
    if ($src === '' || !UrlGuard::isSafeMedia($src)) {
        continue;
    }
    $images[] = [
        'src' => $src,
        'alt' => is_array($row) ? trim((string) ($row['alt'] ?? '')) : '',
    ];
}

$ratio = SliderRatio::normalize($data['ratio'] ?? null);
$autoplay = max(0, min(30, (int) ($data['autoplay'] ?? 0)));
$shuffle = !empty($data['shuffle']);
// Источник «случайная цель»: сервер отрисовал одну цель, но она уедет в кэш
// страницы и станет общей для всех. Признак говорит скрипту запросить свежую
// цель — так у каждого посетителя своя. Без JS остаётся отрисованная.
$fromGoals = (string) ($data['source'] ?? 'manual') === 'goals';
$goalName = $fromGoals ? trim((string) ($data['goal_name'] ?? '')) : '';
$goalDescription = $fromGoals ? trim((string) ($data['goal_description'] ?? '')) : '';

// Разметка и стили общие с блоком «Слайдер» — поведение у них одно, и второй
// набор правил разъехался бы с первым. Скрипт подключаем отсюда: виджет
// сайдбара собирается на каждый запрос, в список ассетов блоков он не попадает.
if ($images !== []) {
    AssetCollector::requireJs('slider');
}
?>
<?php if ($images === []): ?>
    <p class="widget-empty"><?= htmlspecialchars(t($fromGoals ? 'Нет ни одной цели со снимками.' : 'Фотографии не добавлены.'), ENT_QUOTES) ?></p>
<?php else: ?>
<?php // Признак «карусель целей» держит обёртка, а не сам слайдер: скрипт
      // подменяет и текст, и кадры, и обоим нужен общий корень. В значении —
      // адрес на языке страницы: скрипт не знает, какой язык открыт, и жёсткий
      // «/goals/random» приносил на узбекскую страницу русскую цель. ?>
<div class="goal-carousel"<?= $fromGoals
    ? ' data-goal-slider="' . htmlspecialchars(\App\Core\Locale::url('goals/random', $lang), ENT_QUOTES) . '"'
    : '' ?>>
<?php if ($fromGoals): ?>
    <?php // Название — не заголовок разметки: виджет встаёт в любое место
          // страницы, и уровень заголовка тут не предсказать. Диктор получает
          // его через aria-label карусели. ?>
    <div class="goal-carousel__text" data-goal-text>
        <?php if ($goalName !== ''): ?><p class="goal-carousel__name"><?= htmlspecialchars($goalName, ENT_QUOTES) ?></p><?php endif; ?>
        <?php if ($goalDescription !== ''): ?><p class="goal-carousel__desc"><?= htmlspecialchars($goalDescription, ENT_QUOTES) ?></p><?php endif; ?>
    </div>
<?php endif; ?>
<div class="block-slider block-slider--ratio-<?= htmlspecialchars($ratio, ENT_QUOTES) ?>"
     <?= $autoplay > 0 ? 'data-autoplay="' . $autoplay . '" ' : '' ?><?= $shuffle ? 'data-slider-shuffle ' : '' ?>role="region"
     aria-roledescription="<?= htmlspecialchars(t('Карусель'), ENT_QUOTES) ?>"
     aria-label="<?= htmlspecialchars($goalName !== '' ? $goalName : t('Слайдер изображений'), ENT_QUOTES) ?>" tabindex="0">
    <div class="block-slider__track">
        <?php foreach ($images as $index => $image): ?>
            <div class="block-slider__slide<?= $index === 0 ? ' is-active' : '' ?>" role="group"
                 aria-roledescription="<?= htmlspecialchars(t('Слайд'), ENT_QUOTES) ?>"
                 aria-label="<?= ($index + 1) . ' ' . htmlspecialchars(t('из'), ENT_QUOTES) . ' ' . count($images) ?>"
                 aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>">
                <?= Media::picture($image['src'], $image['alt'], null, null, '', $index !== 0, '(max-width: 900px) 100vw, 320px') ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (count($images) > 1): ?>
        <div class="block-slider__nav">
            <button type="button" class="block-slider__prev" aria-label="<?= htmlspecialchars(t('Предыдущий слайд'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('chevron-left', 22) ?></button>
            <button type="button" class="block-slider__next" aria-label="<?= htmlspecialchars(t('Следующий слайд'), ENT_QUOTES) ?>"><?= \App\Core\Icon::render('chevron-right', 22) ?></button>
        </div>
        <div class="block-slider__dots" role="group" aria-label="<?= htmlspecialchars(t('Выбор слайда'), ENT_QUOTES) ?>">
            <?php foreach ($images as $index => $_image): ?>
                <button type="button" class="block-slider__dot<?= $index === 0 ? ' is-active' : '' ?>" data-slide-index="<?= $index ?>" aria-label="<?= htmlspecialchars(t('Перейти к слайду'), ENT_QUOTES) . ' ' . ($index + 1) ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>"></button>
            <?php endforeach; ?>
        </div>
        <span class="visually-hidden" data-slider-status aria-live="polite"></span>
    <?php endif; ?>
</div>
</div>
<?php endif; ?>
