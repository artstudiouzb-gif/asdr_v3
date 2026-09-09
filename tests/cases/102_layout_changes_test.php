<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\News;
use App\Controllers\Site\NewsController;

test('News: sidebar_layout saves, updates and defaults to right_sidebar', function () {
    ensure_test_db();
    $pdo = Database::pdo();

    // 1. Создаём новость без явного указания sidebar_layout
    $slug = 'test-news-sidebar-' . bin2hex(random_bytes(3));
    $nid = News::create([
        'title' => 'Тестовая новость',
        'slug' => $slug,
        'excerpt' => 'Описание',
        'content' => 'Текст',
        'image' => '',
        'status' => 'draft',
        'published_at' => date('Y-m-d H:i:s'),
        'author_id' => null,
    ]);

    // По умолчанию должно быть right_sidebar
    $news = News::findById($nid);
    assert_same('right_sidebar', $news['sidebar_layout']);

    // 2. Обновляем sidebar_layout на left_sidebar
    $data = [
        'title' => 'Тестовая новость 2',
        'slug' => $slug,
        'excerpt' => 'Описание 2',
        'content' => 'Текст 2',
        'image' => '',
        'status' => 'draft',
        'published_at' => date('Y-m-d H:i:s'),
        'sidebar_layout' => 'left_sidebar',
    ];
    News::update($nid, $data);

    $news = News::findById($nid);
    assert_same('left_sidebar', $news['sidebar_layout']);

    // 3. Добавляем audio_url, audio_title и hashtags
    $data['audio_url'] = '/uploads/public/test-podcast.mp3';
    $data['audio_title'] = 'Аудиоверсия выпуска';
    $data['hashtags'] = 'культура, #ташкент, события';
    News::update($nid, $data);

    $news = News::findById($nid);
    assert_same('/uploads/public/test-podcast.mp3', $news['audio_url']);
    assert_same('Аудиоверсия выпуска', $news['audio_title']);
    assert_same('#Культура #Ташкент #События', $news['hashtags']);

    // 4. Обновляем sidebar_layout на no_sidebar
    $data['sidebar_layout'] = 'no_sidebar';
    News::update($nid, $data);

    $news = News::findById($nid);
    assert_same('no_sidebar', $news['sidebar_layout']);

    // Очистка
    $pdo->exec("DELETE FROM news WHERE id = {$nid}");
});

