<?php

use App\Core\ChartData;
use App\Core\CounterFormat;
use App\Core\CounterGoal;
use App\Core\Icon;
use App\Core\SectionHead;
use App\Core\UrlGuard;

/**
 * «Показатели» (блок `counters`).
 *
 * Разметка показателя одна на все виды: полоса, карточки, список, главный
 * показатель, путь к цели и шкала различаются раскладкой в CSS, а не своей
 * вёрсткой — иначе правка подписи или изменения доходила бы до одного вида
 * и молча расходилась с остальными.
 *
 * @var array $data
 * @var int $blockId
 */
$title = (string) ($data['title'] ?? '');
$items = array_values(array_filter((array) ($data['items'] ?? []), 'is_array'));
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$cardBg = (string) $data['card_bg'];
$textColor = (string) $data['text_color'];
$iconSize = (int) $data['icon_size'];
$iconBoxSize = max(42, $iconSize + 22);
$iconBackground = (string) $data['icon_bg'];
$iconPosition = (string) $data['icon_position'];
$panel = (string) $data['panel'];
$textAlign = (string) $data['text_align'];
$variant = (string) $data['variant'];
$valueSize = (string) $data['value_size'];
$countUp = !empty($data['count_up']);
$description = (string) ($data['description'] ?? '');
$source = trim((string) ($data['source'] ?? ''));
$columns = CounterFormat::columns($variant, (int) $data['columns'], count($items));

$scope = '#block-' . (int) $blockId;
$cstyle = '--counter-icon-size:' . $iconSize . 'px;--counter-icon-box-size:' . $iconBoxSize . 'px;'
    . ($cardBg !== '' ? '--counters-bg:' . $cardBg . ';' : '')
    . ($textColor !== '' ? '--counters-text:' . $textColor . ';' : '');
$templateCss = $scope . ' .block-counters{' . $cstyle . '}';

// «Полоса к цели» и «Шкала по годам» показывают путь от базы к цели
// (CounterGoal). Показатель без цели остаётся обычным числом: полоса без
// смысла хуже, чем её отсутствие. Доля уходит переменной в scoped CSS —
// инлайн-стили в блоках запрещены тестами.
$goalVariant = in_array($variant, ['progress', 'scale'], true);
// Колонка иконок у списка появляется, только если иконка есть хоть у одного
// показателя: пустая дорожка всё равно занимала бы промежуток слева.
$hasIcons = (bool) array_filter($items, static fn (array $item): bool => ($item['icon_svg'] ?? '') !== '' || ($item['icon_image'] ?? '') !== '');
// Хвост ряда растягивается на всю ширину (CounterFormat::balance): дыра
// рядом с неполным рядом читается как незагрузившийся показатель.
$balance = in_array($variant, ['row', 'cards', 'progress'], true)
    ? CounterFormat::balance($columns, count($items))
    : null;
if ($balance !== null) {
    $templateCss .= $scope . ' .block-counters{--counters-tracks:' . $balance['tracks']
        . ';--counters-span:' . $balance['span'] . ';--counters-tail-span:' . $balance['tail_span'] . '}';
}
if ($variant === 'lead' && count($items) > 1) {
    // Главный показатель стоит рядом со всеми остальными сразу: сколько строк
    // у списка справа, на столько он и тянется.
    $templateCss .= $scope . ' .block-counters{--counters-rest:' . (count($items) - 1) . '}';
}

$blockClasses = 'block-counters'
    . ($iconBackground === 'off' ? ' block-counters--icons-no-bg' : '')
    . ' block-counters--panel-' . $panel
    . ' block-counters--icon-pos-' . $iconPosition
    . ' block-counters--text-align-' . $textAlign
    . ' block-counters--' . $variant
    . ' block-counters--size-' . $valueSize
    . ' block-counters--cols-' . $columns
    . ($hasIcons ? ' block-counters--has-icons' : '')
    . ($textColor !== '' ? ' block-counters--custom-text' : '')
    . ($balance !== null ? ' block-counters--balanced' : '')
    . ($variant === 'lead' && count($items) === 1 ? ' block-counters--lead-only' : '');
