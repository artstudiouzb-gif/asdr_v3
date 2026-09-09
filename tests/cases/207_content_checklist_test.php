<?php

declare(strict_types=1);

use App\Core\ContentChecklist;

/**
 * Напоминания редактору о незаполненном. Ключевое требование владельца —
 * «но не принудительно»: список ничего не блокирует, сохранение и публикация
 * работают с любым числом пропусков.
 */

function checklist_news(array $overrides = []): array
{
    return array_merge([
        'id' => 1,
        'lang' => 'ru',
        'status' => 'draft',
        'title' => 'Заголовок',
        'image' => '/uploads/public/cover.jpg',
        'excerpt' => 'Анонс материала.',
        'badge' => 'Мероприятия',
        'meta_title' => 'SEO-заголовок',
        'meta_description' => 'SEO-описание',
        'hashtags' => '#реформы',
    ], $overrides);
}

test('Заполненный материал напоминаний не даёт', function () {
    assert_same([], ContentChecklist::forNews(checklist_news()));
});

test('Пропуски называются по одному, с объяснением зачем', function () {
    $items = ContentChecklist::forNews(checklist_news([
        'image' => '',
        'excerpt' => '',
        'badge' => '',
        'meta_title' => '',
        'meta_description' => '',
        'hashtags' => '',
    ]));
    $keys = array_column($items, 'key');

    assert_true(in_array('image', $keys, true), 'нет главного фото');
    assert_true(in_array('excerpt', $keys, true));
    assert_true(in_array('badge', $keys, true));
    assert_true(in_array('seo', $keys, true));
    assert_true(in_array('hashtags', $keys, true));

    // Каждому пункту — причина: список без объяснений перестают читать.
    foreach ($items as $item) {
        assert_true(trim((string) $item['why']) !== '', 'у пункта «' . $item['text'] . '» нет объяснения');
    }
});

test('Заполненного SEO-заголовка достаточно — второй раз не напоминаем', function () {
    $keys = array_column(ContentChecklist::forNews(checklist_news(['meta_description' => ''])), 'key');
    assert_false(in_array('seo', $keys, true));
});

test('Фото без подписи считаются, число склоняется', function () {
    $one = ContentChecklist::forNews(checklist_news(), [['caption' => '', 'credit' => '']]);
    assert_contains('1 фото', $one[0]['text']);

    // Снимок с автором, но без подписи, пропуском не считается.
    $none = ContentChecklist::forNews(checklist_news(), [['caption' => '', 'credit' => 'Пресс-служба']]);
    assert_same([], $none);
});

test('Перевод спрашиваем только у опубликованного материала', function () {
    // Черновик переводить рано — напоминание было бы шумом.
    $draft = ContentChecklist::forNews(checklist_news(['status' => 'draft']));
    assert_same([], array_filter($draft, static fn (array $i): bool => str_starts_with($i['key'], 'lang:')));
});

test('Напоминания ничего не блокируют: сохранение их не проверяет', function () {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/NewsController.php');

    // Чек-лист собирается только для показа формы. Если он появится в
    // store/update рядом с return или Flash::error — это уже запрет.
    $update = substr($controller, (int) strpos($controller, 'public function update'), 2500);
    assert_not_contains('ContentChecklist', $update, 'сохранение не должно зависеть от напоминаний');

    $store = substr($controller, (int) strpos($controller, 'public function store'), 2000);
    assert_not_contains('ContentChecklist', $store);

    // В форме — сворачиваемый список, а не блокирующее сообщение.
    $view = (string) file_get_contents(APP_ROOT . '/app/Views/admin/news/form.php');
    assert_contains('data-content-checklist', $view);
    assert_not_contains('disabled', substr($view, (int) strpos($view, 'data-content-checklist'), 900));
});
