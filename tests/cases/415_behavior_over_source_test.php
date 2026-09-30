<?php

declare(strict_types=1);

/*
 * Тест проверяет то, что получит посетитель, а не текст исходника.
 *
 * Проверка «строка есть в файле» ломается от переименования переменной и
 * проходит, когда строка на месте, а вывод уже другой. Поэтому у тестов есть
 * render_view(), а число чтений конкретных PHP-файлов — бюджет, который
 * может только уменьшаться.
 */

test('render_view: у каждого рендера своя область видимости и честный вывод', function (): void {
    $html = render_view('app/Views/site/_news_list.php', ['items' => [], 'page' => 1, 'pages' => 1, 'category' => '']);
    assert_contains('listing__empty', $html, 'пустая лента говорит, что новостей нет');

    $html = render_view('app/Views/site/_news_list.php', ['items' => sample_news_rows(2), 'page' => 1, 'pages' => 1, 'category' => '']);
    assert_same(2, substr_count($html, '<a class="relnews-card '), 'две записи — две карточки');
    assert_false(isset($card), 'переменные шаблона не утекают в тест');
});

test('render_view: исключение в шаблоне не оставляет открытый буфер', function (): void {
    $level = ob_get_level();
    try {
        render_view('app/Views/site/_news_list.php', ['items' => [['category_id' => 0]], 'page' => 1, 'pages' => 1, 'category' => '']);
    } catch (Throwable) {
        // Запись без slug роняет шаблон — нас интересует только буфер.
    }
    assert_same($level, ob_get_level());
});

test('Бюджет: чтений текста конкретных PHP-файлов в тестах только убывает', function (): void {
    $budget = quality_budget('tests_source_reads');
    assert_true(
        $budget['value'] <= $budget['ceiling'],
        $budget['title'] . ': стало ' . $budget['value'] . ' при потолке ' . $budget['ceiling']
            . '; больше всего: ' . $budget['detail']
    );
});
