<?php

declare(strict_types=1);

use App\Core\SocialSettings;

/**
 * Перевод новости в этой CMS бывает двух видов: полем в `news_translations`
 * и отдельной связанной записью (`translation_group_id`). Публикация знала
 * только про первый вид — вторая языковая версия уходила в канал отдельным
 * постом, да ещё с датой и кнопкой на чужом языке.
 */

/** Пара связанных записей: узбекская основная и русская. @return array{0:int,1:int} */
function group_news_pair(): array
{
    $pdo = \App\Core\Database::pdo();
    $suffix = uniqid();

    $uzId = \App\Models\News::create([
        'title' => 'Uzbek sarlavha', 'slug' => 'grp-uz-' . $suffix, 'excerpt' => 'Uzbek anons',
        'content' => 'matn', 'status' => 'published',
        'published_at' => '2026-08-01 10:00:00', 'lang' => 'uz',
    ]);
    $ruId = \App\Models\News::create([
        'title' => 'Русский заголовок', 'slug' => 'grp-ru-' . $suffix, 'excerpt' => 'Русский анонс',
        'content' => 'текст', 'status' => 'published',
        'published_at' => '2026-08-01 10:00:00', 'lang' => 'ru',
    ]);
    // Рубрика — одна категория на обе записи: у неё общий id, а название
    // переводится. Раньше её изображал текст в badge, который приходилось
    // дублировать в каждой языковой версии.
    $categoryId = (int) \App\Models\NewsCategory::create('Tadbirlar ' . $suffix);
    \App\Models\NewsCategoryTranslation::upsert($categoryId, 'ru', 'Мероприятия');
    $pdo->prepare('UPDATE news SET category_id = :c WHERE id IN (:a, :b)')
        ->execute([':c' => $categoryId, ':a' => $uzId, ':b' => $ruId]);
    // Связываем: так их и связывает админка при добавлении языковой версии.
    $pdo->prepare('UPDATE news SET translation_group_id = :g WHERE id IN (:a, :b)')
        ->execute([':g' => $uzId, ':a' => $uzId, ':b' => $ruId]);

    return [$uzId, $ruId];
}

test('Связанная запись перевода попадает в тот же пост (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $pdo->exec('UPDATE languages SET is_default = 0');
    $pdo->exec("UPDATE languages SET is_default = 1 WHERE code = 'uz'");
    \App\Models\Language::flush();

    try {
        [$uzId, $ruId] = group_news_pair();
        $langs = SocialSettings::buildPost(\App\Models\News::findById($uzId))['langs'];

        assert_same(2, count($langs), 'обе версии в одном посте');
        assert_same('Uzbek sarlavha', (string) $langs[0]['title']);
        assert_same('Русский заголовок', (string) $langs[1]['title']);
        // Ссылка ведёт на собственный адрес русской записи, а не на перевод
        // узбекского слага.
        assert_contains('grp-ru-', (string) $langs[1]['link']);
        assert_contains('/ru/news/', (string) $langs[1]['link']);
        assert_contains('_lang=ru', (string) $langs[1]['link'], 'русская ссылка явно переключает язык и не зависит от cookie посетителя');
        assert_contains('_lang=uz', (string) $langs[0]['link'], 'узбекская ссылка явно переключает язык');
        // Дата и надпись «читать дальше» — на своём языке.
        assert_contains('августа', (string) $langs[1]['date']);
        assert_contains('Читать', (string) $langs[1]['read_more']);
        assert_same('Мероприятия', (string) $langs[1]['category']);

        // Пропущенных языков нет: перевод на месте, пусть и отдельной записью.
        assert_same([], SocialSettings::missingPostLangs(\App\Models\News::findById($uzId)));

        // Публикация русской версии адресуется основной записи — иначе в
        // канал уходит второй пост с тем же материалом.
        assert_same($uzId, \App\Models\News::socialPrimaryId($ruId));
        assert_same($uzId, \App\Models\News::socialPrimaryId($uzId));
    } finally {
        $pdo->exec('UPDATE languages SET is_default = 0');
        $pdo->exec("UPDATE languages SET is_default = 1 WHERE code = 'ru'");
        \App\Models\Language::flush();
    }
});

test('Черновая языковая версия в пост не уходит (БД)', function () {
    ensure_test_db();
    $pdo = \App\Core\Database::pdo();
    $pdo->exec('UPDATE languages SET is_default = 0');
    $pdo->exec("UPDATE languages SET is_default = 1 WHERE code = 'uz'");
    \App\Models\Language::flush();

    try {
        [$uzId, $ruId] = group_news_pair();
        $pdo->prepare("UPDATE news SET status = 'draft' WHERE id = :id")->execute([':id' => $ruId]);

        $langs = SocialSettings::buildPost(\App\Models\News::findById($uzId))['langs'];
        assert_same(1, count($langs), 'неготовый перевод в канал не уходит');
        assert_true(in_array('ru', SocialSettings::missingPostLangs(\App\Models\News::findById($uzId)), true));
    } finally {
        $pdo->exec('UPDATE languages SET is_default = 0');
        $pdo->exec("UPDATE languages SET is_default = 1 WHERE code = 'ru'");
        \App\Models\Language::flush();
    }
});