test('News: frontend layout rendering with and without sidebar', function () {
    ensure_test_db();
    $pdo = Database::pdo();

    // Создаём активный виджет в правом сайдбаре, чтобы renderSidebar вернул непустую строку
    $wid = \App\Models\Widget::create([
        'sidebar' => 'right',
        'type' => 'contacts',
        'title' => 'Контакты',
        'lang' => '',
        'data' => [],
        'is_active' => 1,
    ]);

    // Создаём опубликованную новость с тезисами
    $slug = 'test-news-layout-' . bin2hex(random_bytes(3));
    $imagePath = '/uploads/public/test-news-caption.jpg';
    $nid = News::create([
        'title' => 'Супер новость',
        'slug' => $slug,
        'excerpt' => 'Лид новости',
        'content' => 'Основной текст',
        'image' => $imagePath,
        'status' => 'published',
        'published_at' => date('Y-m-d H:i:s'),
        'author_id' => null,
    ]);

    // Подпись обложки хранится в записи галереи с тем же путём. Так тест
    // проверяет реальный контракт рендера, а не ожидает caption у пустого медиа.
    $pdo->prepare(
        'INSERT INTO news_images (news_id, path, alt_text, caption, credit, sort_order) VALUES (?, ?, ?, ?, ?, 0)'
    )->execute([$nid, $imagePath, 'Заседание коллегии', 'Заседание коллегии', 'Пресс-служба']);

    // Добавляем тезисы (key_points)
    News::updateExtras($nid, [
        'badge' => 'Важное',
        'key_points' => "Первый важный тезис\nВторой важный тезис",
    ]);

    // 1. Тест рендеринга с правым сайдбаром (по умолчанию).
    // Тезисы входят в единую информационную колонку вместе с виджетами.
    $controller = new NewsController();
    
    ob_start();
    try {
        $controller->show(['slug' => $slug]);
    } catch (\Throwable $e) {
        ob_get_clean();
        throw $e;
    }
    $html = ob_get_clean();

    // Обёртка-грид `layout layout--right` ушла при редизайне детальной новости:
    // страница собирается из блоков newsdetail-*, а сайдбар выводится отдельным
    // <aside class="newsdetail-side newsdetail-side--right">.
    assert_contains('newsdetail-side newsdetail-side--right', $html, 'Правый сайдбар новости отрисован');
    assert_contains('newsdetail-actionrail', $html, 'На широком экране доступна компактная панель действий');
    assert_contains('newsdetail__eyebrow', $html, 'Категория и метаданные собраны в единую строку над заголовком');
    assert_contains('newsdetail-share__home', $html, 'Возврат на главную остаётся доступен в адаптивном блоке действий');
    assert_contains('newsdetail-share--article-top', $html, 'Публикация расположена сразу после галереи перед текстом');
    assert_contains('newsdetail-gallery__caption', $html, 'Подпись и автор фотографии сохраняются под большим кадром');
    assert_contains('newsdetail-gallery__main', $html, 'Одна главная обложка создаёт полноценную медиазону без дополнительных фото');
    assert_contains('Контакты', $html, 'Виджет правого сайдбара попал в разметку');
    assert_contains('newsdetail-card--summary', $html, 'Тезисы отображаются в информационной колонке');
    assert_contains('Первый важный тезис', $html);

    // 2. Тест рендеринга без сайдбара
    // Устанавливаем макет no_sidebar
    News::update($nid, [
        'title' => 'Супер новость',
        'slug' => $slug,
        'excerpt' => 'Лид новости',
        'content' => 'Основной текст',
        'image' => '',
        'status' => 'published',
        'published_at' => date('Y-m-d H:i:s'),
        'sidebar_layout' => 'no_sidebar',
    ]);

    ob_start();
    try {
        $controller->show(['slug' => $slug]);
    } catch (\Throwable $e) {
        ob_get_clean();
        throw $e;
    }
    $htmlNoSidebar = ob_get_clean();

    assert_not_contains('layout layout--', $htmlNoSidebar, 'Контейнер сайдбара отсутствует при no_sidebar');
    assert_contains('newsdetail-card--summary', $htmlNoSidebar, 'Смысловые тезисы не зависят от набора пользовательских виджетов');
    assert_contains('newsdetail-side newsdetail-side--right', $htmlNoSidebar, 'Тезисы выводятся в информационной колонке новости');

    $css = theme_css();
    assert_contains('align-items: stretch;', $css, 'медиазона не схлопывается без ленты миниатюр');
    assert_contains('width: 100%;', substr($css, (int) strpos($css, '.newsdetail:not(.newsdetail--premium) .newsdetail-gallery {'), 220));
    foreach ([
        '.newsdetail:not(.newsdetail--premium) .newsdetail-gallery__main',
        '.newsdetail:not(.newsdetail--premium) .newsdetail-video',
        '.newsdetail-card',
        '.newsdetail-subscribe',
        '.relnews-card',
    ] as $selector) {
        $pattern = '/' . preg_quote($selector, '/')
            . '\s*\{[^}]*border-radius:\s*var\(--radius,\s*14px\);/s';
        assert_true(
            preg_match($pattern, $css) === 1,
            "{$selector}: использует глобальное скругление"
        );
    }
    $richCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/rich-content.css');
    assert_contains('border-radius: var(--radius, 12px);', $richCss, 'встроенные в текст фотографии используют глобальное скругление');
    assert_contains('border-radius: 0 var(--radius, 14px) var(--radius, 14px) 0;', $richCss, 'цитата следует глобальному скруглению');
    assert_contains(
        ".newsdetail-gallery__main img'))",
        (string) file_get_contents(APP_ROOT . '/public/assets/js/frontend.js'),
        'lightbox не считает миниатюры галереи отдельными фотографиями'
    );

    // Очистка
    $pdo->exec("DELETE FROM news WHERE id = {$nid}");
    $pdo->exec("DELETE FROM widgets WHERE id = {$wid}");
});
