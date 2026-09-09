<?php

declare(strict_types=1);

use App\Core\BlockData\AdvantagesBlockNormalizer;
use App\Core\BlockData\ContactCardsBlockNormalizer;
use App\Core\BlockData\FaqBlockNormalizer;
use App\Core\BlockData\TestimonialsBlockNormalizer;
use App\Core\BlockRenderer;

test('Advantages normalizer: принимает ключ Tabler, типографит и пропускает пустые строки', function (): void {
    $data = AdvantagesBlockNormalizer::normalize([
        'title_field' => ' Преимущества ',
        'description' => '<p>Краткое <strong>описание</strong>.</p><script>alert(1)</script>',
        'items' => [
            [
                'icon_svg' => 'bolt',
                'title' => ' Скорость ',
                'text' => ' Быстро ',
            ],
            ['icon_svg' => 'alert-circle', 'title' => ' ', 'text' => ' '],
            'unexpected',
        ],
    ]);

    assert_same('Преимущества', $data['title']);
    assert_contains('<strong>описание</strong>', $data['description']);
    assert_not_contains('<script', $data['description']);
    assert_same(1, count($data['items']));
    assert_same('bolt', $data['items'][0]['icon_svg']);
    assert_same('Скорость', $data['items'][0]['title']);
    assert_same('Быстро', $data['items'][0]['text']);
});

test('Testimonials normalizer: сохраняет контракт и отбрасывает опасное фото', function (): void {
    $data = TestimonialsBlockNormalizer::normalize([
        'title_field' => ' Отзывы ',
        'items' => [
            [
                'quote' => ' Отличная работа ',
                'name' => ' Анна ',
                'company' => ' Компания ',
                'photo' => ' javascript:alert(1) ',
            ],
            ['quote' => '', 'name' => '', 'company' => 'Не сохраняется'],
            ['quote' => 'Второй отзыв', 'name' => '', 'photo' => ' /uploads/public/person.jpg '],
        ],
    ]);

    assert_same('Отзывы', $data['title']);
    assert_same(2, count($data['items']));
    assert_same('', $data['items'][0]['photo']);
    assert_same('/uploads/public/person.jpg', $data['items'][1]['photo']);
    assert_same('Компания', $data['items'][0]['company']);
});

test('FAQ normalizer: сохраняет HTML-ответ и удаляет полностью пустые пункты', function (): void {
    $data = FaqBlockNormalizer::normalize([
        'title_field' => ' Вопросы ',
        'items' => [
            [
                'question' => ' Как начать? ',
                'answer' => ' <p style="color:red" onclick="alert(1)">Откройте форму</p><script>alert(2)</script> ',
            ],
            ['question' => ' ', 'answer' => ' '],
        ],
    ]);

    assert_same('Вопросы', $data['title']);
    assert_same(1, count($data['items']));
    assert_same("Как\u{00A0}начать?", $data['items'][0]['question']);
    assert_contains('<p>Откройте форму</p>', $data['items'][0]['answer']);
    assert_not_contains('style=', $data['items'][0]['answer']);
    assert_not_contains('onclick', $data['items'][0]['answer']);
    assert_not_contains('<script', $data['items'][0]['answer']);
});

test('Contact cards normalizer и рендерер блокируют опасные ссылки', function (): void {
    $data = ContactCardsBlockNormalizer::normalize([
        'title_field' => ' Контакты ',
        'items' => [
            [
                'icon_svg' => 'mail',
                'title' => ' E-mail ',
                'lines' => " info@example.uz\npress@example.uz ",
                'link_url' => ' javascript:alert(1) ',
                'link_text' => ' Написать ',
            ],
            ['title' => '', 'lines' => ''],
        ],
    ]);

    assert_same('Контакты', $data['title']);
    assert_same(1, count($data['items']));
    assert_same('', $data['items'][0]['link_url']);
    assert_same("info@example.uz\npress@example.uz", $data['items'][0]['lines']);
    assert_same('mail', $data['items'][0]['icon_svg']);

    $legacy = $data;
    $legacy['items'][0]['link_url'] = 'javascript:alert(1)';
    $rendered = BlockRenderer::render([
        'id' => 136,
        'type' => 'contact_cards',
        'custom_css' => null,
        'data' => json_encode($legacy, JSON_UNESCAPED_UNICODE),
    ]);
    assert_not_contains('javascript:', $rendered['html']);
    assert_not_contains('contact-card__link', $rendered['html']);
});

test('Контроллер делегирует повторяющиеся блоки отдельным нормализаторам', function (): void {
    $controller = (string) file_get_contents(APP_ROOT . '/app/Controllers/Admin/BlockController.php');

    assert_contains('AdvantagesBlockNormalizer::normalize($_POST, $locale)', $controller);
    assert_contains('TestimonialsBlockNormalizer::normalize($_POST, $locale)', $controller);
    assert_contains('FaqBlockNormalizer::normalize($_POST, $locale)', $controller);
    assert_contains('ContactCardsBlockNormalizer::normalize($_POST, $locale)', $controller);
});
