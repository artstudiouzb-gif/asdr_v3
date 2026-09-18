<?php

declare(strict_types=1);

use App\Core\Lang;
use App\Models\InterfaceTranslation;

test('Перевод интерфейса из БД перекрывает файл и очищается обратно до fallback (БД)', function (): void {
    ensure_test_db();

    $key = 'Читать далее';
    $pdo = \App\Core\Database::pdo();
    $pdo->prepare('DELETE FROM interface_translations WHERE lang = ? AND translation_key = ?')
        ->execute(['uz', $key]);
    InterfaceTranslation::flush();
    Lang::flush();

    assert_same('Batafsil o‘qish', Lang::t($key, 'uz'), 'сначала работает штатный uz.php');

    InterfaceTranslation::saveLanguage('uz', [$key => 'Batafsil']);
    Lang::flush();
    assert_same('Batafsil', Lang::t($key, 'uz'), 'значение из админки сильнее файла');

    InterfaceTranslation::saveLanguage('uz', [$key => '']);
    Lang::flush();
    assert_same('Batafsil o‘qish', Lang::t($key, 'uz'), 'очистка override возвращает файл');
});

test('Редактор получает системные ключи из словаря и вызовов t() (БД)', function (): void {
    // Список активных языков лежит в базе, и без неё словари uz/en в сбор не
    // попадают вовсе — остаются одни литералы t(). Пока сценарий не был помечен
    // как требующий базы, он на машине без неё падал, а не пропускался.
    ensure_test_db();

    $keys = Lang::sourceKeys();

    assert_true(in_array('Читать далее', $keys, true));
    assert_true(in_array('Показать интерактивную карту', $keys, true));
    assert_same(count($keys), count(array_unique($keys)), 'в таблице нет дублей ключей');
});

test('Админка языков содержит вход в таблицу переводов и защищённые маршруты', function (): void {
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/languages/index.php');
    $table = (string) file_get_contents(APP_ROOT . '/app/Views/admin/languages/translations.php');
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/LanguageController.php');

    assert_contains('/admin/languages/translations', $view);
    assert_contains('translation_key[]', $table);
    assert_contains('translation_value[]', $table);
    assert_contains("get('/admin/languages/translations'", $routes);
    assert_contains("post('/admin/languages/translations'", $routes);
    assert_contains('Csrf::verifyRequest()', $controller);
    assert_contains('Auth::requireSuperAdmin()', $controller);
});

test('Миграция и полная схема объявляют interface_translations', function (): void {
    $migration = (string) file_get_contents(APP_ROOT . '/database/migrations/2026_09_18_interface_translations.sql');
    $schema = (string) file_get_contents(APP_ROOT . '/database/schema.sql');

    assert_contains('CREATE TABLE IF NOT EXISTS interface_translations', $migration);
    assert_contains('UNIQUE KEY uq_interface_translation (lang, translation_key)', $migration);
    assert_contains('CREATE TABLE IF NOT EXISTS interface_translations', $schema);
});

test('Правка перевода сбрасывает кеш собранных страниц', function (): void {
    // Забыть словарь мало: подписи уже впечатаны в страницы, а те кешируются
    // ключом page:<id>:<lang> с TTL из perf_cache_ttl — умолчание 0, то есть
    // «живёт до правки контента». Без сброса редактор менял подпись, видел
    // «сохранено» и не находил её на сайте до правки любого другого материала.
    $source = (string) file_get_contents(APP_ROOT . '/app/Models/InterfaceTranslation.php');

    assert_contains("Cache::forgetPrefix('page:')", $source);

    // Сброс обязан стоять у каждой записи, а не у одной из трёх.
    $chunks = preg_split('/\n    (?:public|private) static function /', $source) ?: [];
    foreach (['saveLanguage', 'renameLanguage', 'deleteLanguage'] as $method) {
        $body = '';
        foreach ($chunks as $chunk) {
            if (str_starts_with((string) $chunk, $method . '(')) {
                $body = (string) $chunk;
                break;
            }
        }
        assert_true($body !== '', 'метод записи потерян: ' . $method);
        assert_contains('self::bustPageCache();', $body);
    }
});
