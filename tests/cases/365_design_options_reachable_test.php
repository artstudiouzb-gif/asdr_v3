<?php

declare(strict_types=1);

use App\Core\DesignSettings;

/*
 * Настройка «Дизайна», до которой не дотянуться из формы, — мёртвая с другой
 * стороны.
 *
 * Тест 243 стережёт одно направление: раздел не предлагает настроек, которыми
 * не управляет. Обратное не стерёг никто — и накопилось. `title_reveal`
 * объявлен в схеме, описан в заметках проекта как рабочий приём и выставляется
 * пресетами, но в форме не встречается ни разу: поменять его можно было только
 * правкой базы. У `font_size` и `line_height` в форме стоят скрытые поля,
 * которые пересылают обратно уже сохранённое значение — то есть выбор из
 * четырёх и трёх вариантов не предлагается вовсе, хотя оба списка объявлены.
 *
 * Это тот же класс отказа, что у настроек блока «Карточки» и у размера текста
 * оргструктуры: данные, нормализатор и CSS на месте, а редактор настройки не
 * видит ни разу.
 *
 * Условие проверки: настройка либо попадает в общий цикл формы (её группа не в
 * списке пропускаемых), либо нарисована в форме поимённо — и не скрытым полем.
 */

test('Каждая настройка «Дизайна» доступна редактору в форме', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/design/index.php');

    // Доступность считаем по фактической логике самой формы, а не по своему
    // списку: свой разъехался бы с ней при первой правке и начал бы врать в
    // обе стороны сразу. Групп в форме несколько циклов, и они бывают трёх
    // видов.
    //
    // 1. Общий цикл в конце файла с пропуском перечисленных групп.
    $skipped = [];
    if (preg_match('/in_array\(\$groupName,\s*\[(.+?)\]/s', $view, $m) === 1) {
        preg_match_all("/'([^']+)'/", $m[1], $found);
        $skipped = $found[1];
    }
    assert_true($skipped !== [], 'общий цикл формы с пропуском групп обязан остаться');

    // 2. Отдельные циклы по своей группе: foreach ($grouped['Общие'] ...).
    preg_match_all("/\\\$grouped\\['([^']+)'\\]/", $view, $named);
    $renderedGroups = array_values(array_unique($named[1]));

    // 3. Отдельные ключи, выброшенные внутри цикла: in_array($key, [...]).
    $excludedKeys = [];
    if (preg_match_all('/in_array\(\$key,\s*\[(.+?)\]/s', $view, $keyMatch) > 0) {
        foreach ($keyMatch[1] as $chunk) {
            preg_match_all("/'([^']+)'/", $chunk, $found);
            $excludedKeys = array_merge($excludedKeys, $found[1]);
        }
    }

    // Скрытые поля выбора не дают: они пересылают уже сохранённое значение.
    // Именно так «пропали» размер шрифта и межстрочный интервал.
    preg_match_all('/<input[^>]*type="hidden"[^>]*>/', $view, $hiddenMatch);
    $hidden = implode("\n", $hiddenMatch[0]);

    // Два ключа сняты с формы намеренно, и это видно по коду рядом с ними:
    // «Палитру» форма всегда сохраняет как `custom` (скрытое поле), потому что
    // цвета задаются по одному на своей вкладке, а `font_style` вытеснен более
    // подробным выбором семейства (`font_body_choice` + свой font-family).
    // Список короткий и с причиной; третий ключ, тихо выпавший из формы, тест
    // поймает.
    $byDesign = ['palette', 'font_style'];
    assert_contains('name="palette"', $view, 'палитра обязана уходить на сервер скрытым полем');
    assert_contains('name="font_body_choice"', $view, 'выбор семейства шрифта заменяет пресет font_style');

    $unreachable = [];
    foreach (DesignSettings::OPTIONS as $key => $opt) {
        if (in_array($key, $byDesign, true)) {
            continue;
        }
        $group = (string) ($opt['group'] ?? '');
        $byLoop = (!in_array($group, $skipped, true) || in_array($group, $renderedGroups, true))
            && !in_array($key, $excludedKeys, true);
        if ($byLoop) {
            continue;
        }

        $named = 'name="' . $key . '"';
        if (str_contains($view, $named) && !design_option_only_hidden($named, $view, $hidden)) {
            continue;
        }

        $unreachable[] = $key . ' (группа «' . $group . '»)';
    }

    assert_same(
        [],
        $unreachable,
        'настройки объявлены, но редактор их не видит: ' . implode(', ', $unreachable)
    );
});

test('Скрытое поле не считается доступной настройкой', function (): void {
    // Проверяем сам приём измерения: без этого тест выше мог бы молча считать
    // спрятанное поле рабочим выбором — ровно та ошибка, которую он ловит.
    $view = '<input type="hidden" name="demo_key" value="md">';
    preg_match_all('/<input[^>]*type="hidden"[^>]*>/', $view, $m);
    $hidden = implode("\n", $m[0]);

    assert_true(design_option_only_hidden('name="demo_key"', $view, $hidden), 'скрытое поле распознано');

    $visible = '<input type="radio" name="demo_key" value="md">';
    assert_false(
        design_option_only_hidden('name="demo_key"', $visible, ''),
        'обычное поле обязано считаться доступным'
    );
});

/** Встречается ли имя поля только внутри скрытых input'ов. */
function design_option_only_hidden(string $named, string $view, string $hidden): bool
{
    $inView = substr_count($view, $named);
    $inHidden = substr_count($hidden, $named);

    return $inView > 0 && $inView === $inHidden;
}