?>
<div class="<?= htmlspecialchars($blockClasses, ENT_QUOTES) ?>">
    <?= SectionHead::render(['title' => $title, 'description' => $description]) ?>
    <div class="block-counters__grid">
        <?php foreach ($items as $n => $item):
            $value = (string) ($item['value'] ?? '');
            $suffix = (string) ($item['suffix'] ?? '');
            $goal = $goalVariant
                ? CounterGoal::progress($value, (string) ($item['target'] ?? ''), (string) ($item['base'] ?? ''))
                : null;
            if ($goal !== null) {
                $templateCss .= $scope . ' .block-counters__grid>:nth-child(' . ($n + 1) . '){--counter-goal:'
                    . CounterGoal::css($goal) . '}';
            }
            // Отсчёт — для чисел, которые можно отсчитать и потом напечатать
            // ровно так, как набрал редактор: «24/7» и «№1» стоят как есть.
            $countable = $countUp ? CounterFormat::countable($value) : null;
            $countAttrs = $countable === null ? '' : ' data-counter-target="' . htmlspecialchars($countable['target'], ENT_QUOTES) . '"'
                . ($countable['decimals'] > 0 ? ' data-counter-decimals="' . $countable['decimals'] . '" data-counter-sep="' . htmlspecialchars($countable['separator'], ENT_QUOTES) . '"' : '')
                . ($countable['grouped'] ? ' data-counter-group' : '');
            // Ссылка — повторяющееся поле, схема её не видит: проверяем здесь.
            $link = (string) ($item['link'] ?? '');
            if ($link !== '' && !UrlGuard::isSafeLink($link)) {
                $link = '';
            }
            $note = (string) ($item['note'] ?? '');
            $prefix = (string) ($item['prefix'] ?? '');
            $delta = (string) ($item['delta'] ?? '');
            $tone = in_array($item['delta_tone'] ?? '', CounterFormat::TONES, true) ? (string) $item['delta_tone'] : 'neutral';
            $direction = CounterFormat::direction($delta);
            $deltaHtml = $delta === '' ? '' : '<span class="counter__delta counter__delta--' . $tone
                . ($direction !== '' ? ' counter__delta--' . $direction : '') . '">' . htmlspecialchars($delta, ENT_QUOTES) . '</span>';
            $iconImage = trim((string) ($item['icon_image'] ?? ''));
            if ($iconImage !== '' && !UrlGuard::isSafeMedia($iconImage)) {
                $iconImage = '';
            }
            $tag = $link !== '' ? 'a' : 'div';
            $classes = 'counter'
                . ($link !== '' ? ' counter--link' : '')
                . ($goal !== null ? ' counter--goal' : '')
                . ($variant === 'lead' && $n === 0 ? ' counter--lead' : '')
                . ($balance !== null && $n >= count($items) - $balance['tail'] ? ' counter--tail' : '')
                // На планшете колонок две, и нечётный последний встаёт во всю
                // ширину — та же причина, что у хвоста выше.
                . (in_array($variant, ['row', 'cards', 'progress'], true) && count($items) % 2 === 1 && $n === count($items) - 1 && count($items) > 1 ? ' counter--odd-last' : '');
        ?>
            <<?= $tag ?> class="<?= $classes ?>"<?= $link !== '' ? ' href="' . htmlspecialchars($link, ENT_QUOTES) . '"' : '' ?>>
                <?php if ($goal !== null): ?><div class="counter__head"><?php endif; ?>
                <?php if ($iconImage !== ''): ?>
                    <span class="counter__icon" aria-hidden="true"><img class="counter__icon-img" src="<?= htmlspecialchars($iconImage, ENT_QUOTES) ?>" alt="" width="<?= $iconSize ?>" height="<?= $iconSize ?>"></span>
                <?php elseif (!empty($item['icon_svg'])): ?>
                    <span class="counter__icon" aria-hidden="true"><?= Icon::render((string) $item['icon_svg'], $iconSize) ?></span>
                <?php endif; ?>
                <div class="counter__body">
                    <div class="counter__num">
                        <?php if ($prefix !== ''): ?><span class="counter__prefix"><?= htmlspecialchars($prefix, ENT_QUOTES) ?></span><?php endif; ?>
                        <span class="counter__value"<?= $countAttrs ?>><?= htmlspecialchars($value, ENT_QUOTES) ?></span>
                        <?php if ($suffix !== ''): ?><span class="counter__suffix"><?= htmlspecialchars($suffix, ENT_QUOTES) ?></span><?php endif; ?>
                    </div>
                    <div class="counter__label"><?= htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES) ?></div>
                    <?php if ($note !== ''): ?><div class="counter__note"><?= htmlspecialchars($note, ENT_QUOTES) ?></div><?php endif; ?>
                    <?php // У пути к цели изменение стоит под полосой — рядом с тем, от чего оно отсчитано. ?>
                    <?= $goal === null ? $deltaHtml : '' ?>
                </div>
                <?php if ($goal !== null):
                    $target = (string) ($item['target'] ?? '');
                    $base = (string) ($item['base'] ?? '');
                    // Единица («%», «млрд») относится и к цели, а «из 55» —
                    // нет: суффикс с цифрой к цели не приклеивается, иначе
                    // выходило бы «цель — 55 из 55».
                    $targetUnit = $suffix !== '' && preg_match('/\d/', $suffix) !== 1 ? "\u{00A0}" . $suffix : '';
                    // Полоса — рисунок, а не текст: диктору её заменяет подпись
                    // с долей пути, а цифры цели и базы он читает строкой ниже.
                    $goalLabel = t('Путь к цели') . ': ' . ChartData::formatNumber($goal) . "\u{00A0}%";
                    $point = static fn (string $label, string $number): string => ($label !== '' ? '<span class="counter__axis-year">' . htmlspecialchars($label, ENT_QUOTES) . '</span>' : '')
                        . htmlspecialchars($number, ENT_QUOTES);
                ?>
                    </div>
                    <?php if ($variant === 'progress'): ?>
                        <div class="counter__goal">
                            <span class="counter__track" role="img" aria-label="<?= htmlspecialchars($goalLabel, ENT_QUOTES) ?>"><span class="counter__fill"></span><span class="counter__tick"></span></span>
                            <span class="counter__goal-foot">
                                <span><?= htmlspecialchars(trim(t('цель') . ' ' . (string) ($item['target_label'] ?? '')) . ' — ' . $target . $targetUnit, ENT_QUOTES) ?></span>
                                <?= $deltaHtml ?>
                            </span>
                        </div>
                    <?php else: ?>
                        <div class="counter__goal counter__goal--scale">
                            <span class="counter__axis" role="img" aria-label="<?= htmlspecialchars($goalLabel, ENT_QUOTES) ?>"><span class="counter__fill"></span><span class="counter__point counter__point--base"></span><span class="counter__point counter__point--now"></span><span class="counter__point counter__point--target"></span></span>
                            <span class="counter__axis-labels">
                                <span><?= $point((string) ($item['base_label'] ?? ''), $base !== '' ? $base : '0') ?></span>
                                <span class="counter__axis-now"><?= $point((string) ($item['now_label'] ?? ''), $value) ?></span>
                                <span><?= $point((string) ($item['target_label'] ?? ''), $target) ?></span>
                            </span>
                            <?= $deltaHtml ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </div>
    <?php if ($source !== ''): ?>
        <p class="block-counters__source"><?= htmlspecialchars($source, ENT_QUOTES) ?></p>
    <?php endif; ?>
</div>
