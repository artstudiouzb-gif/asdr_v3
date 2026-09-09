<?php

declare(strict_types=1);

/*
 * Микро-фреймворк ассертов для нативного тест-раннера. Никаких зависимостей.
 * Использование в файлах tests/cases/*.php:
 *
 *   test('описание', function () {
 *       assert_same(2, 1 + 1);
 *       assert_true(is_string('x'));
 *   });
 */

final class TestRunner
{
    /** @var array<int, array{name: string, fn: callable}> */
    public static array $tests = [];
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $skipped = 0;
    /** @var array<int, string> */
    public static array $failures = [];

    /**
     * Имя выполняющегося сейчас теста. Нужно обработчику завершения в run.php:
     * если продуктовый код внутри теста вызовет exit, только эта запись
     * покажет, на чём именно оборвался прогон.
     */
    public static string $current = '';

    public static function currentTestName(): string
    {
        return self::$current !== '' ? self::$current : '(до первого теста)';
    }
}

final class SkipTest extends \RuntimeException
{
}

function test(string $name, callable $fn): void
{
    TestRunner::$tests[] = ['name' => $name, 'fn' => $fn];
}

function skip_test(string $reason): void
{
    throw new SkipTest($reason);
}

function assert_true(bool $cond, string $message = ''): void
{
    if ($cond !== true) {
        throw new \RuntimeException('assert_true failed' . ($message !== '' ? ": {$message}" : ''));
    }
}

function assert_false(bool $cond, string $message = ''): void
{
    if ($cond !== false) {
        throw new \RuntimeException('assert_false failed' . ($message !== '' ? ": {$message}" : ''));
    }
}

