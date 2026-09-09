<?php

declare(strict_types=1);

use App\Models\News;

test('Многоязычные детали новостей (Тезисы, Мероприятие, Документы, Таймлайн, Опрос) локализуются детерминированно', function (): void {
    $testNews = [
        'id' => 9999,
        'title' => 'Базовый заголовок новости RU',
        'badge' => 'Реформа',
        'excerpt' => 'Базовый анонс RU',
        'content' => '<p>Базовый текст новости RU</p>',
        'key_points' => "Тезис RU 1\nТезис RU 2",
        'event_meta' => "Дата: 25.07.2026\nМесто: Ташкент",
        'timeline_json' => json_encode([['date' => '25.07.2026', 'title' => 'Старт RU', 'text' => 'Описание RU']]),
        'docs' => json_encode([['title' => 'Документ RU', 'meta' => 'PDF', 'url' => '/files/ru.pdf']]),
        'poll_question' => 'Поддерживаете RU?',
        'poll_options_json' => json_encode(['Да', 'Нет']),
    ];

    $uzTranslation = [
        'title' => 'Asosiy sarlavha UZ',
        'badge' => 'Islohot',
        'excerpt' => 'Asosiy anons UZ',
        'content' => '<p>Asosiy matn UZ</p>',
        'key_points' => "Tezis UZ 1\nTezis UZ 2",
        'event_meta' => "Sana: 25.07.2026\nManzil: Toshkent",
        'timeline_json' => json_encode([['date' => '25.07.2026', 'title' => 'Boshlanishi UZ', 'text' => 'Tavsif UZ']]),
        'docs' => json_encode([['title' => 'Hujjat UZ', 'meta' => 'PDF', 'url' => '/files/uz.pdf']]),
        'poll_question' => 'Qo\'llab-quvvatlaysizmi UZ?',
        'poll_options_json' => json_encode(['Ha', 'Yo\'q']),
    ];

    $reflector = new ReflectionClass(News::class);
    $method = $reflector->getMethod('applyTranslation');
    $result = $method->invoke(null, $testNews, $uzTranslation);

    assert_same('Asosiy sarlavha UZ', $result['title'], 'Заголовок UZ локализован');
    assert_same("Tezis UZ 1\nTezis UZ 2", $result['key_points'], 'Тезисы UZ локализованы');
    assert_same("Sana: 25.07.2026\nManzil: Toshkent", $result['event_meta'], 'Мероприятие UZ локализовано');
    assert_same(json_encode([['date' => '25.07.2026', 'title' => 'Boshlanishi UZ', 'text' => 'Tavsif UZ']]), $result['timeline_json'], 'Таймлайн UZ локализован');
    assert_same(json_encode([['title' => 'Hujjat UZ', 'meta' => 'PDF', 'url' => '/files/uz.pdf']]), $result['docs'], 'Документы UZ локализованы');
    assert_same('Qo\'llab-quvvatlaysizmi UZ?', $result['poll_question'], 'Опрос UZ локализован');
});
