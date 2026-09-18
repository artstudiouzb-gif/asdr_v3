<?php

declare(strict_types=1);

/*
 * Поле, показываемое не при каждом варианте, читает ВЫБРАННУЮ радиокнопку.
 *
 * Вариант отображения показывается, а не называется: это набор плиток с
 * рисунком раскладки, то есть радиокнопки, а не <select>. У радиокнопки
 * `value` — её собственное значение, поэтому querySelector('[name="variant"]')
 * отдавал первую плитку набора и её значение независимо от выбора.
 *
 * Отказ тихий и полный: поле либо не показывалось никогда (значение первой
 * плитки в списке допустимых не значится), либо показывалось всегда, в том
 * числе у чужого варианта. Замерено в браузере на настоящей разметке
 * AdminUi::variantField: до правки выбор «С акцентной цитатой» оставлял поле
 * цитаты скрытым — то есть у блока «Текст» нечем было набрать саму цитату,
 * хотя данные, нормализатор и шаблон были на месте.
 *
 * Подписка страдала тем же: слушали одну первую кнопку набора, а `change`
 * приходит на ту, которую нажали.
 */

/** Тело обработчика условных полей в admin.js. */
function variant_fields_handler(): string
{
    $js = (string) file_get_contents(APP_ROOT . '/public/assets/js/admin.js');
    $start = strpos($js, "document.querySelectorAll('[data-field-when]')");
    assert_true($start !== false, 'обработчик условных полей найден');

    // Тело берём до конца самой IIFE, а не окном фиксированной длины: окно
    // разъезжается с кодом при первой же правке и начинает проверять соседа.
    $end = strpos($js, "})();", (int) $start);
    assert_true($end !== false, 'конец обработчика найден');

    return substr($js, (int) $start, (int) $end - (int) $start);
}

test('Условное поле спрашивает отмеченную радиокнопку, а не первую в наборе', function (): void {
    $body = variant_fields_handler();

    assert_contains("if (group[i].checked) return group[i].value;", $body, 'значение берётся у отмеченной кнопки');
    assert_contains("if (group[i].type !== 'radio') continue;", $body, 'набор кнопок опознаётся по типу поля');
    assert_contains('querySelectorAll', $body, 'подписка идёт на весь набор кнопок');

    // Прежний вызов брал первый элемент с этим именем и читал его value.
    assert_false(
        (bool) preg_match('/var source = document\.querySelector\(\'\[name="\' \+ [\w.()\[\]\' -]*\'"\]\'\);\s*\n\s*if \(!source\) return;\s*\n[^\n]*\n\s*field\.hidden = allowed\.indexOf\(source\.value\)/', $body),
        'значение больше не читается у первой кнопки набора'
    );
});

test('Набор без отмеченной кнопки оставляет поле видимым', function (): void {
    // Скрытие — подсказка редактору, а не условие сохранения. Спрятать поле,
    // которое редактор не может открыть, хуже, чем показать лишнее: без JS
    // видны все поля, и это же поведение обязано быть запасным с JS.
    $body = variant_fields_handler();

    assert_contains('return radio ? null : group[0].value;', $body, 'у набора без выбора ответа нет');
    assert_contains('if (value === null) return;', $body, 'без ответа видимость не трогается');
});

test('Каждое условное поле схемы указывает на существующее поле того же блока', function (): void {
    // Условие, сославшееся на несуществующее поле, — тот же тихий отказ:
    // источника нет, значение не прочитать, настройка не показывается никогда.
    $missing = [];
    foreach (\App\Core\BlockData\BlockFieldSchema::all() as $type => $fields) {
        foreach ($fields as $key => $field) {
            $rule = (new ReflectionObject($field))->getProperty('when')->getValue($field);
            if (!is_array($rule)) {
                continue;
            }
            if (!array_key_exists($rule['field'], $fields)) {
                $missing[] = $type . '.' . $key . ' → ' . $rule['field'];
                continue;
            }
            // Значение условия обязано входить в набор самого источника:
            // опечатка здесь тоже прячет поле навсегда.
            $source = $fields[$rule['field']];
            $known = array_keys((array) (new ReflectionObject($source))->getProperty('options')->getValue($source));
            if ($known === []) {
                continue;
            }
            foreach ($rule['values'] as $value) {
                if (!in_array($value, $known, true)) {
                    $missing[] = $type . '.' . $key . ' → ' . $rule['field'] . '=' . $value . ' (такого значения нет)';
                }
            }
        }
    }

    assert_same([], $missing, 'условие показа ссылается на несуществующее поле или значение');
});