/** @param mixed $expected @param mixed $actual */
function assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf(
            "assert_same failed%s\n     expected: %s\n     actual:   %s",
            $message !== '' ? ": {$message}" : '',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

/** @param mixed $expected @param mixed $actual */
function assert_equals($expected, $actual, string $message = ''): void
{
    assert_same($expected, $actual, $message);
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        if ((str_contains($haystack, "\r") || str_contains($needle, "\r"))
            && str_contains(str_replace("\r\n", "\n", $haystack), str_replace("\r\n", "\n", $needle))) {
            return;
        }
        throw new \RuntimeException(sprintf(
            "assert_contains failed%s\n     needle:   %s\n     haystack: %s",
            $message !== '' ? ": {$message}" : '',
            $needle,
            mb_strlen($haystack) > 300 ? mb_substr($haystack, 0, 300) . '…' : $haystack
        ));
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    $found = str_contains($haystack, $needle)
        || ((str_contains($haystack, "\r") || str_contains($needle, "\r"))
            && str_contains(str_replace("\r\n", "\n", $haystack), str_replace("\r\n", "\n", $needle)));
    if ($found) {
        throw new \RuntimeException(sprintf(
            "assert_not_contains failed%s\n     forbidden needle found: %s",
            $message !== '' ? ": {$message}" : '',
            $needle
        ));
    }
}

function run_tests(): int
{
    foreach (TestRunner::$tests as $t) {
        TestRunner::$current = $t['name'];
        try {
            ($t['fn'])();
            TestRunner::$passed++;
            fwrite(STDOUT, "  \033[32m✓\033[0m {$t['name']}\n");
        } catch (SkipTest $s) {
            TestRunner::$skipped++;
            fwrite(STDOUT, "  \033[33m•\033[0m {$t['name']} (пропущен: {$s->getMessage()})\n");
        } catch (\Throwable $e) {
            TestRunner::$failed++;
            TestRunner::$failures[] = $t['name'];
            fwrite(STDOUT, "  \033[31m✗\033[0m {$t['name']}\n");
            foreach (explode("\n", (string) $e->getMessage()) as $line) {
                fwrite(STDOUT, "      {$line}\n");
            }
        }
    }

    if (TestRunner::$failed > 0) {
        fwrite(STDOUT, "\n\033[31mПроваленные тесты:\033[0m\n  - " . implode("\n  - ", TestRunner::$failures) . "\n");
    }

    fwrite(STDOUT, sprintf(
        "\nИтого: %d пройдено, %d провалено, %d пропущено\n",
        TestRunner::$passed,
        TestRunner::$failed,
        TestRunner::$skipped
    ));

    return TestRunner::$failed === 0 ? 0 : 1;
}

/**
 * Сбрасывает «ручные» настройки дизайна в памяти на время теста.
 *
 * Настройки дизайна живут в общей таблице settings, и тесты, которые их
 * сохраняют, оставляли значения в тестовой БД до следующего прогона: соседние
 * проверки потом падали на чужом радиусе 13px или шрифте Inter. Override
 * только в памяти — БД не трогаем, порядок и содержимое прогонов не важны.
 */
function reset_design_state(): void
{
    $keys = [
        'design_radius_custom', 'design_font_size_custom', 'design_line_height_custom',
        'design_newsdetail_padding_top', 'design_newsdetail_padding_bottom',
        'design_meta_letter_spacing_custom',
        'design_heading_line_height_custom', 'design_heading_line_height', 'design_heading_font_weight', 'design_heading_letter_spacing',
        'design_container_custom', 'design_font_google_body', 'design_font_google_heading',
        'design_custom_color_primary', 'design_custom_color_accent', 'design_custom_font_family',
        'design_typo_scale', 'design_font_style', 'design_preset',
        'design_semantic_bg_primary', 'design_semantic_bg_surface',
        'design_semantic_text_main', 'design_semantic_text_muted', 'design_semantic_border_color',
        'design_spacing_space_small', 'design_spacing_space_premium', 'design_spacing_space_max',
    ];
    foreach (array_keys(\App\Core\DesignSettings::OPTIONS) as $optionKey) {
        $keys[] = 'design_' . $optionKey;
    }
    foreach (array_keys(\App\Core\DesignSettings::TYPO_SIZES) as $fsKey) {
        $keys[] = 'design_' . $fsKey;
    }
    $keys = array_values(array_unique($keys));

    // На тестовой БД строки удаляем, а не обнуляем: для части настроек пустое
    // значение и отсутствие ключа — разные вещи (design_custom_color_primary
    // при отсутствии откатывается к color_primary, а при пустой строке — к
    // цвету по умолчанию). Записать '' значило бы менять смысл проверок.
    // Setting::set() к тому же сбрасывает кэш, поэтому переопределение в
    // памяти не пережило бы первую же запись внутри теста.
    // Без TEST_DB_* подключение ведёт к рабочей базе разработчика — там
    // ограничиваемся памятью, чтобы не стереть его собственные настройки.
    if ((string) (getenv('TEST_DB_DATABASE') ?: '') === '') {
        foreach ($keys as $key) {
            \App\Models\Setting::overrideInMemory($key, '');
        }

        return;
    }

    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    \App\Core\Database::pdo()
        ->prepare("DELETE FROM settings WHERE `key` IN ({$placeholders})")
        ->execute($keys);
    \App\Models\Setting::set('design_preset', ''); // заодно сбрасывает кэш настроек
}

/**
 * Разметка редактора блока целиком: рукописная форма плюс поля, которые
 * рисует схема (`BlockFieldSchema`).
 *
 * Проверки «настройка доступна редактору» обязаны смотреть сюда, а не в один
 * `block_form.php`: у типа, переехавшего на схему, поля собираются на лету, и
 * поиск по файлу формы их не находит — тест падал бы на здоровом коде.
 */
function block_editor_markup(): string
{
    static $markup = null;
    if ($markup !== null) {
        return $markup;
    }

    $markup = (string) file_get_contents(APP_ROOT . '/app/Views/admin/pages/block_form.php');
    foreach (array_keys(\App\Core\BlockData\BlockFieldSchema::all()) as $type) {
        $markup .= "\n" . \App\Core\BlockData\BlockFieldSchema::formHtml($type, []);
    }

    return $markup;
}

/**
 * Публичная тема целиком: gov-theme.css плюс части, вынесенные из общего
 * бандла ради страниц, которым они не нужны (AssetCollector::THEME_PART_MAP).
 *
 * Тесты проверяют правила темы, а не то, в каком файле они лежат. Читать
 * напрямую gov-theme.css нельзя: очередной вынос по разделу 2.1 плана
 * (docs/PERFORMANCE_PLAN.md) молча уронил бы половину проверок вёрстки.
 */
function theme_css(): string
{
    static $css = null;
    if ($css !== null) {
        return $css;
    }

    $parts = [APP_ROOT . '/public/assets/css/gov-theme.css'];
    foreach (glob(APP_ROOT . '/public/assets/css/blocks/*.css') ?: [] as $file) {
        // Минифицированные сборки — производные, в проверках не участвуют.
        if (!str_ends_with($file, '.min.css')) {
            $parts[] = $file;
        }
    }

    $css = '';
    foreach ($parts as $file) {
        $css .= (string) @file_get_contents($file) . "\n";
    }

    return str_replace("\r\n", "\n", $css);
}

/**
 * Все печатные правила публичной части: содержимое каждого блока
 * «@media print» из frontend.css, темы и вынесенных частей темы.
 *
 * Тесты печати раньше брали окно фиксированной длины от первого вхождения
 * «@media print» в конкретном файле. Такая проверка ломается от любого
 * переноса правил между файлами и от вставки нового печатного блока выше,
 * хотя сама вёрстка при этом в порядке.
 */
function public_print_css(): string
{
    static $css = null;
    if ($css !== null) {
        return $css;
    }

    $files = [
        APP_ROOT . '/public/assets/css/frontend.css',
        APP_ROOT . '/public/assets/css/gov-theme.css',
        APP_ROOT . '/public/assets/css/public-content-modes.css',
    ];
    foreach (glob(APP_ROOT . '/public/assets/css/blocks/*.css') ?: [] as $file) {
        if (!str_ends_with($file, '.min.css')) {
            $files[] = $file;
        }
    }

    $css = '';
    foreach ($files as $file) {
        $source = (string) @file_get_contents($file);
        $offset = 0;
        while (($start = strpos($source, '@media print', $offset)) !== false) {
            $brace = strpos($source, '{', $start);
            if ($brace === false) {
                break;
            }
            $depth = 1;
            $i = $brace + 1;
            $len = strlen($source);
            while ($i < $len && $depth > 0) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}') {
                    $depth--;
                }
                $i++;
            }
            $css .= substr($source, $start, $i - $start) . "\n";
            $offset = $i;
        }
    }

    return $css;
}

/*
 * Реестр бюджетов качества: величины, которые могут только уменьшаться.
 * Потолок и способ замера объявлены там один раз, сторожа в tests/cases
 * читают оттуда же — второй список разъехался бы с первым молча.
 */
require_once __DIR__ . '/budgets.php';
