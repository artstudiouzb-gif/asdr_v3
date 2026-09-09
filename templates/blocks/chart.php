<?php

use App\Core\ChartData;
use App\Core\TitleMarkup;

/**
 * «Диаграмма»: сравнение величин, доли целого и показатель к цели.
 *
 * Рисуется разметкой и CSS, без библиотек и без картинки. Картинка не
 * переводится, не читается диктором и мылится на плотном экране; библиотека —
 * это чужой скрипт, которого публичная CSP не пропустит.
 *
 * **Подпись и значение — настоящий текст рядом с полосой, а не подсказка при
 * наведении.** Отсюда доступность получается сама собой: диктор читает
 * «Транспорт — 24 %», отдельного текстового описания не требуется. Сама полоса
 * декоративна и от диктора скрыта.
 *
 * Кругов и колец нет намеренно: доли на круге сравниваются глазом хуже, чем на
 * одной полосе, а сегмент меньше пяти процентов на круге просто не читается.
 *
 * @var array $data
 * @var int $blockId
 */
$title = trim((string) ($data['title'] ?? ''));
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$variant = (string) $data['variant'];
$unit = trim((string) $data['unit']);
$caption = trim((string) $data['caption']);
$chart = ChartData::parse((string) $data['rows'], $variant, (float) (int) $data['total']);
$rows = $chart['rows'];

$scope = '#block-' . (int) $blockId;
$templateCss = '';
foreach ($rows as $index => $row) {
    // Длина полосы — переменной в scoped CSS: инлайн-стили в блоках запрещены.
    $templateCss .= $scope . ' .chart__item--' . $index . '{--chart-share:' . $row['share'] . '%;}';
}

$value = static function (array $row) use ($unit): string {
    return ChartData::formatNumber($row['value']) . ($unit !== '' ? "\u{00A0}" . $unit : '');
};
?>
<?php if ($rows !== []): ?>
    <div class="block-chart block-chart--<?= htmlspecialchars($variant, ENT_QUOTES) ?>">
        <?php if ($title !== ''): ?>
            <h2 class="section-head__title block-chart__title"><?= TitleMarkup::html($title) ?></h2>
        <?php endif; ?>

        <?php if ($variant === 'stacked'): ?>
            <div class="chart__stack" aria-hidden="true">
                <?php foreach ($rows as $index => $row): ?>
                    <span class="chart__seg chart__item--<?= $index ?> chart__seg--<?= $index % ChartData::MAX_SERIES ?>"></span>
                <?php endforeach; ?>
            </div>
            <ul class="chart__legend">
                <?php foreach ($rows as $index => $row): ?>
                    <li class="chart__legend-item">
                        <span class="chart__swatch chart__seg--<?= $index % ChartData::MAX_SERIES ?>" aria-hidden="true"></span>
                        <span class="chart__label"><?= htmlspecialchars($row['label'], ENT_QUOTES) ?></span>
                        <span class="chart__value"><?= htmlspecialchars($value($row), ENT_QUOTES) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <ul class="chart__rows">
                <?php foreach ($rows as $index => $row): ?>
                    <li class="chart__row chart__item--<?= $index ?>">
                        <span class="chart__label"><?= htmlspecialchars($row['label'], ENT_QUOTES) ?></span>
                        <span class="chart__value"><?= htmlspecialchars($value($row), ENT_QUOTES) ?></span>
                        <span class="chart__track" aria-hidden="true"><span class="chart__fill"></span></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($caption !== ''): ?>
            <p class="media-caption block-chart__caption"><?= htmlspecialchars($caption, ENT_QUOTES) ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>
