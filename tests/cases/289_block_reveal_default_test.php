<?php

declare(strict_types=1);

use App\Core\BlockData\BlockPresentationNormalizer as Presentation;
use App\Core\BlockRenderer;

test('Новый блок получает появление при прокрутке', function (): void {
    // Без этого длинная страница читается как один снимок: ничто не сообщает,
    // что ниже есть продолжение.
    $reveal = Presentation::newBlockPresentation(false, false);
    assert_same(['enabled' => true, 'type' => 'fade'], $reveal['_reveal'] ?? null);
});

test('Первый блок страницы и вложенный блок не анимируются', function (): void {
    // Первый блок — первый экран: прятать его до появления значит задержать то,
    // ради чего страницу открыли. Вложенный появляется вместе с контейнером.
    assert_same([], Presentation::newBlockPresentation(true, false));
    assert_same([], Presentation::newBlockPresentation(false, true));
    assert_same([], Presentation::newBlockPresentation(true, true));
});

test('Умолчание доезжает до вывода, а не теряется по дороге', function (): void {
    // Форма и рендерер читают _reveal своей формой записи. Разъедется shape —
    // блок сохранится «с анимацией», а на странице её не будет.
    $render = static function (int $id, array $presentation): string {
        $data = array_merge(['title' => 'Проба', 'text' => '<p>Текст</p>'], $presentation);
        $out = BlockRenderer::render([
            'id' => $id,
            'type' => 'text',
            'title' => 'Проба',
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);

        return (string) ($out['html'] ?? '');
    };

    $html = $render(1, Presentation::newBlockPresentation(false, false));
    assert_contains('data-reveal', $html);
    assert_contains('data-reveal-type="fade"', $html);

    $firstHtml = $render(2, Presentation::newBlockPresentation(true, false));
    assert_false(str_contains($firstHtml, 'data-reveal'), 'первый блок анимируется');
});

test('Редактор добавляет блок с этим умолчанием, а не мимо него', function (): void {
    // Умолчание объявлено один раз; контроллер обязан звать его, иначе список
    // исключений разъедется с описанием.
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/BlockController.php');
    assert_contains('newBlockPresentation(', $controller);
    assert_contains('Block::forPage($pageId, $lang) === []', $controller);
});

test('Скрипт для готовых страниц пропускает первый блок, обложку и чужой выбор', function (): void {
    // Страницы, собранные до появления умолчания, догоняются скриптом. Его
    // исключения обязаны совпадать с исключениями умолчания, иначе задним
    // числом заанимируется первый экран.
    $script = (string) file_get_contents(APP_ROOT . '/scripts/enable_block_reveal.php');
    assert_contains('--dry-run', $script);
    assert_contains('parent_block_id IS NULL', $script);
    assert_contains('первый блок страницы', $script);
    assert_contains("'hero'", $script);
    assert_contains('анимация уже выбрана', $script);
    assert_contains('clearPageCache', $script);
});
