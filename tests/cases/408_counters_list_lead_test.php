<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockData\CountersBlockNormalizer;
use App\Core\BlockRenderer;
use App\Core\CounterFormat;

/*
 * «Показатели»: виды «Список» и «Главный показатель», число колонок без
 * одинокого хвоста, отсчёт «1 200» и «4,5», изменение с оценкой, источник.
 */

/**
 * @param array<string, mixed> $data
 * @return array{html: string, css: string}
 */
function counters_render(array $data, int $id = 408): array
{
    $out = BlockRenderer::render([
        'id' => $id,
        'type' => 'counters',
        'data' => json_encode(CountersBlockNormalizer::normalize($data), JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return ['html' => (string) $out['html'], 'css' => (string) ($out['css'] ?? '')];
}

/** @return list<array<string, string>> */
function counters_items(int $n): array
{
    $items = [];
    for ($i = 1; $i <= $n; $i++) {
        $items[] = ['value' => (string) ($i * 10), 'label' => 'показатель ' . $i];
    }

    return $items;
}

test('Виды «Список» и «Главный показатель» объявлены схемой и нарисованы', function (): void {
    $variant = BlockFieldSchema::fields('counters')['variant'];
    foreach (['list', 'lead'] as $key) {
        assert_true(isset($variant->options[$key]), $key . ' есть в списке видов');
        assert_true(isset($variant->variants[$key]), $key . ' показан рисунком, а не только словом');
    }
    $columns = BlockFieldSchema::fields('counters')['columns'];
    assert_same(0, $columns->default, '«авто» — умолчание: собранные блоки не меняют вида');
    assert_false(in_array('lead', $columns->when['values'] ?? [], true), 'у главного показателя колонок не бывает');
});

test('«Авто» делит ряд без одинокого хвоста; хвост растягивается', function (): void {
    assert_same(3, CounterFormat::columns('row', 0, 5), 'пять — это 3 + 2, а не 4 + 1');
    assert_same(3, CounterFormat::columns('row', 0, 6), 'шесть — два ровных ряда по три');
    assert_same(4, CounterFormat::columns('cards', 0, 7));
    assert_same(2, CounterFormat::columns('row', 0, 2));
    assert_same(5, CounterFormat::columns('row', 5, 7), 'явный выбор редактора главнее');
    assert_same(2, CounterFormat::columns('row', 4, 2), 'колонок не больше, чем показателей');
    assert_same(6, CounterFormat::columns('cards', 6, 12), 'шесть колонок — выбор редактора');
    assert_same(6, CounterFormat::columns('cards', 9, 12), 'колонок не больше шести');
    assert_same(4, CounterFormat::columns('row', 0, 20), 'двадцать — 4 × 5, строк не больше пяти');
    assert_same(6, CounterFormat::columns('row', 0, 30), 'полная сетка — 6 × 5');
    assert_same(6, CounterFormat::columns('row', 0, 21), 'пять колонок оставили бы одиночку в хвосте');
    assert_same(1, CounterFormat::columns('list', 0, 5));
    assert_same(2, CounterFormat::columns('list', 3, 5), 'у списка больше двух колонок не бывает');

    assert_same(null, CounterFormat::balance(3, 6), 'ровные ряды не трогаются');
    assert_same(['tracks' => 6, 'span' => 2, 'tail_span' => 3, 'tail' => 2], CounterFormat::balance(3, 5));

    $out = counters_render(['variant' => 'cards', 'items' => counters_items(5)]);
    assert_contains('block-counters--cols-3', $out['html']);
    assert_contains('block-counters--balanced', $out['html']);
    assert_same(2, substr_count($out['html'], 'counter--tail'), 'хвост — два последних показателя');
    assert_contains('--counters-tracks:6;--counters-span:2;--counters-tail-span:3', $out['css']);
    assert_contains('counter--odd-last', $out['html'], 'на планшете нечётный последний встаёт во всю ширину');
});

test('Показателей — до полной сетки 6 × 5, форма не пускает дальше', function (): void {
    assert_same(30, CounterFormat::MAX_ITEMS);
    $norm = CountersBlockNormalizer::normalize(['items' => counters_items(40)]);
    assert_same(30, count($norm['items']), 'лишнее из присланного отбрасывается');

    $raw = BlockRenderer::render(['id' => 411, 'type' => 'counters', 'custom_css' => '', 'data' => json_encode([
        'variant' => 'cards', 'columns' => 6, 'items' => counters_items(35),
    ])]);
    assert_same(30, (int) preg_match_all('/class="counter(?: [^"]*)?"/', (string) $raw['html']),'вывод держит предел и для данных из файла шаблона');
    assert_contains('block-counters--cols-6', (string) $raw['html']);

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/counters.css');
    assert_contains('.block-counters--cols-6 { --counters-cols: 6; }', $css);
    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');
    assert_contains('CounterFormat::MAX_ITEMS ?>">', $form, 'форма не даёт добавить больше сетки');
});

test('Список: строки-подсетки, колонка иконок только при иконках', function (): void {
    $plain = counters_render(['variant' => 'list', 'items' => counters_items(3)]);
    assert_contains('block-counters--list', $plain['html']);
    assert_not_contains('block-counters--has-icons', $plain['html']);

    $items = counters_items(3);
    $items[1]['icon_svg'] = 'users';
    $icons = counters_render(['variant' => 'list', 'columns' => 2, 'items' => $items]);
    assert_contains('block-counters--has-icons', $icons['html']);
    assert_contains('block-counters--cols-2', $icons['html']);

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/counters.css');
    // Колонка чисел одна на все строки — поэтому подсетка, а не своя сетка у
    // каждой строки: числа разной длины иначе не встали бы столбцом.
    assert_contains('grid-template-columns: subgrid;', $css);
    assert_contains('[icon] auto [num] auto [text] minmax(0, 1fr) [delta] auto', $css);
});

test('Главный показатель тянется на высоту списка рядом', function (): void {
    $out = counters_render(['variant' => 'lead', 'items' => counters_items(4)]);
    assert_same(1, substr_count($out['html'], 'counter--lead'), 'главный — только первый');
    assert_contains('--counters-rest:3', $out['css']);

    $single = counters_render(['variant' => 'lead', 'items' => counters_items(1)]);
    assert_contains('block-counters--lead-only', $single['html'], 'один показатель занимает блок целиком');
    assert_not_contains('--counters-rest', $single['css']);
});

test('Отсчёт: «1 200» и «4,5» считаются и печатаются как набраны', function (): void {
    assert_same(['target' => '1200', 'decimals' => 0, 'separator' => '', 'grouped' => true], CounterFormat::countable("1\u{00A0}200"));
    assert_same(['target' => '4.5', 'decimals' => 1, 'separator' => ',', 'grouped' => false], CounterFormat::countable('4,5'));
    assert_same(null, CounterFormat::countable('24/7'));
    assert_same(null, CounterFormat::countable('№1'));

    // Флажок приходит из формы как «1»; у блоков, собранных до появления
    // настройки, ключа нет вовсе, и схема на выводе подставляет «да» (ниже).
    $out = counters_render(['count_up' => '1', 'items' => [
        ['value' => '25 400', 'label' => 'обращений'],
        ['value' => '4,5', 'label' => 'дня'],
    ]]);
    assert_contains('data-counter-target="25400" data-counter-group', $out['html']);
    assert_contains('data-counter-target="4.5" data-counter-decimals="1" data-counter-sep=","', $out['html']);
    // Без скрипта видна итоговая строка — отсчёт лишь улучшение.
    assert_contains(">25\u{00A0}400</span>", $out['html']);

    $off = counters_render(['items' => [['value' => '120', 'label' => 'объектов']]]);
    assert_not_contains('data-counter-target', $off['html'], 'снятый флажок не оставляет атрибутов');
    $legacy = BlockRenderer::render(['id' => 410, 'type' => 'counters', 'custom_css' => '', 'data' => json_encode([
        'items' => [['value' => '120', 'label' => 'объектов']],
    ])]);
    assert_contains('data-counter-target="120"', (string) $legacy['html'], 'блоки без ключа считают, как и раньше');

    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js');
    assert_contains("el.hasAttribute('data-counter-group')", $js);
    assert_contains('window.asdrReduceMotion', $js, 'отсчёт слушает общий признак «меньше движения»');
    assert_contains('el.style.minWidth', $js, 'ширина числа держится на время отсчёта');
});

test('Изменение: стрелка из знака, цвет из оценки, у любого вида', function (): void {
    assert_same('up', CounterFormat::direction('+12 %'));
    assert_same('down', CounterFormat::direction('−3 п.п.'));
    assert_same('down', CounterFormat::direction('-8'));
    assert_same('', CounterFormat::direction('без изменений'));

    $norm = CountersBlockNormalizer::normalize(['items' => [['value' => '1', 'label' => 'а', 'delta' => '+1', 'delta_tone' => 'evil']]]);
    assert_same('neutral', $norm['items'][0]['delta_tone'], 'тон вне набора — подделанная форма');

    $out = counters_render(['variant' => 'row', 'items' => [
        ['value' => '3,1', 'label' => 'нарушений срока', 'delta' => '+0,4 п.п.', 'delta_tone' => 'bad'],
    ]]);
    assert_contains('class="counter__delta counter__delta--bad counter__delta--up"', $out['html']);

    $custom = counters_render(['text_color' => '#ffffff', 'card_bg' => '#0f2756', 'items' => counters_items(1)]);
    assert_contains('block-counters--custom-text', $custom['html']);

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/counters.css');
    // Цветное изменение без подложки: тонированный фон увёл бы акцент в
    // текстовой роли ниже 4.5:1.
    $rule = (string) preg_replace('/^.*?\n\.counter__delta \{(.*?)\}.*$/s', '$1', $css);
    assert_not_contains('background', $rule);
    assert_contains('.block-counters--custom-text .counter__delta', $css);
});

test('Вид изменения: контур по умолчанию, заливка и строка под чертой', function (): void {
    $field = BlockFieldSchema::fields('counters')['delta_style'];
    assert_same('outline', $field->default, 'собранные блоки сохраняют прежний контур');
    assert_same(['outline', 'fill', 'line'], array_keys($field->options));

    $legacy = BlockRenderer::render(['id' => 412, 'type' => 'counters', 'custom_css' => '', 'data' => json_encode(['items' => counters_items(2)])]);
    assert_contains('block-counters--delta-outline', (string) $legacy['html']);
    $fill = counters_render(['delta_style' => 'fill', 'items' => counters_items(2)]);
    assert_contains('block-counters--delta-fill', $fill['html']);
    $forged = counters_render(['delta_style' => 'glow', 'items' => counters_items(2)]);
    assert_contains('block-counters--delta-outline', $forged['html'], 'значение вне набора — подделанная форма');

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/counters.css');
    // На сплошном тоне подпись берёт противоположную светлоту, а не акцент.
    assert_contains('.block-counters--delta-fill .counter__delta--good { background: var(--gov-teal-text); color: var(--counters-on-tone); }', $css);
    assert_contains('--counters-on-tone: var(--gov-bg)', $css);
    assert_contains('.block-counters--delta-line .counter__delta', $css);
});

test('Пояснение и источник данных выводятся, панель без ложного наведения', function (): void {
    $out = counters_render([
        'title_field' => 'Итоги года',
        'description' => 'Ключевые цифры за 2025 год.',
        'source' => 'Источник: Агентство статистики',
        'items' => counters_items(2),
    ]);
    assert_contains('section-head__description', $out['html']);
    assert_contains('<p class="block-counters__source">Источник: Агентство статистики</p>', $out['html']);

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/counters.css');
    assert_not_contains('!important', $css, 'слои правил больше не спорят флагом приоритета');
    assert_not_contains('.counter:hover', $css, 'наведение — только у показателя-ссылки');

    // Ссылку проверяет и вывод: данные приезжают и из файла шаблона страницы.
    $raw = BlockRenderer::render(['id' => 409, 'type' => 'counters', 'custom_css' => '', 'data' => json_encode([
        'items' => [['value' => '1', 'label' => 'а', 'link' => 'javascript:alert(1)']],
    ])]);
    assert_not_contains('javascript:', (string) $raw['html']);

    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');
    assert_contains("'][delta_tone]", $form, 'оценка изменения задаётся в форме');
});
