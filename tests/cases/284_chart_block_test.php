<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockRenderer;
use App\Core\ChartData;

/** @param array<string, mixed> $input @return array{html: string, css: string} */
function chart_block(array $input, int $id = 81): array
{
    $out = BlockRenderer::render([
        'id' => $id,
        'type' => 'chart',
        'data' => json_encode(BlockFieldSchema::normalize('chart', $input, 'ru'), JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return ['html' => $out['html'], 'css' => $out['css']];
}

test('Диаграмма: значение принимается как в документе — с запятой и разрядами', function (): void {
    $chart = ChartData::parse("Транспорт | 12 400\nОбразование | 5,6\nБез числа |\n | 7", 'bars');

    assert_same(2, count($chart['rows']));
    assert_same(12400.0, $chart['rows'][0]['value']);
    assert_same(5.6, $chart['rows'][1]['value']);
    // База у полос — наибольшее значение: самая длинная полоса занимает ширину.
    assert_same(12400.0, $chart['total']);
    assert_same(100.0, $chart['rows'][0]['share']);
});

test('Диаграмма: у долей база — сумма, у показателя к цели — сто', function (): void {
    $stacked = ChartData::parse("А | 30\nБ | 10", 'stacked');
    assert_same(40.0, $stacked['total']);
    assert_same(75.0, $stacked['rows'][0]['share']);

    $meter = ChartData::parse('Исполнение | 78', 'meter');
    assert_same(100.0, $meter['total']);
    assert_same(78.0, $meter['rows'][0]['share']);

    // Своя база важнее автоматической.
    $own = ChartData::parse('Освоено | 30', 'meter', 60.0);
    assert_same(50.0, $own['rows'][0]['share']);

    // Значение сверх базы полосу за край не выводит.
    $over = ChartData::parse('Перевыполнено | 140', 'meter');
    assert_same(100.0, $over['rows'][0]['share']);
});

test('Диаграмма: седьмая доля сливается в «Прочее», а не получает новый цвет', function (): void {
    // Сгенерированный седьмой оттенок неотличим от занятого при дальтонизме,
    // поэтому хвост сворачивается.
    $chart = ChartData::parse("А|10\nБ|10\nВ|10\nГ|10\nД|10\nЕ|10\nЁ|10\nЖ|30", 'stacked');

    assert_same(ChartData::MAX_SERIES, count($chart['rows']));
    assert_same('Прочее', $chart['rows'][5]['label']);
    assert_same(50.0, $chart['rows'][5]['value']);

    // У полос сравнения хвост не сворачивается: там цвет один на все.
    assert_same(8, count(ChartData::parse("А|10\nБ|10\nВ|10\nГ|10\nД|10\nЕ|10\nЁ|10\nЖ|30", 'bars')['rows']));
});

test('Диаграмма: подпись и значение — текст, полоса от диктора скрыта', function (): void {
    $out = chart_block([
        'title_field' => 'Структура расходов',
        'variant' => 'stacked',
        'rows' => "Транспорт | 24\nОбразование | 18",
        'unit' => '%',
        'caption' => 'По данным на 2026 год',
    ]);

    // Диктор читает «Транспорт — 24 %»: отдельного описания диаграмме не нужно.
    assert_contains('Транспорт', $out['html']);
    assert_contains('24', $out['html']);
    assert_contains('chart__legend', $out['html']);
    // Сама полоса декоративна.
    assert_contains('<div class="chart__stack" aria-hidden="true">', $out['html']);
    // Сравниваем по слову: типографика связывает предлог с числом неразрывным
    // пробелом («на 2026»), и точное совпадение строки тут ничего не проверяет.
    assert_contains('block-chart__caption', $out['html']);
    assert_contains('данным', $out['html']);

    // Длина полосы — переменной: инлайн-стили в блоках запрещены тестами.
    assert_contains('--chart-share:', $out['css']);
    assert_not_contains('style=', $out['html']);
});

test('Диаграмма: без разбираемых данных блок не выводится', function (): void {
    $out = chart_block(['variant' => 'bars', 'rows' => "просто текст\nещё строка"], 82);

    assert_not_contains('chart__row', $out['html']);
    assert_true(BlockRenderer::isVisuallyEmpty($out['html']));
});

test('Диаграмма: палитра долей фиксирована и объявлена для обеих тем', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/chart.css');

    // Слотов ровно столько, сколько адресует ChartData: седьмого цвета нет.
    for ($i = 1; $i <= ChartData::MAX_SERIES; $i++) {
        assert_contains('--viz-' . $i . ':', $css);
    }
    assert_not_contains('--viz-' . (ChartData::MAX_SERIES + 1), $css);

    // Тёмный набор выбран под тёмную поверхность, а не осветлён автоматически.
    assert_contains(':root[data-theme="dark"] .block-chart', $css);
    assert_same(
        ChartData::MAX_SERIES * 2,
        preg_match_all('/--viz-\d:\s*#[0-9a-f]{6}/i', $css),
        'у каждого слота должен быть свой шаг в светлой и тёмной теме'
    );

    // Полосы и шкала берут акцент из настройки, а не бренд-константу.
    assert_contains('var(--gov-teal)', $css);
});
