<?php

declare(strict_types=1);

use App\Core\SocialSettings;

/**
 * Предпросмотр поста и сбор хештегов. Содержимое поста раньше выяснялось по
 * факту в канале: сколько языков вошло, что попало в текст, влезает ли он в
 * лимит. Хештеги переводятся вместе с новостью, а пост один.
 */

test('Хештеги собираются со всех языковых версий, без повторов (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $pdo->exec('UPDATE languages SET is_default = 0');
    $pdo->exec("UPDATE languages SET is_default = 1 WHERE code = 'uz'");
    \App\Models\Language::flush();

    try {
        $id = \App\Models\News::create([
            'title' => 'Uzbek sarlavha', 'slug' => 'tags-' . uniqid(), 'excerpt' => 'Anons',
            'content' => 'matn', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
            'hashtags' => '#islohot #Iqtisodiyot',
        ]);
        \App\Models\NewsTranslation::upsert($id, 'ru', [
            'title' => 'Русский заголовок', 'excerpt' => 'Анонс', 'content' => 'текст',
            'hashtags' => '#реформы #iqtisodiyot',
        ]);

        $tags = (string) SocialSettings::buildPost(\App\Models\News::findById($id))['hashtags'];
        assert_contains('#Islohot', $tags);
        assert_contains('#Реформы', $tags);
        // Канонический регистр не плодит дубли: #Iqtisodiyot и
        // #iqtisodiyot остаются одним тегом после нормализации.
        assert_same(1, substr_count(mb_strtolower($tags), '#iqtisodiyot'));
    } finally {
        $pdo->exec('UPDATE languages SET is_default = 0');
        $pdo->exec("UPDATE languages SET is_default = 1 WHERE code = 'ru'");
        \App\Models\Language::flush();
    }
});

test('Снимки поста считает один метод — превью и публикация не разойдутся', function () {
    $post = [
        'image_url' => 'https://site.uz/cover.jpg',
        'gallery' => ['https://site.uz/a.jpg', 'https://site.uz/cover.jpg', 'http://site.uz/insecure.jpg', ''],
    ];
    $photos = SocialSettings::telegramPhotoUrls($post);

    assert_same(2, count($photos), 'повтор обложки и не-https отброшены');
    assert_same('https://site.uz/cover.jpg', $photos[0]);

    // Публикатор обязан пользоваться тем же списком, иначе «в превью одно, в
    // канале другое».
    $publisher = (string) file_get_contents(APP_ROOT . '/app/Core/SocialPublisher.php');
    assert_contains('SocialSettings::telegramPhotoUrls($post)', $publisher);
});

test('Предпросмотр поста доступен из формы новости', function () {
    $routes = (string) file_get_contents(APP_ROOT . '/public/index.php');
    assert_contains("'/admin/news/{id}/social-preview'", $routes);

    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/NewsController.php');
    assert_contains('public function socialPreview', $controller);
    // Только показ: предпросмотр ничего не отправляет и не ставит в очередь.
    $method = substr($controller, (int) strpos($controller, 'public function socialPreview'), 1800);
    assert_not_contains('enqueueForNews', $method);
    assert_not_contains('dispatch', $method);

    $form = (string) file_get_contents(APP_ROOT . '/app/Views/admin/news/form.php');
    assert_contains('/social-preview', $form);
});
