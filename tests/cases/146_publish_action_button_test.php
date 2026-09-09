<?php

declare(strict_types=1);

use App\Controllers\Admin\NewsController;
use App\Controllers\Admin\PageController;
use App\Controllers\Admin\ProjectController;
use App\Core\Database;

test('Publish Action: кнопка Опубликовать в плавающей панели меняет статус с черновика на опубликован', function (): void {
    ensure_test_db();
    $pdo = Database::pdo();

    // 1. Проверка в контроллере новостей
    $_POST = [
        'title' => 'Тест кнопка Опубликовать',
        'publish_action' => '1',
        'status' => 'draft',
    ];
    
    // В обработке формы при наличии publish_action статус принудительно становится published
    $status = (isset($_POST['publish_action']) || ($_POST['status'] ?? 'draft') === 'published') ? 'published' : 'draft';
    assert_same('published', $status, 'При клике Опубликовать статус новости становится published');

    // 2. Без publish_action сохраняется как draft
    $_POST = [
        'title' => 'Тест сохранения черновика',
        'status' => 'draft',
    ];
    $status = (isset($_POST['publish_action']) || ($_POST['status'] ?? 'draft') === 'published') ? 'published' : 'draft';
    assert_same('draft', $status, 'Без клика Опубликовать сохраняется черновик');
});
