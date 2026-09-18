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

test('Редактор получает системные ключи из словаря и вызовов t()', function (): void {
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
