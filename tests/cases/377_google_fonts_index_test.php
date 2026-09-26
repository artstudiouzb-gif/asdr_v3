<?php

declare(strict_types=1);

use App\Core\DesignSettings;
use App\Models\Setting;

/**
 * Каталог шрифтов «Дизайна» был двадцатью строками: форма обещала «Каталог
 * Google Fonts», а предлагала отобранный список. Полный каталог лежит
 * сгенерированным файлом — здесь проверяется, что он на месте и в том же
 * формате, который понимают установщик и проверка покрытия.
 */
test('индекс Google Fonts прочитан и лежит в формате каталога', function (): void {
    $file = dirname(__DIR__, 2) . '/app/Core/data/google-fonts-index.php';
    assert_true(is_file($file), 'нет app/Core/data/google-fonts-index.php — соберите npm run build:fonts-index');

    $raw = (array) require $file;
    assert_true(count($raw) > 150, 'в индексе подозрительно мало семейств: ' . count($raw));

    foreach ($raw as $slug => $entry) {
        assert_true(is_string($slug) && preg_match('/^[a-z0-9-]+$/', $slug) === 1, "негодный слуг: {$slug}");
        assert_same(3, count($entry), "у {$slug} не три поля");
        assert_true($entry[0] !== '', "у {$slug} нет подписи");

        // Формат запроса тот же, что разбирает LocalGoogleFonts::coverageError:
        // иначе установка отвалится на «неверное описание семейства в каталоге».
        assert_true(
            preg_match('/^([^:]+):wght@([0-9;]+)$/', $entry[2], $parts) === 1,
            "у {$slug} негодный family для css2: {$entry[2]}"
        );

        // Стек обязан начинаться тем же семейством, которое скачает установщик:
        // разъедутся — страница объявит шрифт, для которого нет ни одного
        // @font-face, и браузер молча нарисует системным.
        $family = str_replace('+', ' ', $parts[1]);
        assert_true(
            str_starts_with($entry[1], "'" . $family . "'"),
            "у {$slug} стек не начинается семейством {$family}: {$entry[1]}"
        );
    }
});

test('полный каталог не дублирует отобранные и рукописные семейства', function (): void {
    $extra = DesignSettings::googleFontsExtra();
    assert_true($extra !== [], 'полный каталог пуст');

    $named = static function (array $catalog): array {
        $names = [];
        foreach ($catalog as $entry) {
            $names[strtolower(explode(':', $entry[2])[0])] = true;
        }

        return $names;
    };
    $known = $named(DesignSettings::GOOGLE_FONTS) + $named(DesignSettings::SCRIPT_FONTS);

    foreach ($extra as $slug => $entry) {
        assert_false(isset(DesignSettings::GOOGLE_FONTS[$slug]), "слуг {$slug} уже есть среди отобранных");
        assert_false(isset(DesignSettings::SCRIPT_FONTS[$slug]), "слуг {$slug} уже есть среди рукописных");
        // Один и тот же шрифт под двумя слугами («Exo 2» — exo2 и exo-2) стоял
        // бы в форме дважды с разными подписями.
        assert_false(
            isset($known[strtolower(explode(':', $entry[2])[0])]),
            "семейство {$entry[0]} уже есть в другом каталоге под другим слугом"
        );
    }

    $catalog = DesignSettings::googleFontCatalog();
    assert_same(
        count(DesignSettings::GOOGLE_FONTS) + count($extra),
        count($catalog),
        'каталог для текста и заголовков потерял или задвоил записи'
    );
    foreach (array_keys(DesignSettings::SCRIPT_FONTS) as $slug) {
        assert_true(isset(DesignSettings::fontCatalog()[$slug]), "рукописный {$slug} пропал из общего каталога");
    }
});

test('семейства без узбекской кириллицы в каталог не попадают', function (): void {
    $catalog = DesignSettings::fontCatalog();
    // У Jost нет cyrillic-ext: установка такого семейства отвалилась бы
    // проверкой покрытия, то есть выбор в форме был бы ловушкой.
    assert_false(isset($catalog['jost']), 'Jost не содержит cyrillic-ext');
    assert_false(isset($catalog['poppins']), 'Poppins не содержит кириллицы');
});

test('форма «Дизайна» показывает полный каталог, а не только отобранные', function (): void {
    $view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/design/index.php');
    assert_true(
        substr_count($view, 'googleFontsExtra()') >= 1,
        'форма читает только GOOGLE_FONTS — полный каталог редактору недоступен'
    );
    assert_true(
        substr_count($view, '$googleExtraFonts as $slug') >= 2,
        'полный каталог должен предлагаться и для текста, и для заголовков'
    );
});

test('семейство из полного каталога применяется, а не отбрасывается молча', function (): void {
    // Форма предлагала весь каталог, а сохранение сверяло выбор с
    // отобранными двадцатью: Onest скачивался на диск, а сайт оставался на
    // прежнем шрифте — без единого сообщения.
    assert_true(isset(DesignSettings::googleFontsExtra()['onest']), 'Onest должен быть в полном каталоге');
    assert_same(
        ['font_style' => 'system', 'font_google_body' => 'onest'],
        DesignSettings::normalizeBodyFontChoice('google:onest'),
        'выбор из полного каталога нормализуется в пустоту'
    );
});

test('семейство из полного каталога доходит до стеков текста и заголовков (БД)', function (): void {
    ensure_test_db();
    reset_design_state();
    // Стеки шрифтов сброс дизайна не трогает — возвращаем их сами.
    $absent = "\0absent";
    $before = ['font_family' => Setting::get('font_family', $absent), 'font_heading' => Setting::get('font_heading', $absent)];
    try {
        DesignSettings::save(['font_google_body' => 'onest', 'font_google_heading' => 'onest']);
        assert_same('onest', (string) Setting::get('design_font_google_body', ''));
        assert_same('google:onest', DesignSettings::bodyFontChoice(), 'форма показала бы другой выбор');
        assert_true(str_starts_with((string) Setting::get('font_family', ''), "'Onest'"), 'текст остался на прежнем шрифте');
        assert_true(str_starts_with((string) Setting::get('font_heading', ''), "'Onest'"), 'заголовки остались на прежнем шрифте');
    } finally {
        reset_design_state();
        foreach ($before as $key => $value) {
            if ($value === $absent) {
                \App\Core\Database::pdo()->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$key]);
            } else {
                Setting::set($key, $value);
            }
        }
    }
});
