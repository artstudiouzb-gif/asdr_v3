<?php

declare(strict_types=1);

use App\Models\News;
use App\Models\SearchLog;

test('SearchLog и news_views аналитика дашборда', static function () {
    if (!isset($_ENV['TEST_DB_DATABASE']) && getenv('TEST_DB_DATABASE') === false) {
        skip_test('TEST_DB_* не заданы');
    }

    // 1. Проверка логирования поиска
    SearchLog::record('тест поиска', 5);
    $popular = SearchLog::popular(30, 5);
    assert_true(is_array($popular), 'SearchLog::popular возвращает массив');

    // 2. Проверка инкремента просмотров новости
    $db = \App\Core\Database::pdo();
    $db->exec("INSERT INTO news (title, slug, status) VALUES ('Тестовая новость просмотров', 'test-view-news', 'published') ON DUPLICATE KEY UPDATE title=VALUES(title)");
    $newsId = (int) $db->lastInsertId();
    if ($newsId > 0) {
        News::incrementViews($newsId);
        $topRead = News::mostViewed(30, 5);
        assert_true(is_array($topRead), 'News::mostViewed возвращает массив');
    }
});

/*
 * Дашборд показывает то, с чем надо что-то делать.
 *
 * Прежде он считал своё маленькое состояние системы (версия PHP, «База
 * данных: подключена», «Ошибки очереди: 0») и рисовал график заявок за семь
 * дней. График занимал целую карточку и на госсайте почти всегда состоял из
 * семи нулей; наивный статус повторял то, что уже считает `SystemHealth` для
 * раздела «Состояние системы», — и второй список рядом с первым разъехался бы
 * при первой же новой проверке.
 *
 * Договорённость: факты о состоянии берутся из `SystemHealth`, и на дашборд
 * попадают только `fail` и `warn`. `unknown` («ни разу не использовалась») —
 * не поломка: на свежей установке таких строк большинство, и они утопили бы
 * настоящие.
 */
test('Дашборд читает состояние из SystemHealth и не считает своё', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/DashboardController.php');
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/dashboard.php');

    assert_contains('SystemHealth::groups()', $controller, 'состояние берётся из общего источника');
    assert_contains('SystemHealth::FAIL', $controller, 'в список идут отказы');
    assert_contains('SystemHealth::WARN', $controller, 'и предупреждения');
    assert_not_contains('SystemHealth::UNKNOWN', $controller, '«ни разу не использовалась» — не повод для тревоги');

    foreach (['php_version', 'queue_pending', 'queue_failed', 'active_langs_count'] as $own) {
        assert_not_contains($own, $controller, 'дашборд снова считает состояние сам: ' . $own);
    }

    assert_not_contains('chartData', $controller, 'график заявок за 7 дней убран');
    assert_not_contains('dash-chart', $view, 'разметка графика убрана вместе с данными');
    assert_contains('/admin/health', $view, 'с дашборда должен быть путь ко всем проверкам');
    assert_contains('SystemHealth::solution(', $view, 'каждая проблема получает единый следующий шаг');
    assert_contains('dash-status__action', $view, 'следующий шаг виден кнопкой в самой строке');
});

/*
 * Битые ссылки: посетитель по ним уже приходил и ничего не нашёл. Карточка
 * появляется только при находках — пустая рамка с заголовком читается как
 * поломка (то же правило, по которому виджет «Меню раздела» не печатает
 * пустой `<aside>`).
 */
test('Дашборд показывает битые ссылки только при находках', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/DashboardController.php');
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/dashboard.php');

    assert_contains('NotFoundLog::top(', $controller, 'список берётся из журнала 404');
    assert_contains('if (!empty($brokenLinks))', $view, 'пустой карточки быть не должно');
    assert_contains('/admin/redirects', $view, 'починка — редирект, и он в одном шаге');
});
