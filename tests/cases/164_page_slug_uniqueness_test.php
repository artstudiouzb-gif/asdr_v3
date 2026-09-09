<?php

declare(strict_types=1);

use App\Core\Slug;
use App\Models\Page;

test('Page: уникальность slug по языкам и использование чистых суффиксов', function (): void {
    ensure_test_db();

    $slug = 'contacts-test-' . bin2hex(random_bytes(2));

    $id1 = Page::create([
        'title' => 'Контакты RU',
        'slug' => $slug,
        'status' => 'published',
        'is_home' => 0,
        'layout_type' => 'no_sidebar',
        'lang' => 'ru',
    ]);

    // Тот же slug для другого языка (uz) должен быть доступен без суффиксов
    $uniqueUz = Slug::unique($slug, static fn (string $s, ?int $ex) => Page::slugExists($s, $ex, 'uz'));
    assert_equals($slug, $uniqueUz, 'Для другого языка тот же slug доступен');

    // Для того же языка (ru) должна добавиться чистая цифра -2 вместо случайного hex
    $uniqueRu = Slug::unique($slug, static fn (string $s, ?int $ex) => Page::slugExists($s, $ex, 'ru'));
    assert_equals($slug . '-2', $uniqueRu, 'При коллизии в рамках одного языка добавляется понятный суффикс -2');

    // Редактирование той же страницы (excludeId = $id1) не считает её же slug коллизией
    $uniqueSelf = Slug::unique($slug, static fn (string $s, ?int $ex) => Page::slugExists($s, $ex, 'ru'), $id1);
    assert_equals($slug, $uniqueSelf, 'Редактирование своей же страницы сохраняет оригинальный slug');
});
