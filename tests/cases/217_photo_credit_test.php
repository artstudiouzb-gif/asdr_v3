<?php

declare(strict_types=1);

use App\Core\Media;
use App\Core\TelegramRichMessage;

/**
 * Автор снимка. Подпись выводит слово «Фото:» сама, а редактор естественно
 * пишет его руками — и под фото появлялось «Фото: Фото: пресс-служба».
 */

test('Служебное слово в начале не задваивается', function () {
    assert_same('пресс-служба Агентства', Media::photoCredit('Фото: пресс-служба Агентства'));
    assert_same('пресс-служба Агентства', Media::photoCredit('фото — пресс-служба Агентства'));
    assert_same('press-service', Media::photoCredit('Photo: press-service'));
    assert_same('matbuot xizmati', Media::photoCredit('Surat: matbuot xizmati'));

    // Обычный автор не трогаем, даже если слово «фото» внутри строки.
    assert_same('Иван Фотограф', Media::photoCredit('Иван Фотограф'));
    assert_same('агентство «Фото-пресс»', Media::photoCredit('агентство «Фото-пресс»'));
    assert_same('', Media::photoCredit(null));
    assert_same('', Media::photoCredit('   '));
});

test('Вывод подписи на сайте и в посте пользуется одной нормализацией', function () {
    foreach (['app/Views/site/news_show.php', 'app/Views/site/album.php'] as $view) {
        $src = (string) file_get_contents(APP_ROOT . '/' . $view);
        assert_contains('Media::photoCredit(', $src);
    }

    $doc = TelegramRichMessage::build(
        [['code' => 'ru', 'label' => 'Русский', 'title' => 'T', 'excerpt' => 'Текст', 'link' => '', 'read_more' => '']],
        ['https://site.uz/a.jpg'],
        '',
        '',
        '',
        '',
        ['https://site.uz/a.jpg' => ['caption' => 'Подпись', 'credit' => 'Фото: пресс-служба']]
    );
    assert_contains('<cite>пресс-служба</cite>', $doc['html']);
});

test('Демо-контент показывает подписи и не дублирует лид', function () {
    $seeder = (string) file_get_contents(APP_ROOT . '/app/Core/DemoSeeder.php');

    // Автор — без служебного слова: его подставляет сама подпись.
    assert_not_contains("'Фото: пресс-служба", $seeder);
    assert_contains("'пресс-служба Агентства'", $seeder);

    // Слайдер новости — единственное место, где подпись видна текстом:
    // у демо-новости с макетом «галерея» должны быть снимки.
    assert_contains("'layout' => 'gallery',", $seeder);
    assert_contains("private static function seedNewsGallery", $seeder);

    // Лид не повторяется первым абзацем текста: он уже есть в шапке новости.
    assert_not_contains("'<p><strong>' . htmlspecialchars(\$n['excerpt']", $seeder);
});
