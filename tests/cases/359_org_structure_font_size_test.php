<?php

declare(strict_types=1);

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockRenderer;

/*
 * Размер текста схемы оргструктуры.
 *
 * Настройка была наполовину: шаблон читал `data['font_size']` и печатал
 * `--org-font-size` в scoped CSS, но поля в схеме не было — значение неоткуда
 * было взять и негде сохранить. А если бы и было, оно ничего не меняло: в
 * конце файла стилей лежал остаток «статичного режима» — те же селекторы с
 * font-size в пикселях и `--org-font: 16px`, — и переменную никто не читал.
 *
 * Отказ тихий с обеих сторон: форма выглядит полной, шаблон выглядит рабочим.
 * Поэтому здесь проверяется вся цепочка, а не одно звено.
 */

/** @param array<string, mixed> $data */
function org_structure_output(array $data): string
{
    $rendered = BlockRenderer::render([
        'id' => 7,
        'type' => 'org_structure',
        'custom_css' => null,
        'data' => json_encode($data + ['head_title' => 'Директор', 'branches' => [['role' => 'Заместитель']]]),
    ]);

    return $rendered['html'] . "\n" . ($rendered['css'] ?? '');
}

test('Размер текста схемы объявлен схемой и доступен редактору', function () {
    assert_true(
        isset(BlockFieldSchema::fields('org_structure')['font_size']),
        'у оргструктуры нет поля размера текста'
    );
    assert_contains('bf_font_size', block_editor_markup(), 'поле размера не попало в форму блока');
});

test('Выбранный размер доезжает до scoped CSS, а «как в теме» ничего не печатает', function () {
    assert_contains('--org-font-size:20px', org_structure_output(['font_size' => 20]));
    assert_not_contains('--org-font-size', org_structure_output(['font_size' => 0]), 'умолчание не должно задавать размер');

    // Значение вне набора — подделанная форма, а не «ближайшее допустимое»:
    // схема заменяет его умолчанием, и переменной снова нет.
    assert_not_contains('--org-font-size', org_structure_output(['font_size' => 99]));
});

test('Размеры схемы считаются от --org-font, а не задаются пикселями', function () {
    // Сторож против возврата «статичного режима»: одно правило с font-size в
    // пикселях снова обесценит настройку, и увидеть это можно только замером.
    $css = (string) preg_replace(
        '#/\*.*?\*/#s',
        '',
        (string) file_get_contents(APP_ROOT . '/public/assets/css/blocks/org-structure.css')
    );

    assert_same(
        1,
        preg_match_all('/--org-font\s*:/', $css),
        '--org-font объявляется один раз: второе объявление перекроет настройку'
    );

    preg_match_all('/\.orgstruct[^{]*\{[^}]*\}/', $css, $rules);
    foreach ($rules[0] as $rule) {
        if (preg_match('/font-size\s*:\s*([^;}]+)/', $rule, $m) !== 1) {
            continue;
        }
        $value = trim($m[1]);
        assert_true(
            str_contains($value, 'var(--org-font)') || str_contains($value, 'var(--step'),
            'размер в схеме задан мимо --org-font и шкалы: ' . $value
        );
    }
});
