<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Lang;

// i18n интерфейса сайта: словарь переводов и помощник t()/Lang::t().

test('Словарь UZ загружается и содержит ключевые строки интерфейса', function () {
    $uz = require __DIR__ . '/../../app/Core/lang/uz.php';
    assert_true(is_array($uz), 'uz.php возвращает массив');
    assert_same('Batafsil o‘qish', $uz['Читать далее'] ?? null, 'перевод «Читать далее»');
    assert_same('Barcha yangiliklar', $uz['Все новости'] ?? null, 'перевод «Все новости»');
    assert_true(isset($uz['Проекты и инициативы']), 'есть перевод вводного текста раздела');
    // Ни один перевод не пустой.
    foreach ($uz as $key => $val) {
        assert_true(is_string($val) && $val !== '', 'перевод не пустой: ' . $key);
    }
});

test('Словари UZ и EN синхронны и не содержат пустых переводов', function () {
    $uz = require __DIR__ . '/../../app/Core/lang/uz.php';
    $en = require __DIR__ . '/../../app/Core/lang/en.php';
    assert_same([], array_values(array_diff(array_keys($uz), array_keys($en))), 'ключи UZ присутствуют в EN');
    assert_same([], array_values(array_diff(array_keys($en), array_keys($uz))), 'ключи EN присутствуют в UZ');
    foreach (['uz' => $uz, 'en' => $en] as $lang => $dictionary) {
        foreach ($dictionary as $key => $value) {
            assert_true(is_string($value) && trim($value) !== '', "{$lang}: перевод не пустой: {$key}");
        }
    }

    // PHP молча оставляет из повторяющихся ключей последний, поэтому дубль
    // виден только по исходнику: дважды добавленная строка тихо перебивает
    // прежний перевод.
    foreach (['uz', 'en'] as $lang) {
        $source = (string) file_get_contents(APP_ROOT . '/app/Core/lang/' . $lang . '.php');
        preg_match_all('/^\s{4}\'((?:[^\'\\\\]|\\\\.)*)\'\s*=>/mu', $source, $matches);
        $duplicates = array_keys(array_filter(array_count_values($matches[1]), static fn (int $count): bool => $count > 1));
        assert_same([], $duplicates, "{$lang}: повторяющиеся ключи словаря");
    }
});

test('Каждый ключ t() из публичного кода есть в обоих словарях', function () {
    // Ключ, которого нет в словаре, не ошибка для t() — он вернёт саму
    // русскую строку, и на /uz она молча остаётся русской. Отдельно найти
    // такую строку нечем: страница отвечает 200 и выглядит целой.
    $uz = require APP_ROOT . '/app/Core/lang/uz.php';
    $en = require APP_ROOT . '/app/Core/lang/en.php';

    $files = [];
    foreach (['app/Views/site', 'app/Views/errors', 'app/Controllers/Site', 'app/Core', 'templates'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(APP_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/lang/') || !str_ends_with($path, '.php')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    $missing = [];
    foreach ($files as $path) {
        $tokens = token_get_all((string) file_get_contents($path));
        $significant = [];
        foreach ($tokens as $index => $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $index;
        }
        foreach ($significant as $n => $index) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 't') {
                continue;
            }
            if (($tokens[$significant[$n + 1] ?? -1] ?? null) !== '(') {
                continue;
            }
            $arg = $tokens[$significant[$n + 2] ?? -1] ?? null;
            if (!is_array($arg) || $arg[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue; // t($variable) проверить нечем
            }
            $key = stripcslashes(substr($arg[1], 1, -1));
            if (!preg_match('~[А-Яа-яЁё]~u', $key)) {
                continue;
            }
            $where = str_replace(APP_ROOT . '/', '', $path) . ':' . $arg[2];
            if (!isset($uz[$key])) {
                $missing[] = "uz: «{$key}» ({$where})";
            }
            if (!isset($en[$key])) {
                $missing[] = "en: «{$key}» ({$where})";
            }
        }
    }

    assert_same([], $missing, 'нет перевода у ключей: ' . implode('; ', array_slice($missing, 0, 5)));
});

test('Публичная шапка переводит навигацию, поиск и панель доступности', function () {
    $header = (string) file_get_contents(APP_ROOT . '/app/Views/site/_header.php');
    foreach (['Основное меню', 'Открыть подменю', 'Для слабовидящих', 'Перейти к содержимому', 'Закрыть поиск'] as $label) {
        assert_contains("\$et('{$label}')", $header, "шапка переводит: {$label}");
    }
    assert_not_contains('>Перейти к содержимому</a>', $header);
    assert_not_contains('>Обычная версия</a>', $header);
});

test('Глобальный помощник t() определён', function () {
    assert_true(function_exists('t'), 'функция t() зарегистрирована в bootstrap');
});

test('Lang::t: перевод, идентичность на языке по умолчанию и фолбэк', function () {
    if (!Database::isConnected()) {
        return; // Language::defaultCode() требует БД
    }
    // Язык по умолчанию (ru) — ключ возвращается как есть.
    assert_same('Читать далее', Lang::t('Читать далее', 'ru'));
    // UZ — из словаря.
    assert_same('Batafsil o‘qish', Lang::t('Читать далее', 'uz'));
    // Неизвестный ключ — возвращается сам ключ (безопасный фолбэк).
    assert_same('__no_such_key__', Lang::t('__no_such_key__', 'uz'));
});
