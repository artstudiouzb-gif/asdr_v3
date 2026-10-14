<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockRenderer;

/**
 * «Колонки»: у каждой колонки бывает своё выравнивание по высоте, а верх
 * колонки — это верх её содержимого (отступ над первым заголовком не
 * выходит за блок и не опускает колонку ниже соседней).
 */
test('Колонки: своё выравнивание отдельной колонки доходит до разметки', function (): void {
    $fields = BlockFieldSchema::all()['columns'];
    assert_same(['inherit', 'top', 'center', 'bottom'], array_keys($fields['valign_1']->options));
    assert_same('inherit', $fields['valign_1']->default);
    assert_same(['field' => 'columns', 'values' => [4]], $fields['valign_4']->when, 'четвёртая колонка видна только при четырёх колонках');

    $render = static fn (array $data): string => (string) BlockRenderer::render([
        'id' => 0, 'type' => 'columns', 'custom_css' => '',
        'data' => json_encode($data + ['columns' => 2]),
    ])['html'];

    assert_false(str_contains($render([]), 'cms-columns__col--valign-'), '«как у всех» — без класса, вид собранных страниц прежний');
    $html = $render(['valign_2' => 'center']);
    assert_same(1, substr_count($html, 'cms-columns__col cms-columns__col--valign-center'));

    $css = (string) file_get_contents(APP_ROOT . '/public/assets/css/public.min.css');
    foreach (['top' => 'start', 'center' => 'center', 'bottom' => 'end'] as $value => $self) {
        assert_contains('.cms-columns__col--valign-' . $value . '{align-self:' . $self . '}', $css);
    }
    assert_contains('.cms-columns__col>.cms-block>:first-child', $css, 'отступ над первым элементом колонки снимается');
});
