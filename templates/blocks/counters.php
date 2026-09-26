<?php
/** @var array $data */
$title = $data['title'] ?? '';
$items = $data['items'] ?? [];
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
$cstyle = '--counter-icon-size:' . $iconSize . 'px;--counter-icon-box-size:' . $iconBoxSize . 'px;'
    . ($cardBg !== '' ? '--counters-bg:' . $cardBg . ';' : '')
    . ($textColor !== '' ? '--counters-text:' . $textColor . ';' : '');
$templateCss = '#block-' . $blockId . ' .block-counters{' . $cstyle . '}';
// «Полоса к цели» и «Шкала по годам» показывают путь от базы к цели
// (CounterGoal). Показатель без цели остаётся обычным числом: полоса без
// смысла хуже, чем её отсутствие. Доля уходит переменной в scoped CSS —
// инлайн-стили в блоках запрещены тестами.
$goalVariant = in_array($variant, ['progress', 'scale'], true);
$blockClasses = ($iconBackground === 'off' ? ' block-counters--icons-no-bg' : '')
    . ' block-counters--panel-' . $panel
    . ' block-counters--icon-pos-' . $iconPosition
    . ' block-counters--text-align-' . $textAlign
    . ' block-counters--' . $variant
    . ' block-counters--size-' . $valueSize;
?>
<div class="block-counters<?= $blockClasses ?>">
    <?= \App\Core\SectionHead::render(['title' => $title]) ?>
    <div class="block-counters__grid">
        <?php foreach (array_values($items) as $n => $item):
            $value = (string) ($item['value'] ?? '');
            $suffix = (string) ($item['suffix'] ?? '');
            $goal = $goalVariant
                ? \App\Core\CounterGoal::progress($value, (string) ($item['target'] ?? ''), (string) ($item['base'] ?? ''))
                : null;
            if ($goal !== null) {
                $templateCss .= '#block-' . $blockId . ' .block-counters__grid>:nth-child(' . ($n + 1) . '){--counter-goal:'
                    . \App\Core\CounterGoal::css($goal) . '}';
            }
            // Отсчёт включается только для чистого числа: «24/7» и «№1»
            // анимировать нечем, а разряды в «1 200» скрипт бы потерял.
            $countable = preg_match('/^\d{1,9}$/', $value) === 1;
            $link = (string) ($item['link'] ?? '');
            $note = (string) ($item['note'] ?? '');
            $prefix = (string) ($item['prefix'] ?? '');
            $iconImage = trim((string) ($item['icon_image'] ?? ''));
            if ($iconImage !== '' && !\App\Core\UrlGuard::isSafeMedia($iconImage)) {
                $iconImage = '';
            }
            $tag = $link !== '' ? 'a' : 'div';
        ?>
            <<?= $tag ?> class="counter<?= $link !== '' ? ' counter--link' : '' ?><?= $goal !== null ? ' counter--goal' : '' ?>"<?= $link !== '' ? ' href="' . htmlspecialchars($link, ENT_QUOTES) . '"' : '' ?>>
                <?php if ($goal !== null): ?><div class="counter__head"><?php endif; ?>
                <?php if ($iconImage !== ''): ?>
                    <span class="counter__icon" aria-hidden="true"><img class="counter__icon-img" src="<?= htmlspecialchars($iconImage, ENT_QUOTES) ?>" alt="" width="<?= $iconSize ?>" height="<?= $iconSize ?>"></span>
                <?php elseif (!empty($item['icon_svg'])): ?>
                    <span class="counter__icon" aria-hidden="true"><?= \App\Core\Icon::render($item['icon_svg'], $iconSize) ?></span>
                <?php endif; ?>
                <div class="counter__body">
                    <div class="counter__num">
                        <?php if ($prefix !== ''): ?><span class="counter__prefix"><?= htmlspecialchars($prefix, ENT_QUOTES) ?></span><?php endif; ?>
                        <span class="counter__value"<?= $countable ? ' data-counter-target="' . htmlspecialchars($value, ENT_QUOTES) . '"' : '' ?>><?= htmlspecialchars($value, ENT_QUOTES) ?></span>
                        <?php if ($suffix !== ''): ?><span class="counter__suffix"><?= htmlspecialchars($suffix, ENT_QUOTES) ?></span><?php endif; ?>
                    </div>
                    <div class="counter__label"><?= htmlspecialchars($item['label'] ?? '', ENT_QUOTES) ?></div>
                    <?php if ($note !== ''): ?><div class="counter__note"><?= htmlspecialchars($note, ENT_QUOTES) ?></div><?php endif; ?>
                </div>
                <?php if ($goal !== null):
                    $target = (string) ($item['target'] ?? '');
                    $base = (string) ($item['base'] ?? '');
                    $delta = (string) ($item['delta'] ?? '');
                    // Единица («%», «млрд») относится и к цели, а «из 55» —
                    // нет: суффикс с цифрой к цели не приклеивается, иначе
                    // выходило бы «цель — 55 из 55».
                    $targetUnit = $suffix !== '' && preg_match('/\d/', $suffix) !== 1 ? "\u{00A0}" . $suffix : '';
                    // Полоса — рисунок, а не текст: диктору её заменяет подпись
                    // с долей пути, а цифры цели и базы он читает строкой ниже.
                    $goalLabel = t('Путь к цели') . ': ' . \App\Core\ChartData::formatNumber($goal) . "\u{00A0}%";
                    $point = static fn (string $label, string $number): string => ($label !== '' ? '<span class="counter__axis-year">' . htmlspecialchars($label, ENT_QUOTES) . '</span>' : '')
                        . htmlspecialchars($number, ENT_QUOTES);
                ?>
                    </div>
                    <?php if ($variant === 'progress'): ?>
                        <div class="counter__goal">
                            <span class="counter__track" role="img" aria-label="<?= htmlspecialchars($goalLabel, ENT_QUOTES) ?>"><span class="counter__fill"></span><span class="counter__tick"></span></span>
                            <span class="counter__goal-foot">
                                <span><?= htmlspecialchars(trim(t('цель') . ' ' . (string) ($item['target_label'] ?? '')) . ' — ' . $target . $targetUnit, ENT_QUOTES) ?></span>
                                <?php if ($delta !== ''): ?><span class="counter__delta"><?= htmlspecialchars($delta, ENT_QUOTES) ?></span><?php endif; ?>
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
                            <?php if ($delta !== ''): ?><span class="counter__delta"><?= htmlspecialchars($delta, ENT_QUOTES) ?></span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </div>
</div>
