<?php

declare(strict_types=1);

use App\Models\News;
use App\Core\SocialPublisher;
use App\Core\SocialSettings;

test('Хештеги новостей нормализуются и попадают в социальный пост', function (): void {
// 1. Нормализация хештегов
assert_same("#Культура #Ташкент #События", News::cleanHashtags("культура, #ташкент, события"));
assert_same("#Спорт #Футбол", News::cleanHashtags("#спорт #футбол #СПОРТ"));
assert_same(
    "#Oʻzbekiston2030 #Gʻoya #Maʻnaviyat #Toʻgʻri",
    News::cleanHashtags("#O‘zbekiston2030 #g'oya #maʼnaviyat #toʹg′ri")
);
assert_same(null, News::cleanHashtags(""));
assert_same(null, News::cleanHashtags(null));

// 2. Включение хештегов в текст публикаций соцсетей
$post = SocialSettings::buildPost([
    "title" => "Тестовая новость",
    "slug" => "test-news",
    "excerpt" => "Краткий анонс новости",
    "content" => "<p>Текст новости</p>",
    "image" => "/uploads/test.jpg",
    "hashtags" => "культура, #ташкент",
]);

assert_same("#Культура #Ташкент", (string) $post["hashtags"]);
assert_not_contains("#Культура", (string) $post["message"], 'теги хранятся отдельно от текста');

$sent = [];
$http = static function (string $method, string $url, string $body, array $headers) use (&$sent): array {
    parse_str($body, $sent);
    return ['status' => 200, 'body' => '{"id":"post-1"}', 'error' => ''];
};
$result = (new SocialPublisher($http))->publish('facebook', ['token' => 'T', 'page_id' => '1'], $post);
assert_true($result['ok']);
assert_contains("\n\n\n#Культура #Ташкент", (string) ($sent['message'] ?? ''), 'теги отделены от ссылки пустой строкой');

$view = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/site/news_show.php');
assert_contains('class="newsdetail-hashtags" data-no-translit', $view);
});
