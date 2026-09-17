<?php

declare(strict_types=1);

use App\Core\AdminUi;
use App\Core\Database;
use App\Models\BlockSnippet;

/*
 * Раздел «Шаблоны страниц».
 *
 * Библиотека шаблонов существовала с самого начала, но жила внутри формы
 * страницы: увидеть сохранённое можно было, только открыв какую-нибудь
 * страницу и прокрутив её конструктор до конца, а скачать файлом — тем более
 * (список выгрузки стоял там же). То есть раздел был, но входа в него не было.
 *
 * Теперь у библиотеки свой адрес, а применение шаблона осталось **одной**
 * последовательностью на редактор и на раздел: вторая копия разъехалась бы
 * при первой правке — в одной появилась бы автокопия перед заменой, в другой
 * нет.
 */

function snippet_library_source(string $path): string
{
    return (string) file_get_contents(APP_ROOT . '/' . $path);
}

/** Тело метода контроллера: от объявления до начала следующего. */
function snippet_library_method(string $source, string $name): string
{
    $start = strpos($source, 'public function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $next = strpos($source, "\n    /**", $start);

    return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
}

test('Раздел объявлен маршрутом, пунктом меню и иконкой', function (): void {
    $routes = snippet_library_source('public/index.php');
    foreach ([
        "\$router->get('/admin/snippets'",
        "\$router->post('/admin/snippets/apply'",
        "\$router->post('/admin/snippets/{id}/rename'",
        "\$router->get('/admin/snippets/export'",
        "\$router->post('/admin/snippets/import'",
    ] as $route) {
        assert_contains($route, $routes, 'маршрут объявлен: ' . $route);
    }

    $header = snippet_library_source('app/Views/admin/layout/header.php');
    assert_contains("'snippets' => ['/admin/snippets'", $header, 'пункт меню объявлен');
    assert_true(
        str_contains(AdminUi::navigationIcon('snippets'), '<svg'),
        'у раздела рисуется иконка (неизвестный ключ Icon::render отдаёт пустой строкой)'
    );

    assert_true(
        is_file(APP_ROOT . '/app/Views/admin/snippets/index.php'),
        'вьюха раздела существует'
    );
});

test('Применение шаблона описано в контроллере один раз', function (): void {
    $controller = snippet_library_source('app/Controllers/Admin/SnippetController.php');

    // Редактор и раздел зовут общий applySnippet(); внутри него — и снимок
    // блоков из библиотеки, и автокопия перед заменой. Второй вызов
    // applyToPage в файле законный: он принадлежит готовой сборке
    // (PagePresets), у которой своих данных в библиотеке нет.
    assert_contains('private function applySnippet(', $controller, 'общая последовательность выделена');
    assert_same(
        2,
        substr_count($controller, '$this->applySnippet('),
        'её зовут оба входа — конструктор страницы и раздел'
    );
    // Сами входы к модели не обращаются: их дело — найти шаблон и запись,
    // а разбор снимка, автокопия и вставка лежат за общим методом.
    foreach (['insert', 'apply'] as $entry) {
        $body = snippet_library_method($controller, $entry);
        assert_true($body !== '', 'метод ' . $entry . '() найден в контроллере');
        assert_not_contains('BlockSnippet::applyToPage(', $body, $entry . '() не вставляет блоки сам');
        assert_not_contains('BlockSnippet::autoBackup(', $body, $entry . '() не снимает автокопию сам');
    }
});

test('Изменяющие действия раздела идут POST-ом с CSRF', function (): void {
    $view = snippet_library_source('app/Views/admin/snippets/index.php');

    foreach (['/admin/snippets/apply', '/rename', '/delete', '/admin/snippets/import'] as $action) {
        assert_contains($action, $view, 'в разделе есть действие ' . $action);
    }
    // Форм в разделе столько же, сколько токенов: незащищённая форма меняла бы
    // библиотеку по ссылке с чужого сайта.
    assert_same(
        substr_count($view, 'method="post"'),
        substr_count($view, 'Csrf::field()'),
        'у каждой POST-формы раздела свой CSRF-токен'
    );
    // Скачивание — единственное действие по ссылке, и оно ничего не меняет.
    assert_contains('href="/admin/snippets/export?id=', $view, 'скачивание — ссылка на выгрузку');
});

test('Список отдаёт вес, состав и признак автокопии', function (): void {
    if (!Database::isConnected()) {
        skip_test('TEST_DB_* не заданы');
    }

    $id = BlockSnippet::create('Тест библиотеки', [
        ['type' => 'text', 'title' => null, 'data' => ['text' => 'абв'], 'custom_css' => ''],
    ]);
    $autoId = BlockSnippet::create(BlockSnippet::AUTO_PREFIX . 'Тест (ru) — 01.01.2026', [
        ['type' => 'text', 'title' => null, 'data' => [], 'custom_css' => ''],
    ]);

    try {
        $rows = [];
        foreach (BlockSnippet::all() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        assert_true(isset($rows[$id]), 'шаблон попал в список');
        assert_same(1, $rows[$id]['blocks_count'], 'посчитаны блоки');
        assert_true($rows[$id]['bytes'] > 0, 'вес снимка посчитан');
        assert_false((bool) $rows[$id]['is_auto'], 'обычный шаблон не помечен автокопией');
        assert_true((bool) $rows[$autoId]['is_auto'], 'автокопия опознана по префиксу');
        assert_false((bool) $rows[$id]['is_broken'], 'целый снимок не помечен повреждённым');
    } finally {
        BlockSnippet::delete($id);
        BlockSnippet::delete($autoId);
    }
});

test('Переименование: пустое имя и чужой id отклоняются', function (): void {
    if (!Database::isConnected()) {
        skip_test('TEST_DB_* не заданы');
    }

    $id = BlockSnippet::create('Старое имя', [
        ['type' => 'text', 'title' => null, 'data' => [], 'custom_css' => ''],
    ]);

    try {
        assert_false(BlockSnippet::rename($id, '   '), 'пустое имя не принимается');
        assert_false(BlockSnippet::rename(0, 'Ничьё'), 'несуществующий шаблон не переименовывается');

        assert_true(BlockSnippet::rename($id, 'Новое имя'), 'живой шаблон переименован');
        assert_same('Новое имя', (string) (BlockSnippet::findById($id)['name'] ?? ''), 'имя записано');

        // MySQL считает изменённые строки: переименование в то же самое имя
        // дало бы rowCount() = 0, то есть «шаблон не найден» на живом шаблоне.
        assert_true(BlockSnippet::rename($id, 'Новое имя'), 'повторное то же имя — не отказ');
    } finally {
        BlockSnippet::delete($id);
    }
});
