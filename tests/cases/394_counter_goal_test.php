<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockData\CountersBlockNormalizer;
use App\Core\BlockRenderer;
use App\Core\CounterGoal;
use App\Core\VariantPreview;

/**
 * «Показатели»: варианты «Полоса к цели» и «Шкала по годам».
 *
 * Число без цели не говорит, много это или мало, поэтому у показателя бывают
 * база и цель, а доля пути считается от базы. Без цели показатель в этих
 * вариантах выводится обычным числом — полоса без смысла хуже её отсутствия.
 */

/**
 * @param array<string, mixed> $input
 * @return array{html:string, css:string}
 */
function counters_goal_render(array $input): array
{
    $rendered = BlockRenderer::render([
        'id' => 394,
        'type' => 'counters',
        'data' => json_encode(CountersBlockNormalizer::normalize($input), JSON_UNESCAPED_UNICODE),
        'custom_css' => '',
    ]);

    return ['html' => (string) $rendered['html'], 'css' => (string) ($rendered['css'] ?? '')];
}

test('Путь к цели считается от базы, а не от нуля', function (): void {
    assert_same(68.0, CounterGoal::progress('68', '100'));
    // С 41 до 68 при цели 100 — это 46 % пути, а не 68 %.
    assert_same(45.76, round((float) CounterGoal::progress('68', '100', '41'), 2));
    // Значения как в документе: запятая и пробелы разрядов.
    assert_same(23.14, round((float) CounterGoal::progress('12,4', '30', '7,1'), 2));
    assert_same(50.0, CounterGoal::progress('12 400', '24 800'));
    // Перевыполнение упирается в шкалу, откат ниже базы — в её начало.
    assert_same(100.0, CounterGoal::progress('130', '100'));
    assert_same(0.0, CounterGoal::progress('30', '100', '41'));
    // Считать нечем — пути нет.
    assert_same(null, CounterGoal::progress('24/7', '100'));
    assert_same(null, CounterGoal::progress('68', ''));
    assert_same(null, CounterGoal::progress('68', '41', '41'));
    assert_same('45.8%', CounterGoal::css(45.76));
    assert_same('68%', CounterGoal::css(68.0));
});

test('Варианты пути к цели предложены плитками с рисунком', function (): void {
    $variant = BlockFieldSchema::all()['counters']['variant'];
    $options = $variant->options;
    foreach (['progress', 'scale'] as $value) {
        assert_true(isset($options[$value]), "у «Показателей» нет варианта {$value}");
    }
    foreach (['goal', 'axis'] as $shape) {
        assert_true(VariantPreview::isKnown($shape), "рисунок {$shape} не описан — плитка вышла бы пустой");
        assert_contains('<svg', VariantPreview::svg($shape));
    }
});

test('Поля пути к цели сохраняются и выводятся (форма → нормализатор → шаблон)', function (): void {
    $data = CountersBlockNormalizer::normalize(['variant' => 'progress', 'items' => [[
        'value' => '68', 'suffix' => '%', 'label' => 'Показатель', 'target' => '100', 'base' => '41',
        'base_label' => '2023', 'now_label' => '2026', 'target_label' => '2030', 'delta' => '+6 п.п. за год',
    ]]]);
    $item = $data['items'][0];
    foreach (['target' => '100', 'base' => '41', 'base_label' => '2023', 'now_label' => '2026', 'target_label' => '2030', 'delta' => '+6 п.п. за год'] as $key => $expected) {
        // Типограф ставит неразрывный пробел после коротких слов — сравниваем текст, а не пробелы.
        assert_same($expected, str_replace("\u{00A0}", ' ', (string) ($item[$key] ?? '')), "поле {$key} потерялось при сохранении");
    }

    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');
    foreach (['target', 'base', 'base_label', 'now_label', 'target_label', 'delta'] as $key) {
        assert_contains("\$field('{$key}'", $form, "в форме показателя нет поля {$key}");
    }
    // Группа видна только при этих вариантах — иначе редактор видел бы поля,
    // которые ни на что не влияют.
    assert_contains('data-field-when="variant" data-field-value="progress,scale"', $form);
});

test('«Полоса к цели»: полоса, отметка цели и подпись для диктора', function (): void {
    $out = counters_goal_render(['variant' => 'progress', 'items' => [
        ['value' => '68', 'suffix' => '%', 'label' => 'А', 'target' => '100', 'target_label' => '2030', 'delta' => '+6'],
        ['value' => '42', 'suffix' => 'из 55', 'label' => 'Б', 'target' => '55'],
        ['value' => '120', 'label' => 'Без цели'],
    ]]);
    assert_same(2, substr_count($out['html'], 'counter--goal'), 'показатель без цели получил полосу');
    assert_contains('class="counter__track" role="img" aria-label="Путь к цели: 68', $out['html']);
    assert_contains('counter__tick', $out['html']);
    assert_contains('+6', $out['html']);
    // Единица относится к цели, а «из 55» — нет.
    assert_contains("цель 2030 — 100\u{00A0}%", $out['html']);
    assert_false(str_contains($out['html'], 'цель — 55 из 55'), 'суффикс с цифрой приклеился к цели');
    // Доля — переменной в scoped CSS, а не инлайн-стилем.
    assert_contains('--counter-goal:68%', $out['css']);
    assert_false(str_contains($out['html'], 'style='), 'инлайн-стиль в блоке');
});

test('«Шкала по годам»: три точки и подписи базы, текущего и цели', function (): void {
    $out = counters_goal_render(['variant' => 'scale', 'items' => [
        ['value' => '68', 'label' => 'А', 'target' => '100', 'base' => '41', 'base_label' => '2023', 'now_label' => '2026', 'target_label' => '2030'],
    ]]);
    foreach (['counter__point--base', 'counter__point--now', 'counter__point--target'] as $point) {
        assert_contains($point, $out['html']);
    }
    foreach (['2023</span>41', '2026</span>68', '2030</span>100'] as $label) {
        assert_contains($label, $out['html']);
    }
    // В обычной полосе те же данные пути не рисуют: вариант выбирает редактор.
    $row = counters_goal_render(['variant' => 'row', 'items' => [['value' => '68', 'label' => 'А', 'target' => '100']]]);
    assert_false(str_contains($row['html'], 'counter--goal'), 'путь к цели появился в обычной полосе');
});

test('Стили пути к цели есть, а движение гаснет при «меньше движения»', function (): void {
    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/counters.css');
    foreach (['.block-counters--progress', '.block-counters--scale', '.counter__fill', '.counter__axis-now'] as $selector) {
        assert_contains($selector, $css, "нет правила {$selector}");
    }
    assert_contains('animation-timeline: view()', $css);
    assert_contains('html[data-a11y-motion="off"] .counter--goal .counter__fill { animation: none; }', $css);
    assert_contains('@media (prefers-reduced-motion: reduce)', $css);
});
