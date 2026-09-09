<?php

declare(strict_types=1);

use App\Core\SocialSettings;

/**
 * Пост уходит в канал ровно на тех языках, где заполнен перевод. Незаполненный
 * язык просто исчезает из поста — редактор замечает это уже в канале. Поэтому
 * админка обязана сказать о пропуске в момент публикации.
 */

test('Незаполненный перевод виден как пропущенный язык (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $pdo->exec('UPDATE languages SET is_default = 0');
    $pdo->exec("UPDATE languages SET is_default = 1 WHERE code = 'uz'");
    \App\Models\Language::flush();

    try {
        $id = \App\Models\News::create([
            'title' => 'Uzbek sarlavha', 'slug' => 'lang-gap-' . uniqid(), 'excerpt' => 'Uzbek anons',
            'content' => 'matn', 'status' => 'published', 'published_at' => date('Y-m-d H:i:s'),
        ]);
        $news = \App\Models\News::findById($id);

        $missing = SocialSettings::missingPostLangs($news);
        assert_true(in_array('ru', $missing, true), 'русского перевода нет — язык пропущен');
        assert_false(in_array('uz', $missing, true), 'основной язык всегда в посте');

        // Перевод заполнен — пропусков не остаётся.
        \App\Models\NewsTranslation::upsert($id, 'ru', [
            'title' => 'Русский заголовок', 'excerpt' => 'Русский анонс', 'content' => 'текст',
        ]);
        assert_false(in_array('ru', SocialSettings::missingPostLangs(\App\Models\News::findById($id)), true));
    } finally {
        $pdo->exec('UPDATE languages SET is_default = 0');
        $pdo->exec("UPDATE languages SET is_default = 1 WHERE code = 'ru'");
        \App\Models\Language::flush();
    }
});

test('Публикация в соцсети сообщает о пропущенном языке', function () {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/NewsController.php');
    $push = substr($controller, (int) strpos($controller, 'public function pushSocial'), 4200);

    assert_contains('missingPostLangs', $push);
    // Подсказка, а не запрет: публикация уже состоялась выше по коду.
    assert_not_contains('return', substr($push, (int) strpos($push, 'missingPostLangs'), 400));
});

test('Черновая нагрузка без id не лезет в БД за переводами', function () {
    assert_same([], SocialSettings::missingPostLangs(['title' => 'Без id', 'slug' => 'draft']));
});

test('Русская ссылка из Telegram явно выбирает русский язык', function () {
    $post = SocialSettings::buildPost([
        'title' => 'Русский заголовок',
        'slug' => 'russian-link',
        'excerpt' => 'Анонс',
        'lang' => 'ru',
        'published_at' => '2026-08-01 10:00:00',
    ]);

    assert_same('ru', (string) ($post['langs'][0]['code'] ?? ''));
    assert_contains('/news/russian-link?_lang=ru', (string) ($post['langs'][0]['link'] ?? ''));
});
