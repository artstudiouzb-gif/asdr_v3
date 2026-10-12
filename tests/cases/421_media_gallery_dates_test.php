<?php

declare(strict_types=1);

/**
 * Дата публикации у видео и фотоальбомов блока «Медиа» — по галочке и тем же
 * видом, что у карточки новости: значок и число над заголовком. У ручной
 * плитки строка под заголовком своя, вторая дата рядом её повторяла бы.
 */
function media_gallery_dates_render(bool $dates, array $items): string
{
    return render_view('templates/blocks/media_gallery.php', [
        'blockId' => 9,
        'data' => [
            'title' => 'Медиа', 'columns' => 4, 'ratio' => '16-9', 'dates' => $dates,
            'items' => $items,
        ],
    ]);
}

test('Медиа: дата видео и альбома выводится по галочке, над заголовком', function (): void {
    $items = [
        ['kind' => 'video', 'title' => 'Ролик', 'image' => '', 'meta' => '01:34', 'url' => 'https://youtu.be/abc', 'date' => '2026-09-08 10:00:00'],
        ['kind' => 'photo', 'title' => 'Альбом', 'image' => '', 'meta' => '', 'url' => '/albums/a', 'date' => '2026-08-25 09:00:00'],
    ];

    $off = media_gallery_dates_render(false, $items);
    assert_not_contains('mediacard__date--auto', $off, 'без галочки дат нет — вид собранных блоков не меняется');

    $on = media_gallery_dates_render(true, $items);
    assert_contains('<time class="mediacard__date mediacard__date--auto" datetime="2026-09-08"', $on);
    assert_contains('datetime="2026-08-25"', $on, 'у альбома тоже');
    assert_contains('#tabler-calendar', $on, 'значок, как у карточки новости');
    assert_true(strpos($on, 'mediacard__date--auto') < strpos($on, 'mediacard__title'), 'дата над заголовком');

    $manual = media_gallery_dates_render(true, [
        ['kind' => 'video', 'title' => 'Своя', 'image' => '', 'meta' => '', 'url' => '', 'text' => 'Весна 2026', 'date' => '2026-09-08'],
    ]);
    assert_not_contains('mediacard__date--auto', $manual, 'своя строка редактора главнее');
    assert_contains('Весна 2026', $manual);
});
